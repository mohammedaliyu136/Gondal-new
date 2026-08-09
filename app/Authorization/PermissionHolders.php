<?php

namespace App\Authorization;

use App\Models\User;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Which active users hold this permission?" — asked once, in SQL.
 *
 * PERM-1 makes a permission a database row, so the only honest answer is a join.
 * The answer was being computed by hydrating every active user and calling
 * hasPermission() on each: /collection-points cost 159 queries to render, 113 of
 * them existing solely to filter one dropdown, and the bill grows with every
 * hire. NotificationService::usersWithPermission() arrived at the same shape
 * independently, which is what a missing shared query looks like.
 *
 * The predicates below are the ones User::effectiveRoles() and
 * effectivePermissionKeys() already apply, moved into one statement:
 *
 *   ROLE-2  an assignment counts only while it is live — not soft-deleted, not
 *           past its valid_until — and only while its role is active
 *   ROLE-3  the automatic role is held by everyone with or without a role_user
 *           row, so a key it carries makes every active user a holder
 *   PERM-3  a retired permission grants nothing, though its history survives
 *
 * ARCH-4 layer 1 ONLY. "May this holder do it to THIS record?" is a scope
 * question and the caller still has to ask it with the record in hand.
 */
class PermissionHolders
{
    /**
     * Active holders of $permissionKey, as a builder so the caller keeps its own
     * ordering, columns and pagination.
     */
    public function query(string $permissionKey): Builder
    {
        $key = PermissionKey::tryParse($permissionKey);

        /*
         * A key that is not shaped like one is held by nobody. Failing closed
         * matters here: returning every active user would turn a typo in a
         * permission name into a silent grant on whatever screen asked.
         */
        if ($key === null) {
            return User::query()->whereRaw('1 = 0');
        }

        $holders = User::query()->where('users.status', 'active');

        if ($this->grantedByTheAutomaticRole($key)) {
            return $holders;
        }

        return $holders->whereExists(fn ($query) => $query
            ->from('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereColumn('role_user.user_id', 'users.id')
            ->whereNull('role_user.deleted_at')
            ->where(fn ($inner) => $inner
                ->whereNull('role_user.valid_until')
                ->orWhere('role_user.valid_until', '>', Wat::now()))
            ->whereNull('roles.deleted_at')
            ->where('roles.status', 'active')
            ->whereNull('permissions.retired_at')
            ->where('permissions.resource_key', $key->resourceKey)
            ->where('permissions.action', $key->action));
    }

    /** ROLE-3 — held by every account, so it short-circuits the whole join. */
    private function grantedByTheAutomaticRole(PermissionKey $key): bool
    {
        return DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.is_automatic', true)
            ->where('roles.status', 'active')
            ->whereNull('roles.deleted_at')
            ->whereNull('permissions.retired_at')
            ->where('permissions.resource_key', $key->resourceKey)
            ->where('permissions.action', $key->action)
            ->exists();
    }
}
