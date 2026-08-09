<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for ProductCategory against `shop.categories` (§5.1). */
class ProductCategoryPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'shop.categories';
    }
}
