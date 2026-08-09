<?php

namespace App\Services\Milk;

use App\Exceptions\RuleViolationException;
use App\Models\ActivityType;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\QualityFollowup;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-5 — "When a farmer accumulates followup_threshold rejections of the same
 * reason within followup_window_days, the system opens a quality_followup
 * AUTOMATICALLY and notifies the extension team. Defaults: adulteration 3-in-30,
 * spoilage 3-in-30, late 2-in-30."
 *
 * The threshold and window come from the reason row, so retuning them is a
 * Settings change. The values in force are COPIED onto the follow-up so a later
 * retune never makes an existing follow-up look wrong.
 *
 * Phase 5 acceptance — "closing it requires a logged field activity", which is
 * why close() takes a FieldActivity and refuses without one.
 */
class QualityFollowupService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Called after a delivery is recorded with a rejection. Returns the
     * follow-up if this rejection opened one.
     */
    public function evaluateForDelivery(Delivery $delivery, RejectionReason $reason): ?QualityFollowup
    {
        if (! $reason->opensFollowups()) {
            return null;
        }

        $farmer = $delivery->farmer;

        if ($farmer === null) {
            return null;
        }

        // One open follow-up per farmer per reason — a fourth rejection escalates
        // the existing follow-up rather than opening a duplicate.
        $existing = QualityFollowup::query()
            ->where('subject_type', $farmer->getMorphClass())
            ->where('subject_id', $farmer->getKey())
            ->where('rejection_reason_id', $reason->getKey())
            ->open()
            ->first();

        $count = $this->countRecentRejections($farmer, $reason);

        if ($existing !== null) {
            $existing->forceFill(['trigger_count' => $count])->save();

            return $existing;
        }

        if ($count < (int) $reason->followup_threshold) {
            return null;
        }

        $followup = QualityFollowup::query()->create([
            'subject_type' => $farmer->getMorphClass(),
            'subject_id' => $farmer->getKey(),
            'rejection_reason_id' => $reason->getKey(),
            'trigger_count' => $count,
            // Copied, so a later retune cannot rewrite this follow-up's story.
            'threshold' => (int) $reason->followup_threshold,
            'window_days' => (int) $reason->followup_window_days,
            'opened_at' => Wat::now(),
            'status' => QualityFollowup::STATUS_OPEN,
        ]);

        $this->audit->created(
            $followup,
            sprintf(
                'Quality follow-up opened automatically for %s — %d %s rejections in %d days',
                $farmer->name,
                $count,
                $reason->name,
                (int) $reason->followup_window_days,
            ),
            'Community Engagement',
            ['rule' => 'BR-5', 'farmer' => $farmer->code, 'reason' => $reason->code],
        );

        // BR-5 — "and notifies the extension team". NOTIF-2 filters recipients
        // to those who could actually open the follow-up.
        $this->notifications->send(
            eventCode: 'quality.followup_opened',
            recipients: $this->notifications->usersWithPermission('community.extension.view'),
            title: 'Quality follow-up opened: '.$farmer->name,
            body: sprintf(
                '%d %s rejections in %d days at %s.',
                $count,
                $reason->name,
                (int) $reason->followup_window_days,
                $delivery->collectionPoint?->name ?? 'a collection point',
            ),
            actionUrl: route('field-activities.index'),
            subject: $followup,
        );

        return $followup;
    }

    /**
     * BR-5 — the count within the reason's own window.
     *
     * `litres_rejected > 0` is not belt-and-braces for the BR-1 guard that now
     * refuses a reason with nothing rejected: rows written before that guard
     * existed are still in the table, and a farmer whose history carries two of
     * them would have their first real rejection open a follow-up at count 3. A
     * visit to a compound is a real cost to the farmer and to the extension
     * team's credibility, so the threshold counts rejections, not reason codes.
     */
    public function countRecentRejections(Farmer $farmer, RejectionReason $reason): int
    {
        $since = Wat::now()->subDays((int) $reason->followup_window_days);

        return Delivery::withoutDataScope()
            ->where('farmer_id', $farmer->getKey())
            ->where('rejection_reason_id', $reason->getKey())
            ->where('litres_rejected', '>', 0)
            ->where('delivered_at', '>=', $since)
            ->count();
    }

    /**
     * Phase 5 acceptance — "closing it requires a logged field activity."
     *
     * @throws RuleViolationException
     */
    public function close(QualityFollowup $followup, FieldActivity $activity, User $actor): QualityFollowup
    {
        if (! $followup->isOpen()) {
            throw RuleViolationException::make(
                'ST-1',
                'That follow-up is already closed.',
                ['followup' => $followup->getKey(), 'status' => $followup->status],
            );
        }

        /** @var ActivityType|null $type */
        $type = $activity->activityType;

        if ($type === null || ! $type->closes_quality_followup) {
            throw RuleViolationException::make(
                'BR-5',
                sprintf(
                    'A %s activity cannot close a quality follow-up. Log a visit or a training session instead.',
                    $type?->name ?? 'that kind of',
                ),
                ['activity_type' => $type?->code],
                'activity_type_id',
            );
        }

        $followup->forceFill([
            'closed_by_activity_id' => $activity->getKey(),
            'closed_by_user_id' => $actor->getKey(),
            'closed_at' => Wat::now(),
            'status' => QualityFollowup::STATUS_CLOSED,
        ])->save();

        $this->audit->edited(
            $followup,
            'Quality follow-up closed by '.$activity->reference,
            'Community Engagement',
            ['status' => QualityFollowup::STATUS_OPEN],
            ['status' => QualityFollowup::STATUS_CLOSED, 'activity' => $activity->reference],
            $actor,
        );

        return $followup;
    }

    /**
     * The open follow-ups against a farmer or a collection point, for the detail
     * screens.
     *
     * @return Collection<int, QualityFollowup>
     */
    public function openFor(Model $subject)
    {
        return QualityFollowup::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->open()
            ->with('rejectionReason')
            ->get();
    }
}
