<?php

namespace App\Authorization;

/**
 * SCOPE-1 — the scope vocabulary from PRD §5.3.
 *
 * This is part of the authorisation *engine*, not operational reference data
 * (§9). Adding a scope type means teaching every scopeable model how to
 * constrain itself, which is code — so an enum is correct here.
 */
enum ScopeType: string
{
    case Point = 'point';
    case Center = 'center';
    case Lga = 'lga';
    case Department = 'department';
    case Communities = 'communities';
    case Own = 'own';
    case Network = 'network';

    public function label(): string
    {
        return match ($this) {
            self::Point => 'Collection point',
            self::Center => 'Collection center',
            self::Lga => 'LGA',
            self::Department => 'Department',
            self::Communities => 'Communities',
            self::Own => 'Own records only',
            self::Network => 'Network-wide',
        };
    }

    /** Does this scope type need a target to be meaningful? */
    public function requiresTarget(): bool
    {
        return match ($this) {
            self::Own, self::Network => false,
            default => true,
        };
    }

    /**
     * Does the target hold a list rather than a single id?
     *
     * Every targeted type does. A supervisor who covers two centres, or an
     * officer who covers three points, is an ordinary fact of the network rather
     * than an exception, and the alternative — one assignment of the same role
     * per centre — makes the role list unreadable and the revocation of one
     * centre easy to miss.
     *
     * The storage is a union of two places, and both are read on the way out:
     * a single target stays in `role_user.scope_target_id`, exactly where it has
     * always been, and a list lives in `role_user_scope_targets`. Existing
     * assignments therefore need no migration and behave identically.
     */
    public function hasManyTargets(): bool
    {
        return $this->requiresTarget();
    }

    /** The table a single target id points at, for label resolution. */
    public function targetTable(): ?string
    {
        return match ($this) {
            self::Point => 'collection_points',
            self::Center => 'collection_centers',
            self::Lga => 'lgas',
            self::Department => 'departments',
            self::Communities => 'communities',
            default => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
