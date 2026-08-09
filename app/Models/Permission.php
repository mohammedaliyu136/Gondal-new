<?php

namespace App\Models;

use App\Authorization\PermissionKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * §5.1 / PERM-1 — a permission is a ROW. Nothing in the codebase enumerates
 * these; new permissions arrive by migration and seeder.
 *
 * PERM-3 — deleting a permission is forbidden. `retire()` sets retired_at,
 * which hides it from the matrix while preserving every historical grant.
 */
class Permission extends Model
{
    protected $fillable = [
        'resource_key', 'action', 'label', 'description',
        'is_sensitive', 'position',
    ];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function getKeyStringAttribute(): string
    {
        return $this->resource_key.'.'.$this->action;
    }

    public function key(): PermissionKey
    {
        return PermissionKey::make($this->resource_key, $this->action);
    }

    public function module(): string
    {
        return $this->key()->module();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * PERM-3 — the only legal way to remove a permission from circulation.
     */
    public function retire(string $reason): void
    {
        $this->forceFill([
            'retired_at' => now(),
            'retired_reason' => $reason,
        ])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill(['retired_at' => null, 'retired_reason' => null])->save();
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    /** PERM-3 — the matrix shows live permissions only. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }

    /** PERM-2 / G-6 */
    public function scopeSensitive(Builder $query): Builder
    {
        return $query->where('is_sensitive', true);
    }

    /**
     * The matrix in role-detail.html is grouped by resource, with `—` where an
     * action does not apply to that resource (§5.1).
     *
     * @return Collection<string, Collection<int, self>>
     */
    public static function matrix(): Collection
    {
        return static::query()
            ->live()
            ->orderBy('position')
            ->orderBy('resource_key')
            ->get()
            ->groupBy('resource_key');
    }
}
