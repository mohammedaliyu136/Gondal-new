<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PaymentBatch;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Hr\PayrollService;
use App\Services\Payment\Modules\PayrollPaymentService;
use App\Services\Payment\PaymentService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly PaymentService $paymentService,
        private readonly PayrollPaymentService $payrollPaymentService,
    ) {}

    public function index(Request $request): View
    {
        $runs = PayrollRun::query()
            ->with(['runBy', 'workflowInstance.currentStage.approvingRole'])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        $selectedRunId = $request->integer('run_id');
        $current = $selectedRunId
            ? PayrollRun::query()->with(['runBy', 'workflowInstance.currentStage.approvingRole'])->find($selectedRunId)
            : $runs->first();

        $canDisburse = $this->allows('payments.disbursements.initialize')
            || $this->allows('payments.disbursements.authorize')
            || $this->allows('hr.payroll.approve');

        $payslipsQuery = null;
        if ($current !== null) {
            $payslipsQuery = $current->payslips()->with('employee.department')->orderBy('id');

            if ($q = trim((string) $request->input('q', ''))) {
                $payslipsQuery->where(function ($query) use ($q): void {
                    $query->where('reference', 'like', "%{$q}%")
                        ->orWhereHas('employee', function ($eq) use ($q): void {
                            $eq->where('name', 'like', "%{$q}%")
                                ->orWhere('code', 'like', "%{$q}%")
                                ->orWhere('email', 'like', "%{$q}%");
                        })
                        ->orWhereHas('employee.department', function ($dq) use ($q): void {
                            $dq->where('name', 'like', "%{$q}%")
                                ->orWhere('code', 'like', "%{$q}%");
                        });
                });
            }

            if ($deptId = $request->input('department')) {
                $payslipsQuery->whereHas('employee', function ($eq) use ($deptId): void {
                    $eq->where('department_id', $deptId);
                });
            }
        }

        $payslips = $payslipsQuery === null
            ? collect()
            : $payslipsQuery->paginate($this->perPage($request->integer('per_page') ?: null), ['*'], 'payslip_page')
                ->appends($request->except('payslip_page'));

        $onRunEmployeeIds = $current ? $current->payslips()->pluck('employee_id')->all() : [];
        $availableEmployees = ($current && $current->status === PayrollRun::STATUS_DRAFT)
            ? Employee::query()->onPayroll()->excludingTestData()->whereNotIn('id', $onRunEmployeeIds)->orderBy('name')->get()
            : collect();

        return view('hr.payroll.index', [
            'runs' => $runs,
            'current' => $current,
            'payslips' => $payslips,
            'departments' => \App\Models\Department::query()->active()->orderBy('name')->get(),
            'availableEmployees' => $availableEmployees,
            'canRun' => $this->allows('hr.payroll.create'),
            'canEditDraft' => $this->allows('hr.payroll.create') || $this->allows('hr.payroll.edit'),
            'canDisburse' => $canDisburse,
            'canMarkPaid' => $canDisburse && Route::has('payroll.paid'),
            'nextPeriod' => Wat::today()->startOfMonth(),
            'paymentsModuleBlocked' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $run = $this->payroll->generate(
            (int) $validated['period_year'],
            (int) $validated['period_month'],
            $this->currentUser(),
        );

        return back()->with('success', sprintf(
            'Payroll generated for %s — %d employees. Test accounts were excluded.',
            $run->periodLabel(),
            (int) $run->employee_count,
        ));
    }

    /**
     * Synchronize all draft payslips against active salary profiles and records.
     */
    public function syncRun(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.create', 'hr.payroll.edit'], null, 'Sync draft payroll run');

        $this->payroll->syncDraftRun($payrollRun, $this->currentUser());

        return back()->with('success', sprintf('Draft payroll for %s has been synchronized with the latest employee salary profiles.', $payrollRun->periodLabel()));
    }

    /**
     * Add a missing employee to a draft payroll run.
     */
    public function addEmployee(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.create', 'hr.payroll.edit'], null, 'Add employee to draft payroll run');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $employee = Employee::query()->findOrFail($validated['employee_id']);
        $payslip = $this->payroll->addEmployeeToRun($payrollRun, $employee, $this->currentUser());

        return back()->with('success', sprintf('%s has been added to %s draft payroll (Ref: %s).', $employee->name, $payrollRun->periodLabel(), $payslip->reference));
    }

    /**
     * Recalculate a single draft payslip against latest master structure.
     */
    public function recalculatePayslip(Payslip $payslip): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.create', 'hr.payroll.edit'], $payslip->employee, 'Recalculate draft payslip');

        $this->payroll->recalculatePayslip($payslip, $this->currentUser());

        return back()->with('success', sprintf('Payslip %s for %s has been recalculated.', $payslip->reference, $payslip->employee?->name));
    }

    /**
     * Remove an employee from a draft payroll run.
     */
    public function destroyPayslip(Payslip $payslip): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.create', 'hr.payroll.edit'], $payslip->employee, 'Remove employee from draft payroll run');

        $employeeName = $payslip->employee?->name ?? 'Employee';
        $ref = $payslip->reference;
        $this->payroll->removePayslip($payslip, $this->currentUser());

        return back()->with('success', sprintf('%s (Payslip %s) removed from draft payroll run.', $employeeName, $ref));
    }

    /**
     * Discard / delete an entire draft payroll run.
     */
    public function destroy(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.create', 'hr.payroll.delete'], null, 'Discard draft payroll run');

        $period = $payrollRun->periodLabel();
        $this->payroll->discardDraft($payrollRun, $this->currentUser());

        return redirect()->route('payroll.index')->with('success', sprintf('Draft payroll run for %s has been discarded.', $period));
    }

    public function submit(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.create', null, 'Submit payroll for approval');

        $this->payroll->submitForApproval($payrollRun, $this->currentUser());

        return back()->with('success', $payrollRun->periodLabel().' submitted for approval.');
    }

    /**
     * Mark payroll paid / release funds.
     */
    public function markPaid(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'payments.disbursements.initialize', 'hr.payroll.approve'],
            null,
            'Mark payroll paid'
        );

        $this->payroll->markPaid($payrollRun, $this->currentUser());

        return back()->with('success', $payrollRun->periodLabel().' marked paid.');
    }

    /**
     * Dedicated disbursement breakdown and payment page for an approved/paid payroll run.
     */
    public function payment(PayrollRun $payrollRun, Request $request): View
    {
        $this->authorizeAnyAccess(
            ['hr.payroll.view', 'payments.disbursements.view'],
            $payrollRun,
            'Payroll Payment Details'
        );

        $payrollRun->load(['runBy', 'workflowInstance.currentStage.approvingRole', 'workflowInstance.actions.actor']);

        $payslips = $payrollRun->payslips()
            ->with('employee.department')
            ->orderBy('id')
            ->paginate($this->perPage($request->integer('per_page') ?: 50));

        $gateways = $this->paymentService->getGatewayStatuses();

        $batches = PaymentBatch::query()
            ->where('source_type', $payrollRun->getMorphClass())
            ->where('source_id', $payrollRun->getKey())
            ->with(['items', 'initiatedBy', 'authorizedBy'])
            ->latest('id')
            ->get();

        $canInitialize = $this->allows('payments.disbursements.initialize') || $this->allows('hr.payroll.approve');
        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('hr.payroll.approve');

        return view('hr.payroll.payment', [
            'run' => $payrollRun,
            'payslips' => $payslips,
            'gateways' => $gateways,
            'batches' => $batches,
            'canInitialize' => $canInitialize,
            'canAuthorize' => $canAuthorize,
            'canDisburse' => $canInitialize || $canAuthorize,
        ]);
    }

    /**
     * Disburse payroll payments via configured gateway or record bank transfer settlement.
     */
    public function disburse(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'payments.disbursements.initialize', 'hr.payroll.approve'],
            $payrollRun,
            'Disburse Payroll Payment'
        );

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:bank_transfer,paystack,monnify,zainpay,cash'],
            'reference_notes' => ['nullable', 'string', 'max:500'],
            'otp' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $batch = $this->payrollPaymentService->disburseRun(
                $payrollRun,
                $validated['payment_method'],
                $this->currentUser(),
                $validated['reference_notes'] ?? null,
                $validated['otp'] ?? null
            );

            return redirect()->route('payroll.payment', $payrollRun)->with('success', sprintf(
                'Payroll for %s (%s) has been successfully processed via batch %s (%s).',
                $payrollRun->periodLabel(),
                Money::format((int) $payrollRun->net_total_minor),
                $batch->batch_reference,
                ucfirst(str_replace('_', ' ', $validated['payment_method']))
            ));
        } catch (\Throwable $e) {
            return redirect()->route('payroll.payment', $payrollRun)->with('error', $e->getMessage());
        }
    }

    /**
     * Detail view for a specific payroll payment batch and its reconciled items with payslips.
     */
    public function batch(PayrollRun $payrollRun, PaymentBatch $batch, Request $request): View
    {
        $this->authorizeAnyAccess(
            ['hr.payroll.view', 'payments.disbursements.view'],
            $payrollRun,
            'Payroll Batch Details'
        );

        // Auto-synchronize live status with payment gateway on batch detail view visit
        if (in_array($batch->gateway, ['monnify', 'paystack', 'zainpay'])) {
            try {
                $batch = $this->payrollPaymentService->syncBatchStatus($batch, $this->currentUser());
            } catch (\Throwable $e) {
                Log::info('Auto-sync batch status on visit note: ' . $e->getMessage());
            }
        }

        $batch->load(['initiatedBy', 'authorizedBy', 'source']);
        $payrollRun->load(['payslips.employee.department']);

        $items = $batch->items()
            ->with(['recipient'])
            ->orderBy('id')
            ->paginate($this->perPage($request->integer('per_page') ?: 50));

        $payslipsByEmployee = $payrollRun->payslips->keyBy('employee_id');

        $canInitialize = $this->allows('payments.disbursements.initialize') || $this->allows('hr.payroll.approve');
        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('hr.payroll.approve');

        return view('hr.payroll.batch', [
            'run' => $payrollRun,
            'batch' => $batch,
            'items' => $items,
            'payslipsByEmployee' => $payslipsByEmployee,
            'canInitialize' => $canInitialize,
            'canAuthorize' => $canAuthorize,
        ]);
    }

    /**
     * Authorize an existing pending payment batch using an OTP/2FA code.
     */
    public function validateBatchOtp(Request $request, PayrollRun $payrollRun, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'hr.payroll.approve'],
            $payrollRun,
            'Validate Batch OTP'
        );

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:32'],
        ]);

        try {
            $syncedBatch = $this->payrollPaymentService->authorizeBatchOtp($batch, $validated['otp'], $this->currentUser());

            $msg = sprintf(
                'Payment batch %s has been authorized and synchronized (%d successful, %d failed).',
                $batch->batch_reference,
                $syncedBatch->successful_items_count,
                $syncedBatch->failed_items_count
            );

            if ($request->headers->get('referer') && str_contains($request->headers->get('referer'), '/batches/')) {
                return redirect()->route('payroll.batches.show', [$payrollRun, $batch])->with('success', $msg);
            }

            return redirect()->route('payroll.payment', $payrollRun)->with('success', $msg);
        } catch (\Throwable $e) {
            if ($request->headers->get('referer') && str_contains($request->headers->get('referer'), '/batches/')) {
                return redirect()->route('payroll.batches.show', [$payrollRun, $batch])->with('error', $e->getMessage());
            }

            return redirect()->route('payroll.payment', $payrollRun)->with('error', $e->getMessage());
        }
    }

    /**
     * Resend authorization OTP/2FA code via Monnify or Paystack.
     */
    public function resendBatchOtp(Request $request, PayrollRun $payrollRun, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'hr.payroll.approve'],
            $payrollRun,
            'Resend Batch OTP'
        );

        try {
            $this->payrollPaymentService->resendBatchOtp($batch, $this->currentUser());

            $msg = sprintf('A new authorization OTP code has been dispatched by %s.', ucfirst($batch->gateway));

            if ($request->headers->get('referer') && str_contains($request->headers->get('referer'), '/batches/')) {
                return redirect()->route('payroll.batches.show', [$payrollRun, $batch])->with('success', $msg);
            }

            return redirect()->route('payroll.payment', $payrollRun)->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Re-query live payment gateway to sync batch settlement status and update payslips.
     */
    public function syncBatchStatus(Request $request, PayrollRun $payrollRun, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'hr.payroll.approve', 'payments.disbursements.initialize', 'hr.payroll.view'],
            $payrollRun,
            'Sync Batch Status'
        );

        try {
            $this->payrollPaymentService->syncBatchStatus($batch, $this->currentUser());

            $message = sprintf(
                'Payment batch %s status verified with %s: %d Successful, %d Failed (Gateway: %s, Batch Status: %s).',
                $batch->batch_reference,
                ucfirst($batch->gateway),
                $batch->successful_items_count,
                $batch->failed_items_count,
                $batch->gateway_status ?: 'UPDATED',
                ucfirst(str_replace('_', ' ', $batch->status))
            );

            // Redirect back to referring page (batch detail or payment overview)
            if ($request->headers->get('referer') && str_contains($request->headers->get('referer'), '/batches/')) {
                return redirect()->route('payroll.batches.show', [$payrollRun, $batch])->with('success', $message);
            }

            return redirect()->route('payroll.payment', $payrollRun)->with('success', $message);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * §4 — `hr.payroll.view` OR own. The record check settles which: a staff
     * member's own payslip passes on the `own` scope of hr.payslip.own.view.
     */
    public function payslip(Payslip $payslip): View
    {
        $this->authorizeAnyAccess(
            ['hr.payroll.view', 'hr.payslip.own.view'],
            $payslip,
            'Payslip → '.$payslip->reference,
        );

        return view('hr.payroll.payslip', [
            'payslip' => $payslip->load(['employee.department', 'payrollRun']),
            'isOwn' => $payslip->employee_id !== null
                && (int) $payslip->employee_id === (int) $this->currentUser()?->employee_id,
        ]);
    }
}
