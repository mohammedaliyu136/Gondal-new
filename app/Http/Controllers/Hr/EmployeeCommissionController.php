<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeCommissionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a commission or performance incentive for an employee.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.edit', 'hr.payroll.create'], $employee, 'Record Commission → ' . $employee->name);

        $amountRaw = str_replace(',', '', trim($request->input('amount', '0')));
        $request->merge(['amount' => $amountRaw]);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'earned_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'compensation_type_id' => ['nullable', 'exists:hr_compensation_types,id'],
        ]);

        $earnedDate = \Carbon\Carbon::parse($validated['earned_on']);
        $amountMinor = Money::fromMajor($validated['amount']);

        $reference = Sequences::next('commissions');

        $commission = EmployeeCommission::query()->create([
            'employee_id' => $employee->id,
            'compensation_type_id' => $validated['compensation_type_id'] ?? null,
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'period_year' => (int) $earnedDate->format('Y'),
            'period_month' => (int) $earnedDate->format('n'),
            'earned_on' => $validated['earned_on'],
            'description' => $validated['description'],
            'status' => EmployeeCommission::STATUS_APPROVED,
            'approved_by_user_id' => $this->currentUser()?->id,
            'created_by_user_id' => $this->currentUser()?->id,
        ]);

        $this->audit->created(
            $commission,
            sprintf('Commission of %s recorded for %s: %s', Money::format($amountMinor), $employee->name, $commission->description),
            'Human Resources',
            [],
            $this->currentUser()
        );

        return redirect()->back()->with('success', sprintf(
            'Commission of %s (%s) recorded for %s and queued for %s %d payroll.',
            Money::format($amountMinor),
            $commission->description,
            $employee->name,
            $earnedDate->format('F'),
            $commission->period_year
        ));
    }

    /**
     * Delete / cancel an unbilled commission.
     */
    public function destroy(EmployeeCommission $commission): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.edit', $commission->employee, 'Delete Commission → ' . $commission->reference);

        if ($commission->status === EmployeeCommission::STATUS_PROCESSED || $commission->payslip_id !== null) {
            return redirect()->back()->with('error', 'Cannot remove commission that has already been processed in a finalized payroll run.');
        }

        $commission->delete();

        return redirect()->back()->with('success', 'Commission record removed.');
    }
}
