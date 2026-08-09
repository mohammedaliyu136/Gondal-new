<?php

namespace App\Services\Auth;

use App\Models\AuthSession;
use App\Models\Device;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\Request;

/**
 * §6.1 `sessions` — the auditable session register.
 *
 * BR-32 deactivating a user revokes sessions
 * BR-33 changing a password revokes all OTHER sessions
 * AUTH-2 an administrator can revoke a trusted device
 *
 * Which register row belongs to the current request is tracked by putting the
 * row's id INTO the session, not by matching on the framework's session id. That
 * id is not a stable handle — it is deliberately rotated on sign-in and by other
 * security middleware — so matching on it stops working exactly when it matters:
 * at sign-out, and when BR-33 has to decide which session to spare. The row id,
 * carried in the session, survives every rotation.
 */
class SessionRegistry
{
    /** The session key holding the current register row's id. */
    private const KEY = 'auth.register_id';

    public function start(User $user, Request $request, ?Device $device = null): AuthSession
    {
        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device?->getKey(),
            'http_session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'started_at' => Wat::now(),
            'last_seen_at' => Wat::now(),
        ]);

        if ($request->hasSession()) {
            $request->session()->put(self::KEY, $session->getKey());
        }

        return $session;
    }

    public function touch(User $user, Request $request): void
    {
        $current = $this->current($user, $request);

        if ($current === null) {
            return;
        }

        $current->forceFill([
            'last_seen_at' => Wat::now(),
            // The framework rotates the session id; keep the register in step so an
            // administrator reading it sees the live value.
            'http_session_id' => $request->hasSession()
                ? $request->session()->getId()
                : $current->http_session_id,
        ])->save();
    }

    public function endCurrent(User $user, Request $request, string $reason = 'signout'): void
    {
        $this->current($user, $request)?->end($reason);

        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }

    /** BR-32 */
    public function endAllFor(User $user, string $reason): int
    {
        return AuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => Wat::now(), 'ended_reason' => $reason]);
    }

    /**
     * BR-33 — "Changing a password revokes all other sessions."
     *
     * The framework side of this is Auth::logoutOtherDevices(); the register is
     * updated here so the profile screen and the audit trail agree.
     */
    public function endOthersFor(User $user, Request $request, string $reason = 'password_change'): int
    {
        $query = AuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at');

        $current = $this->current($user, $request);

        /*
         * If the current session cannot be identified, END EVERY OPEN ROW rather
         * than guessing which one to spare. BR-33's purpose is that the other
         * sessions die; erring towards revoking one too many costs the user a
         * sign-in, while erring the other way leaves a session alive that the rule
         * says should be gone. The person doing this has just proved they know the
         * current password, so an extra sign-in is the cheaper mistake.
         */
        if ($current !== null) {
            $query->whereKeyNot($current->getKey());
        }

        return $query->update(['ended_at' => Wat::now(), 'ended_reason' => $reason]);
    }

    /**
     * BR-32 / BR-33 — has this request's own register row been ended?
     *
     * endAllFor() and endOthersFor() write `ended_at` and nothing else. Until
     * something ASKS, that column is a note in a ledger: "sign out other
     * sessions" and the administrator's "sign out everywhere" both reported a
     * count and left every one of those sessions able to keep working, because no
     * request path ever read the row back. EnsureAccountIsUsable asks on every
     * authenticated request, which is what turns the ledger entry into a
     * revocation.
     *
     * Answers false when the row cannot be identified. A session with no register
     * row has not been revoked — it was never registered — and logging those out
     * would sign out everybody whose session predates this table.
     */
    public function isCurrentRevoked(User $user, Request $request): bool
    {
        return $this->current($user, $request)?->ended_at !== null;
    }

    /**
     * The register row for this request.
     *
     * The session's own record of the row id is authoritative. Failing that, a
     * match on the framework session id covers a row written before the id was
     * stored. There is deliberately NO third guess: returning "probably the latest
     * open row" would let endOthersFor() spare a session that is not the caller's,
     * which is the one mistake BR-33 must not make.
     */
    private function current(User $user, Request $request): ?AuthSession
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::KEY);

        if ($id !== null) {
            $row = AuthSession::query()
                ->where('user_id', $user->getKey())
                ->whereKey($id)
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        return AuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->where('http_session_id', $request->session()->getId())
            ->latest('id')
            ->first();
    }
}
