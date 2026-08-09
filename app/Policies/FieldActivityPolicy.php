<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for FieldActivity against `community.extension` (§5.1). */
class FieldActivityPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'community.extension';
    }
}
