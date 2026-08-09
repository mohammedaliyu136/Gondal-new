<?php

namespace App\Services\Auth;

use App\Models\FailedSignin;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Support\Wat;
use Illuminate\Http\Request;

/**
 * AUTH-6 — "Failed sign-ins are logged and throttled. 5 failures in 15 minutes
 * locks the account for 30 minutes and notifies the user."
 *
 * NFR-8 — rate limiting is per IP AND per account. The per-IP limiter is the
 * route's own throttle; this class owns the per-account rule, because a lockout
 * has to survive a cache flush and has to be explicable from the audit log.
 */
class SigninThrottle
{
    /**
     * Records the failure, and reports whether THIS attempt is the one that
     * locked the account — so the caller can say "locked" on the triggering
     * attempt instead of the generic message, which used to invite a sixth try.
     */
    public function record(string $email, string $reason, ?User $user, Request $request): bool
    {
        FailedSignin::query()->create([
            'email' => $email,
            'user_id' => $user?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => $reason,
            'occurred_at' => Wat::now(),
        ]);

        if ($user !== null) {
            return $this->lockIfNeeded($user);
        }

        return false;
    }

    public function recentFailureCount(string $email): int
    {
        $window = (int) config('gondal.auth.lockout_window_minutes', 15);

        return FailedSignin::query()
            ->where('email', $email)
            ->where('occurred_at', '>=', Wat::now()->subMinutes($window))
            ->count();
    }

    private function lockIfNeeded(User $user): bool
    {
        $limit = (int) config('gondal.auth.lockout_failures', 5);
        $window = (int) config('gondal.auth.lockout_window_minutes', 15);
        $lockFor = (int) config('gondal.auth.lockout_minutes', 30);

        /*
         * Attempts made WHILE locked are recorded with reason "locked" and are
         * excluded from the count. Counting them meant a user who kept trying
         * through the lock could be re-locked for a further half hour by a single
         * wrong password the moment it expired — the lock felt endless, and the
         * inevitable response is asking a colleague to share an account.
         *
         * stillCounting() is the other exclusion: a SUCCESSFUL sign-in supersedes
         * every failure before it (see clear()). Without that, the count read
         * straight through a correct password — four wrong attempts, a normal
         * sign-in, one typo a moment later, and the "fifth consecutive failure"
         * locked an account whose holder had just demonstrated they knew the
         * password. AUTH-6 is meant to describe somebody guessing, and a run
         * interrupted by a success is not that.
         */
        $failures = FailedSignin::query()
            ->stillCounting()
            ->where('user_id', $user->getKey())
            ->where('reason', '!=', 'locked')
            ->where('occurred_at', '>=', Wat::now()->subMinutes($window))
            ->count();

        if ($failures < $limit || $user->isLocked()) {
            return false;
        }

        $until = Wat::now()->addMinutes($lockFor);
        $user->forceFill(['locked_until' => $until])->save();

        // AUTH-6 — "and notifies the user".
        $user->notify(new AccountLockedNotification($until, $failures));

        return true;
    }

    /**
     * A successful sign-in releases the lock AND settles the failures behind it.
     *
     * The second half is what this method used to claim and never did: its docblock
     * said the window would not carry over, but it only cleared `locked_until` —
     * the LOCK, not the COUNT — so four failures before a good sign-in plus one
     * typo after it still added up to five and cost the user half an hour.
     *
     * The rows are marked, not deleted. AUTH-6's first clause is that failed
     * sign-ins are logged, and an administrator looking at a lockout afterwards
     * needs the addresses, IPs and reasons intact; all that changes is that these
     * particular failures can no longer contribute to a future lock.
     */
    public function clear(User $user): void
    {
        $user->forceFill(['locked_until' => null])->save();

        FailedSignin::query()
            ->stillCounting()
            ->where('user_id', $user->getKey())
            ->update(['superseded_at' => Wat::now()]);
    }
}
