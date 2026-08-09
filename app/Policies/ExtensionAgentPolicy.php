<?php

namespace App\Policies;

/** SCOPE-2 — record-level checks for ExtensionAgent against `community.extension` (§5.1). */
class ExtensionAgentPolicy extends BasePolicy
{
    protected function resourceKey(): string
    {
        return 'community.extension';
    }
}
