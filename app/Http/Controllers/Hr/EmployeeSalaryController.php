<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSalaryProfile;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeSalaryController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Show salary structure and compensation builder for an employee.
     */
    public function edit(Employee $employee): View
    {
        $this->authorizeAnyAccess(
            ['hr.payroll.view', 'hr.payroll.edit', 'hr.payroll.create'],
            $employee,
            'Manage Salary Structure → ' . $employee->name
        );

        $employee->load([
            'department',
            'salaryProfile',
            'staffLoans.compensationType',
            'staffLoans.repayments',
            'commissions.compensationType',
            'overtimes',
        ]);
        $profile = $employee->salaryProfile;

        // Fallback default structure if none exists
        if (!$profile) {
            $gross = (int) $employee->gross_monthly_minor;
            $profile = new EmployeeSalaryProfile([
                'employee_id' => $employee->id,
                'basic_salary_minor' => (int) round($gross * 0.50),     // 50% Basic default
                'housing_allowance_minor' => (int) round($gross * 0.30), // 30% Housing default
                'transport_allowance_minor' => (int) round($gross * 0.20), // 20% Transport default
                'pension_rate_pct' => 8.00,
                'is_pension_exempt' => false,
                'tax_rate_pct' => 7.00,
                'is_tax_exempt' => false,
            ]);
            $profile->refreshComputedTotals();
        }

        $canSetSalary = $this->allows('hr.payroll.edit', $employee) || $this->allows('hr.payroll.create', $employee);

        return view('hr.employees.salary', [
            'employee' => $employee,
            'profile' => $profile,
            'canEdit' => $canSetSalary,
            'canSetSalary' => $canSetSalary,
            'loanTypes' => \App\Models\HrCompensationType::query()->category('loan')->active()->orderBy('name')->get(),
            'commissionTypes' => \App\Models\HrCompensationType::query()->category('commission')->active()->orderBy('name')->get(),
            'allowanceTypes' => \App\Models\HrCompensationType::query()->category('allowance')->active()->orderBy('name')->get(),
        ]);
    }

    /**
     * Store or update an employee's salary and compensation structure.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeAnyAccess(['hr.payroll.edit', 'hr.payroll.create'], $employee, 'Update Salary Structure → ' . $employee->name);

        // Sanitize thousand comma separators from inputs
        $sanitized = [];
        foreach ($request->all() as $k => $v) {
            if (is_string($v) && !in_array($k, ['notes', 'effective_date', '_token', '_method'], true)) {
                $sanitized[$k] = str_replace(',', '', trim($v));
            }
        }
        if ($sanitized !== []) {
            $request->merge($sanitized);
        }

        $validated = $request->validate([
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'utility_allowance' => ['nullable', 'numeric', 'min:0'],
            'medical_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowance' => ['nullable', 'numeric', 'min:0'],

            'pension_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_pension_exempt' => ['nullable', 'boolean'],
            'tax_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_tax_exempt' => ['nullable', 'boolean'],

            'nhis' => ['nullable', 'numeric', 'min:0'],
            'union_dues' => ['nullable', 'numeric', 'min:0'],
            'other_deduction' => ['nullable', 'numeric', 'min:0'],

            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $profile = $employee->salaryProfile ?? new EmployeeSalaryProfile(['employee_id' => $employee->id]);

        $beforeGross = $profile->gross_monthly_minor ?? $employee->gross_monthly_minor;

        $profile->fill([
            'basic_salary_minor' => Money::fromMajor($validated['basic_salary'] ?? '0') ?? 0,
            'housing_allowance_minor' => Money::fromMajor($validated['housing_allowance'] ?? '0') ?? 0,
            'transport_allowance_minor' => Money::fromMajor($validated['transport_allowance'] ?? '0') ?? 0,
            'utility_allowance_minor' => Money::fromMajor($validated['utility_allowance'] ?? '0') ?? 0,
            'medical_allowance_minor' => Money::fromMajor($validated['medical_allowance'] ?? '0') ?? 0,
            'other_allowance_minor' => Money::fromMajor($validated['other_allowance'] ?? '0') ?? 0,

            'commission_minor' => 0,
            'overtime_minor' => 0,
            'bonus_minor' => 0,

            'pension_rate_pct' => (float) ($validated['pension_rate_pct'] ?? 8.00),
            'is_pension_exempt' => $request->boolean('is_pension_exempt'),
            'tax_rate_pct' => (float) ($validated['tax_rate_pct'] ?? 7.00),
            'is_tax_exempt' => $request->boolean('is_tax_exempt'),

            'nhis_minor' => Money::fromMajor($validated['nhis'] ?? '0') ?? 0,
            'union_dues_minor' => Money::fromMajor($validated['union_dues'] ?? '0') ?? 0,
            'other_deduction_minor' => Money::fromMajor($validated['other_deduction'] ?? '0') ?? 0,

            'loan_deduction_minor' => 0,
            'loan_balance_minor' => 0,

            'effective_date' => $validated['effective_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $profile->refreshComputedTotals();

        // Synchronize employee record gross monthly
        $employee->forceFill([
            'gross_monthly_minor' => $profile->gross_monthly_minor,
        ])->save();

        $this->audit->edited(
            $employee,
            sprintf(
                'Updated salary structure for %s (%s): Gross %s, Net %s',
                $employee->name,
                $employee->code,
                Money::format((int) $profile->gross_monthly_minor),
                Money::format((int) $profile->net_monthly_minor)
            ),
            'Human Resources',
            ['gross_monthly_minor' => $beforeGross],
            ['gross_monthly_minor' => $profile->gross_monthly_minor, 'net_monthly_minor' => $profile->net_monthly_minor],
            $this->currentUser()
        );

        return redirect()->route('employees.show', $employee)->with('success', sprintf(
            'Salary structure for %s updated. Monthly Gross: %s, Net: %s.',
            $employee->name,
            Money::format((int) $profile->gross_monthly_minor),
            Money::format((int) $profile->net_monthly_minor)
        ));
    }
}
