<?php

namespace App\Models\Concerns;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeSet;
use App\Contracts\Scopeable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SCOPE-2 — bolts the global scope onto a Scopeable model and provides the two
 * escapes a screen legitimately needs:
 *
 *   withoutDataScope()          the unfiltered query, for a caller that has
 *                               already established network-wide rights
 *   inScopeFor($user, $action)  the query narrowed by a specific action's scope
 *                               rather than the model's `view` scope
 */
trait AppliesDataScope
{
    public static function bootAppliesDataScope(): void
    {
        static::addGlobalScope(new DataScope);
    }

    /**
     * SCOPE-4 — the caller must hold `milk.totals.network.view` (or the module's
     * equivalent) before using this. It is never a workaround for a denial.
     */
    public static function withoutDataScope(): Builder
    {
        return static::query()->withoutGlobalScope(DataScope::class);
    }

    /**
     * Most models are reachable through exactly one permission resource, so the
     * default is that one key. A model reachable two ways overrides this — see the
     * contract for why a single key does not merely under-report but denies.
     *
     * @return array<int, string>
     */
    public function scopeResourceKeys(): array
    {
        return [$this->scopeResourceKey()];
    }

    /**
     * The record-level counterpart of the global scope: narrow by the scopes
     * attached to a specific PERMISSION, which is not always this model's own.
     */
    public function scopeInScopeFor(Builder $query, User $user, ?string $permissionKey = null): Builder
    {
        /** @var Scopeable&Model $model */
        $model = $query->getModel();

        $query->withoutGlobalScope(DataScope::class);

        DataScope::constrain(
            $query,
            $model,
            $permissionKey === null
                ? DataScope::scopeSetFor($model, $user)
                : $user->scopeSetFor($permissionKey),
            $user,
        );

        return $query;
    }

    /**
     * SCOPE-2, second layer — does this exact record fall inside the user's scope
     * for the permission being exercised? Answered with a keyed re-query, so a
     * direct-ID fetch cannot dodge what the list filter would have hidden.
     *
     * The permission is passed in rather than derived from this model, because the
     * two are often different resources. Recording a delivery is
     * `milk.deliveries.create`, but the record whose scope must admit it is a
     * COLLECTION POINT. Deriving `milk.points.create` from the model would ask the
     * wrong question — and, since a Collection Agent is not granted that, would
     * refuse work the role plainly exists to do. The scope that matters is always
     * the scope attached to the permission the user is exercising; the model only
     * says how each scope TYPE narrows it.
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
            ->withoutGlobalScope(DataScope::class)
            ->whereKey($this->getKey())
            ->tap(fn (Builder $query) => DataScope::constrain($query, $this, $scopes, $user))
            ->exists();
    }

    /**
     * BR-35 / TEST-1 — "Test accounts are excluded from every report, every
     * aggregate, and payroll." Reporting queries opt in explicitly rather than
     * having test rows hidden globally, because a test user must still be able
     * to see their own work while a run is in progress (TEST-2).
     */
    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.is_test', false);
    }

    /** Convenience alias used throughout the reporting services. */
    public function scopeNotTest(Builder $query): Builder
    {
        return $this->scopeExcludingTestData($query);
    }

    /**
     * SCR-1 / SCOPE-3 — route-model binding deliberately IGNORES the data scope.
     *
     * If binding respected it, typing another center's id into the URL would give a
     * bare 404: technically safe, but it tells the user nothing and writes no audit
     * entry. SCOPE-3 requires the same populated 403 as a missing permission, and
     * BR-34 requires the attempt to be logged. So the record is resolved here and
     * the controller's Access::authorize() call decides — which is also what makes
     * SCOPE-2's "the policy prevents direct-ID access" the thing actually doing the
     * work, rather than an accident of query filtering.
     *
     * Soft deletes still apply, so a deleted record is a 404 as it should be.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::query()
            ->withoutGlobalScope(DataScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        return static::query()
            ->withoutGlobalScope(DataScope::class)
            ->withTrashed()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function scopeSetForCurrentUser(string $action = 'view'): ScopeSet
    {
        $user = auth()->user();

        return $user instanceof User
            ? $user->scopeSetFor($this->scopeResourceKey().'.'.$action)
            : ScopeSet::network();
    }
}
