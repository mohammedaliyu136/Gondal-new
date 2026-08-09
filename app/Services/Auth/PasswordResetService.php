<?php

namespace App\Services\Auth;

use App\Models\AuditEntry;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-4 — "Password reset: email → 6-digit code (15-minute expiry) → new
 * password. Reset revokes all sessions."
 *
 * BR-31 — the administrator never sees or sets the password. This flow is the
 * only way one is chosen, whether the account is new or the user forgot theirs.
 */
class PasswordResetService
{
    public const SESSION_RESET_USER = 'auth.reset_user_id';

    public const SESSION_RESET_VERIFIED = 'auth.reset_verified';

    public function __construct(
        private readonly LoginCodeService $codes,
        private readonly PasswordPolicy $policy,
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Always reports success to the caller. Telling an anonymous visitor whether
     * an address exists would make this form a staff directory.
     */
    public function requestCode(Request $request, string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->isActive()) {
            return;
        }

        $this->codes->issue($user, LoginCode::PURPOSE_RESET, $request->ip());

        $request->session()->put(self::SESSION_RESET_USER, $user->getKey());
        $request->session()->forget(self::SESSION_RESET_VERIFIED);
    }

    public function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_RESET_USER);

        return $id === null ? null : User::query()->find($id);
    }

    public function verifyCode(Request $request, string $code): bool
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return false;
        }

        $result = $this->codes->verify($user, LoginCode::PURPOSE_RESET, $code);

        if (! $result['ok']) {
            $this->audit->failedSignin($user->email, 'bad_reset_code:'.($result['reason'] ?? 'unknown'), $user);

            return false;
        }

        $request->session()->put(self::SESSION_RESET_VERIFIED, true);

        return true;
    }

    public function isVerified(Request $request): bool
    {
        return $request->session()->get(self::SESSION_RESET_VERIFIED) === true
            && $this->pendingUser($request) !== null;
    }

    /**
     * AUTH-5 — the reuse check happens here, because the validation rules cannot
     * see the user's history.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function resetPassword(Request $request, string $plain): array
    {
        $user = $this->pendingUser($request);

        if ($user === null || ! $this->isVerified($request)) {
            return ['ok' => false, 'reason' => 'not_verified'];
        }

        if ($this->policy->isReused($user, $plain)) {
            return ['ok' => false, 'reason' => 'reused'];
        }

        $this->policy->apply($user, $plain);

        /*
         * A completed reset clears any lockout. The emailed code proves control
         * of the mailbox, which is stronger evidence of identity than the lock
         * protects against — and without this, a locked-out user could complete
         * the whole reset, be told "sign in with it", and bounce off the lock
         * with the correct new password.
         */
        if ($user->isLocked()) {
            $user->forceFill(['locked_until' => null])->save();
        }

        /*
         * AUTH-4 / BR-33 — a reset revokes ALL sessions, including any the
         * attacker may hold.
         *
         * "All" has to include the phones. A bearer token is a session by every
         * meaning that matters here — it reaches POST /sync/batch, which records
         * deliveries, sales and farmer registrations — and it used to survive a
         * reset for the remainder of its 30 days. The person resetting their
         * password is very often the person whose phone was lost.
         */
        $this->sessions->endAllFor($user, 'password_reset');
        $this->tokens->revokeAllFor($user, 'password_reset');
        Auth::logout();

        $request->session()->forget([self::SESSION_RESET_USER, self::SESSION_RESET_VERIFIED]);

        $this->audit->write([
            'actor' => $user,
            'event_type' => AuditEntry::EVENT_DATA_EDIT,
            'module' => 'Account',
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
            'summary' => $user->name.' reset their password; all sessions and mobile tokens revoked',
            'detail' => ['rule' => 'AUTH-4'],
        ]);

        return ['ok' => true];
    }

    /**
     * The signed-in change-password path (profile screen and AUTH-5 expiry).
     * BR-33 — revokes all OTHER sessions, leaving the current one alive.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function changePassword(Request $request, User $user, string $current, string $plain): array
    {
        if (! Hash::check($current, (string) $user->password_hash)) {
            return ['ok' => false, 'reason' => 'current_incorrect'];
        }

        if ($this->policy->isReused($user, $plain)) {
            return ['ok' => false, 'reason' => 'reused'];
        }

        $this->policy->apply($user, $plain);

        $this->sessions->endOthersFor($user, $request, 'password_change');
        Auth::logoutOtherDevices($plain);

        /*
         * BR-33 — "all OTHER sessions". Every mobile token is an other session:
         * the change screen is a web flow (EnsureAccountIsUsable refuses it on
         * the phone), so no token can be the caller's, and sparing one would
         * mean a password change that leaves a stolen phone signed in.
         */
        $this->tokens->revokeAllFor($user, 'password_change');

        $this->audit->write([
            'actor' => $user,
            'event_type' => AuditEntry::EVENT_DATA_EDIT,
            'module' => 'Account',
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
            'summary' => $user->name.' changed their password; other sessions and mobile tokens revoked',
            'detail' => ['rule' => 'BR-33'],
        ]);

        return ['ok' => true];
    }
}
