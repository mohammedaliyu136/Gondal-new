<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * SCOPE-2 — "Scope is enforced with Eloquent global scopes on every scopeable
 * model, plus a policy check on every single-record read and write."
 *
 * A model that implements this contract declares two things:
 *
 *  1. Which permission resource its scope is resolved from. A user's scope is
 *     per-permission (ROLE-2 unions the roles that grant it), so the model must
 *     say which permission the query layer should ask about.
 *
 *  2. How each scope type narrows a query on it. `center` means something
 *     different for a Consignment (a column) than for a Delivery (a join
 *     through its collection point), and only the model knows which.
 */
interface Scopeable
{
    /**
     * The resource key whose `view` grant governs list access to this model,
     * e.g. 'milk.deliveries'.
     */
    public function scopeResourceKey(): string;

    /**
     * Every permission resource through which this model can legitimately be
     * reached, widest first.
     *
     * Most models have one. A few are reachable two ways, and for those a single
     * key is not merely incomplete — it denies. Leave is the case that proved it:
     * `hr.leave` is held by HR and by line managers, while an ordinary member of
     * staff holds the separate `hr.leave.own`. Resolving the list scope from
     * `hr.leave.view` alone gave every ordinary employee an empty scope set, and
     * because an empty set fails closed (ROLE-2, correctly), they could file a
     * leave request and then not see it. Self-service was invisible to the people
     * it exists for.
     *
     * The effective scope is the UNION across the keys the user actually holds, so
     * an HR officer who is also an employee sees the department's requests and
     * their own, and someone holding neither still sees nothing.
     *
     * @return array<int, string>
     */
    public function scopeResourceKeys(): array;

    /**
     * Map of ScopeType value => callable(Builder $query, array<int,int> $targetIds): void
     *
     * A scope type absent from the map cannot admit any record of this model,
     * and so denies — never falls open.
     *
     * @return array<string, callable(Builder, array<int, int>): void>
     */
    public function scopeConstraints(): array;
}
