<?php

namespace App\Authorization;

use Stringable;

/**
 * §5.1 — a permission key is `{resource}.{action}` where action is one of
 * view, create, edit, delete, approve. The resource part may itself contain
 * dots (`milk.consignment.confirm`, `purchase.approve.depthead`), so the action
 * is always the final segment.
 */
final class PermissionKey implements Stringable
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'approve'];

    private function __construct(
        public readonly string $resourceKey,
        public readonly string $action,
    ) {}

    public static function make(string $resourceKey, string $action): self
    {
        return new self($resourceKey, $action);
    }

    /**
     * Returns null when $key is not shaped like a permission — which is how
     * Gate abilities such as "view" or "manageRoster" fall through to policies.
     */
    public static function tryParse(string $key): ?self
    {
        $position = strrpos($key, '.');

        if ($position === false) {
            return null;
        }

        $action = substr($key, $position + 1);
        $resourceKey = substr($key, 0, $position);

        if ($resourceKey === '' || ! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        return new self($resourceKey, $action);
    }

    public static function looksLikeOne(string $key): bool
    {
        return self::tryParse($key) !== null;
    }

    /**
     * The module a key belongs to, used for audit entries and nav grouping.
     */
    public function module(): string
    {
        return match (explode('.', $this->resourceKey)[0]) {
            'milk' => 'Milk Collection',
            'logistics' => 'Logistics',
            'purchase' => 'Purchases',
            'community' => 'Community Engagement',
            'shop' => 'One-Stop Shop',
            'hr' => 'Human Resources',
            'admin' => 'Administration',
            default => 'System',
        };
    }

    public function __toString(): string
    {
        return $this->resourceKey.'.'.$this->action;
    }
}
