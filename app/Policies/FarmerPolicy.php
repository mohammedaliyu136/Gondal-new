<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Farmer against `community.farmers` (§5.1). */
class FarmerPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'community.farmers';
    }
}
