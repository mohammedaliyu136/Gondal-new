<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Hr\PayrollService;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * payroll.html and payslip.html.
 *
 * G-6 — payroll is the sharpest sensitive boundary in the system. Only
 *   hr.payroll.view reaches the run; a member of staff reaches THEIR OWN payslip
 *   through hr.payslip.own.view, which is `own`-scoped (ROLE-3).
 * BR-35 — test accounts are excluded when the run is generated.
 * §15.1 — FARMER and TRANSPORT payments are NOT here. Phase 7 is blocked on an
 *   open decision; this is staff payroll only.
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): View
    {
        $runs = PayrollRun::query()
            ->with(['runBy', 'workflowInstance.currentStage.approvingRole'])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        $current = $runs->first();

        return view('hr.payroll.index', [
            'runs' => $runs,
            'current' => $current,
            /*
             * NFR-2 — paginated, not truncated. This was limit(50)->get() with no
             * total and no paginator: at 51 employees the fifty-first payslip
             * stopped appearing while the run's own gross and net kept
             * reconciling, so the table disagreed with the tiles above it and
             * looked correct doing so.
             */
            'payslips' => $current === null
                ? collect()
                : $current->payslips()->with('employee.department')->orderBy('id')
                    ->paginate($this->perPage($request->integer('per_page') ?: null), ['*'], 'payslip_page'),
            'canRun' => $this->allows('hr.payroll.create'),
            /*
             * Route::has because the POST is registered separately from this
             * screen; without the guard the whole page 500s while the two halves
             * are out of step, which is a worse failure than a missing button.
             */
            'canMarkPaid' => $this->allows('hr.payroll.approve') && Route::has('payroll.paid'),
            'nextPeriod' => Wat::today()->startOfMonth(),
            // §15.1 — surfaced on screen rather than silently missing.
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

    public function submit(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.create', null, 'Submit payroll for approval');

        $this->payroll->submitForApproval($payrollRun, $this->currentUser());

        return back()->with('success', $payrollRun->periodLabel().' submitted for approval.');
    }

    /**
     * §6.8's `paid` status and `paid_at` were unreachable — a run could never
     * leave `approved`, so the register could not say which months had actually
     * been paid. Gated on hr.payroll.approve: releasing the money is the approver's
     * act, not the officer's who generated the run.
     */
    public function markPaid(PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.approve', null, 'Mark payroll paid');

        $this->payroll->markPaid($payrollRun, $this->currentUser());

        return back()->with('success', $payrollRun->periodLabel().' marked paid.');
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
