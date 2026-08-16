<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HrCompensationType;
use App\Models\StaffLoan;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffLoanController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue / Grant a new staff loan or cash advance.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.edit', 'hr.payroll.create'], $employee, 'Grant Staff Loan → ' . $employee->name);

        // Sanitize comma thousand separators
        $cleaned = [];
        foreach ($request->all() as $k => $v) {
            if (is_string($v) && in_array($k, ['principal_amount', 'monthly_installment'], true)) {
                $cleaned[$k] = str_replace(',', '', trim($v));
            }
        }
        $request->merge($cleaned);

        $validated = $request->validate([
            'compensation_type_id' => ['nullable', 'exists:hr_compensation_types,id'],
            'principal_amount' => ['required', 'numeric', 'min:100'],
            'monthly_installment' => ['required', 'numeric', 'min:10'],
            'disbursed_on' => ['required', 'date'],
            'start_period_month' => ['required', 'integer', 'between:1,12'],
            'start_period_year' => ['required', 'integer', 'min:2020'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $principalMinor = Money::fromMajor($validated['principal_amount']);
        $installmentMinor = Money::fromMajor($validated['monthly_installment']);

        $reference = Sequences::next('loans');

        $loan = StaffLoan::query()->create([
            'employee_id' => $employee->id,
            'compensation_type_id' => $validated['compensation_type_id'] ?? null,
            'reference' => $reference,
            'principal_amount_minor' => $principalMinor,
            'monthly_installment_minor' => $installmentMinor,
            'total_repaid_minor' => 0,
            'balance_minor' => $principalMinor,
            'disbursed_on' => $validated['disbursed_on'],
            'start_period_year' => $validated['start_period_year'],
            'start_period_month' => $validated['start_period_month'],
            'status' => StaffLoan::STATUS_ACTIVE,
            'notes' => $validated['notes'] ?? null,
            'approved_by_user_id' => $this->currentUser()?->id,
            'created_by_user_id' => $this->currentUser()?->id,
        ]);

        $this->audit->created(
            $loan,
            sprintf(
                'Granted staff loan %s to %s: Principal %s, Installment %s/mo',
                $loan->reference,
                $employee->name,
                Money::format($principalMinor),
                Money::format($installmentMinor)
            ),
            'Human Resources',
            [],
            $this->currentUser()
        );

        return redirect()->back()->with('success', sprintf(
            'Loan %s of %s granted to %s with monthly installment of %s.',
            $loan->reference,
            Money::format($principalMinor),
            $employee->name,
            Money::format($installmentMinor)
        ));
    }

    /**
     * Record manual out-of-band loan repayment.
     */
    public function repay(Request $request, StaffLoan $loan): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.edit', $loan->employee, 'Record Loan Repayment → ' . $loan->reference);

        $amountRaw = str_replace(',', '', trim($request->input('amount', '0')));
        $request->merge(['amount' => $amountRaw]);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $amountMinor = Money::fromMajor($validated['amount']);
        $amountMinor = min($amountMinor, (int) $loan->balance_minor);

        $repayment = $loan->recordRepayment(
            $amountMinor,
            null,
            null,
            $this->currentUser()
        );

        $this->audit->created(
            $repayment,
            sprintf(
                'Manual loan repayment of %s recorded for %s (%s). New balance: %s',
                Money::format($amountMinor),
                $loan->employee->name,
                $loan->reference,
                Money::format((int) $loan->balance_minor)
            ),
            'Human Resources',
            [],
            $this->currentUser()
        );

        return redirect()->back()->with('success', sprintf(
            'Repayment of %s recorded. Outstanding balance: %s.',
            Money::format($amountMinor),
            Money::format((int) $loan->balance_minor)
        ));
    }

    /**
     * Toggle pause / active status on a loan.
     */
    public function toggleStatus(StaffLoan $loan): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.edit', $loan->employee, 'Toggle Loan Status → ' . $loan->reference);

        $newStatus = $loan->status === StaffLoan::STATUS_ACTIVE ? StaffLoan::STATUS_PAUSED : StaffLoan::STATUS_ACTIVE;
        $loan->forceFill(['status' => $newStatus])->save();

        return redirect()->back()->with('success', sprintf(
            'Loan %s status updated to %s.',
            $loan->reference,
            ucfirst($newStatus)
        ));
    }
}
