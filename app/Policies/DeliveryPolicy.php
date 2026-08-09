<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Delivery against `milk.deliveries` (§5.1). */
class DeliveryPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'milk.deliveries';
    }
}
