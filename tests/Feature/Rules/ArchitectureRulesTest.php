<?php

namespace Tests\Feature\Rules;

use App\Authorization\Access;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Grade;
use App\Models\IdempotencyKey;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Sequence;
use App\Policies\DeliveryPolicy;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;
use Tests\GondalTestCase;

/** §3 architecture and §13 non-functional requirements. */
class ArchitectureRulesTest extends GondalTestCase
{
    /**
     * ARCH-6 / NFR-5 — "All money in minor units (kobo) as integers. All volumes in
     * litres as decimal(10,2)" and "All monetary and volume arithmetic in integers
     * or fixed-point decimals. Never floats."
     */
    public function test_arch6_money_and_volume_arithmetic_are_exact(): void
    {
        // The classic float trap: 0.1 + 0.2 !== 0.3.
        $this->assertSame('0.30', Volume::add('0.10', '0.20'));
        $this->assertSame('0.10', Volume::subtract('0.30', '0.20'));

        // A hundred tenths of a litre make exactly ten litres.
        $this->assertSame('10.00', Volume::sum(array_fill(0, 100, '0.10')));

        // Money parses operator input to integer kobo and never loses a kobo.
        $this->assertSame(340_000_000, Money::fromMajor('3,400,000.00'));
        $this->assertSame(1, Money::fromMajor('0.01'));
        $this->assertSame(-1_050, Money::fromMajor('-10.50'));

        // A percentage of an amount stays integral, rounded half-up.
        $this->assertSame(13_500, Money::percentageOf(675_000, '2'));
        $this->assertIsInt(Money::percentageOf(675_000, '2'));

        // Valuing a volume at a rate is integer arithmetic throughout.
        $this->assertSame(675_000, Money::valueVolume('27.00', 25_000));
        $this->assertSame(676_250, Money::valueVolume('27.05', 25_000));

        // And a percentage comparison is exact rather than approximate.
        $this->assertSame('0.24', Volume::percentageOf('-8.00', '3400.00'));
    }

