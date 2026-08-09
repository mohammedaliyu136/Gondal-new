<?php

namespace App\Services\Mobile;

use App\Authorization\Access;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\ActivityType;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FarmerValidation;
use App\Models\FieldActivity;
use App\Models\MobileSyncRecord;
use App\Models\Sale;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Community\FarmerValidationService;
use App\Services\Milk\DeliveryService;
use App\Services\Shop\SaleService;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The offline queue, drained.
 *
 * A field client captures work with no signal and pushes the lot when it finds
 * some. That is the ONLY thing this service adds to the system: batching, and
 * the per-record idempotency that makes a retry safe. Everything else is the
 * behaviour that already exists —
 *
 *   · every record is authorised through Access, both layers (ARCH-4), so an
 *     Extension Agent's phone cannot record a delivery no matter what it sends;
 *   · deliveries go through DeliveryService, so BR-1's three reasons, BR-3's
 *     cut-off and BR-6's stored arithmetic apply exactly as at a desk;
 *   · sales go through SaleService, so BR-26, BR-27 and BR-30 apply;
 *   · every write is audited (AUDIT-4 tags the source).
 *
 * ONE RECORD'S FAILURE IS ONE RECORD'S FAILURE. Each is committed in its own
 * transaction and its own try, and a rejection is reported against its
 * client_uuid so the phone can mark that row failed and keep the rest. A batch
 * that rolled back wholesale would mean one farmer's mistyped litre count
 * silently discarding a morning's collection from six others.
 */
class MobileSyncService
{
    /** The keys a client may send, and the handler for each. */
    private const HANDLERS = [
        'farmer_registrations' => 'registerFarmer',
        'farmer_validations' => 'validateFarmer',
        'milk_collections' => 'recordDelivery',
        'oss_sales' => 'recordSale',
        'field_visits' => 'recordFieldVisit',
    ];

