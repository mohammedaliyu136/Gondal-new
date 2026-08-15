<?php

namespace App\Services\Workflow;

use App\Models\LeaveRequest;
use App\Models\PaymentRun;
use App\Models\TransportPaymentRun;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Contracts\WorkflowSubjectInterface;
use App\Models\WorkflowInstance;
use App\Services\Hr\LeaveService;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Finance\TransportPaymentRunService;
use App\Services\Hr\PayrollService;
use App\Services\Purchases\RequisitionService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

class SubjectSynchroniser
{
    public function __construct(private readonly Container $container) {}

    /**
     * Synchronize a subject after a workflow state change.
     */
    public function sync(?Model $subject, ?WorkflowInstance $instance = null): void
    {
        if ($subject === null) {
            return;
        }

        if ($subject instanceof WorkflowSubjectInterface) {
            $latestInstance = $instance ?? WorkflowInstance::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->latest('id')
                ->first();

            if ($latestInstance !== null) {
                if ($latestInstance->status === WorkflowInstance::STATUS_APPROVED) {
                    $subject->onWorkflowApproved($latestInstance);
                } elseif ($latestInstance->status === WorkflowInstance::STATUS_REJECTED) {
                    $rejectionReason = (string) ($latestInstance->actions()
                        ->where('action', 'reject')
                        ->latest('id')
                        ->value('comment') ?? 'Rejected');
                    $subject->onWorkflowRejected($latestInstance, $rejectionReason);
                }
            }
        }

        if ($subject instanceof Requisition) {
            $this->container->make(RequisitionService::class)->syncFromWorkflow($subject);

            return;
        }

        if ($subject instanceof LeaveRequest) {
            $this->container->make(LeaveService::class)->syncFromWorkflow($subject);

            return;
        }

        if ($subject instanceof PayrollRun) {
            $this->container->make(PayrollService::class)->syncFromWorkflow($subject);

            return;
        }

        if ($subject instanceof TransportPaymentRun) {
            $this->container->make(TransportPaymentRunService::class)->syncFromWorkflow($subject);

            return;
        }

        if ($subject instanceof PaymentRun) {
            $this->container->make(FarmerPaymentRunService::class)->syncFromWorkflow($subject);
        }
    }
}