    /**
     * ARCH-9 — "Timezone Africa/Lagos (WAT). Store UTC, present WAT."
     *
     * This is the one that bites silently, so it is asserted at the storage layer:
     * what lands in the column must be the UTC instant, whatever timezone the value
     * was built in.
     */
    public function test_arch9_instants_are_stored_utc_and_presented_wat(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Africa/Lagos', Wat::zone());

        // The two halves of the helper are genuinely different views.
        $this->assertSame('UTC', Wat::now()->getTimezone()->getName());
        $this->assertSame('Africa/Lagos', Wat::local()->getTimezone()->getName());
        $this->assertSame(Wat::now()->getTimestamp(), Wat::local()->getTimestamp());

        // "06:20 WAT today" stored is 05:20 UTC.
        $instant = Wat::todayAt(6, 20);

        $this->assertSame('UTC', $instant->getTimezone()->getName());
        $this->assertSame('05:20', $instant->format('H:i'));
        // And presented back, it is 06:20 again.
        $this->assertSame('06:20', Wat::time($instant));

        // Through a real column, end to end.
        [$world, $agent] = $this->agent();

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'delivered_at' => Wat::todayAt(6, 20),
        ], $agent);

        $stored = DB::table('deliveries')->where('id', $delivery->id)->value('delivered_at');

        $this->assertStringContainsString('05:20', $stored, 'The column holds UTC.');
        $this->assertSame('06:20', Wat::time($delivery->refresh()->delivered_at), 'The screen shows WAT.');
    }

    /**
     * NFR-4 — "Concurrency: confirming an already-confirmed consignment must fail,
     * not overwrite. Use optimistic locking on consignments and batches."
     */
    public function test_nfr4_a_stale_write_fails_rather_than_overwriting(): void
    {
        [$world, $officer] = $this->officer();

        $consignment = $this->dispatch($world, $officer);
        $this->recordTests($consignment, $officer);

        // Two readers hold the same version.
        $readerOne = Consignment::query()->findOrFail($consignment->id);
        $readerTwo = Consignment::query()->findOrFail($consignment->id);

        $this->assertSame((int) $readerOne->lock_version, (int) $readerTwo->lock_version);

        app(ConsignmentService::class)->confirm($readerOne, [
            'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
        ], $officer);

        // The second reader's confirm is refused by the rule.
        try {
            app(ConsignmentService::class)->confirm($readerTwo, [
                'litres_rejected_at_center' => '50.00',
                'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            ], $officer);

            $this->fail('Confirming an already-confirmed consignment must fail.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('NFR-4', $exception->ruleId);
        }

        // The first confirmation stands, untouched.
        $fresh = $consignment->refresh();
        $this->assertSame('0.00', (string) $fresh->litres_rejected_at_center);
        $this->assertSame(1, (int) $fresh->lock_version);
    }

    /** NFR-4 — the lock itself refuses a write made against a stale version. */
    public function test_nfr4_optimistic_locking_detects_a_concurrent_change(): void
    {
        [$world, $officer] = $this->officer();
        $consignment = $this->dispatch($world, $officer);

        $stale = Consignment::query()->findOrFail($consignment->id);

        // Somebody else moves the row on.
        Consignment::query()->whereKey($consignment->id)->update([
            'officer_notes' => 'Changed by another request',
            'lock_version' => DB::raw('lock_version + 1'),
        ]);

        $stale->officer_notes = 'My change';

        try {
            $stale->saveWithLock();
            $this->fail('A write against a stale version must fail.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('NFR-4', $exception->ruleId);
            $this->assertStringContainsString('changed by someone else', $exception->getMessage());
        }

        $this->assertSame('Changed by another request', $consignment->refresh()->officer_notes);
    }

    /**
     * ARCH-7 — "All write endpoints accept an optional Idempotency-Key header;
     * replays return the original result."
     */
    public function test_arch7_a_replayed_write_returns_the_original_result(): void
    {
        [$world, $agent] = $this->agent();

        $payload = [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '24.00',
            'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
        ];

        $first = $this->withHeaders(['Idempotency-Key' => 'field-capture-001'])
            ->postJson('/api/deliveries', $payload);

        $first->assertCreated();
        $reference = $first->json('data.reference');

        // The client is unsure whether it landed, so it retries with the same key.
        $replay = $this->withHeaders(['Idempotency-Key' => 'field-capture-001'])
            ->postJson('/api/deliveries', $payload);

        $replay->assertCreated();
        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'));
        $this->assertSame($reference, $replay->json('data.reference'));

        // Exactly one delivery exists.
        $this->assertSame(1, Delivery::withoutDataScope()->count());
        $this->assertDatabaseHas('idempotency_keys', ['key' => 'field-capture-001']);
    }

    /** ARCH-7 — the same key with a different body is a client bug, not a retry. */
    public function test_arch7_the_same_key_with_a_different_body_is_refused(): void
    {
        [$world, $agent] = $this->agent();

        $this->withHeaders(['Idempotency-Key' => 'reused-key'])
            ->postJson('/api/deliveries', [
                'collection_point_id' => $world['pointA']->id,
                'farmer_id' => $world['farmer']->id,
                'litres_presented' => '24.00',
                'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
            ])->assertCreated();

        $response = $this->withHeaders(['Idempotency-Key' => 'reused-key'])
            ->postJson('/api/deliveries', [
                'collection_point_id' => $world['pointA']->id,
                'farmer_id' => $world['farmer']->id,
                'litres_presented' => '99.00',
                'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $this->assertSame('ARCH-7', $response->json('rule'));
        $this->assertSame(1, Delivery::withoutDataScope()->count());
    }

    /** ARCH-7 — a write with no key behaves normally, twice. */
    public function test_arch7_the_header_is_optional(): void
    {
        [$world, $agent] = $this->agent();

        $payload = [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '5.00',
            'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
        ];

        $this->postJson('/api/deliveries', $payload)->assertCreated();
        $this->postJson('/api/deliveries', $payload)->assertCreated();

        $this->assertSame(2, Delivery::withoutDataScope()->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    /**
     * ARCH-2 — "API-first. Controllers return JSON via API resources." The API and
     * the web UI share their rules, their authorisation and their audit trail.
     */
    public function test_arch2_the_api_enforces_the_same_rules_as_the_web_ui(): void
    {
        [$world, $agent] = $this->agent();

        // BR-1 over the API: a rejection with no reason is the same 422, same rule.
        $response = $this->postJson('/api/deliveries', [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '20.00',
            'litres_rejected' => '5.00',
            'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $this->assertSame('BR-1', $response->json('rule'));

        // AUDIT-4 — an API write is tagged `api`, not `web`.
        $this->postJson('/api/deliveries', [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '20.00',
            'delivered_at' => Wat::todayAt(6, 30)->toIso8601String(),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Milk Collection',
            'source' => 'api',
        ]);
    }

    /** ARCH-2 / SCR-1 — a 403 over the API carries the same facts as the page. */
    public function test_arch2_an_api_denial_carries_the_same_facts(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('API Scoped Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        // milk.deliveries.create is not in this role, so the route refuses it.
        $response = $this->postJson('/api/deliveries', [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '20.00',
        ]);

        $response->assertStatus(403);
        $response->assertJsonStructure([
            'message', 'rule',
            'denial' => ['reason', 'permission_key', 'data_scope', 'reference'],
        ]);
        $this->assertSame('milk.deliveries.create', $response->json('denial.permission_key'));
        $this->assertStringStartsWith('DENY-', $response->json('denial.reference'));
    }

    /**
     * §9 / §18.7 — reference data reaches a client as DATA, so a field app cannot
     * ship its own copy that would drift from Settings.
     */
    public function test_reference_data_is_served_to_clients(): void
    {
        [$world, $agent] = $this->agent();

        $response = $this->getJson('/api/reference-data');

        $response->assertOk();

        $grades = collect($response->json('data.grades'));
        $gradeA = $grades->firstWhere('code', 'GRD-A');

        // BR-13 — rates arrive WITH their effective dates, never as a bare number.
        $this->assertNotEmpty($gradeA['rates']);
        $this->assertArrayHasKey('effective_from', $gradeA['rates'][0]);
        $this->assertSame('250.00', $gradeA['rates'][0]['rate_per_litre']);

        // BR-1 — the reasons, and the stages each is enabled for.
        $reasons = collect($response->json('data.rejection_reasons'));
        $late = $reasons->firstWhere('code', 'REJ-LATE');

        $this->assertSame(['point', 'center'], $late['available_at']);
        $this->assertTrue($late['is_cutoff_breach']);
        $this->assertSame(2, $late['followup_threshold']);

        // The conventions, stated rather than left for a client to guess.
        $this->assertSame('NGN', $response->json('data.conventions.currency'));
        $this->assertSame('Africa/Lagos', $response->json('data.conventions.timezone_display'));
    }

    /** NFR-2 — "Paginate every list, default 25. Never return unbounded collections." */
    public function test_nfr2_lists_are_paginated_with_a_default_of_25(): void
    {
        $this->assertSame(25, (int) config('gondal.pagination.per_page'));

        [$world, $agent] = $this->agent();

        foreach (range(1, 30) as $n) {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '5.00',
                'delivered_at' => Wat::todayAt(6, 0)->addMinutes($n),
            ], $agent);
        }

        $response = $this->getJson('/api/deliveries');

        $response->assertOk();
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(30, $response->json('meta.total'));

        // And a caller cannot ask for everything at once: per_page is capped.
        $greedy = $this->getJson('/api/deliveries?per_page=10000');

        $greedy->assertOk();
        $this->assertSame(
            (int) config('gondal.pagination.max_per_page'),
            (int) $greedy->json('meta.per_page'),
            'per_page is capped at the configured maximum, however much is asked for.',
        );
    }

    /** NFR-9 — a request id on every response, and no secrets in it. */
    public function test_nfr9_every_response_carries_a_request_id(): void
    {
        [$world, $agent] = $this->agent();

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));

        // The id is echoed back when the client supplies one, so a user can quote it.
        $mine = $this->withHeaders(['X-Request-Id' => 'trace-me-123'])->get(route('dashboard'));
        $this->assertSame('trace-me-123', $mine->headers->get('X-Request-Id'));

        // And it reaches the audit trail.
        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '3.00',
            'delivered_at' => Wat::todayAt(6, 0),
        ], $agent);

        $this->assertNotNull(
            AuditEntry::query()->latest('id')->value('request_id'),
        );
    }

    /**
     * §9 — reference numbering is DATA. Changing the format changes the next
     * reference, and the reset period is honoured.
     */
    public function test_reference_numbering_is_configurable(): void
    {
        /*
         * §17 — the seeded shapes.
         *
         * DEL carries the date because it resets DAILY: without it, the second
         * day's DEL-0001 collides with the first day's under a unique constraint,
         * and recording the first delivery of any later day threw a 500. CNS does
         * not reset, so a bare running number stays unique for ever.
         */
        /*
         * Wat::LOCAL, not Wat::now(). The implementation stamps {day} from the WAT
         * clock because that is the clock the daily reset runs on; deriving the
         * expectation from the UTC clock made this assertion inherit whichever one
         * the implementation happened to use, so it could never disagree with the
         * bug it exists to catch. Between 00:00 and 01:00 WAT the two differ.
         */
        $today = Wat::local()->format('Ymd');

        $this->assertSame("DEL-{$today}-0001", Sequences::next('deliveries'));
        $this->assertSame("DEL-{$today}-0002", Sequences::next('deliveries'));
        $this->assertSame('CNS-0001', Sequences::next('consignments'));
        $this->assertSame(
            'REQ-'.Wat::local()->format('Y').'-0001',
            Sequences::next('requisitions'),
            'REQ carries the year and resets yearly.',
        );

        // An administrator changes the shape; the next reference follows.
        Sequence::query()->where('key', 'deliveries')->update([
            'prefix' => 'INTAKE',
            'digits' => 6,
            'reference_format' => '{prefix}/{year}/{number}',
        ]);

        $this->assertSame(
            'INTAKE/'.Wat::local()->format('Y').'/000003',
            Sequences::next('deliveries'),
            'Existing records keep their references; only new ones change.',
        );
    }

    /** §9 — a daily-reset sequence starts again on a new day. */
    public function test_daily_sequences_reset(): void
    {
        // Wat::LOCAL — see the note in test_reference_numbering_is_configurable.
        $today = Wat::local()->format('Ymd');

        $this->assertSame("DEL-{$today}-0001", Sequences::next('deliveries'));
        $this->assertSame("DEL-{$today}-0002", Sequences::next('deliveries'));

        // Yesterday's counter does not carry over: the number goes back to 1.
        Sequence::query()->where('key', 'deliveries')->update([
            'last_reset_on' => Wat::today()->subDay()->toDateString(),
            'current_value' => 87,
        ]);

        $reissued = Sequences::next('deliveries');

        $this->assertStringEndsWith('-0001', $reissued, 'The daily counter resets.');

        /*
         * ...but the REFERENCE must not repeat. This assertion used to read
         * `assertSame('DEL-0001', ...)`, which is the same string the first call
         * returned — the test demonstrated a duplicate reference and passed. The
         * column is unique, so in production the first delivery of day two threw
         * a 500. The date in the reference is what keeps the reset harmless.
         */
        $this->assertSame("DEL-{$today}-0001", $reissued);
        $this->assertStringContainsString($today, $reissued,
            'A daily sequence must say which day it is counting.');
    }

    /** ARCH-8 — "Soft deletes everywhere. Nothing operational is ever hard-deleted." */
    public function test_arch8_operational_records_are_soft_deleted(): void
    {
        [$world, $agent] = $this->agent();

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '12.00',
            'delivered_at' => Wat::todayAt(6, 0),
        ], $agent);

        $delivery->delete();

        $this->assertSoftDeleted('deliveries', ['id' => $delivery->id]);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id]);

        // And the policy refuses a force delete outright.
        $this->assertFalse(
            app(DeliveryPolicy::class)->forceDelete($agent, $delivery),
        );
    }

    /**
     * ARCH-5 — "Do not adopt an off-the-shelf roles package without verifying it
     * supports §5.3 data scope."
     */
    public function test_arch5_no_off_the_shelf_permission_package_is_installed(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $required = array_keys(array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? [],
        ));

        foreach ($required as $package) {
            $this->assertStringNotContainsString(
                'permission',
                strtolower($package),
                'Scope is built here, not delegated to a package that cannot express it.',
            );
        }

        // What is installed instead: our own two layers.
        $this->assertTrue(class_exists(Access::class));
        $this->assertTrue(interface_exists(Scopeable::class));
    }

    /* ------------------------------------------------------------------ */

    private function agent(): array
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Arch Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        return [$world, $agent->fresh()];
    }

    private function officer(): array
    {
        $world = $this->makeMilkWorld();
        $officer = $this->makeUser('Arch Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        return [$world, $officer->fresh()];
    }

    private function dispatch(array $world, $actor): Consignment
    {
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '200.00',
            'delivered_at' => Wat::todayAt(6, 0),
        ], $actor);

        return app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['dispatched_at' => Wat::todayAt(7, 0)],
            $actor,
        );
    }

    private function recordTests(Consignment $consignment, $actor): void
    {
        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $actor,
            );
        }
    }

    /**
     * ARCH-9, the input half: a naive string off a form is a WAT wall-clock
     * reading, not UTC.
     *
     * Every datetime-local field posts "2026-08-01T06:05" — what the operator's
     * clock said. Reading that as UTC added an hour to every time entered through
     * the interface: stored an hour late, and judged against the collection
     * cut-off an hour late, so on-time milk needed a supervisor override.
     */
    public function test_arch9_a_naive_form_time_is_read_as_west_africa_time(): void
    {
        $typed = '2026-08-01T06:05';

        // What the operator sees back is what they typed.
        $this->assertSame('06:05', Wat::of($typed)->format('H:i'));
        $this->assertSame($typed, Wat::forInput($typed));

        // What gets stored is the instant that names — 05:05 UTC.
        $this->assertSame('2026-08-01 05:05:00', Wat::instant($typed)->toDateTimeString());
        $this->assertSame('UTC', Wat::instant($typed)->timezoneName);

        // The round trip through the form is stable.
        $this->assertSame($typed, Wat::forInput(Wat::instant($typed)));

        // A string carrying its own offset keeps it — API clients are unaffected.
        $this->assertSame('2026-08-01 06:05:00', Wat::instant('2026-08-01T06:05:00Z')->toDateTimeString());
        $this->assertSame('07:05', Wat::of('2026-08-01T06:05:00Z')->format('H:i'));

        // A Carbon keeps its instant regardless of the zone it arrives in.
        $instant = Wat::todayAt(6, 5);
        $this->assertSame($instant->timestamp, Wat::instant($instant)->timestamp);
        $this->assertSame('06:05', Wat::of($instant)->format('H:i'));
    }

    /** And the cut-off rule therefore judges a form time by the operator's clock. */
    public function test_arch9_the_cutoff_rule_uses_the_clock_the_operator_read(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Clock Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $cutoff = $world['pointA']->effectiveCutoff();          // "07:00"
        [$hour, $minute] = array_map('intval', explode(':', $cutoff));
        $justBefore = Wat::today()->setTime($hour, $minute)->subMinutes(5)->format('H:i');

        // Recorded five minutes BEFORE the cut-off: accepted, no override needed.
        $this->post(route('deliveries.store'), [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '20.00',
            'delivered_at' => Wat::today()->format('Y-m-d').'T'.$justBefore,
        ])->assertSessionHasNoErrors();

        $delivery = Delivery::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertFalse((bool) $delivery->was_after_cutoff, 'On-time milk must not be flagged late.');
        $this->assertSame($justBefore, Wat::of($delivery->delivered_at)->format('H:i'));
    }
}
