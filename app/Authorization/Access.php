<?php

namespace App\Authorization;

use App\Contracts\Scopeable;
use App\Exceptions\AccessDeniedException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ARCH-4 — "Authorisation implemented in two distinct layers: permission check
 * (may this role do X?) and scope check (may this user do X to this record?)."
 *
 * Both layers live here, and the two entry points are deliberately different:
 *
 *   allows()     answers a question. Used by views (`@can`), by nav building
 *                (SCR-2) and by resources deciding whether to include a
 *                sensitive figure (BR-29). Never throws.
 *
 *   authorize()  performs an action's gate. Throws AccessDeniedException, which
 *                carries the missing permission, the reason, the user's scope
 *                and a quotable reference, and which has already been written to
 *                the audit log (BR-34).
 *
 * Keeping them separate is what stops a "show the edit button?" check from
 * filling the audit log with denials that were never attempts.
 */
class Access
{
    public function __construct(private readonly Denials $denials) {}

    /**
     * Layer 1 only: does the user hold this permission at all?
     */
    public function hasPermission(?User $user, string $permissionKey): bool
    {
        return $user?->hasPermission($permissionKey) ?? false;
    }

    /**
     * Both layers, as a question. A record is checked against the scopes tied to
     * the specific action, not to `view` (SCOPE-2).
     */
    public function allows(?User $user, string $permissionKey, ?Model $record = null): bool
    {
        if (! $user instanceof User || ! $user->hasPermission($permissionKey)) {
            return false;
        }

        if ($record === null) {
            // A permission with no record still has to be satisfiable: a role
            // scoped to a point that no longer exists must not pass.
            return ! $user->scopeSetFor($permissionKey)->isEmpty();
        }

        if (! $record instanceof Scopeable) {
            return true;
        }

        return $record->isWithinScopeFor($user, $permissionKey);
    }

    /**
     * True when the user holds ANY of the given permissions. Used by screens
     * reachable two ways, e.g. `/leave` needs `hr.leave.view` OR
     * `hr.leave.own.view` (§4).
     */
    public function allowsAny(?User $user, array $permissionKeys, ?Model $record = null): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->allows($user, $key, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gate. SCOPE-3 — a scope failure and a missing permission produce the
     * same 403 and the same audit entry; only the explanation differs.
     *
     * @throws AccessDeniedException
     */
    public function authorize(?User $user, string $permissionKey, ?Model $record = null, ?string $label = null): void
    {
        // Layer 1 — permission.
        if (! $user instanceof User || ! $user->hasPermission($permissionKey)) {
            throw $this->denials->permission($user, $permissionKey, $label);
        }

        $scopes = $user->scopeSetFor($permissionKey);

        if ($scopes->isEmpty()) {
            throw $this->denials->permission($user, $permissionKey, $label);
        }

        if ($record === null || ! $record instanceof Scopeable) {
            return;
        }

        // Layer 2 — scope. The policy prevents direct-ID access that the global
        // scope on lists would otherwise have hidden.
        if (! $record->isWithinScopeFor($user, $permissionKey)) {
            throw $this->denials->scope($user, $permissionKey, $record, $label, [
                'scope' => $scopes->toArray(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $permissionKeys
     *
     * @throws AccessDeniedException
     */
    public function authorizeAny(?User $user, array $permissionKeys, ?Model $record = null, ?string $label = null): void
    {
        if ($permissionKeys === []) {
            return;
        }

        if ($this->allowsAny($user, $permissionKeys, $record)) {
            return;
        }

        /*
         * Which permission should the denial name, when several would have opened
         * the screen?
         *
         * If the user HOLDS one of them, name that one: the failure is a scope
         * failure, and the page should say "you have this permission, but not for
         * this record" rather than telling them to request a permission they
         * already have.
         *
         * If they hold none, name the first: the failure is a missing permission,
         * and the page gives them one concrete thing to ask an administrator for.
         */
        $held = null;

        foreach ($permissionKeys as $key) {
            if ($this->hasPermission($user, $key)) {
                $held = $key;

                break;
            }
        }

        $this->authorize($user, $held ?? $permissionKeys[0], $record, $label);
    }

    /**
     * §4 — `/approvals` requires "any purchase.approve.*".
     *
     * @throws AccessDeniedException
     */
    public function authorizeAnyMatching(?User $user, string $prefix, ?string $label = null): void
    {
        if ($user instanceof User && $user->hasPermissionMatching($prefix)) {
            return;
        }

        throw $this->denials->permission($user, $prefix.'*', $label);
    }
}
