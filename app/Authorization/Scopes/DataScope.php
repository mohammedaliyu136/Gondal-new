<?php

namespace App\Authorization\Scopes;

use App\Authorization\Scope;
use App\Authorization\ScopeSet;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope as EloquentScope;
use Illuminate\Support\Facades\Auth;

/**
 * SCOPE-2, first layer — "the global scope prevents accidental leakage in lists".
 *
 * Applied to every model implementing App\Contracts\Scopeable. It resolves the
 * signed-in user's scopes for that model's `view` permission and narrows the
 * query to their union (SCOPE-1). SCOPE-4 follows for free: an aggregate built
 * on a scoped query is itself scoped.
 *
 * Enforcement is keyed to there being an authenticated user. Every HTTP route
 * that touches a scopeable model sits behind `auth`, so the only unauthenticated
 * callers are the console, seeders and queue workers — code acting as the system
 * rather than as a person. System code that must be explicit can say so with
 * DataScope::asSystem(), and a screen that legitimately needs the unfiltered set
 * uses Model::withoutGlobalScope(DataScope::class) with a permission check of
 * its own.
 */
class DataScope implements EloquentScope
{
    /** Set while system code is deliberately running unscoped. */
    private static bool $suppressed = false;

    /**
     * Run a callback with the global scope suspended. Used by seeders and by
     * jobs that act for the system, never to work around a denial.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function asSystem(Closure $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$suppressed || ! $model instanceof Scopeable) {
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        // The union across every permission resource that reaches this model, not
        // just its primary one. A model reachable two ways (leave, payslips) would
        // otherwise deny the self-service holder entirely, because an empty scope
        // set fails closed.
        self::constrain($builder, $model, self::scopeSetFor($model, $user), $user);
    }

    /**
     * The union of a user's scopes across every permission resource that reaches
     * this model.
     *
     * Most models name one resource. A model reachable two ways — leave and
     * payslips, which HR reaches through `hr.*` and an employee reaches through
     * the automatic `*.own` grant — must union both, because an empty scope set
     * fails closed. Resolving from the primary key alone gave every ordinary
     * member of staff an empty set, so they could file a leave request and then
     * not see it.
     *
     * Lives here rather than on the trait so both the global scope and the
     * record-level check resolve scope through exactly one implementation.
     */
    public static function scopeSetFor(Scopeable&Model $model, User $user): ScopeSet
    {
        $set = ScopeSet::empty();

        foreach ($model->scopeResourceKeys() as $resourceKey) {
            $set = $set->merge($user->scopeSetFor($resourceKey.'.view'));
        }

        return $set;
    }

    /**
     * Narrow $builder to the union of $scopes. Shared with the policy layer so
     * both layers agree on what "in scope" means (SCOPE-3).
     */
    public static function constrain(Builder $builder, Scopeable&Model $model, ScopeSet $scopes, User $user): void
    {
        // ROLE-2 — absence of a grant is the denial. Nothing is visible.
        if ($scopes->isEmpty()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        // SCOPE-1 — one unrestricted assignment is enough.
        if ($scopes->isNetwork()) {
            return;
        }

        $constraints = $model->scopeConstraints();
        $applicable = $scopes->satisfiable();

        // Every scope held is targeted but has no target, or names a dimension
        // this model cannot express. Fail closed.
        $usable = array_values(array_filter(
            $applicable,
            static fn (Scope $scope) => isset($constraints[$scope->type->value]),
        ));

        if ($usable === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $query) use ($usable, $constraints, $user) {
            foreach ($usable as $scope) {
                $query->orWhere(function (Builder $inner) use ($scope, $constraints, $user) {
                    $targets = $scope->type === ScopeType::Own
                        ? [$user->getKey()]
                        : $scope->targetIds;

                    ($constraints[$scope->type->value])($inner, $targets);
                });
            }
        });
    }
}
