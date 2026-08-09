<?php

namespace App\Http\Middleware;

use App\Authorization\Access;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §4 — the permission column of the screen inventory, enforced at the route.
 *
 * Usage:
 *   ->middleware('permission:milk.deliveries.view')          one permission
 *   ->middleware('permission:hr.leave.view|hr.leave.own.view') any of several
 *   ->middleware('permission:purchase.approve.*')            any matching prefix
 *
 * This is layer 1 only (ARCH-4). Record-level scope is checked by the controller
 * through Access::authorize() with the record in hand.
 */
class RequirePermission
{
    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        foreach ($permissions as $specification) {
            // "a|b" means any of a, b.
            $keys = explode('|', $specification);

            $wildcards = array_values(array_filter($keys, static fn (string $key) => str_ends_with($key, '*')));
            $exact = array_values(array_filter($keys, static fn (string $key) => ! str_ends_with($key, '*')));

            foreach ($wildcards as $wildcard) {
                if ($user instanceof User && $user->hasPermissionMatching(rtrim($wildcard, '*'))) {
                    continue 2;
                }
            }

            if ($exact !== []) {
                // Throws a fully populated AccessDeniedException (SCR-1) and
                // writes the blocked-access audit entry (BR-34).
                $this->access->authorizeAny($user, $exact);

                continue;
            }

            $this->access->authorizeAnyMatching($user, rtrim($wildcards[0] ?? '', '*'));
        }

        return $next($request);
    }
}
