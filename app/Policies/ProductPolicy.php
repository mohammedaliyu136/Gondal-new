<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Product against `shop.inventory` (§5.1). */
class ProductPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'shop.inventory';
    }
}
