<?php

namespace App\Services\Hr;

use App\Exceptions\RuleViolationException;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 — payroll runs.
 *
 * BR-35 / TEST-1 — "Test accounts are excluded from ... payroll." The exclusion
 *   happens when the run is GENERATED: an employee linked to a test account never
 *   gets a payslip, so no total ever contains them and nothing has to be filtered
 *   out afterwards.
 *
 * G-6 — every figure here is gated on hr.payroll.view (§5.1), except a user's own
 *   payslip, which hr.payslip.own.view covers.
 *
 * §15.1 — farmer and transport payments are NOT here. Their module's home is an
 *   open decision and Phase 7 is blocked. This is staff payroll only.
 */
class PayrollService
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function generate(int $year, int $month, User $actor): PayrollRun
    {
        if (PayrollRun::query()->forPeriod($year, $month)->exists()) {
            throw RuleViolationException::make(
                'ST-1',
                'A payroll run already exists for that period.',
                ['year' => $year, 'month' => $month],
            );
        }

        return DB::transaction(function () use ($year, $month, $actor): PayrollRun {
            $run = PayrollRun::query()->create([
                'period_month' => $month,
                'period_year' => $year,
                'status' => PayrollRun::STATUS_DRAFT,
                'run_by_user_id' => $actor->getKey(),
            ]);

            // BR-35 — an employee whose account is flagged is_test is excluded.
            $employees = Employee::query()
                ->onPayroll()
                ->excludingTestData()
                ->with(['salaryProfile', 'activeLoans.compensationType'])
                ->get();

            foreach ($employees as $employee) {
                $this->calculateAndPersistPayslip($run, $employee, $actor);
            }

            $this->reconcileRunTotals($run);

            $this->audit->created(
                $run,
                sprintf(
                    'Payroll run generated for %s — %d employees, %s net',
                    $run->periodLabel(),
                    $run->employee_count,
                    Money::format((int) $run->net_total_minor),
                ),
                'Human Resources',
                ['rule' => 'BR-35', 'test_accounts_excluded' => true],
                $actor,
            );

            return $run;
        });
    }

    /**
     * Compute and persist payslip for an individual employee within a draft run.
     */
    public function calculateAndPersistPayslip(
        PayrollRun $run,
        Employee $employee,
        User $actor,
        ?Payslip $existing = null,
    ): Payslip {
        // 1. If existing payslip, detach any previously linked items
        if ($existing) {
            \App\Models\EmployeeCommission::query()->where('payslip_id', $existing->id)->update(['payslip_id' => null]);
            \App\Models\EmployeeOvertime::query()->where('payslip_id', $existing->id)->update(['payslip_id' => null]);
            \App\Models\StaffLoanRepayment::query()->where('payslip_id', $existing->id)->where('status', 'pending')->delete();
        }

        $earnings = [];
        $deductionItems = [];

        $profile = $employee->salaryProfile;
        if ($profile) {
            if ($profile->basic_salary_minor > 0) {
                $earnings[] = ['label' => 'Basic Salary', 'amount_minor' => (int) $profile->basic_salary_minor];
            }
            if ($profile->housing_allowance_minor > 0) {
                $earnings[] = ['label' => 'Housing Allowance', 'amount_minor' => (int) $profile->housing_allowance_minor];
            }
            if ($profile->transport_allowance_minor > 0) {
                $earnings[] = ['label' => 'Transport Allowance', 'amount_minor' => (int) $profile->transport_allowance_minor];
            }
            if ($profile->utility_allowance_minor > 0) {
                $earnings[] = ['label' => 'Utility & Meal Allowance', 'amount_minor' => (int) $profile->utility_allowance_minor];
            }
            if ($profile->medical_allowance_minor > 0) {
                $earnings[] = ['label' => 'Medical Allowance', 'amount_minor' => (int) $profile->medical_allowance_minor];
            }
            if ($profile->other_allowance_minor > 0) {
                $earnings[] = ['label' => 'Other Fixed Allowance', 'amount_minor' => (int) $profile->other_allowance_minor];
            }
            if ($profile->bonus_minor > 0) {
                $earnings[] = ['label' => 'Regular Bonus', 'amount_minor' => (int) $profile->bonus_minor];
            }

            $pensionRate = $profile->pension_rate_pct ?: 8.0;
            $isPensionExempt = $profile->is_pension_exempt;
            $taxRate = $profile->tax_rate_pct ?: 7.0;
            $isTaxExempt = $profile->is_tax_exempt;
            $nhisMinor = (int) $profile->nhis_minor;
            $unionMinor = (int) $profile->union_dues_minor;
            $otherDeductionMinor = (int) $profile->other_deduction_minor;
        } else {
            $employeeGross = (int) $employee->gross_monthly_minor;
            $earnings[] = ['label' => 'Basic Salary', 'amount_minor' => (int) round($employeeGross * 0.50)];
            $earnings[] = ['label' => 'Housing Allowance', 'amount_minor' => (int) round($employeeGross * 0.30)];
            $earnings[] = ['label' => 'Transport Allowance', 'amount_minor' => (int) round($employeeGross * 0.20)];

            $pensionRate = 8.0;
            $isPensionExempt = false;
            $taxRate = 7.0;
            $isTaxExempt = false;
            $nhisMinor = 0;
            $unionMinor = 0;
            $otherDeductionMinor = 0;
        }

        // 2. Fetch and attach dynamic Commissions for this period
        $commissions = \App\Models\EmployeeCommission::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $run->period_year)
            ->where('period_month', $run->period_month)
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($q) use ($existing) {
                $q->whereNull('payslip_id');
                if ($existing) {
                    $q->orWhere('payslip_id', $existing->id);
                }
            })
            ->get();

        foreach ($commissions as $comm) {
            $earnings[] = [
                'label' => sprintf('Commission: %s (%s)', $comm->description, $comm->reference),
                'amount_minor' => (int) $comm->amount_minor,
            ];
        }

        // 3. Fetch and attach dynamic Overtime records for this period
        $overtimes = \App\Models\EmployeeOvertime::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $run->period_year)
            ->where('period_month', $run->period_month)
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($q) use ($existing) {
                $q->whereNull('payslip_id');
                if ($existing) {
                    $q->orWhere('payslip_id', $existing->id);
                }
            })
            ->get();

        foreach ($overtimes as $ot) {
            $earnings[] = [
                'label' => sprintf('Overtime: %s (%.1f hrs @ %s/hr)', $ot->description, $ot->hours, Money::format((int) $ot->hourly_rate_minor)),
                'amount_minor' => (int) $ot->total_amount_minor,
            ];
        }

        // Compute total gross
        $employeeGross = (int) array_sum(array_column($earnings, 'amount_minor'));

        // 4. Calculate Statutory Deductions
        $pension = $isPensionExempt ? 0 : (int) round(($employeeGross * $pensionRate) / 100);
        if ($pension > 0) {
            $deductionItems[] = ['label' => sprintf('Pension Contribution (%.1f%%)', $pensionRate), 'amount_minor' => $pension];
        }

        $taxable = max(0, $employeeGross - $pension);
        $tax = $isTaxExempt ? 0 : (int) round(($taxable * $taxRate) / 100);
        if ($tax > 0) {
            $deductionItems[] = ['label' => sprintf('PAYE Income Tax (%.1f%%)', $taxRate), 'amount_minor' => $tax];
        }

        if ($nhisMinor > 0) {
            $deductionItems[] = ['label' => 'Health Insurance (NHIS)', 'amount_minor' => $nhisMinor];
        }
        if ($unionMinor > 0) {
            $deductionItems[] = ['label' => 'Union / Cooperative Dues', 'amount_minor' => $unionMinor];
        }
        if ($otherDeductionMinor > 0) {
            $deductionItems[] = ['label' => 'Other Voluntary Deductions', 'amount_minor' => $otherDeductionMinor];
        }

        // 5. Dynamic Staff Loan Deductions
        $loanRepaymentPlans = [];
        $activeLoans = $employee->staffLoans()->where('status', 'active')->where('balance_minor', '>', 0)->get();
        foreach ($activeLoans as $loan) {
            $installment = min((int) $loan->monthly_installment_minor, (int) $loan->balance_minor);
            if ($installment > 0) {
                $loanLabel = $loan->compensationType ? $loan->compensationType->name : 'Staff Loan';
                $deductionItems[] = [
                    'label' => sprintf('%s Repayment (%s)', $loanLabel, $loan->reference),
                    'amount_minor' => $installment,
                ];
                $loanRepaymentPlans[] = [
                    'loan' => $loan,
                    'amount_minor' => $installment,
                ];
            }
        }

        $employeeDeductions = (int) array_sum(array_column($deductionItems, 'amount_minor'));
        $employeeNet = max(0, $employeeGross - $employeeDeductions);

        $breakdown = [
            'earnings' => $earnings,
            'deductions' => $deductionItems,
        ];

        if ($existing) {
            $existing->update([
                'gross_minor' => $employeeGross,
                'deductions_minor' => $employeeDeductions,
                'net_minor' => $employeeNet,
                'breakdown' => $breakdown,
                'ytd' => [
                    'gross_minor' => $this->yearToDateGrossBefore($employee, $run) + $employeeGross,
                ],
            ]);
            $payslip = $existing;
        } else {
            $payslip = Payslip::query()->create([
                'payroll_run_id' => $run->getKey(),
                'employee_id' => $employee->getKey(),
                'reference' => Sequences::next('payslips'),
                'gross_minor' => $employeeGross,
                'deductions_minor' => $employeeDeductions,
                'net_minor' => $employeeNet,
                'breakdown' => $breakdown,
                'ytd' => [
                    'gross_minor' => $this->yearToDateGrossBefore($employee, $run) + $employeeGross,
                ],
            ]);
        }

        // Link processed commissions and overtime to this payslip
        foreach ($commissions as $comm) {
            $comm->update(['payslip_id' => $payslip->id]);
        }
        foreach ($overtimes as $ot) {
            $ot->update(['payslip_id' => $payslip->id]);
        }

        // Queue pending loan repayment records
        foreach ($loanRepaymentPlans as $plan) {
            \App\Models\StaffLoanRepayment::query()->create([
                'staff_loan_id' => $plan['loan']->id,
                'payslip_id' => $payslip->id,
                'payroll_run_id' => $run->id,
                'amount_minor' => $plan['amount_minor'],
                'repaid_on' => Wat::today(),
                'status' => 'pending',
                'recorded_by_user_id' => $actor->id,
            ]);
        }

        return $payslip;
    }

    /**
     * Recalculate a single draft payslip against latest salary profile and queued variable pay.
     */
    public function recalculatePayslip(Payslip $payslip, User $actor): Payslip
    {
        $run = $payslip->payrollRun;
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make('ST-1', 'Only payslips in draft payroll runs can be recalculated.');
        }

        return DB::transaction(function () use ($run, $payslip, $actor): Payslip {
            $employee = $payslip->employee()->with(['salaryProfile', 'activeLoans.compensationType'])->firstOrFail();
            $updated = $this->calculateAndPersistPayslip($run, $employee, $actor, $payslip);
            $this->reconcileRunTotals($run);

            $this->audit->edited(
                $payslip,
                sprintf('Recalculated draft payslip %s for %s (%s net)', $payslip->reference, $employee->name, Money::format((int) $updated->net_minor)),
                'Human Resources',
                [],
                ['gross_minor' => $updated->gross_minor, 'net_minor' => $updated->net_minor],
                $actor,
            );

            return $updated;
        });
    }

    /**
     * Remove / exclude an employee's payslip from a draft payroll run.
     */
    public function removePayslip(Payslip $payslip, User $actor): void
    {
        $run = $payslip->payrollRun;
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make('ST-1', 'Only payslips in draft payroll runs can be removed.');
        }

        DB::transaction(function () use ($run, $payslip, $actor): void {
            $employeeName = $payslip->employee?->name ?? 'Employee';
            $ref = $payslip->reference;

            // Release commissions and overtimes back to unbilled queue
            \App\Models\EmployeeCommission::query()->where('payslip_id', $payslip->id)->update(['payslip_id' => null]);
            \App\Models\EmployeeOvertime::query()->where('payslip_id', $payslip->id)->update(['payslip_id' => null]);
            \App\Models\StaffLoanRepayment::query()->where('payslip_id', $payslip->id)->delete();

            $payslip->delete();

            $this->reconcileRunTotals($run);

            $this->audit->deleted(
                $payslip,
                sprintf('Removed %s (payslip %s) from draft payroll run %s', $employeeName, $ref, $run->periodLabel()),
                'Human Resources',
                ['run_id' => $run->id, 'period' => $run->periodLabel()],
                $actor,
            );
        });
    }

    /**
     * Add a missing employee to a draft payroll run.
     */
    public function addEmployeeToRun(PayrollRun $run, Employee $employee, User $actor): Payslip
    {
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make('ST-1', 'Employees can only be added to draft payroll runs.');
        }

        if ($run->payslips()->where('employee_id', $employee->id)->exists()) {
            throw RuleViolationException::make('ST-2', sprintf('%s is already on this payroll run.', $employee->name));
        }

        return DB::transaction(function () use ($run, $employee, $actor): Payslip {
            $employee->loadMissing(['salaryProfile', 'activeLoans.compensationType']);
            $payslip = $this->calculateAndPersistPayslip($run, $employee, $actor);
            $this->reconcileRunTotals($run);

            $this->audit->created(
                $payslip,
                sprintf('Added %s to draft payroll run %s (payslip %s, %s net)', $employee->name, $run->periodLabel(), $payslip->reference, Money::format((int) $payslip->net_minor)),
                'Human Resources',
                ['run_id' => $run->id],
                $actor,
            );

            return $payslip;
        });
    }

    /**
     * Sync all draft payslips against active master employee records and salary structures.
     */
    public function syncDraftRun(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make('ST-1', 'Only draft payroll runs can be synchronized.');
        }

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $employees = Employee::query()
                ->onPayroll()
                ->excludingTestData()
                ->with(['salaryProfile', 'activeLoans.compensationType'])
                ->get();

            $activeEmployeeIds = $employees->pluck('id')->all();

            // 1. Remove payslips of employees no longer eligible / on payroll
            $orphanedPayslips = $run->payslips()->whereNotIn('employee_id', $activeEmployeeIds)->get();
            foreach ($orphanedPayslips as $orphaned) {
                $this->removePayslip($orphaned, $actor);
            }

            // 2. Recalculate or create payslips for all eligible employees
            foreach ($employees as $employee) {
                $existing = $run->payslips()->where('employee_id', $employee->id)->first();
                $this->calculateAndPersistPayslip($run, $employee, $actor, $existing);
            }

            $this->reconcileRunTotals($run);

            $this->audit->edited(
                $run,
                sprintf('Synchronized draft payroll run for %s — %d employees, %s net', $run->periodLabel(), $run->employee_count, Money::format((int) $run->net_total_minor)),
                'Human Resources',
                [],
                ['employee_count' => $run->employee_count, 'net_total_minor' => $run->net_total_minor],
                $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * Discard / delete an entire draft payroll run and release all attached records.
     */
    public function discardDraft(PayrollRun $run, User $actor): void
    {
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make('ST-1', 'Only draft payroll runs can be discarded.');
        }

        DB::transaction(function () use ($run, $actor): void {
            $label = $run->periodLabel();

            foreach ($run->payslips as $payslip) {
                \App\Models\EmployeeCommission::query()->where('payslip_id', $payslip->id)->update(['payslip_id' => null]);
                \App\Models\EmployeeOvertime::query()->where('payslip_id', $payslip->id)->update(['payslip_id' => null]);
                \App\Models\StaffLoanRepayment::query()->where('payslip_id', $payslip->id)->delete();
                $payslip->delete();
            }

            $run->delete();

            $this->audit->deleted(
                $run,
                sprintf('Discarded draft payroll run for %s', $label),
                'Human Resources',
                ['period' => $label],
                $actor,
            );
        });
    }

    /**
     * Reconcile employee count, gross, deductions, and net totals on a payroll run.
     */
    public function reconcileRunTotals(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();
        $run->forceFill([
            'employee_count' => $payslips->count(),
            'gross_total_minor' => (int) $payslips->sum('gross_minor'),
            'deductions_total_minor' => (int) $payslips->sum('deductions_minor'),
            'net_total_minor' => (int) $payslips->sum('net_minor'),
        ])->save();
    }

    public function submitForApproval(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                'Only a draft payroll run can be submitted.',
                ['status' => $run->status],
            );
        }

        $instance = $this->workflow->start(
            Workflow::APPLIES_PAYROLL_RUN,
            $run,
            $actor,
            (int) $run->net_total_minor,
        );

        $run->forceFill([
            'workflow_instance_id' => $instance->getKey(),
            'status' => PayrollRun::STATUS_PROCESSING,
        ])->save();

        return $run->refresh();
    }

    public function syncFromWorkflow(PayrollRun $run): PayrollRun
    {
        $instance = $run->workflowInstance;

        if ($instance === null) {
            return $run;
        }

        if ($instance->status === WorkflowInstance::STATUS_APPROVED) {
            $run->forceFill([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_at' => $instance->completed_at,
            ])->save();
        }

        if ($instance->status === WorkflowInstance::STATUS_REJECTED) {
            $run->forceFill(['status' => PayrollRun::STATUS_DRAFT])->save();
        }

        return $run;
    }

    /**
     * §6.8 gives payroll_runs the status set draft|processing|approved|paid and a
     * paid_at column, and nothing could ever write either: the register showed an
     * approved run as approved forever, so "which months have actually been paid"
     * was not answerable from the system. This is staff payroll, which is Phase 8
     * and built — §15.1 defers FARMER and TRANSPORT payments, not this one.
     */
    public function markPaid(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_APPROVED) {
            throw RuleViolationException::make(
                'ST-1',
                'Only an approved payroll run can be marked paid.',
                ['status' => $run->status],
            );
        }

        // ARCH-9 — an instant, stored UTC.
        $paidAt = Wat::now();

        return DB::transaction(function () use ($run, $actor, $paidAt): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_PAID,
                'paid_at' => $paidAt,
            ])->save();

            $payslipIds = $run->payslips()->pluck('id')->all();

            // 1. Confirm staff loan repayments and update loan debt balances
            $pendingRepayments = \App\Models\StaffLoanRepayment::query()
                ->where('payroll_run_id', $run->id)
                ->where('status', 'pending')
                ->with('loan')
                ->get();

            foreach ($pendingRepayments as $repayment) {
                $repayment->update(['status' => 'confirmed']);
                $loan = $repayment->loan;
                if ($loan) {
                    $loan->total_repaid_minor += (int) $repayment->amount_minor;
                    $loan->balance_minor = max(0, (int) $loan->principal_amount_minor - (int) $loan->total_repaid_minor);
                    if ($loan->balance_minor <= 0) {
                        $loan->status = \App\Models\StaffLoan::STATUS_COMPLETED;
                    }
                    $loan->save();
                }
            }

            // 2. Mark dynamic commissions as processed in payroll
            \App\Models\EmployeeCommission::query()
                ->whereIn('payslip_id', $payslipIds)
                ->update(['status' => \App\Models\EmployeeCommission::STATUS_PROCESSED]);

            // 3. Mark dynamic overtime as processed in payroll
            \App\Models\EmployeeOvertime::query()
                ->whereIn('payslip_id', $payslipIds)
                ->update(['status' => \App\Models\EmployeeOvertime::STATUS_PROCESSED]);

            $this->audit->edited(
                $run,
                sprintf('%s marked paid — %s to %d employees', $run->periodLabel(), Money::format((int) $run->net_total_minor), (int) $run->employee_count),
                'Human Resources',
                ['status' => PayrollRun::STATUS_APPROVED, 'paid_at' => null],
                ['status' => PayrollRun::STATUS_PAID, 'paid_at' => $paidAt->toIso8601String()],
                $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * NFR-5 — integer arithmetic throughout. Percentages are applied through
     * Money::percentageOf so no float ever touches a payslip.
     *
     * @return array<string, mixed>
     */
    private function breakdownFor(int $grossMinor): array
    {
        $pension = Money::percentageOf($grossMinor, 8);       // employee contribution
        $tax = Money::percentageOf($grossMinor - $pension, 7);

        return [
            'earnings' => [
                ['label' => 'Basic salary', 'amount_minor' => $grossMinor],
            ],
            'deductions' => [
                ['label' => 'Pension (8%)', 'amount_minor' => $pension],
                ['label' => 'PAYE (7% of taxable)', 'amount_minor' => $tax],
            ],
        ];
    }

    /**
     * Gross already paid this year BEFORE $run's own month.
     *
     * A run that syncFromWorkflow() sent back to draft on a rejection is not a
     * payroll that happened, so its payslips must not accumulate into the next
     * month's figure — otherwise a rejected January is paid twice on paper.
     */
    private function yearToDateGrossBefore(Employee $employee, PayrollRun $run): int
    {
        return (int) Payslip::withoutDataScope()
            ->where('employee_id', $employee->getKey())
            ->whereHas('payrollRun', fn ($query) => $query
                ->where('period_year', $run->period_year)
                ->where('period_month', '<', $run->period_month)
                ->countsTowardYearToDate())
            ->sum('gross_minor');
    }
}
