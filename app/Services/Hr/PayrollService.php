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
            // Employee::excludingTestData() is the one definition of that
            // population; the register's "Monthly gross" tile reads the same one.
            $employees = Employee::query()
                ->onPayroll()
                ->excludingTestData()
                ->get();

            $gross = 0;
            $deductions = 0;
            $net = 0;

            foreach ($employees as $employee) {
                $employeeGross = (int) $employee->gross_monthly_minor;

                // A real deduction schedule is a Phase 8 detail the PRD does not
                // specify beyond the payslip's breakdown JSON. What matters for
                // the contract is that the arithmetic is integer (NFR-5) and that
                // the breakdown is recorded rather than recomputed on read.
                $breakdown = $this->breakdownFor($employeeGross);
                $employeeDeductions = array_sum(array_column($breakdown['deductions'], 'amount_minor'));
                $employeeNet = $employeeGross - $employeeDeductions;

                Payslip::query()->create([
                    'payroll_run_id' => $run->getKey(),
                    'employee_id' => $employee->getKey(),
                    'reference' => Sequences::next('payslips'),
                    'gross_minor' => $employeeGross,
                    'deductions_minor' => $employeeDeductions,
                    'net_minor' => $employeeNet,
                    'breakdown' => $breakdown,
                    'ytd' => [
                        /*
                         * The month this payslip is FOR has to be added by hand.
                         * The array is built before the insert, so the row the
                         * sum should include does not exist yet — every payslip
                         * understated year-to-date gross by exactly that month,
                         * and January's read zero. It is printed straight to the
                         * employee, who can add their own payslips up.
                         */
                        'gross_minor' => $this->yearToDateGrossBefore($employee, $run) + $employeeGross,
                    ],
                ]);

                $gross += $employeeGross;
                $deductions += $employeeDeductions;
                $net += $employeeNet;
            }

            $run->forceFill([
                'gross_total_minor' => $gross,
                'deductions_total_minor' => $deductions,
                'net_total_minor' => $net,
                'employee_count' => $employees->count(),
            ])->save();

            $this->audit->created(
                $run,
                sprintf(
                    'Payroll run generated for %s — %d employees, %s net',
                    $run->periodLabel(),
                    $employees->count(),
                    Money::format($net),
                ),
                'Human Resources',
                ['rule' => 'BR-35', 'test_accounts_excluded' => true],
                $actor,
            );

            return $run;
        });
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

        $run->forceFill([
            'status' => PayrollRun::STATUS_PAID,
            'paid_at' => $paidAt,
        ])->save();

        $this->audit->edited(
            $run,
            sprintf('%s marked paid — %s to %d employees', $run->periodLabel(), Money::format((int) $run->net_total_minor), (int) $run->employee_count),
            'Human Resources',
            ['status' => PayrollRun::STATUS_APPROVED, 'paid_at' => null],
            ['status' => PayrollRun::STATUS_PAID, 'paid_at' => $paidAt->toIso8601String()],
            $actor,
        );

        return $run->refresh();
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
