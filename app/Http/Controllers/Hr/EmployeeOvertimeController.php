<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeOvertime;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeOvertimeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record an overtime entry for an employee.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.edit', 'hr.payroll.create'], $employee, 'Record Overtime → ' . $employee->name);

        $rateRaw = str_replace(',', '', trim($request->input('hourly_rate', '0')));
        $request->merge(['hourly_rate' => $rateRaw]);

        $validated = $request->validate([
            'hours' => ['required', 'numeric', 'min:0.5', 'max:200'],
            'hourly_rate' => ['required', 'numeric', 'min:1'],
            'worked_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $workedDate = \Carbon\Carbon::parse($validated['worked_on']);
        $hourlyRateMinor = Money::fromMajor($validated['hourly_rate']);
        $hours = (float) $validated['hours'];
        $totalAmountMinor = (int) round($hours * $hourlyRateMinor);

        $reference = Sequences::next('overtime');

        $overtime = EmployeeOvertime::query()->create([
            'employee_id' => $employee->id,
            'reference' => $reference,
            'hours' => $hours,
            'hourly_rate_minor' => $hourlyRateMinor,
            'total_amount_minor' => $totalAmountMinor,
            'period_year' => (int) $workedDate->format('Y'),
            'period_month' => (int) $workedDate->format('n'),
            'worked_on' => $validated['worked_on'],
            'description' => $validated['description'],
            'status' => EmployeeOvertime::STATUS_APPROVED,
            'approved_by_user_id' => $this->currentUser()?->id,
            'created_by_user_id' => $this->currentUser()?->id,
        ]);

        $this->audit->created(
            $overtime,
            sprintf('Overtime of %s (%.1f hrs @ %s/hr) recorded for %s', Money::format($totalAmountMinor), $hours, Money::format($hourlyRateMinor), $employee->name),
            'Human Resources',
            [],
            $this->currentUser()
        );

        return redirect()->back()->with('success', sprintf(
            'Overtime of %s (%.1f hrs) recorded for %s and queued for %s %d payroll.',
            Money::format($totalAmountMinor),
            $hours,
            $employee->name,
            $workedDate->format('F'),
            $overtime->period_year
        ));
    }

    /**
     * Delete / cancel an unbilled overtime entry.
     */
    public function destroy(EmployeeOvertime $overtime): RedirectResponse
    {
        $this->authorizeAccess('hr.payroll.edit', $overtime->employee, 'Delete Overtime → ' . $overtime->reference);

        if ($overtime->status === EmployeeOvertime::STATUS_PROCESSED || $overtime->payslip_id !== null) {
            return redirect()->back()->with('error', 'Cannot remove overtime that has already been processed in a finalized payroll run.');
        }

        $overtime->delete();

        return redirect()->back()->with('success', 'Overtime record removed.');
    }
}
