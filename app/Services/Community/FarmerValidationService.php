<?php

namespace App\Services\Community;

use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Farmer;
use App\Models\FarmerValidation;
use App\Models\User;
use App\Models\ValidationReason;
use App\Models\ValidationRound;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Farmer revalidation: M&E decides who needs checking and who checks them;
 * field staff do the checking.
 *
 * The separation is the point of the feature and it is enforced here, not by
 * convention. `assign()` is reached with `community.validation.create`, which
 * M&E holds; `submit()` is reached with `community.farmers.validate`, which M&E
 * does not. Neither method quietly does the other's job, so the person who
 * scheduled a check can never also be the person who declared it passed.
 *
 * BR-36 — an overdue farmer's milk is still collected and their payment waits.
 * The hold lives on the Farmer (see `paymentIsHeldPendingValidation`); nothing
 * here refuses a delivery, and nothing here should ever learn how.
 */
class FarmerValidationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * M&E's decision: this farmer, this reason, this person, by this date.
     *
     * @param  array<string, mixed>  $options
     */
    public function assign(Farmer $farmer, ValidationReason $reason, User $assignedBy, array $options = []): FarmerValidation
    {
        /*
         * One open assignment per farmer. A second is not more thorough — it is
         * two field workers driving to the same household, and a queue that
         * grows faster than anyone can work it.
         */
        $existing = FarmerValidation::withoutDataScope()
            ->where('farmer_id', $farmer->getKey())
            ->whereIn('status', [FarmerValidation::STATUS_PENDING, FarmerValidation::STATUS_SUBMITTED, FarmerValidation::STATUS_RETURNED])
            ->first();

        if ($existing !== null) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf('%s already has an open revalidation (%s). Reassign or cancel that one instead.',
                    $farmer->name, $existing->reference),
                ['farmer' => $farmer->code, 'existing' => $existing->reference],
                'farmer_id',
            );
        }

        $assignee = $this->resolveAssignee($options['assigned_to_user_id'] ?? null);

        $validation = DB::transaction(fn () => FarmerValidation::query()->create([
            'reference' => Sequences::next('farmer_validations'),
            'farmer_id' => $farmer->getKey(),
            'validation_round_id' => $options['validation_round_id'] ?? null,
            'validation_reason_id' => $reason->getKey(),
            'assigned_to_user_id' => $assignee?->getKey(),
            'assigned_by_user_id' => $assignedBy->getKey(),
            'assigned_at' => Wat::now(),
            'due_on' => $options['due_on'] ?? null,
            'status' => FarmerValidation::STATUS_PENDING,
            'source' => $options['source'] ?? 'web',
        ]));

        $this->audit->created(
            $validation,
            sprintf('%s — %s assigned for revalidation (%s)%s',
                $validation->reference,
                $farmer->name,
                $reason->name,
                $assignee === null ? ', unassigned' : ' to '.$assignee->name,
            ),
            'Community Engagement',
            ['farmer' => $farmer->code, 'reason' => $reason->code, 'due_on' => $options['due_on'] ?? null],
            $assignedBy,
        );

        $this->notifyAssignee($validation, $assignee, $farmer);

        return $validation;
    }

    /**
     * Bulk: one act of judgement, many assignments.
     *
     * @param  Collection<int, Farmer>  $farmers
     * @return array{round: ValidationRound, assigned: int, skipped: array<int, string>}
     */
    public function openRound(string $name, Collection $farmers, ValidationReason $reason, User $openedBy, array $options = []): array
    {
        $round = ValidationRound::query()->create([
            'reference' => Sequences::next('validation_rounds'),
            'name' => $name,
            'criteria' => $options['criteria'] ?? null,
            'validation_reason_id' => $reason->getKey(),
            'opens_on' => $options['opens_on'] ?? Wat::today()->toDateString(),
            'due_on' => $options['due_on'] ?? null,
            // M&E's call. Defaults from Settings, never from code.
            'auto_approve' => $options['auto_approve'] ?? ValidationRound::defaultAutoApprove(),
            'status' => ValidationRound::STATUS_OPEN,
            'opened_by_user_id' => $openedBy->getKey(),
        ]);

        $assigned = 0;
        $skipped = [];

        foreach ($farmers as $farmer) {
            try {
                $this->assign($farmer, $reason, $openedBy, [
                    'validation_round_id' => $round->getKey(),
                    'due_on' => $round->due_on?->toDateString(),
                    'assigned_to_user_id' => $options['assigned_to_user_id'] ?? null,
                ]);
                $assigned++;
            } catch (RuleViolationException $exception) {
                // A farmer already in somebody's queue is skipped, not an error
                // that abandons the other 200. The round reports what it left.
                $skipped[] = $farmer->code.': '.$exception->getMessage();
            }
        }

        $this->audit->created(
            $round,
            sprintf('%s — revalidation round opened: %d farmers, %d skipped', $round->reference, $assigned, count($skipped)),
            'Community Engagement',
            ['criteria' => $round->criteria, 'auto_approve' => $round->auto_approve, 'skipped' => $skipped],
            $openedBy,
        );

        return ['round' => $round, 'assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * The field worker's answer. Applies any corrections to the farmer record
     * and closes the assignment — or parks it for review, if M&E asked for one.
     *
     * @param  array<string, mixed>  $data  outcome, findings, and any corrected fields
     */
    public function submit(FarmerValidation $validation, array $data, User $fieldWorker): FarmerValidation
    {
        if (! $validation->isOpen()) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf('%s is already %s and cannot be submitted again.', $validation->reference, $validation->status),
                ['validation' => $validation->reference, 'status' => $validation->status],
                'status',
            );
        }

        $outcome = (string) ($data['outcome'] ?? FarmerValidation::OUTCOME_CONFIRMED);

        $this->guardOutcome($outcome);

        $farmer = $validation->farmer;

        if ($farmer === null) {
            throw RuleViolationException::make(
                'USER-1',
                'This revalidation names a farmer that no longer exists.',
                ['validation' => $validation->reference],
                'farmer_id',
            );
        }

        $corrections = $this->corrections($data);
        $before = $farmer->only(array_keys($corrections));

        $autoApprove = $validation->round?->auto_approve ?? ValidationRound::defaultAutoApprove();

        DB::transaction(function () use ($validation, $farmer, $corrections, $before, $outcome, $data, $fieldWorker, $autoApprove): void {
            /*
             * Corrections are applied on SUBMISSION, not on approval.
             *
             * The field worker is standing with the farmer; what they report is
             * the best information the system has, and holding it behind a
             * review means the register stays knowingly wrong until somebody at
             * a desk gets to it. A review that disagrees returns the assignment
             * and the correction is revisited — which is the right way round.
             */
            if ($corrections !== [] && $outcome === FarmerValidation::OUTCOME_CORRECTED) {
                $farmer->forceFill($corrections)->save();
            }

            $validation->forceFill(array_merge($this->submittedLocation($data), [
                'status' => $autoApprove ? FarmerValidation::STATUS_ACCEPTED : FarmerValidation::STATUS_SUBMITTED,
                'outcome' => $outcome,
                'before' => $before,
                'after' => $corrections,
                'findings' => $data['findings'] ?? null,
                'submitted_by_user_id' => $fieldWorker->getKey(),
                'submitted_at' => Wat::now(),
                'source' => $data['source'] ?? $validation->source,
                'reviewed_by_user_id' => null,
                'reviewed_at' => $autoApprove ? Wat::now() : null,
            ]))->save();

            if ($autoApprove) {
                $this->markFarmerValidated($validation, $farmer);
            }
        });

        $this->audit->created(
            $validation,
            sprintf('%s — %s revalidation %s by %s%s',
                $validation->reference,
                $farmer->name,
                $outcome,
                $fieldWorker->name,
                $autoApprove ? ' (auto-approved)' : ', awaiting review',
            ),
            'Community Engagement',
            ['before' => $before, 'after' => $corrections, 'findings' => $data['findings'] ?? null],
            $fieldWorker,
        );

        if (! $autoApprove) {
            $this->notifications->send(
                eventCode: 'validation.awaiting_review',
                recipients: $this->notifications->usersWithPermission('community.validation.approve'),
                title: 'Revalidation awaiting review: '.$farmer->name,
                body: sprintf('%s reported "%s" for %s.', $fieldWorker->name, $outcome, $farmer->name),
                subject: $validation,
            );
        }

        return $validation->refresh();
    }

    /** M&E accepts what came back. */
    public function accept(FarmerValidation $validation, User $reviewer, ?string $note = null): FarmerValidation
    {
        $this->guardAwaitingReview($validation);

        DB::transaction(function () use ($validation, $reviewer, $note): void {
            $validation->forceFill([
                'status' => FarmerValidation::STATUS_ACCEPTED,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => Wat::now(),
                'review_note' => $note,
            ])->save();

            if ($validation->farmer !== null) {
                $this->markFarmerValidated($validation, $validation->farmer);
            }
        });

        $this->audit->approval(
            $validation,
            sprintf('%s — revalidation accepted', $validation->reference),
            ['note' => $note],
            $reviewer,
        );

        return $validation->refresh();
    }

    /**
     * Bulk: one act of judgement, many acceptances.
     *
     * The sibling of `openRound()` at the other end of the queue. M&E has
     * already narrowed the review list to a community, an agent or a point and
     * satisfied itself that the batch is sound; signing each one individually
     * adds clicks, not scrutiny.
     *
     * Skips rather than aborts, for openRound()'s reason: one row that has
     * since been returned or cancelled by somebody else must not abandon the
     * other ninety-nine. What was left is reported, never swallowed.
     *
     * Each acceptance still writes its own audit entry through `accept()`, so
     * the trail is per-farmer even though the decision was collective; the
     * summary entry below records that they were accepted together, which is
     * the fact an auditor would want and could not otherwise reconstruct.
     *
     * @param  Collection<int, FarmerValidation>  $validations
     * @return array{accepted: int, skipped: array<int, string>}
     */
    public function acceptMany(Collection $validations, User $reviewer, ?string $note = null): array
    {
        $accepted = 0;
        $skipped = [];

        foreach ($validations as $validation) {
            try {
                $this->accept($validation, $reviewer, $note);
                $accepted++;
            } catch (RuleViolationException $exception) {
                $skipped[] = $validation->reference.': '.$exception->getMessage();
            }
        }

        /*
         * Written through `write()` rather than `approval()`: that helper stamps
         * the module as Purchases and needs a subject model, and this entry is a
         * Community Engagement fact about a SET of records rather than about any
         * one of them.
         */
        $this->audit->write([
            'actor' => $reviewer,
            'event_type' => AuditEntry::EVENT_APPROVAL,
            'module' => 'Community Engagement',
            'summary' => sprintf('Revalidation reviewed in bulk: %d accepted, %d skipped', $accepted, count($skipped)),
            'detail' => [
                'accepted' => $accepted,
                'skipped' => $skipped,
                'note' => $note,
                'references' => $validations->pluck('reference')->all(),
            ],
        ]);

        return ['accepted' => $accepted, 'skipped' => $skipped];
    }

    /** M&E sends it back: the assignment reopens for the same field worker. */
    public function returnToField(FarmerValidation $validation, User $reviewer, string $note): FarmerValidation
    {
        $this->guardAwaitingReview($validation);

        $validation->forceFill([
            'status' => FarmerValidation::STATUS_RETURNED,
            'reviewed_by_user_id' => $reviewer->getKey(),
            'reviewed_at' => Wat::now(),
            'review_note' => $note,
        ])->save();

        $this->audit->rejection(
            $validation,
            sprintf('%s — revalidation returned to the field', $validation->reference),
            ['note' => $note],
            $reviewer,
        );

        $this->notifyAssignee($validation, $validation->assignedTo, $validation->farmer, returned: true);

        return $validation->refresh();
    }

    public function cancel(FarmerValidation $validation, User $actor, string $reason): FarmerValidation
    {
        if ($validation->status === FarmerValidation::STATUS_ACCEPTED) {
            throw RuleViolationException::make(
                'BR-36',
                'An accepted revalidation is part of the record and cannot be cancelled.',
                ['validation' => $validation->reference],
                'status',
            );
        }

        $validation->forceFill([
            'status' => FarmerValidation::STATUS_CANCELLED,
            'review_note' => $reason,
            'reviewed_by_user_id' => $actor->getKey(),
            'reviewed_at' => Wat::now(),
        ])->save();

        $this->audit->edited(
            $validation,
            sprintf('%s — revalidation cancelled', $validation->reference),
            'Community Engagement',
            ['status' => FarmerValidation::STATUS_PENDING],
            ['status' => FarmerValidation::STATUS_CANCELLED, 'reason' => $reason],
            $actor,
        );

        return $validation->refresh();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Where the field worker was when they submitted, if the phone knew.
     *
     * A revalidation can release a payment BR-36 is holding, so where it was
     * recorded is worth keeping — but it is evidence for a reviewer, never a
     * gate. A submission with no fix is accepted exactly as before: the places
     * hardest to get a signal in are the places the register is least reliable
     * about, and refusing them would lose the visit that fixes it.
     *
     * Empty array rather than nulls, so a web submission — which never carries
     * a coordinate — cannot blank one a phone recorded on an earlier attempt.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function submittedLocation(array $data): array
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [];
        }

        if (abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return [];
        }

        $accuracy = $data['location_accuracy_m'] ?? null;

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'location_accuracy_m' => is_numeric($accuracy) ? (int) round((float) $accuracy) : null,
            'located_at' => Wat::instant($data['located_at'] ?? null) ?? Wat::now(),
        ];
    }

    /**
     * Only a check that actually verified the farmer moves the clock.
     *
     * `not_found` and `refused` close the assignment honestly — the field
     * worker went and reported what happened — but they leave the farmer
     * overdue, and therefore leave BR-36's payment hold in place. That is the
     * intended outcome: a farmer nobody can find is precisely the record the
     * hold exists for.
     */
    private function markFarmerValidated(FarmerValidation $validation, Farmer $farmer): void
    {
        if (! in_array((string) $validation->outcome, FarmerValidation::VERIFYING_OUTCOMES, true)) {
            return;
        }

        $farmer->forceFill(['last_validated_on' => Wat::today()->toDateString()])->save();
    }

    /**
     * The fields a field worker may correct — and only these.
     *
     * `validate` is a narrower authority than `edit` precisely so that a
     * Collection Agent verifying a phone number cannot also move a farmer into
     * another cooperative or reassign their collection point. Those are
     * consequential changes with money attached (cooperative deductions, which
     * centre the milk counts against), and they belong to somebody holding
     * `community.farmers.edit`.
     *
     * A submission naming anything outside this list is not rejected — the
     * extra field is simply not applied, because losing a whole visit over an
     * unexpected key would be the worse failure. The `after` snapshot records
     * exactly what was taken.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function corrections(array $data): array
    {
        $allowed = ['phone', 'gender', 'year_of_birth', 'herd_size', 'lactating_count'];

        $corrections = [];

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === null || $value === '') {
                continue;
            }

            $corrections[$field] = in_array($field, ['year_of_birth', 'herd_size', 'lactating_count'], true)
                ? (int) $value
                : (string) $value;
        }

        return $corrections;
    }

    private function guardOutcome(string $outcome): void
    {
        $valid = [
            FarmerValidation::OUTCOME_CONFIRMED,
            FarmerValidation::OUTCOME_CORRECTED,
            FarmerValidation::OUTCOME_NOT_FOUND,
            FarmerValidation::OUTCOME_REFUSED,
        ];

        if (! in_array($outcome, $valid, true)) {
            throw RuleViolationException::make(
                'BR-36',
                'A revalidation must say what was found: confirmed, corrected, not found, or refused.',
                ['outcome' => $outcome, 'allowed' => $valid],
                'outcome',
            );
        }
    }

    private function guardAwaitingReview(FarmerValidation $validation): void
    {
        if (! $validation->isAwaitingReview()) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf('%s is %s, so there is nothing to review.', $validation->reference, $validation->status),
                ['validation' => $validation->reference, 'status' => $validation->status],
                'status',
            );
        }
    }

    /**
     * A named assignee must actually be able to do the work. Assigning a
     * revalidation to somebody without `community.farmers.validate` produces a
     * task that sits in their queue and can never be completed — and the person
     * who notices is the farmer, three months later, still unpaid.
     */
    private function resolveAssignee(mixed $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            throw RuleViolationException::make(
                'BR-36',
                'That user does not exist.',
                ['user_id' => $userId],
                'assigned_to_user_id',
            );
        }

        if (! $user->hasPermission('community.farmers.validate')) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf('%s cannot carry out a revalidation — their role does not include it.', $user->name),
                ['user' => $user->email],
                'assigned_to_user_id',
            );
        }

        return $user;
    }

    private function notifyAssignee(FarmerValidation $validation, ?User $assignee, ?Farmer $farmer, bool $returned = false): void
    {
        if ($farmer === null) {
            return;
        }

        // NOTIF-2 — an unassigned item goes to everyone who could pick it up,
        // not to nobody.
        $recipients = $assignee !== null
            ? collect([$assignee])
            : $this->notifications->usersWithPermission('community.farmers.validate');

        $this->notifications->send(
            eventCode: $returned ? 'validation.returned' : 'validation.assigned',
            recipients: $recipients,
            title: $returned
                ? 'Revalidation returned: '.$farmer->name
                : 'Revalidation assigned: '.$farmer->name,
            body: $returned
                ? (string) $validation->review_note
                : sprintf('%s%s.',
                    $validation->reason?->name ?? 'Revalidation',
                    $validation->due_on === null ? '' : ' — due '.$validation->due_on->toDateString(),
                ),
            subject: $validation,
        );
    }
}
