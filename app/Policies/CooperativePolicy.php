<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Cooperative against `community.cooperatives` (§5.1). */
class CooperativePolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'community.cooperatives';
    }
}
