<?php

namespace App\Services\Workflow;

use App\Models\LeaveRequest;
use App\Models\PaymentRun;
use App\Models\TransportPaymentRun;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Services\Hr\LeaveService;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Finance\TransportPaymentRunService;
use App\Services\Hr\PayrollService;
use App\Services\Purchases\RequisitionService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-20 / BR-22 — mirror a workflow instance's outcome onto its subject.
 *
 * This lived in ApprovalsController, so the /api twins did not do it. Three API
 * approvals of a ₦200,000 requisition — the first reducing it to ₦150,000 —
 * left the instance `approved` at 15,000,000 kobo and the requisition still
 * `in_review` with `approved_total_minor` null, which is the figure Accounts
 * would pay against. An API REJECTION was worse: the requisition stayed
 * `in_review`, so `resubmit()` refused it (not `rejected`), `submit()` refused
 * it (neither `draft` nor `rejected`) and the instance was closed, so no later
 * action could sync it. BR-20's "returns the subject to the requester, who may
 * revise and resubmit" had no code path out at all.
 *
 * A step every caller must remember is a step that belongs below the callers.
 * The engine now performs it inside the same transaction as the state change,
 * and this class is the only thing that knows which service owns which subject —
 * which is what keeps App\Services\Workflow free of HR and Purchases imports.
 *
 * The services are resolved on demand rather than injected: RequisitionService,
 * LeaveService and PayrollService each depend on WorkflowEngine, and the engine
 * depends on this, so constructor injection would close the loop.
 */
class SubjectSynchroniser
{
    public function __construct(private readonly Container $container) {}

    /**
     * Subjects with no service of their own — a Batch under the reconciliation
     * workflow, a stock adjustment — carry their state on the instance and need
     * no mirror, so an unknown subject is a no-op rather than a failure.
     */
    public function sync(?Model $subject): void
    {
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

        // §14 Phase 7. Without this arm the engine approves the instance and the
        // run stays `processing` for ever: the money is cleared and nothing can
        // be paid out against it, with no error anywhere to say why.
        if ($subject instanceof TransportPaymentRun) {
            $this->container->make(TransportPaymentRunService::class)->syncFromWorkflow($subject);

            return;
        }

        if ($subject instanceof PaymentRun) {
            $this->container->make(FarmerPaymentRunService::class)->syncFromWorkflow($subject);
        }
    }
}
