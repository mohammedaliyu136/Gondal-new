<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for CollectionPoint against `milk.points` (§5.1). */
class CollectionPointPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'milk.points';
    }
}
