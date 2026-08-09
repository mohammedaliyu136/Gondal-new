<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Role against `admin.roles` (§5.1). */
class RolePolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'admin.roles';
    }
}
