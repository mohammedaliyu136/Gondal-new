<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §5.2 — "A role is a name, description, data-scope type, status, and a set of
 * granted permissions. Nothing more."
 *
 * ROLE-1 — there is no hierarchy and no inheritance in v1. Do not add one
 *          without revisiting §5.2; the previous system's failure was exactly
 *          this kind of implicit widening.
 * ROLE-7 — a role with assigned users cannot be deleted, only disabled.
 */
class Role extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'name', 'description', 'scope_type', 'status', 'accent', 'created_by_user_id',
        // §16 — the persona reference, carried with the grant set it describes.
        'responsibilities', 'restrictions', 'mobile_home',
    ];

    protected function casts(): array
    {
        return [
            'is_automatic' => 'boolean',
            'retired_at' => 'datetime',
            'permissions_changed_at' => 'datetime',
            'responsibilities' => 'array',
            'restrictions' => 'array',
        ];
    }

    /* ---------------------------------------------------------------------
     | §16 — persona
     * ------------------------------------------------------------------ */

    /**
     * The "Their day" list from personas.html. Empty for a role nobody has
     * described yet (ROLE-5, Farm Manager) — the caller shows nothing rather
     * than inventing a job.
     *
     * @return array<int, string>
     */
    public function responsibilityList(): array
    {
        return array_values(array_filter((array) ($this->responsibilities ?? [])));
    }

    /**
     * The "Cannot see" list. This is the half of a role that a permission
     * matrix cannot state: absence of a grant is silent, and a boundary nobody
     * can read is a boundary nobody reviews.
     *
     * @return array<int, string>
     */
    public function restrictionList(): array
    {
        return array_values(array_filter((array) ($this->restrictions ?? [])));
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withPivot('granted_by_user_id')
            ->withTimestamps();
    }

    /** PERM-3 — the grants that still count. */
    public function livePermissions(): BelongsToMany
    {
        return $this->permissions()->whereNull('permissions.retired_at');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->using(RoleAssignment::class)
            ->withPivot(['id', 'scope_type', 'scope_target_id', 'assigned_by_user_id', 'assigned_at', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function lastPassingTestRun(): BelongsTo
    {
        return $this->belongsTo(PermissionTestRun::class, 'last_passing_test_run_id');
    }

    public function defaultScopeType(): ScopeType
    {
        return ScopeType::tryFrom((string) $this->scope_type) ?? ScopeType::Network;
    }

    /* ---------------------------------------------------------------------
     | Status
     * ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRetired(): bool
    {
        return $this->status === self::STATUS_RETIRED;
    }

    /** ROLE-5 — Farm Manager is seeded draft with zero permissions (§15.2). */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * ROLE-7 — "A role with assigned users cannot be deleted, only disabled."
     *
     * @throws RuleViolationException
     */
    public function guardAgainstDeletion(): void
    {
        $assigned = $this->users()->count();

        if ($assigned > 0) {
            throw RuleViolationException::make(
                'ROLE-7',
                "{$this->name} is assigned to {$assigned} user(s) and cannot be deleted. Disable it instead.",
                ['role' => $this->name, 'assigned_users' => $assigned],
            );
        }
    }

    /** PERM-2 — the "Sensitive Access" counter on role-detail.html. */
    public function sensitivePermissionCount(): int
    {
        return $this->livePermissions()->where('permissions.is_sensitive', true)->count();
    }

    /** TEST-5 — has the current grant set been validated by a passing run? */
    public function hasUnvalidatedChanges(): bool
    {
        if ($this->permissions_changed_at === null) {
            return false;
        }

        $run = $this->lastPassingTestRun;

        return $run === null
            || $run->completed_at === null
            || $run->completed_at->lessThan($this->permissions_changed_at);
    }

    /** TEST-5 — the count that makes the warning concrete. */
    public function liveUserCount(): int
    {
        return $this->users()->where('users.is_test', false)->where('users.status', 'active')->count();
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** ROLE-4 — retired roles stay visible on roles.html to preserve the trail. */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RETIRED);
    }

    public function scopeAssignable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_DISABLED]);
    }
}
