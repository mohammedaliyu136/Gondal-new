<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-32 — "Deactivating a user blocks sign-in and revokes sessions but preserves
 *   all attribution on their historical records." A session that outlives the
 *   deactivation is ended here on the next request.
 * BR-33 — a session the register says was revoked stops working here too. This
 *   is the middleware every authenticated request passes through on both
 *   surfaces, which is why the check lives here rather than in `session.touch`:
 *   the API group does not carry that one.
 * AUTH-5 — a password past its maximum age must be changed before anything else.
 * AUTH-6 — a locked account cannot continue mid-session either.
 */
class EnsureAccountIsUsable
{
    /** Routes a user with an expired password may still reach. */
    private const ALWAYS_ALLOWED = [
        'password.change',
        'password.change.store',
        'auth.signout',
        'profile',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        /*
         * ROLE-6 — "Editing a role takes effect on the assigned users' NEXT
         * REQUEST. No re-login required."
         *
         * Permission and scope resolution is memoised on the User instance for the
         * life of one request, which is what keeps a page from re-running the same
         * joins a dozen times. Dropping the memo here guarantees the promise holds
         * even where the instance outlives the request — a long-lived worker, an
         * Octane container, or a test that reuses the object.
         */
        $user->forgetAccessMemo();

        if (! $user->isActive() || $user->isLocked()) {
            $reason = $user->isLocked() ? 'locked' : 'deactivated';

            app(SessionRegistry::class)->endAllFor($user, $reason);

            $message = $user->isLocked()
                ? 'This account is temporarily locked. Try again later or contact IT Support.'
                : 'This account has been deactivated. Contact your system administrator.';

            /*
             * ARCH-2 — the same rule, two surfaces. A phone has nowhere to be
             * redirected to, so BR-32 lands there as a 403 with the same sentence
             * and the token is revoked instead: a deactivated user must not stay
             * signed in on a device either.
             *
             * The browser session is torn down WHATEVER the surface. Deciding
             * that from the caller's own Authorization header used to skip these
             * three lines whenever a browser request happened to carry one,
             * leaving a live http_sessions row for a deactivated account and
             * showing the user raw JSON instead of BR-32's explanation.
             */
            if ($this->isTokenRequest($request)) {
                app(ApiTokenService::class)->revokeAllFor($user, $reason);
            }

            $this->tearDownSession($request);

            if ($this->isTokenRequest($request)) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        /*
         * BR-32 / BR-33 — the session register is authoritative about whether
         * this session may continue. Ending a row is what "sign out other
         * sessions", the administrator's "sign out everywhere" and a password
         * reset all do; reading it here is what makes any of them mean anything.
         */
        if ($request->hasSession() && app(SessionRegistry::class)->isCurrentRevoked($user, $request)) {
            $message = 'This session was signed out. Sign in again to continue.';

            $this->tearDownSession($request);

            if ($this->isTokenRequest($request)) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        // AUTH-5 — maximum password age of 90 days.
        if ($user->passwordHasExpired() && ! in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            $message = 'Your password is over '.config('gondal.auth.password_max_age_days').' days old and must be changed.';

            /*
             * The phone cannot change a password — AUTH-5's change screen is a
             * web flow, and putting one on a field device would mean handling
             * password entry and history checks on the least trusted surface.
             * So the API says so plainly, with a code the client can branch on,
             * rather than redirecting into HTML it cannot render.
             */
            if ($this->isTokenRequest($request)) {
                return response()->json([
                    'message' => $message.' Sign in on the web to change it.',
                    'code' => 'password_expired',
                ], 403);
            }

            return redirect()->route('password.change')->with('status', $message);
        }

        return $next($request);
    }

    /** Dismantles the browser session, if this request has one at all. */
    private function tearDownSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * True when the caller is a mobile client on the token guard rather than a
     * browser on the session guard.
     *
     * Asked of the guard that actually authenticated the request, not of a header
     * the client controls: `auth:api` calls Auth::shouldUse('api'), so the default
     * driver is the fact. The old test — "carries a bearer header" — fired on any
     * browser request that happened to send one, and the response shape is not
     * something a caller should be able to choose.
     */
    private function isTokenRequest(Request $request): bool
    {
        return auth()->getDefaultDriver() === 'api' || ! $request->hasSession();
    }
}
