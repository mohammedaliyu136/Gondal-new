<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\PaymentRun;
use App\Models\TransportPaymentRun;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Models\WorkflowInstance;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollService;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Finance\TransportPaymentRunService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * my-approvals.html.
 *
 * BR-23 — the queue is resolved from the stage's ROLE plus scope.
 * BR-18 — the queue excludes your own submissions, and acting on one is a 403.
 * BR-24 — a delegation puts the delegator's items in the delegate's queue.
 */
class ApprovalsController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RequisitionService $requisitions,
        private readonly LeaveService $leave,
        private readonly PayrollService $payroll,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->currentUser();

        $queue = $this->engine->queueFor($user)
            ->orderByRaw('case when current_stage_due_at is null then 1 else 0 end')
            ->orderBy('current_stage_due_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('approvals.index', [
            'queue' => $queue,
            'overdueCount' => $this->engine->queueFor($user)
                ->whereNotNull('current_stage_due_at')
                ->where('current_stage_due_at', '<', now())
                ->count(),
            'delegations' => $user->delegationsReceived()->active()->with(['fromUser', 'role'])->get(),
        ]);
    }

    public function show(Request $request, WorkflowInstance $instance): View
    {
        $user = $this->currentUser();

        $instance->loadMissing([
            'workflow.stages.approvingRole',
            'workflow.bands',
            'currentStage.approvingRole',
            'requester',
            'actions.actor',
            'actions.onBehalfOf',
            'actions.stage',
            'band',
            'subject',
        ]);

        $subject = $instance->subject;

        if ($subject instanceof Requisition) {
            $subject->loadMissing(['items', 'department', 'requester', 'serviceProvider', 'attachments', 'revises']);
        } elseif ($subject instanceof LeaveRequest) {
            $subject->loadMissing(['employee.department', 'leaveType', 'attachments']);
        } elseif ($subject instanceof PayrollRun) {
            $subject->loadMissing(['payslips.employee.department', 'runBy']);
        } elseif ($subject instanceof PaymentRun) {
            $subject->loadMissing(['payments.farmer', 'runBy']);
        } elseif ($subject instanceof TransportPaymentRun) {
            $subject->loadMissing(['payments.driver', 'runBy']);
        } elseif ($subject instanceof \App\Models\Batch) {
            $subject->loadMissing(['collectionCenter', 'consignments.grade', 'discrepancyCause', 'rejectionReason']);
        }

        $canAct = $this->engine->canAct($instance, $user);
        $stage = $instance->currentStage;
        $stageActionHtml = null;

        if ($canAct && $stage?->hasStageAction()) {
            $stageActionHtml = $stage->stageActionHandler()?->renderForm($instance, $stage);
        }

        return view('approvals.show', [
            'instance' => $instance,
            'subject' => $subject,
            'canAct' => $canAct,
            'stage' => $stage,
            'stageActionHtml' => $stageActionHtml,
            'applicableStages' => $instance->applicableStages(),
        ]);
    }

    public function approve(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $validated = $request->validate([
            // BR-22 — an approver may reduce the amount, never raise it.
            'approved_amount' => ['nullable', 'string'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $stage = $instance->currentStage;
        $actionHandler = $stage?->stageActionHandler();
        $actionPayload = null;

        if ($actionHandler !== null) {
            $actionPayload = $actionHandler->validate($request, $instance, $stage);
            $actionHandler->execute($instance, $stage, $this->currentUser(), $actionPayload);
            // Refresh instance in case stage action changed amounts
            $instance->refresh();
        }

        $amount = ($validated['approved_amount'] ?? null) === null
            ? null
            : Money::fromMajor($validated['approved_amount']);

        $this->engine->approve(
            $instance,
            $this->currentUser(),
            $amount,
            $validated['comment'] ?? null,
            $actionPayload,
        );

        $this->syncSubject($instance->fresh(['subject']));

        return back()->with('success', 'Approved.');
    }

    public function reject(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $this->engine->reject($instance, $this->currentUser(), $validated['comment']);

        $this->syncSubject($instance->fresh(['subject']));

        return back()->with('success', 'Rejected and returned to the requester.');
    }

    public function requestInfo(Request $request, WorkflowInstance $instance): RedirectResponse
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $this->engine->requestInfo($instance, $this->currentUser(), $validated['comment']);

        return back()->with('success', 'The requester has been asked for more information.');
    }

    /**
     * BR-20 — the subject mirrors the instance's terminal state. Kept in one place
     * so every action path updates it identically.
     */
    private function syncSubject(WorkflowInstance $instance): void
    {
        $subject = $instance->subject;

        match (true) {
            $subject instanceof Requisition => $this->requisitions->syncFromWorkflow($subject),
            $subject instanceof LeaveRequest => $this->leave->syncFromWorkflow($subject),
            $subject instanceof PayrollRun => $this->payroll->syncFromWorkflow($subject),
            $subject instanceof PaymentRun => app(FarmerPaymentRunService::class)->syncFromWorkflow($subject),
            $subject instanceof TransportPaymentRun => app(TransportPaymentRunService::class)->syncFromWorkflow($subject),
            default => null,
        };
    }
}
