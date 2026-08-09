<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §6.1 `sessions` — keeps the auditable session register current so the profile
 * screen can list active sessions and BR-33 can revoke "all OTHER sessions"
 * meaningfully.
 */
class TouchAuthSession
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->sessions->touch($user, $request);
        }

        return $next($request);
    }
}
