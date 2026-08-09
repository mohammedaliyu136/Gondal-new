<?php

namespace App\Services\Milk;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionPoint;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Sequences;
use App\Support\Settings;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — recording a farmer delivery at a collection point.
 *
 * BR-1  a rejection cites a reason from `rejection_reasons` enabled for the
 *       POINT stage. Free text is never accepted.
 * BR-2  rejected volume is excluded from payment and from transport fees, which
 *       follows from BR-6 keeping it out of litres_accepted.
 * BR-3  a delivery after the point's cut-off may only be recorded as rejected
 *       with the cut-off reason, or accepted under a LOGGED supervisor override
 *       by a holder of milk.deliveries.cutoff_override.
 * BR-6  litres_accepted = presented − rejected (also a database check, DM-1).
 * BR-5  the rejection may open a quality follow-up automatically.
 * BR-12 litres_payable = litres_accepted + Σ adjustments, written by
 *       AdjustmentService. Recording sets it to the accepted volume so the
 *       column is never null and never has to be coalesced by a reader.
 */
class DeliveryService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QualityFollowupService $followups,
        private readonly Access $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(CollectionPoint $point, Farmer $farmer, array $data, User $actor): Delivery
    {
        $presented = (string) $data['litres_presented'];
        $rejected = (string) ($data['litres_rejected'] ?? '0');
        $reasonId = $data['rejection_reason_id'] ?? null;
        /*
         * ARCH-9 — the operator types a WAT wall-clock time; the column stores the
         * instant. BR-3 needs the WAT view to compare against the point's cut-off,
         * so both are kept: $deliveredAtLocal for the rule, $deliveredAt for the row.
         */
        $deliveredAtLocal = Wat::of($data['delivered_at'] ?? null) ?? Wat::local();
        $deliveredAt = Wat::instant($deliveredAtLocal);

        $this->guardDeliveredAt($deliveredAtLocal);
        $this->guardVolumes($presented, $rejected);

        $reason = $reasonId === null ? null : RejectionReason::query()->find($reasonId);

        $this->guardRejection($rejected, $reason);

        // BR-3 — the cut-off check needs the reason and the override together.
        $cutoff = $point->effectiveCutoff();
        $isLate = $this->isAfterCutoff($deliveredAtLocal, $cutoff);
        $override = (bool) ($data['cutoff_override'] ?? false);

        if ($isLate) {
            $this->guardCutoff($presented, $rejected, $reason, $override, $data, $cutoff, $point, $actor);
        }

        $accepted = Volume::subtract($presented, $rejected);
        $status = Delivery::deriveStatus($presented, $rejected);

        $delivery = DB::transaction(function () use (
            $point, $farmer, $actor, $deliveredAt, $presented, $rejected, $accepted,
            $reason, $status, $isLate, $cutoff, $override, $data
        ): Delivery {
            return Delivery::query()->create([
                'reference' => Sequences::next('deliveries'),
                'collection_point_id' => $point->getKey(),
                'farmer_id' => $farmer->getKey(),
                'recorded_by_user_id' => $actor->getKey(),
                'delivered_at' => $deliveredAt,
                'litres_presented' => $presented,
                'litres_rejected' => $rejected,
                // BR-6 / DM-1 — stored, not computed on read.
                'litres_accepted' => $accepted,
                /*
                 * BR-12 — the farmer's payable volume starts life equal to the
                 * accepted volume and only an adjustment moves it. Seeded here
                 * rather than left to the column default so that a payment run
                 * reading `litres_payable` never has to know that a delivery
                 * with no adjustments is a special case.
                 */
                'litres_adjusted' => '0.00',
                'litres_payable' => $accepted,
                'rejection_reason_id' => $reason?->getKey(),
                'containers' => $data['containers'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $status,
                'was_after_cutoff' => $isLate,
                'cutoff_applied' => $cutoff,
                // BR-3 — the override is attributed and audited, never implicit.
                'cutoff_override_by_user_id' => $isLate && $override ? $actor->getKey() : null,
                'cutoff_override_reason' => $isLate && $override
                    ? (string) ($data['cutoff_override_reason'] ?? '')
                    : null,
            ]);
        });

        $this->audit->created(
            $delivery,
            sprintf(
                '%s recorded for %s at %s — %s presented, %s rejected, %s accepted',
                $delivery->reference,
                $farmer->name,
                $point->name,
                Volume::format($presented),
                Volume::format($rejected),
                Volume::format($accepted),
            ),
            'Milk Collection',
            [
                'rejection_reason' => $reason?->name,
                'after_cutoff' => $isLate,
                'cutoff_applied' => $cutoff,
                'cutoff_override_reason' => $delivery->cutoff_override_reason,
                'rules' => ['BR-1', 'BR-3', 'BR-6'],
            ],
            $actor,
        );

        // BR-5 — a rejection may trip a threshold and open a follow-up.
        if ($reason !== null && Volume::toCentilitres($rejected) > 0) {
            $this->followups->evaluateForDelivery($delivery, $reason);
        }

        return $delivery;
    }

    /**
     * BR-12 — an adjustment to a recorded delivery goes through
     * AdjustmentService; editing the figures directly is not offered. What can be
     * edited is the descriptive detail.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(Delivery $delivery, array $data, User $actor): Delivery
    {
        $before = $delivery->only(['containers', 'notes']);

        $delivery->fill([
            'containers' => $data['containers'] ?? $delivery->containers,
            'notes' => $data['notes'] ?? $delivery->notes,
        ])->save();

        $this->audit->edited(
            $delivery,
            $delivery->reference.' details updated',
            'Milk Collection',
            $before,
            $delivery->only(['containers', 'notes']),
            $actor,
        );

        return $delivery;
    }

    /* ------------------------------------------------------------------ */

    /**
     * BR-3 — the cut-off comparison is only meaningful against a real, recent
     * instant.
     *
     * `delivered_at` arrived validated as nothing more than a date, so it could
     * be set to next month (isAfterCutoff then judges a morning nobody has lived
     * through) or back into a day that has already been dispatched, reconciled
     * and reported — where the litres land in totals that were signed off days
     * ago and in a payment period that may already be closed.
     *
     * §18.7 / §9 — how far back is a knob an administrator turns, not a constant.
     * 0 switches the backstop off for an installation that genuinely enters milk
     * from paper weeks later.
     *
     * Both bounds are judged on the WAT calendar DAY rather than on the instant.
     * The morning queue starts at 05:30 and an agent keying it in at 05:29 by a
     * phone whose clock drifts is recording today's milk, not tomorrow's; failing
     * that on a few seconds of skew would send them back to the notebook. What is
     * being refused is a delivery dated to a day that has not happened, or to one
     * whose totals were reported and reconciled while the agent was elsewhere.
     */
    private function guardDeliveredAt(Carbon $deliveredAtLocal): void
    {
        $now = Wat::local();
        $today = $now->copy()->startOfDay();

        if ($deliveredAtLocal->copy()->startOfDay()->greaterThan($today)) {
            throw RuleViolationException::make(
                'BR-3',
                'A delivery cannot be dated on a future day.',
                ['delivered_at' => Wat::dateTime($deliveredAtLocal), 'today' => Wat::date($today)],
                'delivered_at',
            );
        }

        $limitDays = Settings::integer('milk.delivery_backdate_limit_days', 0);

        if ($limitDays <= 0) {
            return;
        }

        $earliest = $today->copy()->subDays($limitDays);

        if ($deliveredAtLocal->lessThan($earliest)) {
            throw RuleViolationException::make(
                'BR-3',
                sprintf(
                    'A delivery cannot be dated more than %d days back — that day has already been dispatched and reported. Ask a supervisor to record the correction as an adjustment.',
                    $limitDays,
                ),
                [
                    'delivered_at' => Wat::dateTime($deliveredAtLocal),
                    'earliest' => Wat::date($earliest),
                    'limit_days' => $limitDays,
                ],
                'delivered_at',
            );
        }
    }

    private function guardVolumes(string $presented, string $rejected): void
    {
        if (Volume::toCentilitres($presented) <= 0) {
            throw RuleViolationException::make(
                'BR-6',
                'Presented volume must be greater than zero.',
                [],
                'litres_presented',
            );
        }

        if (Volume::toCentilitres($rejected) < 0) {
            throw RuleViolationException::make(
                'BR-6',
                'Rejected volume cannot be negative.',
                [],
                'litres_rejected',
            );
        }

        if (Volume::compare($rejected, $presented) === 1) {
            throw RuleViolationException::make(
                'BR-6',
                'Rejected volume cannot exceed the volume presented.',
                ['presented' => $presented, 'rejected' => $rejected],
                'litres_rejected',
            );
        }
    }

    /** BR-1 */
    private function guardRejection(string $rejected, ?RejectionReason $reason): void
    {
        $hasRejection = Volume::toCentilitres($rejected) > 0;

        if ($hasRejection && $reason === null) {
            throw RuleViolationException::make(
                'BR-1',
                'Rejected volume needs a reason from the configured list.',
                [],
                'rejection_reason_id',
            );
        }

        if ($reason === null) {
            return;
        }

        /*
         * BR-1, the other way round: a reason with nothing rejected is not a
         * rejection. The row was accepted, stored with `status = accepted`, and
         * then counted by BR-5's threshold — QualityFollowupService counts on
         * `rejection_reason_id` alone. A clerk who picked "adulteration" and then
         * corrected the volume to zero silently pre-loaded the counter, so the
         * farmer's FIRST genuine spoilage landed at count 3 and sent the
         * extension team to their compound over a data-entry artefact.
         */
        if (! $hasRejection) {
            throw RuleViolationException::make(
                'BR-1',
                sprintf(
                    'Recording "%s" with nothing rejected is not a rejection. Enter the rejected litres, or clear the reason.',
                    $reason->name,
                ),
                ['reason' => $reason->code],
                'litres_rejected',
            );
        }

        if ($reason->status !== 'active') {
            throw RuleViolationException::make(
                'BR-1',
                "The reason '{$reason->name}' is no longer available.",
                ['reason' => $reason->code],
                'rejection_reason_id',
            );
        }

        if (! $reason->isAvailableAt(RejectionReason::STAGE_POINT)) {
            throw RuleViolationException::make(
                'BR-1',
                "The reason '{$reason->name}' is not enabled for collection points.",
                ['reason' => $reason->code, 'stage' => 'point'],
                'rejection_reason_id',
            );
        }
    }

    /**
     * BR-3 — "A delivery arriving after its point's cutoff_time may only be
     * recorded as rejected with reason `late`, or accepted with an explicit
     * supervisor override that is logged."
     *
     * The "late" reason is identified by the administrator's `is_cutoff_breach`
     * flag, never by matching a code (§18.7).
     *
     * @param  array<string, mixed>  $data
     */
    private function guardCutoff(
        string $presented,
        string $rejected,
        ?RejectionReason $reason,
        bool $override,
        array $data,
        string $cutoff,
        CollectionPoint $point,
        User $actor,
    ): void {
        $fullyRejectedForCutoff = $reason !== null
            && $reason->is_cutoff_breach
            && Volume::compare($rejected, $presented) === 0;

        if ($fullyRejectedForCutoff) {
            return;
        }

        if (! $override) {
            $cutoffReason = RejectionReason::cutoffBreach();

            throw RuleViolationException::make(
                'BR-3',
                sprintf(
                    'This delivery is after the %s cut-off. Reject it in full for "%s", or record a supervisor override with a reason.',
                    $cutoff,
                    $cutoffReason?->name ?? 'failure to meet delivery time',
                ),
                ['cutoff' => $cutoff],
                'delivered_at',
            );
        }

        if (trim((string) ($data['cutoff_override_reason'] ?? '')) === '') {
            throw RuleViolationException::make(
                'BR-3',
                'A supervisor override needs a written reason — it is logged.',
                ['cutoff' => $cutoff],
                'cutoff_override_reason',
            );
        }

        /*
         * BR-3 says SUPERVISOR override. Until this line the rule checked that
         * somebody typed a sentence, then attributed the override to whoever was
         * recording — so the agent holding the late milk authorised their own
         * acceptance of it.
         *
         * ARCH-4, both layers, with the point in hand: a supervisor for one point
         * is not a supervisor for every point. Checked in the SERVICE rather than
         * in the controller so the REST API and the offline sync cannot bypass
         * what the screen enforces — the same reason guardGrading() checks BR-4's
         * permission here (ConsignmentService::guardGrading).
         *
         * PERM-1 — the permission is a row, arriving by migration
         * (2026_01_03_000200_hold_br3s_cutoff_override_behind_its_own_permission).
         * The permanent home for the GRANT is RoleSeeder's catalogue, which
         * rewrites permission_role on every seed; until it carries
         * `'milk.deliveries' => [... 'cutoff_override']` for Milk Collection
         * Supervisor and Milk Collection Officer, a freshly seeded database has
         * the permission and nobody holding it, and every override is refused.
         */
        $this->access->authorize(
            $actor,
            'milk.deliveries.cutoff_override',
            $point,
            'Override the '.$cutoff.' cut-off at '.$point->name,
        );
    }

    private function isAfterCutoff(Carbon $deliveredAt, string $cutoff): bool
    {
        return $deliveredAt->format('H:i') > $cutoff;
    }
}
