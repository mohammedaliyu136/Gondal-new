<?php

namespace App\Http\Controllers;

use App\Authorization\Access;
use App\Exceptions\AccessDeniedException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ARCH-4, layer 2 — every controller that touches a specific record calls
 * `$this->authorizeAccess()` with the record in hand. The route middleware can
 * only check the permission; only the controller knows which record.
 *
 * SCOPE-2 — "plus a policy check on every single-record read and write."
 */
abstract class Controller
{
    /**
     * @throws AccessDeniedException
     */
    protected function authorizeAccess(string $permissionKey, ?Model $record = null, ?string $label = null): void
    {
        app(Access::class)->authorize($this->currentUser(), $permissionKey, $record, $label);
    }

    /**
     * §4 — screens reachable two ways, e.g. `/leave` with hr.leave.view OR
     * hr.leave.own.view.
     *
     * @param  array<int, string>  $permissionKeys
     *
     * @throws AccessDeniedException
     */
    protected function authorizeAnyAccess(array $permissionKeys, ?Model $record = null, ?string $label = null): void
    {
        app(Access::class)->authorizeAny($this->currentUser(), $permissionKeys, $record, $label);
    }

    protected function allows(string $permissionKey, ?Model $record = null): bool
    {
        return app(Access::class)->allows($this->currentUser(), $permissionKey, $record);
    }

    /**
     * The question form of authorizeAnyAccess, for deciding whether to render a
     * control on a screen two different roles reach for different reasons.
     *
     * @param  array<int, string>  $permissionKeys
     */
    protected function allowsAny(array $permissionKeys, ?Model $record = null): bool
    {
        return app(Access::class)->allowsAny($this->currentUser(), $permissionKeys, $record);
    }

    protected function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** NFR-2 — paginate every list, default 25, never unbounded. */
    protected function perPage(?int $requested = null): int
    {
        $default = (int) config('gondal.pagination.per_page', 25);
        $max = (int) config('gondal.pagination.max_per_page', 100);

        return min($max, max(1, $requested ?? $default));
    }
}