    public function __construct(
        private readonly Access $access,
        private readonly DeliveryService $deliveries,
        private readonly FarmerValidationService $validations,
        private readonly SaleService $sales,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $batch
     * @return array{results: array<string, mixed>, accepted: int, rejected: int}
     */
    public function handle(User $user, array $batch, Request $request): array
    {
        $results = [];
        $errors = [];
        $accepted = 0;

        foreach (self::HANDLERS as $key => $handler) {
            $records = $batch[$key] ?? [];

            if (! is_array($records)) {
                continue;
            }

            $results[$key] = [];

            foreach ($records as $payload) {
                if (! is_array($payload)) {
                    $errors[] = ['type' => $key, 'client_uuid' => null, 'error' => 'Record is not an object.'];

                    continue;
                }

                $clientUuid = $this->clientUuid($payload);

                if ($clientUuid === null) {
                    $errors[] = ['type' => $key, 'client_uuid' => null, 'error' => 'Record has no client_uuid.'];

                    continue;
                }

                // ARCH-7 — already written on a previous attempt. Answer with
                // the original id; do not write a second one.
                $existing = MobileSyncRecord::query()
                    ->where('user_id', $user->getKey())
                    ->where('client_uuid', $clientUuid)
                    ->first();

                if ($existing !== null) {
                    $results[$key][] = [
                        'client_uuid' => $clientUuid,
                        'db_id' => $existing->subject_id,
                        'duplicate' => true,
                    ];
                    $accepted++;

                    continue;
                }

                try {
                    $record = DB::transaction(fn () => $this->{$handler}($user, $payload, $request));

                    MobileSyncRecord::query()->create([
                        'user_id' => $user->getKey(),
                        'client_uuid' => $clientUuid,
                        'record_type' => $key,
                        'subject_type' => $record::class,
                        'subject_id' => $record->getKey(),
                    ]);

                    $results[$key][] = ['client_uuid' => $clientUuid, 'db_id' => $record->getKey()];
                    $accepted++;
                } catch (Throwable $exception) {
                    $errors[] = [
                        'type' => $key,
                        'client_uuid' => $clientUuid,
                        'error' => $this->explain($exception),
                    ];
                }
            }
        }

        $results['errors'] = $errors;

        return ['results' => $results, 'accepted' => $accepted, 'rejected' => count($errors)];
    }

    /* ------------------------------------------------------------------
     | Handlers. Each returns the model it wrote.
     * --------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $payload
     */
    private function registerFarmer(User $user, array $payload, Request $request): Model
    {
        $this->refuseToDuplicate($payload);

        $community = $this->resolveCommunity($payload);

        // ARCH-4 layer 2 — the community decides whether this agent may enrol
        // here. An Extension Agent scoped to four communities cannot enrol into
        // a fifth, whatever the phone sends.
        $this->access->authorize($user, 'community.farmers.create', $community, 'Mobile: enrol a farmer');

        $cooperative = $this->resolveCooperative($payload);
        $point = $this->resolveCollectionPoint($payload);

        $farmer = Farmer::query()->create([
            'code' => $this->farmerCode($payload),
            'name' => $this->string($payload, 'name', 255) ?? 'Unnamed farmer',
            'gender' => $this->string($payload, 'gender', 16),
            'phone' => $this->string($payload, 'phone', 32),
            'community_id' => $community->getKey(),
            'lga_id' => $community->lga_id,
            'cooperative_id' => $cooperative?->getKey(),
            'default_collection_point_id' => $point?->getKey(),
            'herd_size' => $this->integer($payload, 'herd_size'),
            'lactating_count' => $this->integer($payload, 'lactating_count'),
            'status' => 'active',
            'enrolled_by_user_id' => $user->getKey(),
            'enrolled_on' => Wat::today()->toDateString(),
        ]);

        $this->audit->created(
            $farmer,
            $farmer->name.' enrolled at '.$community->name.' from AgentConnect',
            'Community Engagement',
            ['source' => 'mobile'],
            $user,
        );

        return $farmer;
    }

    /**
     * A `farmer_registrations` record that names a farmer the server already has
     * is not a registration.
     *
     * The phone's edit screen re-runs the registration flow for any farmer,
     * including one it was given by the server, and the client_uuid it carries is
     * always one the `mobile_sync_records` ledger has never seen — a server-sourced
     * farmer has no client_uuid, and a locally-enrolled one that has already synced
     * is deliberately given a fresh one. So ARCH-7's idempotency cannot see it, and
     * `farmerCode()` reassigns the code on collision, so the duplicate lands with a
     * DIFFERENT code and no unique constraint catches it. Two records for one
     * person means split delivery history, split payment identity and a double
     * count in `farmers_under_care`.
     *
     * >>> BLOCKED ON A BUSINESS DECISION — do not resolve this by inventing an
     * >>> update path. §16 and BR-36 point the other way: a field worker holds
     * >>> `community.farmers.validate`, not `edit`, and revalidation already
     * >>> exists as the sanctioned way to correct a farmer in the field. Until the
     * >>> business says whether a phone may edit an enrolled farmer at all, the
     * >>> record is REFUSED with a message that names the sanctioned route. A
     * >>> refusal the agent can read and act on beats a duplicate nobody notices
     * >>> until the payment run splits one farmer in two.
     * >>>
     * >>> If the answer is "yes, editing stays": add a `farmer_updates` batch key
     * >>> gated on `community.farmers.edit` that resolves by `db_id` and updates.
     * >>> This guard stays either way — `farmer_registrations` must never create a
     * >>> farmer for a payload that names an existing one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function refuseToDuplicate(array $payload): void
    {
        // Only an explicit server id counts. A COLLIDING code is a different
        // problem with a different right answer — farmerCode() mints a fresh one
        // from the server's own sequence, because the phone's guess at a code was
        // never authoritative and a genuinely new farmer must not be refused for it.
        $id = $payload['db_id'] ?? $payload['farmer_db_id'] ?? null;

        $existing = is_numeric($id) ? Farmer::withoutDataScope()->find((int) $id) : null;

        if ($existing === null) {
            return;
        }

        throw RuleViolationException::make(
            'BR-36',
            sprintf(
                '%s is already on the register as %s. Enrolment cannot change an existing farmer — '
                .'ask Monitoring & Evaluation to raise a revalidation, which is how details are corrected in the field.',
                $existing->name,
                $existing->code,
            ),
            ['farmer' => $existing->code, 'db_id' => $existing->getKey()],
            'db_id',
        );
    }

    /**
     * A revalidation coming back from the field.
     *
     * BR-36 — the check was ASSIGNED, so the submission closes that assignment
     * rather than editing a farmer on its own authority. The permission is
     * `community.farmers.validate`, which is narrower than `edit` on purpose: a
     * Collection Agent may confirm what they see in front of them without
     * gaining the run of the register.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateFarmer(User $user, array $payload, Request $request): Model
    {
        $validation = $this->resolveValidation($payload);
        $farmer = $validation->farmer ?? $this->requireFarmer($payload);

        // Layer 2 — the farmer decides, not the assignment. An agent handed a
        // task for a community they do not cover still cannot act on it.
        $this->access->authorize($user, 'community.farmers.validate', $farmer, 'Mobile: revalidate '.$farmer->name);

        return $this->validations->submit($validation, array_merge([
            'outcome' => $payload['outcome'] ?? null,
            'findings' => $this->string($payload, 'findings', 4000) ?? $this->string($payload, 'notes', 4000),
            'phone' => $this->string($payload, 'phone', 32),
            'gender' => $this->string($payload, 'gender', 16),
            'year_of_birth' => $this->integer($payload, 'year_of_birth'),
            'herd_size' => $this->integer($payload, 'herd_size'),
            'lactating_count' => $this->integer($payload, 'lactating_count'),
            'source' => 'mobile',
        ], $this->coordinates($payload)), $user);
    }

    /**
     * The assignment this submission answers.
     *
     * A phone should always send `validation_id` — it got the assignment from
     * the server. But a build in the field may predate that, or the queue may
     * have been refreshed between capture and sync, so the farmer's own open
     * assignment is accepted as a fallback. What is NOT accepted is a
     * submission with no assignment at all: an unrequested "validation" is an
     * edit wearing a costume, and it would let a field worker mark any farmer
     * verified — including, under BR-36, releasing their held payment.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveValidation(array $payload): FarmerValidation
    {
        $id = $payload['validation_id'] ?? null;

        if (is_numeric($id)) {
            $validation = FarmerValidation::withoutDataScope()->find((int) $id);

            if ($validation !== null) {
                return $validation;
            }
        }

        $farmer = $this->findFarmer($payload);

        $open = $farmer === null ? null : FarmerValidation::withoutDataScope()
            ->where('farmer_id', $farmer->getKey())
            ->open()
            ->orderBy('assigned_at')
            ->first();

        if ($open !== null) {
            return $open;
        }

        throw RuleViolationException::make(
            'BR-36',
            $farmer === null
                ? 'This revalidation names no farmer the server has.'
                : sprintf('%s has no open revalidation. Monitoring & Evaluation assign these — ask them to raise one.', $farmer->name),
            ['farmer' => $farmer?->code, 'validation_id' => $id],
            'validation_id',
        );
    }

    /**
     * BR-1 / BR-3 / BR-6 all live in DeliveryService, which is why this method
     * resolves references and does no arithmetic of its own.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordDelivery(User $user, array $payload, Request $request): Model
    {
        $farmer = $this->requireFarmer($payload);

        /*
         * Resolved WITHOUT the data scope, deliberately.
         *
         * "Which point is this delivery at?" is identification; "may you record
         * there?" is the very next line. Reading the farmer's default point
         * through the scope conflates them, and the user who may not record at
         * that point is told "this delivery names no collection point" — which
         * is false, and sends them looking for a missing field instead of an
         * administrator. Authorise, then explain.
         */
        $point = $this->resolveCollectionPoint($payload)
            ?? ($farmer->default_collection_point_id === null
                ? null
                : CollectionPoint::withoutDataScope()->find($farmer->default_collection_point_id));

        if ($point === null) {
            throw RuleViolationException::make(
                'BR-2',
                'This delivery names no collection point, and the farmer has no default one.',
                ['farmer' => $farmer->code],
                'collection_point_id',
            );
        }

        $this->access->authorize($user, 'milk.deliveries.create', $point, 'Mobile: record intake at '.$point->name);

        $delivery = $this->deliveries->record($point, $farmer, [
            'litres_presented' => $payload['volume'] ?? $payload['litres_presented'] ?? 0,
            'litres_rejected' => $payload['litres_rejected'] ?? 0,
            'rejection_reason_id' => $payload['rejection_reason_id'] ?? null,
            'containers' => $this->integer($payload, 'containers'),
            'notes' => $this->string($payload, 'notes', 2000),
            /*
             * ARCH-9 — the phone sends the wall-clock time it captured, which is
             * the time the milk was actually presented rather than the time the
             * batch happened to reach the server. BR-3's cut-off is judged on
             * that, so a late sync cannot turn an on-time delivery into a late
             * one, and an override still has to be explicit and attributed.
             */
            'delivered_at' => $this->deliveredAt($payload),
            'cutoff_override' => (bool) ($payload['cutoff_override'] ?? false),
            'cutoff_override_reason' => $this->string($payload, 'cutoff_override_reason', 255),
        ], $user);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSale(User $user, array $payload, Request $request): Model
    {
        $this->access->authorize($user, 'shop.sales.create', null, 'Mobile: record a sale');

        $lines = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['product_id'])) {
                continue;
            }

