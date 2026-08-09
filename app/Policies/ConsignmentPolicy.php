<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Consignment against `milk.consignment.confirm` (§5.1). */
class ConsignmentPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'milk.consignment.confirm';
    }
}
