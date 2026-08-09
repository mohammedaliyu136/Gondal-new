<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Payslip against `hr.payroll` (§5.1). */
class PayslipPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'hr.payroll';
    }
}
