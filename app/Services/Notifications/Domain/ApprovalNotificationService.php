<?php

namespace App\Services\Notifications\Domain;

use App\Models\Requisition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Notifications\Contracts\ApprovalNotificationServiceInterface;
use App\Services\Notifications\Contracts\NotificationServiceInterface;

class ApprovalNotificationService implements ApprovalNotificationServiceInterface
{
    public function __construct(private readonly NotificationServiceInterface $notifications) {}

    public function notifyApprovalQueued(WorkflowInstance $instance, WorkflowStage $stage): int
    {
        $roleId = (int) $stage->approving_role_id;
        $recipients = $this->notifications->usersHoldingRole($roleId);

        $subjectName = $instance->subject?->reference ?? "Instance #{$instance->id}";
        $title = "Action Required: Approval needed for {$subjectName}";
        $body = "{$subjectName} has entered the '{$stage->name}' approval stage and requires your review.";
        $actionUrl = route('approvals.index');

        return $this->notifications->send(
            eventCode: 'approval.queued',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $instance->subject,
        );
    }

    public function notifyRequisitionDecided(Requisition $requisition, string $decision, ?string $reason = null): int
    {
        $creator = $requisition->createdBy;

        if (! $creator) {
            return 0;
        }

        $decisionUpper = strtoupper($decision);
        $title = "Requisition {$requisition->reference} was {$decisionUpper}";
        $body = "Your requisition {$requisition->reference} for '{$requisition->title}' has been {$decision}."
            . ($reason ? " Reason / notes: {$reason}" : '');
        $actionUrl = route('requisitions.show', $requisition);

        return $this->notifications->send(
            eventCode: 'requisition.decided',
            recipients: [$creator],
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $requisition,
        );
    }

    public function notifyApprovalOverdue(WorkflowInstance $instance, WorkflowStage $stage): int
    {
        $roleId = (int) $stage->approving_role_id;
        $recipients = $this->notifications->usersHoldingRole($roleId);

        $subjectName = $instance->subject?->reference ?? "Instance #{$instance->id}";
        $title = "Overdue Approval: {$subjectName}";
        $body = "Item {$subjectName} is overdue in your approval queue for stage '{$stage->name}'. Please take immediate action.";
        $actionUrl = route('approvals.index');

        return $this->notifications->send(
            eventCode: 'approval.overdue',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $instance->subject,
        );
    }
}
