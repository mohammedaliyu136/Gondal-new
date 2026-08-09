<?php

namespace App\Models;

use App\Authorization\ScopeType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * SCOPE-1 — the role_user row. It is a first-class record rather than a bare
 * pivot because it carries the data scope, and because the `communities` scope
 * type needs child rows (role_user_scope_targets).
 */
class RoleAssignment extends Pivot
{
    use SoftDeletes;

    protected $table = 'role_user';

    public $incrementing = true;

    protected $fillable = [
        'role_id', 'user_id', 'scope_type', 'scope_target_id',
        'assigned_by_user_id', 'assigned_at', 'valid_until',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'valid_until' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function scopeTargets(): HasMany
    {
        return $this->hasMany(RoleUserScopeTarget::class, 'role_user_id');
    }

    public function scopeType(): ScopeType
    {
        return ScopeType::tryFrom((string) $this->scope_type) ?? ScopeType::Network;
    }

    /**
     * @return array<int, int>
     */
    public function targetIds(): array
    {
        // The union of both places a target can be held — see ScopeType::hasManyTargets().
        $ids = $this->scopeTargets()->pluck('target_id')->map(fn ($id) => (int) $id)->all();

        if ($this->scope_target_id !== null) {
            $ids[] = (int) $this->scope_target_id;
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /** ROLE-2 — a time-boxed assignment that has run out grants nothing. */
    public function hasExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** SCR-1 — "Kumbotso Center only". */
    public function describeScope(): string
    {
        $type = $this->scopeType();

        if (! $type->requiresTarget()) {
            return $type->label();
        }

        $table = $type->targetTable();
        $ids = $this->targetIds();

        if ($table === null || $ids === []) {
            return $type->label().' — no target set';
        }

        $names = DB::table($table)->whereIn('id', $ids)->pluck('name')->all();

        return $names === [] ? $type->label().' — target missing' : implode(', ', $names);
    }
}
