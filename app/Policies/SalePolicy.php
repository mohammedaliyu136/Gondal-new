<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Sale against `shop.sales` (§5.1). */
class SalePolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'shop.sales';
    }
}
