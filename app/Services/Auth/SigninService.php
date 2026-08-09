<?php

namespace App\Services\Auth;

use App\Models\Device;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\NewDeviceSigninNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * §10 — the whole sign-in story in one place.
 *
 * AUTH-1 email + password, then a 6-digit emailed code. Both steps are required
 *        unless the device carries a valid trust token.
 * AUTH-2 "trust this device for 30 days" skips the code step.
 * AUTH-6 failed sign-ins are logged and throttled; 5 in 15 minutes locks for 30.
 * AUTH-7 a sign-in from a new device notifies the user.
 * AUTH-8 there is NO self-registration. Nothing here creates an account.
 * BR-32  a deactivated user cannot sign in, but their history keeps their name.
 */
class SigninService
{
    public const SESSION_PENDING_USER = 'auth.pending_user_id';

    public const SESSION_PENDING_REMEMBER = 'auth.pending_remember_device';

    public const SESSION_PENDING_PURPOSE = 'auth.pending_purpose';

    public function __construct(
        private readonly LoginCodeService $codes,
        private readonly DeviceTrustService $devices,
        private readonly SigninThrottle $throttle,
        private readonly SessionRegistry $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1. Returns what the caller should do next.
     *
     * @return array{status: 'signed_in'|'code_sent'|'failed', reason?: string, user?: User}
     */
    public function attempt(Request $request, string $email, string $password, bool $rememberDevice): array
    {
        $user = User::query()->where('email', $email)->first();

        // A missing account and a wrong password are reported identically so the
        // form cannot be used to enumerate staff e-mail addresses.
        if ($user === null) {
            $this->throttle->record($email, 'unknown_email', null, $request);
            $this->audit->failedSignin($email, 'unknown_email');

            return ['status' => 'failed', 'reason' => 'credentials'];
        }

        if ($user->isLocked()) {
            $this->throttle->record($email, 'locked', $user, $request);
            $this->audit->failedSignin($email, 'locked', $user);

            return ['status' => 'failed', 'reason' => 'locked', 'user' => $user];
        }

        // BR-32
        if (! $user->isActive()) {
            $this->throttle->record($email, 'deactivated', $user, $request);
            $this->audit->failedSignin($email, 'deactivated', $user);

            return ['status' => 'failed', 'reason' => 'deactivated'];
        }

        if (! Hash::check($password, (string) $user->password_hash)) {
            $lockedNow = $this->throttle->record($email, 'bad_password', $user, $request);
            $this->audit->failedSignin($email, 'bad_password', $user);

            // The attempt that trips the lock is told so. The old behaviour
            // returned the generic message, and the user's natural sixth try —
            // possibly with the RIGHT password — bounced off the lock unexplained.
            return ['status' => 'failed', 'reason' => $lockedNow ? 'locked' : 'credentials', 'user' => $user->refresh()];
        }

        // AUTH-2 — a valid trust token skips the code step entirely.
        $trusted = $this->devices->trustedDeviceFor($user, $request);

        if ($trusted !== null || ! $user->two_factor_enabled) {
            $this->completeSignin($request, $user, $trusted, viaTrustedDevice: $trusted !== null);

            return ['status' => 'signed_in', 'user' => $user];
        }

        // AUTH-1 — otherwise, the second factor.
        $this->codes->issue($user, LoginCode::PURPOSE_SIGNIN, $request->ip());

        $request->session()->put(self::SESSION_PENDING_USER, $user->getKey());
        $request->session()->put(self::SESSION_PENDING_REMEMBER, $rememberDevice);
        $request->session()->put(self::SESSION_PENDING_PURPOSE, LoginCode::PURPOSE_SIGNIN);

        return ['status' => 'code_sent', 'user' => $user];
    }

    /**
     * Step 2 — verify the emailed code.
     *
     * @return array{status: 'signed_in'|'failed', reason?: string, user?: User}
     */
    public function verifyCode(Request $request, string $code): array
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return ['status' => 'failed', 'reason' => 'expired_challenge'];
        }

        $result = $this->codes->verify($user, LoginCode::PURPOSE_SIGNIN, $code);

        if (! $result['ok']) {
            $this->throttle->record($user->email, 'bad_code', $user, $request);
            $this->audit->failedSignin($user->email, 'bad_code:'.($result['reason'] ?? 'unknown'), $user);

            return ['status' => 'failed', 'reason' => $result['reason'] ?? 'mismatch'];
        }

        $device = null;

        // AUTH-2
        if ($request->session()->pull(self::SESSION_PENDING_REMEMBER, false)) {
            $device = $this->devices->remember($user, $request);
        }

        $this->completeSignin($request, $user, $device, viaTrustedDevice: false);

        return ['status' => 'signed_in', 'user' => $user];
    }

    public function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_PENDING_USER);

        return $id === null ? null : User::query()->find($id);
    }

    public function resendCode(Request $request): bool
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return false;
        }

        $this->codes->issue($user, LoginCode::PURPOSE_SIGNIN, $request->ip());

        return true;
    }

    public function signOut(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->sessions->endCurrent($user, $request, 'signout');
            $this->audit->signin($user, $user->name.' signed out');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /* ------------------------------------------------------------------ */

    private function completeSignin(Request $request, User $user, ?Device $device, bool $viaTrustedDevice): void
    {
        // AUTH-7 — decided BEFORE the device is registered, or it is never new.
        $isNewDevice = ! $viaTrustedDevice && $this->devices->isNewDevice($user, $request);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget([
            self::SESSION_PENDING_USER,
            self::SESSION_PENDING_REMEMBER,
            self::SESSION_PENDING_PURPOSE,
        ]);

        $this->throttle->clear($user);
        $user->forceFill(['last_signed_in_at' => Wat::now()])->save();

        $this->sessions->start($user, $request, $device);

        $this->audit->signin($user, $user->name.' signed in', [
            'via_trusted_device' => $viaTrustedDevice,
            'new_device' => $isNewDevice,
            'ip' => $request->ip(),
        ]);

        if ($isNewDevice) {
            $user->notify(new NewDeviceSigninNotification(
                deviceLabel: substr((string) $request->userAgent(), 0, 120),
                ip: $request->ip(),
                whenWat: Wat::dateTime(Wat::now()),
            ));
        }
    }
}
