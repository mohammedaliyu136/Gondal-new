<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Requisition against `purchase.requisitions` (§5.1). */
class RequisitionPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'purchase.requisitions';
    }
}
