<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for Batch against `milk.batch.dispatch` (§5.1). */
class BatchPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'milk.batch.dispatch';
    }
}
