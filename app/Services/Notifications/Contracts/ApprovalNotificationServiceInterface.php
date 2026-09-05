<?php

namespace App\Services\Notifications\Contracts;

use App\Models\Requisition;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;

interface ApprovalNotificationServiceInterface
{
    /**
     * Notify approvers holding the active stage role that an item entered their queue.
     */
    public function notifyApprovalQueued(WorkflowInstance $instance, WorkflowStage $stage): int;

    /**
     * Notify the initiator that their requisition has been approved or rejected.
     */
    public function notifyRequisitionDecided(Requisition $requisition, string $decision, ?string $reason = null): int;

    /**
     * Notify approvers that an item in their queue is overdue.
     */
    public function notifyApprovalOverdue(WorkflowInstance $instance, WorkflowStage $stage): int;
}
