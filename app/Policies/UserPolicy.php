<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for User against `admin.users` (§5.1). */
class UserPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'admin.users';
    }
}