            $lines[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (float) ($item['quantity'] ?? 0),
            ];
        }

        $farmer = $this->findFarmer($payload);

        return $this->sales->record([
            // Sale::CUSTOMER_WALKIN is 'walkin'. The literal 'walk_in' that stood
            // here matched no branch of Sale::customerLabel() and no filter on the
            // web screen, so every mobile counter sale was invisible under
            // "Walk-in" while still rendering as one.
            'customer_type' => $payload['customer_type'] ?? ($farmer !== null ? Sale::CUSTOMER_FARMER : Sale::CUSTOMER_WALKIN),
            'farmer_id' => $farmer?->getKey(),
            'cooperative_id' => $this->resolveCooperative($payload)?->getKey(),
            'customer_name' => $this->string($payload, 'farmer_name', 255),
            'payment_method' => $payload['payment_method'] ?? 'cash',
            'amount_received_minor' => (int) round(100 * (float) ($payload['amount_received'] ?? $payload['total_amount'] ?? 0)),
            'prescription_reference' => $this->string($payload, 'prescription_reference', 64),
            'notes' => $this->string($payload, 'notes', 500),
            // ARCH-9 — the instant of the sale, not the calendar day it fell on.
            'sold_at' => $this->capturedAt($payload, 'sold_at'),
        ], $lines, $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordFieldVisit(User $user, array $payload, Request $request): Model
    {
        $community = $this->resolveCommunity($payload);
        $agent = $this->requireExtensionAgentFor($user);

        $this->access->authorize($user, 'community.extension.create', $agent, 'Mobile: log a field visit');

        /*
         * ARCH-4 layer 2, SECOND subject — and ARCH-2, which says this surface
         * enforces what the web UI enforces.
         *
         * Authorising the agent alone passes for every agent: it is their own
         * register row. `community_id` then reached the insert having been
         * checked for nothing but existence, so a phone could log a visit —
         * and the farmers-reached figure that feeds programme reporting —
         * against any community in the network, 200 km outside the three the
         * agent actually covers.
         *
         * FieldActivityController fixed exactly this on the web and carries a
         * comment saying so; the sync path never got the same fix, which is the
         * failure mode ARCH-2 exists to catch. Hand-built JSON is the surface
         * where it matters most.
         */
        $this->access->authorize(
            $user,
            'community.extension.create',
            $community,
            'Mobile: log a field visit in '.$community->name,
        );

        $farmers = array_values(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['farmer_id'] ?? null) : null,
            (array) ($payload['farmers'] ?? []),
        )));

        $activity = FieldActivity::query()->create(array_merge($this->coordinates($payload), [
            'reference' => Sequences::next('field_activities'),
            'extension_agent_id' => $agent->getKey(),
            'activity_type_id' => $this->resolveActivityType($payload)->getKey(),
            'community_id' => $community->getKey(),
            // One activity can reach several farmers; the single-farmer column
            // is filled only when the visit was to exactly one household.
            'farmer_id' => count($farmers) === 1 ? (int) $farmers[0] : null,
            'activity_date' => $payload['visit_date'] ?? Wat::today()->toDateString(),
            'farmers_reached' => count($farmers),
            'topic' => $this->topicOf($payload),
            'findings' => $this->string($payload, 'notes', 4000),
            // ARCH-2 — the column that was put there for exactly this client.
            'source' => 'mobile',
            'synced_at' => Wat::now(),
        ]));

        $this->audit->created(
            $activity,
            sprintf('%s logged from AgentConnect — %s, %d farmers reached',
                $activity->reference, $community->name, count($farmers)),
            'Community Engagement',
            ['topics' => $payload['topics'] ?? [], 'source' => 'mobile'],
            $user,
        );

        return $activity;
    }

    /* ------------------------------------------------------------------
     | Resolution helpers
     * --------------------------------------------------------------- */

    /**
     * Where the phone was standing, if it knew.
     *
     * Returns an empty array rather than nulls when there is no usable fix, so
     * a caller can `array_merge()` it into a create without overwriting
     * anything, and so "no fix" never becomes a row of zeroes — 0,0 is a real
     * coordinate in the Gulf of Guinea and would read as a location the worker
     * was demonstrably not at.
     *
     * A fix is taken only if BOTH bounds hold. A phone reporting a latitude of
     * 91 is not reporting a place.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function coordinates(array $payload): array
    {
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [];
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return [];
        }

        $accuracy = $payload['location_accuracy_m'] ?? $payload['accuracy'] ?? null;

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_accuracy_m' => is_numeric($accuracy) ? (int) round((float) $accuracy) : null,
            /*
             * When the fix was taken, not when it synced — the two can be days
             * apart for a worker out of signal, and it is the first that says
             * whether the coordinate belongs to this record.
             */
            'located_at' => Wat::instant($payload['located_at'] ?? null) ?? Wat::now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function clientUuid(array $payload): ?string
    {
        $uuid = trim((string) ($payload['client_uuid'] ?? ''));

        return $uuid === '' ? null : substr($uuid, 0, 36);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireFarmer(array $payload): Farmer
    {
        $farmer = $this->findFarmer($payload);

        if ($farmer === null) {
            throw RuleViolationException::make(
                'USER-1',
                'This record names a farmer the server does not have. Sync the farmer registration first.',
                ['farmer' => $payload['farmer_id'] ?? $payload['db_id'] ?? null],
                'farmer_id',
            );
        }

        return $farmer;
    }

    /**
     * A phone knows a farmer three ways: the server id it was given, the id it
     * was given for a farmer it registered itself, or the farmer's code. All
     * three are tried, in that order of certainty.
     *
     * The lookup deliberately ignores the data scope: identifying WHICH farmer a
     * record is about is not the same question as whether this user may act on
     * them, and that second question is asked immediately afterwards by
     * Access::authorize() against the record itself. Filtering here instead
     * would turn a refusal into "no such farmer", which is both less true and
     * harder to act on.
     *
     * @param  array<string, mixed>  $payload
     */
    private function findFarmer(array $payload): ?Farmer
    {
        foreach (['farmer_db_id', 'db_id', 'farmer_id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_numeric($value)) {
                $farmer = Farmer::withoutDataScope()->find((int) $value);

                if ($farmer !== null) {
                    return $farmer;
                }
            }
        }

        $code = trim((string) ($payload['farmer_code'] ?? $payload['id'] ?? ''));

        return $code === '' ? null : Farmer::withoutDataScope()->where('code', $code)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCommunity(array $payload): Community
    {
        $id = $payload['community_id'] ?? null;

        if (is_numeric($id)) {
            $community = Community::query()->find((int) $id);

            if ($community !== null) {
                return $community;
            }
        }

        $name = trim((string) ($payload['community'] ?? $payload['village'] ?? ''));

        $community = $name === '' ? null : Community::query()->where('name', $name)->first();

        if ($community === null) {
            /*
             * A community is §9 reference data — an administrator creates it in
             * Settings. The phone must not invent one, or the register grows a
             * long tail of near-duplicate spellings that nothing can reconcile.
             */
            throw RuleViolationException::make(
                'ARCH-6',
                $name === ''
                    ? 'This record names no community.'
                    : sprintf('“%s” is not a community on the register. Ask an administrator to add it.', $name),
                ['community' => $name],
                'community_id',
            );
        }

        return $community;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCooperative(array $payload): ?Cooperative
    {
        $id = $payload['cooperative_id'] ?? null;

        if (is_numeric($id)) {
            return Cooperative::withoutDataScope()->find((int) $id);
        }

        $name = trim((string) ($payload['cooperative'] ?? ''));

        return $name === '' ? null : Cooperative::withoutDataScope()->where('name', $name)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCollectionPoint(array $payload): ?CollectionPoint
    {
        foreach (['collection_point_id', 'point_id', 'collection_centre'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_numeric($value)) {
                $point = CollectionPoint::withoutDataScope()->find((int) $value);

                if ($point !== null) {
                    return $point;
                }
            }
        }

        $name = trim((string) ($payload['collection_point'] ?? ''));

        return $name === '' ? null : CollectionPoint::withoutDataScope()->where('name', $name)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveActivityType(array $payload): ActivityType
    {
        $id = $payload['activity_type_id'] ?? null;

        if (is_numeric($id)) {
            $type = ActivityType::query()->find((int) $id);

            if ($type !== null) {
                return $type;
            }
        }

        $label = trim((string) ($payload['activity_type'] ?? $payload['activity_type_code'] ?? ''));

        if ($label !== '') {
            $type = ActivityType::query()
                ->where('code', strtoupper($label))
                ->orWhere('name', $label)
                ->first();

            if ($type !== null) {
                return $type;
            }
        }

        /*
         * §9 — the activity type is reference data an administrator owns, and
         * the column is NOT NULL because an activity with no type cannot be
         * reported on. The current client sends topics rather than a type, so
         * an unstated one falls back to the household visit — which is what the
         * app's visit-report screen actually records.
         *
         * The fallback is a lookup, never an invention: if an administrator has
         * retired VISIT, the first active type in their own order is used, and
         * only a register with no active types at all is an error. Losing an
         * agent's morning to a picker mismatch would be the worse failure.
         */
        $fallback = ActivityType::query()->where('code', 'VISIT')->where('status', 'active')->first()
            ?? ActivityType::query()->where('status', 'active')->orderBy('position')->first();

        if ($fallback === null) {
            throw RuleViolationException::make(
                'ARCH-6',
                'No activity type is configured, so a visit cannot be recorded. Ask an administrator to add one in Settings.',
                [],
                'activity_type_id',
            );
        }

        return $fallback;
    }

    /**
     * A field visit belongs to an extension agent, not merely to a user — the
     * register, the targets and the follow-up trail all hang off that record.
     */
    private function requireExtensionAgentFor(User $user): ExtensionAgent
    {
        $agent = ExtensionAgent::withoutDataScope()->where('user_id', $user->getKey())->first();

        if ($agent === null) {
            throw RuleViolationException::make(
                'USER-2',
                'You have no extension-agent record, so a visit cannot be logged against you. Ask an administrator to create one.',
                ['user' => $user->email],
                'extension_agent_id',
            );
        }

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function farmerCode(array $payload): string
    {
        $code = trim((string) ($payload['id'] ?? $payload['farmer_code'] ?? ''));

        if ($code !== '' && ! Farmer::withoutDataScope()->where('code', $code)->exists()) {
            return substr($code, 0, 24);
        }

        // The phone's code collided, or it sent none. The server's own sequence
        // is authoritative anyway (ARCH-6), so it decides.
        return Sequences::next('farmers');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliveredAt(array $payload): ?string
    {
        return $this->capturedAt($payload, 'delivered_at');
    }

    /**
     * The instant a field record was captured, never a calendar day.
     *
     * ARCH-9 — an instant and a date are different things, and deriving one from
     * the other destroys information that cannot be recovered from the row. The
     * app posts a bare `date` alongside its real instants, and preferring that
     * `date` meant Wat::instant() parsed it as midnight: EVERY mobile delivery and
     * sale was stored at 00:00 WAT. The time of the intake was gone from the
     * receipt and from Wat::relative(), and BR-3's cut-off — which compares the WAT
     * wall clock against the point's cut-off — saw 00:00 for every one of them, so
     * the rule could never fire on the field channel at all.
     *
     * The phone already sends what is needed. `captured_at` is the moment of
     * capture and `queued_at` (sync_service.dart) is a UTC ISO-8601 instant stamped
     * when the row entered the offline queue; either is closer to the truth than
     * midnight. The bare `date` is the last resort, and even then the instant is
     * the sync receipt rather than midnight whenever the claimed day is today —
     * which is the ordinary case, a phone finding signal the same morning.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $ownKey  the record type's own instant field
     */
    private function capturedAt(array $payload, string $ownKey): ?string
    {
        foreach ([$ownKey, 'captured_at', 'queued_at'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '' && ! $this->isBareDate($value)) {
                return $value;
            }
        }

        $claimed = trim((string) ($payload['date'] ?? ''));

        if ($claimed === '') {
            // Nothing to go on: the services stamp the receipt instant themselves.
            return null;
        }

        $receipt = Wat::local();

        if (Wat::of($claimed)?->toDateString() === $receipt->toDateString()) {
            return Wat::now()->toIso8601String();
        }

        /*
         * A phone that was offline across a day boundary. The hour is genuinely
         * unrecoverable, so the claimed WAT day is preserved and the record sits at
         * the start of it — deliberately BEFORE any cut-off, because inventing a
         * late time would refuse milk on the strength of a guess.
         */
        return $claimed;
    }

    /** "2026-08-05" is a calendar day; "2026-08-05T06:12:00Z" is an instant. */
    private function isBareDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function topicOf(array $payload): ?string
    {
        $topics = (array) ($payload['topics'] ?? []);

        if ($topics === []) {
            return $this->string($payload, 'topic', 255);
        }

        return substr(implode(', ', array_map(static fn ($topic) => (string) $topic, $topics)), 0, 255);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key, int $max): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value === '' ? null : substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function integer(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * What the phone is told about a rejection.
     *
     * The message matters more here than anywhere else in the system: it is read
     * by a field worker, hours later, with the record in front of them and no
     * way to ask anybody. So a rule violation keeps its rule ID and its sentence,
     * a refusal says which permission was missing, and only a genuinely unknown
     * error becomes a generic line — and that one is still logged in full.
     */
    private function explain(Throwable $exception): string
    {
        if ($exception instanceof RuleViolationException) {
            return $exception->ruleId.' — '.$exception->getMessage();
        }

        if ($exception instanceof AccessDeniedException) {
            return 'Not permitted: '.$exception->getMessage();
        }

        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        report($exception);

        return 'The server could not accept this record.';
    }
}
