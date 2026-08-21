<?php

namespace App\Services\Milk;

use App\Exceptions\RuleViolationException;
use App\Models\Batch;
use App\Models\CollectionCenter;
use App\Models\Consignment;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — dispatching a batch to the factory and reconciling it there.
 *
 * NG-5 — scope ends at factory intake reconciliation. Nothing here models
 *   processing or production.
 *
 * BR-9  litres_dispatched = Σ litres_confirmed; only confirmed AND graded
 *       consignments may join
 * BR-10 discrepancy_litres = received − dispatched (negative for a shortfall)
 * BR-11 beyond the configured tolerance, supervisor_notes is required BEFORE
 *       release, and the write is rejected otherwise
 * NFR-4 optimistic locking on reconciliation and release
 */
class BatchService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly \App\Services\Finance\FarmerWalletService $walletService,
    ) {}

    /**
     * @param  array<int, int>  $consignmentIds
     * @param  array<string, mixed>  $data
     */
    public function dispatch(CollectionCenter $center, array $consignmentIds, array $data, User $actor): Batch
    {
        /** @var Collection<int, Consignment> $consignments */
        $consignments = Consignment::query()
            ->whereIn('id', $consignmentIds)
            ->where('collection_center_id', $center->getKey())
            ->get();

        if ($consignments->isEmpty()) {
            throw RuleViolationException::make(
                'BR-9',
                'Select at least one confirmed consignment to batch.',
                [],
                'consignment_ids',
            );
        }

        // BR-9 — "Only confirmed and graded consignments may join a batch."
        foreach ($consignments as $consignment) {
            if (! $consignment->isBatchable()) {
                throw RuleViolationException::make(
                    'BR-9',
                    sprintf(
                        '%s cannot join a batch: %s.',
                        $consignment->reference,
                        match (true) {
                            $consignment->batch_id !== null => 'it is already on batch '.($consignment->batch?->reference ?? '—'),
                            ! $consignment->isConfirmed() => 'it has not been confirmed',
                            $consignment->grade_id === null => 'it has no grade assigned',
                            default => 'its status is '.$consignment->status,
                        },
                    ),
                    ['consignment' => $consignment->reference, 'status' => $consignment->status],
                );
            }
        }

        $litres = Volume::sum($consignments->pluck('litres_confirmed')->all());

        /*
         * BR-9 / BR-11 — a zero-litre batch is a hole in the tolerance rule,
         * not a harmless edge. A consignment rejected in full at the centre is
         * confirmed, graded and `partly_rejected`, so it satisfies
         * scopeBatchable and dispatches at 0 L. BR-11 then divides by that
         * zero: Volume::exceedsPercentage() answers false and
         * discrepancyPercentage() answers null, so whatever the factory
         * receives reconciles inside tolerance and releases with no cause and
         * no supervisor note — precisely the case BR-11 exists to catch.
         *
         * ConsignmentService::dispatch() already refuses the same thing one
         * level down under BR-7; this is that guard, at the level where the
         * tolerance is applied.
         */
        if (Volume::toCentilitres($litres) <= 0) {
            throw RuleViolationException::make(
                'BR-9',
                'The selected consignments have no confirmed volume to send to the factory.',
                ['consignments' => $consignments->pluck('reference')->all()],
                'consignment_ids',
            );
        }

        $batch = DB::transaction(function () use ($center, $consignments, $litres, $data, $actor): Batch {
            /*
             * BR-9 / DM-2 — the batchable guard above ran on a snapshot read
             * outside this transaction and the write carried no predicate, so
             * two concurrent dispatches of the same consignments both passed
             * and the second overwrote batch_id: two batches each claiming the
             * same litres, the first orphaned with volume and no consignments,
             * and its phantom volume landing in BR-10's discrepancy at the
             * factory. Claim the rows under a lock, write only the ones still
             * unbatched, and roll the whole thing back if the count moved.
             */
            $claimed = Consignment::query()
                ->whereIn('id', $consignments->pluck('id'))
                ->whereNull('batch_id')
                ->lockForUpdate()
                ->pluck('id');

            if ($claimed->count() !== $consignments->count()) {
                throw $this->raceLost($consignments->count() - $claimed->count(), $consignments->count());
            }

            $batch = Batch::query()->create([
                'reference' => Sequences::next('batches'),
                'collection_center_id' => $center->getKey(),
                'dispatched_by_user_id' => $actor->getKey(),
                'dispatched_at' => Wat::instant($data['dispatched_at'] ?? null) ?? Wat::now(),
                'litres_dispatched' => $litres,
                'containers' => $data['containers'] ?? null,
                'trip_id' => $data['trip_id'] ?? null,
                'status' => Batch::STATUS_IN_TRANSIT,
            ]);

            $assigned = Consignment::query()
                ->whereIn('id', $claimed)
                ->whereNull('batch_id')
                ->update(['batch_id' => $batch->getKey()]);

            if ($assigned !== $claimed->count()) {
                throw $this->raceLost($claimed->count() - $assigned, $consignments->count());
            }

            return $batch;
        });

        $this->audit->created(
            $batch,
            sprintf(
                '%s dispatched from %s to the factory — %s across %d consignments',
                $batch->reference,
                $center->name,
                Volume::format($litres),
                $consignments->count(),
            ),
            'Milk Collection',
            ['rule' => 'BR-9', 'consignments' => $consignments->pluck('reference')->all()],
            $actor,
        );

        return $batch;
    }

    /**
     * DM-2 — another batch took these consignments while this request was in
     * flight. Raised inside the transaction so nothing survives it.
     */
    private function raceLost(int $taken, int $selected): RuleViolationException
    {
        return RuleViolationException::make(
            'DM-2',
            sprintf(
                '%d of the %d selected %s already on another batch. Nothing was dispatched — reload the list and try again.',
                $taken,
                $selected,
                $taken === 1 ? 'consignments is' : 'consignments are',
            ),
            ['taken' => $taken, 'selected' => $selected],
            'consignment_ids',
        );
    }

    /**
     * Factory intake. BR-10 computes the discrepancy; BR-11 decides whether a
     * note becomes mandatory.
     *
     * @param  array<string, mixed>  $data
     */
    public function reconcile(Batch $batch, array $data, User $actor): Batch
    {
        // NFR-4
        if ($batch->reconciled_at !== null) {
            throw RuleViolationException::make(
                'NFR-4',
                "{$batch->reference} was already reconciled by "
                    .($batch->reconciledBy?->name ?? 'someone else')
                    .' at '.Wat::dateTime($batch->reconciled_at).'.',
                ['batch' => $batch->reference],
            );
        }

        $received = (string) $data['litres_received'];

        if (Volume::isNegative($received)) {
            throw RuleViolationException::make(
                'BR-10',
                'Received volume cannot be negative.',
                [],
                'litres_received',
            );
        }

        $rejectedAtFactory = (string) ($data['litres_rejected_at_factory'] ?? '0');
        $reason = ($data['rejection_reason_id'] ?? null) === null
            ? null
            : RejectionReason::query()->find($data['rejection_reason_id']);

        if (Volume::toCentilitres($rejectedAtFactory) > 0) {
            if ($reason === null) {
                throw RuleViolationException::make(
                    'BR-1',
                    'Volume rejected at the factory needs a reason from the configured list.',
                    [],
                    'rejection_reason_id',
                );
            }

            if (! $reason->isAvailableAt(RejectionReason::STAGE_FACTORY)) {
                throw RuleViolationException::make(
                    'BR-1',
                    "The reason '{$reason->name}' is not enabled at the factory.",
                    ['reason' => $reason->code, 'stage' => 'factory'],
                    'rejection_reason_id',
                );
            }

            if (Volume::compare($rejectedAtFactory, $received) === 1) {
                throw RuleViolationException::make(
                    'BR-10',
                    'Rejected volume cannot exceed the volume received.',
                    [],
                    'litres_rejected_at_factory',
                );
            }
        }

        // BR-10 — signed, negative for a shortfall.
        $discrepancy = Volume::subtract($received, (string) $batch->litres_dispatched);

        $batch->fill([
            'reconciled_by_user_id' => $actor->getKey(),
            'reconciled_at' => Wat::instant($data['reconciled_at'] ?? null) ?? Wat::now(),
            'litres_received' => $received,
            'containers_received' => $data['containers_received'] ?? null,
            'discrepancy_litres' => $discrepancy,
            'discrepancy_cause_id' => $data['discrepancy_cause_id'] ?? null,
            'litres_rejected_at_factory' => $rejectedAtFactory,
            'rejection_reason_id' => $reason?->getKey(),
            'supervisor_notes' => $data['supervisor_notes'] ?? null,
        ]);

        $exceedsTolerance = $batch->exceedsTolerance();
        $batch->status = $exceedsTolerance ? Batch::STATUS_DISCREPANCY : Batch::STATUS_RECONCILED;

        // A variance beyond tolerance also needs its cause named, so the
        // reconciliation screen's figures can be explained later.
        if ($exceedsTolerance && $batch->discrepancy_cause_id === null) {
            throw RuleViolationException::make(
                'BR-11',
                sprintf(
                    'The variance is %s%% against a tolerance of %s%%. Select the cause of the discrepancy.',
                    // Null when nothing was dispatched to divide by — a ratio
                    // that does not exist, on a batch that still needs a cause.
                    $batch->discrepancyPercentage() ?? 'unmeasurable',
                    $batch->tolerancePercentage(),
                ),
                ['discrepancy_pct' => $batch->discrepancyPercentage()],
                'discrepancy_cause_id',
            );
        }

        $batch->saveWithLock();

        // Credit farmer wallets for their deliveries in this reconciled batch
        $walletStats = $this->walletService->creditForReconciledBatch($batch, $actor);

        $this->audit->edited(
            $batch,
            sprintf(
                '%s reconciled at the factory — %s received against %s dispatched (%s L variance, %s%%)',
                $batch->reference,
                Volume::format($received),
                Volume::format($batch->litres_dispatched),
                $discrepancy,
                $batch->discrepancyPercentage() ?? '0.00',
            ),
            'Milk Collection',
            ['status' => Batch::STATUS_IN_TRANSIT],
            [
                'status' => $batch->status,
                'litres_received' => $received,
                'discrepancy_litres' => $discrepancy,
                'discrepancy_pct' => $batch->discrepancyPercentage(),
                'tolerance_pct' => $batch->tolerancePercentage(),
                'exceeds_tolerance' => $exceedsTolerance,
                'rules' => ['BR-10', 'BR-11'],
            ],
            $actor,
        );

        if ($exceedsTolerance) {
            // NOTIF-3 — "batch discrepancy".
            $this->notifications->send(
                eventCode: 'batch.discrepancy',
                recipients: $this->notifications->usersWithPermission('milk.reconciliation.approve', $batch),
                title: $batch->reference.' variance beyond tolerance',
                body: sprintf(
                    '%s L variance (%s%%) against a %s%% tolerance at %s.',
                    $discrepancy,
                    $batch->discrepancyPercentage(),
                    $batch->tolerancePercentage(),
                    $batch->collectionCenter?->name ?? 'the center',
                ),
                actionUrl: route('reconciliation.index'),
                subject: $batch,
            );
        }

        return $batch;
    }

    /**
     * §8 — reconciled|discrepancy → released.
     *
     * BR-11 — "If |discrepancy| / litres_dispatched exceeds the configured
     * tolerance (default 1%), the supervisor must supply supervisor_notes before
     * the batch can be released. Reject the write otherwise."
     */
    public function release(Batch $batch, ?string $supervisorNotes, User $actor): Batch
    {
        if (! $batch->isReleasable()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf(
                    'A batch can only be released from `reconciled` or `discrepancy`. %s is `%s`.',
                    $batch->reference,
                    $batch->status,
                ),
                ['batch' => $batch->reference, 'status' => $batch->status],
            );
        }

        $notes = trim((string) ($supervisorNotes ?? $batch->supervisor_notes ?? ''));

        if ($batch->exceedsTolerance() && $notes === '') {
            throw RuleViolationException::make(
                'BR-11',
                sprintf(
                    'The variance on %s is %s%%, beyond the %s%% tolerance. Record a supervisor note before releasing it.',
                    $batch->reference,
                    $batch->discrepancyPercentage() ?? 'unmeasurable',
                    $batch->tolerancePercentage(),
                ),
                [
                    'discrepancy_pct' => $batch->discrepancyPercentage(),
                    'tolerance_pct' => $batch->tolerancePercentage(),
                ],
                'supervisor_notes',
            );
        }

        $previous = $batch->status;

        $batch->fill([
            'supervisor_notes' => $notes === '' ? null : $notes,
            'released_at' => Wat::now(),
            'released_by_user_id' => $actor->getKey(),
            'status' => Batch::STATUS_RELEASED,
        ]);

        $batch->saveWithLock();

        $this->audit->edited(
            $batch,
            $batch->reference.' released to production and payment',
            'Milk Collection',
            ['status' => $previous],
            [
                'status' => Batch::STATUS_RELEASED,
                'supervisor_notes' => $notes === '' ? null : $notes,
                'rule' => 'BR-11',
            ],
            $actor,
        );

        return $batch;
    }
}
