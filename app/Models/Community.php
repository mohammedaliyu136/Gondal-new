<?php

namespace App\Models;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §9 — communities (wards) are reference data, and the target of the
 * `communities` SCOPE-1 scope type.
 *
 * ARCH-4 — this record is the SUBJECT of a layer-2 check, not merely a scope
 * target. Enrolling a farmer and logging a field visit are both authorised
 * against the community they happen in, and Access::authorize() returns early
 * for anything that is not Scopeable — so while this was a plain Model, every
 * one of those checks silently degraded to layer 1. An Extension Agent scoped to
 * four communities could enrol into a fifth from the phone or the web, and the
 * comment above the call said they could not.
 */
class Community extends Model implements Scopeable
{
    use SoftDeletes;

    protected $fillable = ['lga_id', 'name'];

    public function scopeResourceKey(): string
    {
        return 'community.farmers';
    }

    /** @return array<int, string> */
    public function scopeResourceKeys(): array
    {
        return [$this->scopeResourceKey()];
    }

    /**
     * Every dimension a community can be reached through. `point` and `center`
     * matter as much as `communities` does: a Collection Agent holds
     * community.farmers.create with `point` scope, so omitting them would refuse
     * the enrolment their role exists to perform.
     *
     * `own` is deliberately absent. A community has no creator and belongs to
     * nobody, so there is no honest constraint to write — and per the Scopeable
     * contract an absent type denies rather than falling open, which is the right
     * answer to "may I enrol into my own community?" when nothing records which
     * one that is.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereIn('communities.id', $ids),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereIn('communities.lga_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoints',
                fn (Builder $inner) => $inner->whereIn('collection_points.id', $ids),
            ),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoints',
                fn (Builder $inner) => $inner->whereIn('collection_points.collection_center_id', $ids),
            ),
        ];
    }

    /**
     * SCOPE-2's second layer only — this model deliberately does NOT carry the
     * DataScope global scope that AppliesDataScope would bolt on.
     *
     * Communities are §9 reference data and appear as a picker on screens the
     * holder reaches through a different permission entirely: /communities opens
     * on `community.farmers.view` OR `milk.points.view`, and the collection-point
     * and user-administration forms both list them. Resolving a list filter from
     * `community.farmers.view` would empty every one of those pickers for a
     * milk-side or administrative user, which is a new fault in place of the old
     * one. What was missing was never the list filter; it was the record check.
     */
    public function isWithinScopeFor(User $user, string $permissionKey): bool
    {
        $scopes = $user->scopeSetFor($permissionKey);

        if ($scopes->isEmpty()) {
            return false;
        }

        if ($scopes->isNetwork()) {
            return true;
        }

        return static::query()
            ->whereKey($this->getKey())
            ->tap(fn (Builder $query) => DataScope::constrain($query, $this, $scopes, $user))
            ->exists();
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function collectionPoints(): HasMany
    {
        return $this->hasMany(CollectionPoint::class);
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class);
    }

    public function cooperatives(): HasMany
    {
        return $this->hasMany(Cooperative::class);
    }

    public function extensionAgents(): BelongsToMany
    {
        return $this->belongsToMany(ExtensionAgent::class, 'agent_community')
            ->withPivot('assigned_at');
    }
}
