<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Employee against `hr.employees` (§5.1). */
class EmployeePolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'hr.employees';
    }
}
