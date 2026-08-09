<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for CollectionCenter against `milk.points` (§5.1). */
class CollectionCenterPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'milk.points';
    }
}
