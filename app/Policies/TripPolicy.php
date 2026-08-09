<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Trip against `logistics.trips` (§5.1). */
class TripPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'logistics.trips';
    }
}
