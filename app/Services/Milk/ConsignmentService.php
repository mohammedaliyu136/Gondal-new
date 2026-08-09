<?php

namespace App\Services\Milk;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Grade;
use App\Models\QualityTest;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — dispatching a point's accepted milk, and confirming it at the center.
 *
 * BR-7  litres_dispatched = Σ litres_accepted of its deliveries
 * BR-8  litres_confirmed  = dispatched + Σ adjustments − rejected at center
 * BR-4  a grade may be assigned only after every REQUIRED quality test is
 *       recorded, and only by a holder of milk.grade.create
 * BR-13/14 the applicable rate is snapshotted at confirmation, so BR-13's "a
 *       delivery confirmed yesterday still reports yesterday's rate" holds
 *       whatever happens to the rate later
 * NFR-4 confirming an already-confirmed consignment fails rather than overwrites
 * DM-2  a fully rejected delivery never joins a consignment
 */
class ConsignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly Access $access,
    ) {}

    /**
     * The agent dispatches the day's accepted volume as one consignment.
     *
     * @param  array<int, int>  $deliveryIds
     * @param  array<string, mixed>  $data
     */
    public function dispatch(CollectionPoint $point, array $deliveryIds, array $data, User $actor): Consignment
    {
        /** @var Collection<int, Delivery> $deliveries */
        $deliveries = Delivery::query()
            ->whereIn('id', $deliveryIds)
            ->where('collection_point_id', $point->getKey())
            ->get();

        if ($deliveries->isEmpty()) {
            throw RuleViolationException::make(
                'BR-7',
                'Select at least one delivery to dispatch.',
                [],
                'delivery_ids',
            );
        }

        // DM-2 — a fully rejected delivery has no volume to travel, and a
        // delivery already on a consignment cannot be dispatched twice.
        foreach ($deliveries as $delivery) {
            if ($delivery->consignment_id !== null) {
                throw RuleViolationException::make(
                    'DM-2',
                    "{$delivery->reference} is already on consignment {$delivery->consignment->reference}.",
                    ['delivery' => $delivery->reference],
                );
            }

            if ($delivery->status === Delivery::STATUS_REJECTED) {
                throw RuleViolationException::make(
                    'DM-2',
                    "{$delivery->reference} was rejected in full and has no volume to dispatch.",
                    ['delivery' => $delivery->reference],
                );
            }
        }

        // BR-7
        $litres = Volume::sum($deliveries->pluck('litres_accepted')->all());

        if (Volume::toCentilitres($litres) <= 0) {
            throw RuleViolationException::make(
                'BR-7',
                'The selected deliveries have no accepted volume to dispatch.',
                [],
                'delivery_ids',
            );
        }

        $consignment = DB::transaction(function () use ($point, $deliveries, $litres, $data, $actor): Consignment {
            /*
             * DM-2 — the guard above ran against a snapshot read outside this
             * transaction, which is a read-check-write with nothing on the
             * write. Under READ COMMITTED two requests that both read before
             * either commits both passed it, and the second UPDATE re-evaluated
             * a WHERE of only `id in (…)` — still matching — and overwrote.
             * One 40 L delivery produced two consignments of 40 L each: the
             * first orphaned with volume and no deliveries, still batchable
             * once confirmed, its phantom litres flowing into BR-10's
             * discrepancy and every dashboard aggregate. A phone retrying while
             * the first request is in flight is enough.
             *
             * So the write carries the guard: only rows still unassigned are
             * claimed, and if that is fewer than we selected somebody else got
             * there first and the whole transaction rolls back rather than
             * leaving an orphan behind. NFR-4's optimistic locking was applied
             * to confirmation and reconciliation but not here, and dispatch is
             * the one place a volume is created rather than amended.
             */
            $claimed = Delivery::query()
                ->whereIn('id', $deliveries->pluck('id'))
                ->whereNull('consignment_id')
                ->lockForUpdate()
                ->pluck('id');

            if ($claimed->count() !== $deliveries->count()) {
                throw $this->raceLost($deliveries->count() - $claimed->count(), $deliveries->count());
            }

            $consignment = Consignment::query()->create([
                'reference' => Sequences::next('consignments'),
                'collection_point_id' => $point->getKey(),
                'collection_center_id' => $point->collection_center_id,
                'dispatched_by_user_id' => $actor->getKey(),
                'dispatched_at' => Wat::instant($data['dispatched_at'] ?? null) ?? Wat::now(),
                'litres_dispatched' => $litres,
                'containers' => $data['containers'] ?? null,
                'trip_id' => $data['trip_id'] ?? null,
                'status' => Consignment::STATUS_AWAITING,
            ]);

            $assigned = Delivery::query()
                ->whereIn('id', $claimed)
                // The predicate the original write was missing. On a driver
                // whose row lock is advisory rather than blocking, this is what
                // actually refuses the second claim.
                ->whereNull('consignment_id')
                ->update(['consignment_id' => $consignment->getKey()]);

            if ($assigned !== $claimed->count()) {
                throw $this->raceLost($claimed->count() - $assigned, $deliveries->count());
            }

            return $consignment;
        });

        $this->audit->created(
            $consignment,
            sprintf(
                '%s dispatched from %s to %s — %s across %d deliveries',
                $consignment->reference,
                $point->name,
                $point->collectionCenter?->name ?? 'the center',
                Volume::format($litres),
                $deliveries->count(),
            ),
            'Milk Collection',
            ['rule' => 'BR-7', 'deliveries' => $deliveries->pluck('reference')->all()],
            $actor,
        );

        // NOTIF-3 — "consignment awaiting confirmation".
        $this->notifications->send(
            eventCode: 'consignment.awaiting_confirmation',
            recipients: $this->notifications->usersWithPermission('milk.consignment.confirm.edit', $consignment),
            title: $consignment->reference.' awaiting confirmation',
            body: sprintf('%s from %s.', Volume::format($litres), $point->name),
            actionUrl: route('consignments.index'),
            subject: $consignment,
        );

        return $consignment;
    }

    /**
     * BR-4 — record one quality test result. Called before grading.
     */
    public function recordQualityTest(
        Consignment $consignment,
        QualityTestDefinition $definition,
        ?string $reading,
        User $actor,
    ): QualityTest {
        $passed = $definition->accepts($reading);

        $key = [
            'consignment_id' => $consignment->getKey(),
            'quality_test_definition_id' => $definition->getKey(),
        ];

        $result = [
            // Snapshotted, so retiring the definition later cannot rewrite
            // this recorded result.
            'test_type' => $definition->code,
            'reading' => $reading,
            'acceptable_range' => $definition->describeRange(),
            'passed' => $passed,
            'recorded_by_user_id' => $actor->getKey(),
            'recorded_at' => Wat::now(),
        ];

        try {
            $test = QualityTest::query()->updateOrCreate($key, $result);
        } catch (UniqueConstraintViolationException) {
            /*
             * BR-4 — the confirmation screen posts one row per test from a form
             * it does not own, identified by which submit button was clicked, so
             * repeated clicks on a slow connection are the expected interaction.
             * Both requests miss the SELECT and both INSERT; the partial unique
             * index now refuses the second rather than leaving the consignment
             * with two answers to "did it pass the alcohol test?". Losing the
             * race is not an error the operator should see — apply this reading
             * to the row the winner wrote.
             */
            $test = QualityTest::query()->where($key)->firstOrFail();
            $test->fill($result)->save();
        }

        $this->audit->created(
            $test,
            sprintf(
                '%s — %s test recorded: %s (%s)',
                $consignment->reference,
                $definition->name,
                (string) $reading,
                $passed ? 'pass' : 'fail',
            ),
            'Milk Collection',
            ['rule' => 'BR-4', 'range' => $definition->describeRange()],
            $actor,
        );

        return $test;
    }

    /**
     * The officer confirms the litre count, records any rejection at the center,
     * and assigns a grade.
     *
     * @param  array<string, mixed>  $data
     */
    public function confirm(Consignment $consignment, array $data, User $actor): Consignment
    {
        // NFR-4 — "confirming an already-confirmed consignment must fail, not
        // overwrite."
        if ($consignment->isConfirmed()) {
            throw RuleViolationException::make(
                'NFR-4',
                "{$consignment->reference} was already confirmed by "
                    .($consignment->confirmedBy?->name ?? 'someone else')
                    .' at '.Wat::dateTime($consignment->confirmed_at).'.',
                ['consignment' => $consignment->reference],
            );
        }

        $rejected = (string) ($data['litres_rejected_at_center'] ?? '0');
        $reason = ($data['rejection_reason_id'] ?? null) === null
            ? null
            : RejectionReason::query()->find($data['rejection_reason_id']);

        $this->guardCenterRejection($consignment, $rejected, $reason);

        $grade = ($data['grade_id'] ?? null) === null
            ? null
            : Grade::query()->find($data['grade_id']);

        if ($grade !== null) {
            $this->guardGrading($consignment, $grade, $actor);
        }

        // BR-8 — dispatched + Σ adjustments − rejected at center.
        $adjustments = $consignment->adjustmentTotal();
        $confirmed = Volume::subtract(
            Volume::add($consignment->litres_dispatched, $adjustments),
            $rejected,
        );

        if (Volume::isNegative($confirmed)) {
            throw RuleViolationException::make(
                'BR-8',
                'Adjustments and rejection cannot take the confirmed volume below zero.',
                [
                    'dispatched' => (string) $consignment->litres_dispatched,
                    'adjustments' => $adjustments,
                    'rejected' => $rejected,
                ],
                'litres_rejected_at_center',
            );
        }

        /*
         * BR-13 / BR-14 — the rate is snapshotted against the SERVER's clock,
         * never against the confirmation time the caller sent.
         *
         * `confirmed_at` arrives from the request and both surfaces used to
         * hand it straight to rateOn(). BR-14 was satisfied throughout — the
         * row and the number were both snapshotted — which is why nothing
         * caught it: the snapshot was faithful, it was the anchor that was
         * forged. With Grade A cut to ₦200/L today, a confirmed_at set a week
         * back snapshotted ₦250/L, so a 100 L consignment was valued 25% high
         * by the person keying it, on a screen that needs no supervisor. The
         * reverse underpays the farmer just as quietly.
         *
         * The operator-stated confirmation time is still recorded — late data
         * entry is real and the day's aggregates read it — it is simply no
         * longer money. guardConfirmationTime() keeps it honest as a record.
         */
        $anchoredAt = Wat::now();
        $confirmedAt = $this->guardConfirmationTime($consignment, $data['confirmed_at'] ?? null, $anchoredAt);
        $rate = $grade?->rateOn($anchoredAt);

        if ($grade !== null && $rate === null) {
            throw RuleViolationException::make(
                'BR-13',
                "{$grade->name} has no rate effective on ".Wat::date($anchoredAt)
                    .'. Add an effective-dated rate in Settings first.',
                ['grade' => $grade->code],
                'grade_id',
            );
        }

        $status = $this->deriveStatus($rejected, $adjustments);

        $consignment->fill([
            'confirmed_by_user_id' => $actor->getKey(),
            'confirmed_at' => $confirmedAt,
            'litres_confirmed' => $confirmed,
            'grade_id' => $grade?->getKey(),
            // BR-14 — both the row AND the number.
            'grade_rate_id' => $rate?->getKey(),
            'rate_per_litre_minor' => $rate?->rate_per_litre_minor,
            // Written even when the grade comes later, so grade() has an anchor
            // that nobody chose.
            'rate_anchored_at' => $anchoredAt,
            'litres_rejected_at_center' => $rejected,
            'rejection_reason_id' => $reason?->getKey(),
            'intake_temperature_c' => $data['intake_temperature_c'] ?? null,
            'officer_notes' => $data['officer_notes'] ?? null,
            'status' => $status,
        ]);

        // NFR-4 — the write itself is guarded by the version.
        $consignment->saveWithLock();

        $this->audit->edited(
            $consignment,
            sprintf(
                '%s confirmed — %s confirmed, %s rejected at center%s',
                $consignment->reference,
                Volume::format($confirmed),
                Volume::format($rejected),
                $grade === null ? '' : ', '.$grade->name,
            ),
            'Milk Collection',
            ['status' => Consignment::STATUS_AWAITING],
            [
                'status' => $status,
                'litres_confirmed' => $confirmed,
                'adjustments' => $adjustments,
                'grade' => $grade?->code,
                // BR-14 — the snapshot is part of the record of what happened.
                'rate_per_litre_minor' => $rate?->rate_per_litre_minor,
                'grade_rate_effective_from' => $rate?->effective_from?->toDateString(),
                // BR-14 — which clock priced this, so a reviewer can tell a
                // late-keyed confirmation from a re-priced one.
                'rate_anchored_at' => $anchoredAt->toIso8601String(),
                'snapshot_anchored_to' => 'server clock at confirmation',
                'rules' => ['BR-4', 'BR-8', 'BR-13', 'BR-14'],
            ],
            $actor,
        );

        return $consignment;
    }

    /* ------------------------------------------------------------------ */

    /**
     * DM-2 — somebody else dispatched these litres while this request was in
     * flight. Raised inside the transaction so it rolls back: half a
     * consignment is worse than none, because the half with the volume on it
     * still batches.
     */
    private function raceLost(int $taken, int $selected): RuleViolationException
    {
        return RuleViolationException::make(
            'DM-2',
            sprintf(
                '%d of the %d selected %s already dispatched on another consignment. Nothing was dispatched — reload the list and try again.',
                $taken,
                $selected,
                $taken === 1 ? 'deliveries was' : 'deliveries were',
            ),
            ['taken' => $taken, 'selected' => $selected],
            'delivery_ids',
        );
    }

    /**
     * ST-1 — a consignment cannot be confirmed before it was dispatched.
     *
     * `confirmed_at` no longer prices anything, so this is about the record
     * rather than the money: the day aggregates on the centre and dashboard
     * screens all filter on it, and a confirmation stamped before its own
     * dispatch puts litres into a day that had already been reported. The
     * upper bound (not in the future) is validation on both surfaces, because
     * that one is about operator input; this one needs the record in hand and
     * so belongs here, where the API cannot go round it.
     */
    private function guardConfirmationTime(Consignment $consignment, mixed $stated, Carbon $now): Carbon
    {
        $confirmedAt = Wat::instant($stated instanceof CarbonInterface || is_string($stated) ? $stated : null) ?? $now;

        if ($consignment->dispatched_at !== null && $confirmedAt->lessThan($consignment->dispatched_at)) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf(
                    '%s was dispatched at %s. It cannot be confirmed before it left the point.',
                    $consignment->reference,
                    Wat::dateTime($consignment->dispatched_at),
                ),
                [
                    'dispatched_at' => $consignment->dispatched_at->toIso8601String(),
                    'confirmed_at' => $confirmedAt->toIso8601String(),
                ],
                'confirmed_at',
            );
        }

        return $confirmedAt;
    }

    /** BR-1 — the reason must be enabled for the CENTER stage. */
    private function guardCenterRejection(Consignment $consignment, string $rejected, ?RejectionReason $reason): void
    {
        $hasRejection = Volume::toCentilitres($rejected) > 0;

        if ($hasRejection && $reason === null) {
            throw RuleViolationException::make(
                'BR-1',
                'Volume rejected at the center needs a reason from the configured list.',
                [],
                'rejection_reason_id',
            );
        }

        if ($reason !== null && ! $reason->isAvailableAt(RejectionReason::STAGE_CENTER)) {
            throw RuleViolationException::make(
                'BR-1',
                "The reason '{$reason->name}' is not enabled for collection centers.",
                ['reason' => $reason->code, 'stage' => 'center'],
                'rejection_reason_id',
            );
        }

        if (Volume::compare($rejected, (string) $consignment->litres_dispatched) === 1) {
            throw RuleViolationException::make(
                'BR-8',
                'Rejected volume cannot exceed the volume dispatched.',
                ['dispatched' => (string) $consignment->litres_dispatched, 'rejected' => $rejected],
                'litres_rejected_at_center',
            );
        }
    }

    /**
     * Grade a consignment AFTER confirmation.
     *
     * Confirming without a grade is a legitimate choice — the lab may be busy and
     * the litre count should not wait for it. But confirm() is one-shot (NFR-4)
     * and used to be the only writer of grade_id, so "grade later" was a promise
     * the system could not keep: the consignment stayed ungraded forever and BR-9
     * kept it out of every batch. This is the "later".
     *
     * BR-13/BR-14 are preserved by anchoring the snapshot to the CONFIRMATION
     * ANCHOR, not to today: the farmer is owed the rate in force on the day
     * their milk was accepted, and grading it three days later must not move
     * that. The anchor is the server clock stamped by confirm(), not the
     * caller-supplied `confirmed_at` it used to be — otherwise every hole this
     * class closes at confirmation reopens one route later.
     */
    public function grade(Consignment $consignment, Grade $grade, User $actor): Consignment
    {
        if (! $consignment->isConfirmed()) {
            throw RuleViolationException::make(
                'BR-4',
                "{$consignment->reference} has not been confirmed yet. Assign the grade as part of confirming it.",
                ['consignment' => $consignment->reference],
                'grade_id',
            );
        }

        // Changing an assigned grade is a different act with a different owner —
        // it moves money after the fact, and is deliberately not offered here.
        if ($consignment->grade_id !== null) {
            throw RuleViolationException::make(
                'BR-4',
                sprintf(
                    '%s is already graded %s. Changing an assigned grade needs a supervisor.',
                    $consignment->reference,
                    $consignment->grade?->name ?? '',
                ),
                ['consignment' => $consignment->reference],
                'grade_id',
            );
        }

        $this->guardGrading($consignment, $grade, $actor);

        $anchoredAt = $consignment->rateAnchor();
        $rate = $grade->rateOn($anchoredAt);

        if ($rate === null) {
            throw RuleViolationException::make(
                'BR-13',
                "{$grade->name} has no rate effective on ".Wat::date($anchoredAt)
                    .' — the day this consignment was confirmed. Add an effective-dated rate in Settings first.',
                ['grade' => $grade->code],
                'grade_id',
            );
        }

        $consignment->fill([
            'grade_id' => $grade->getKey(),
            'grade_rate_id' => $rate->getKey(),
            'rate_per_litre_minor' => $rate->rate_per_litre_minor,
        ]);

        $consignment->saveWithLock();

        $this->audit->edited(
            $consignment,
            sprintf(
                '%s graded %s after confirmation — %s/L as of %s',
                $consignment->reference,
                $grade->name,
                Money::format($rate->rate_per_litre_minor),
                Wat::date($anchoredAt),
            ),
            'Milk Collection',
            ['grade' => null],
            [
                'grade' => $grade->code,
                'rate_per_litre_minor' => $rate->rate_per_litre_minor,
                'rate_effective_from' => $rate->effective_from?->toDateString(),
                'snapshot_anchored_to' => 'rate_anchored_at',
                'rules' => ['BR-4', 'BR-13', 'BR-14'],
            ],
            $actor,
        );

        return $consignment;
    }

    /**
     * Change a grade that has already been assigned — the re-grade control break.
     *
     * WHY THIS EXISTS. A clerk keeps `milk.grade.create`, because a grader who is
     * unavailable at 06:00 blocks the whole morning and that costs more than the
     * mis-grading it would prevent. The break is therefore not on grading but on
     * RE-grading: changing a grade already assigned moves money after the fact,
     * for milk that has already been accepted and may already have been paid for.
     *
     * So `milk.grade.edit` is held by the Quality Officer and the supervisor only,
     * a reason is mandatory, and every use lands on the exceptions list where
     * somebody reads it. Before this, the system said "changing an assigned grade
     * needs a supervisor" and then offered the supervisor no way to do it — a
     * refusal with no path behind it, which in practice means the correction is
     * made in a notebook instead.
     *
     * BR-13/BR-14 hold exactly as they do for a first grading: the new rate is the
     * one in force on CONFIRMED_AT, not today. A re-grade corrects what the milk
     * was; it does not re-price it at this week's rate.
     */
    public function regrade(Consignment $consignment, Grade $grade, string $reason, User $actor): Consignment
    {
        $this->access->authorize($actor, 'milk.grade.edit', $consignment,
            'Re-grade '.$consignment->reference);

        if ($consignment->grade_id === null) {
            throw RuleViolationException::make(
                'BR-4',
                "{$consignment->reference} has no grade yet — assign one rather than changing one.",
                ['consignment' => $consignment->reference],
                'grade_id',
            );
        }

        $previous = $consignment->grade;

        if ((int) $consignment->grade_id === (int) $grade->getKey()) {
            throw RuleViolationException::make(
                'BR-4',
                sprintf('%s is already graded %s.', $consignment->reference, $grade->name),
                ['consignment' => $consignment->reference],
                'grade_id',
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw RuleViolationException::make(
                'BR-4',
                'A re-grade needs a reason. It changes what a farmer is paid, and the reason is what the exceptions list is read for.',
                ['consignment' => $consignment->reference],
                'regrade_reason',
            );
        }

        // The rejection grades are system outcomes, not quality outcomes, and the
        // same guard that stops one being assigned must stop one being moved to.
        if ($grade->is_rejection) {
            throw RuleViolationException::make(
                'BR-4',
                "{$grade->name} is a system grade and cannot be assigned as a quality outcome. "
                    .'Record the rejected volume with a reason instead.',
                ['grade' => $grade->code],
                'grade_id',
            );
        }

        $anchoredAt = $consignment->rateAnchor();
        $rate = $grade->rateOn($anchoredAt);

        if ($rate === null) {
            throw RuleViolationException::make(
                'BR-13',
                "{$grade->name} has no rate effective on ".Wat::date($anchoredAt)
                    .' — the day this consignment was confirmed. Add an effective-dated rate in Settings first.',
                ['grade' => $grade->code],
                'grade_id',
            );
        }

        $before = [
            'grade' => $previous?->code,
            'rate_per_litre_minor' => $consignment->rate_per_litre_minor,
        ];

        $consignment->fill([
            'grade_id' => $grade->getKey(),
            'grade_rate_id' => $rate->getKey(),
            'rate_per_litre_minor' => $rate->rate_per_litre_minor,
            'regraded_at' => Wat::now(),
            'regraded_by_user_id' => $actor->getKey(),
            'regrade_reason' => $reason,
        ]);

        $consignment->saveWithLock();

        $this->audit->edited(
            $consignment,
            sprintf(
                '%s re-graded %s → %s by %s — %s',
                $consignment->reference,
                $previous?->name ?? 'ungraded',
                $grade->name,
                $actor->name,
                $reason,
            ),
            'Milk Collection',
            $before,
            [
                'grade' => $grade->code,
                'rate_per_litre_minor' => $rate->rate_per_litre_minor,
                'rate_effective_from' => $rate->effective_from?->toDateString(),
                'snapshot_anchored_to' => 'rate_anchored_at',
                'regrade_reason' => $reason,
                'rules' => ['BR-4', 'BR-13', 'BR-14'],
            ],
            $actor,
        );

        return $consignment;
    }

    /**
     * BR-4 — "Grade is assigned at consignment confirmation by a user holding
     * milk.grade.create, and only after all configured quality tests are
     * recorded."
     */
    private function guardGrading(Consignment $consignment, Grade $grade, User $actor): void
    {
        /*
         * ARCH-4 — both layers, not just the first. `hasPermission` alone asked
         * only "may this person grade anything?", so an officer scoped to one
         * centre passed it while grading a consignment at another. Access::
         * authorize asks the scoped question with the record in hand, and refuses
         * with the populated access-denied screen and the BR-34 audit entry.
         *
         * Checked here in the service rather than in the controller, so the API
         * cannot bypass what the screen enforces.
         */
        $this->access->authorize($actor, 'milk.grade.create', $consignment,
            'Grade '.$consignment->reference);

        if ($grade->is_rejection) {
            throw RuleViolationException::make(
                'BR-4',
                "{$grade->name} is a system grade and cannot be assigned as a quality outcome. "
                    .'Record the rejected volume with a reason instead.',
                ['grade' => $grade->code],
                'grade_id',
            );
        }

        $required = QualityTestDefinition::query()->required()->get();
        $recorded = $consignment->qualityTests()->pluck('quality_test_definition_id')->all();

        $missing = $required
            ->reject(fn (QualityTestDefinition $definition) => in_array($definition->getKey(), $recorded, true))
            ->map(fn (QualityTestDefinition $definition) => $definition->name)
            ->values();

        if ($missing->isNotEmpty()) {
            throw RuleViolationException::make(
                'BR-4',
                'Record every required quality test before assigning a grade. Missing: '
                    .$missing->implode(', ').'.',
                ['missing' => $missing->all()],
                'grade_id',
            );
        }
    }

    /** §8 — confirmed | adjusted | partly_rejected. */
    private function deriveStatus(string $rejected, string $adjustments): string
    {
        if (Volume::toCentilitres($rejected) > 0) {
            return Consignment::STATUS_PARTLY_REJECTED;
        }

        if (Volume::toCentilitres($adjustments) !== 0) {
            return Consignment::STATUS_ADJUSTED;
        }

        return Consignment::STATUS_CONFIRMED;
    }
}
