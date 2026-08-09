<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\NewDeviceSigninNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * §10 for a device that has no session — the same story, told without cookies.
 *
 * Every rule the browser meets is met here, in the same order and by the same
 * collaborators: AUTH-1's two steps, AUTH-2's device trust, AUTH-6's throttle
 * and lockout, AUTH-7's new-device notice, AUTH-8's absence of registration, and
 * BR-32's block on a deactivated account. The only differences are mechanical:
 *
 *   · the pending sign-in lives in the CACHE under an opaque challenge handle,
 *     not in the session — a phone that closes the app between the password and
 *     the code must still be able to finish;
 *   · device trust travels as a token the client stores, not as a cookie;
 *   · success ends in a bearer token instead of a session id.
 *
 * SigninService is deliberately not subclassed. Its two steps are woven through
 * the session — pending user, pending remember-flag, regenerate on completion —
 * and a subclass that overrode all of that would share a name with its parent
 * and nothing else. The rules are shared by sharing the services that hold them.
 */
class MobileSigninService
{
    /** How long a half-finished sign-in survives, in minutes. */
    private const CHALLENGE_TTL = 15;

    public function __construct(
        private readonly LoginCodeService $codes,
        private readonly DeviceTrustService $devices,
        private readonly SigninThrottle $throttle,
        private readonly ApiTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1 — email and password.
     *
     * @return array{status: 'signed_in'|'code_required'|'failed', reason?: string, user?: User, token?: string, device_token?: string, challenge?: string}
     */
    public function attempt(Request $request, string $email, string $password, ?string $deviceToken): array
    {
        $user = User::query()->where('email', $email)->first();

        // A missing account and a wrong password are reported identically, for
        // the same reason as on the web: the form must not enumerate staff.
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

            return [
                'status' => 'failed',
                'reason' => $lockedNow ? 'locked' : 'credentials',
                'user' => $user->refresh(),
            ];
        }

        // AUTH-2 — a device this user has already trusted skips the code step.
        $trusted = $this->devices->trustedDeviceForToken($user, $deviceToken, $request);

        if ($trusted !== null || ! $user->two_factor_enabled) {
            return $this->complete($request, $user, $trusted, viaTrustedDevice: $trusted !== null);
        }

        // AUTH-1 — otherwise, the second factor.
        $this->codes->issue($user, LoginCode::PURPOSE_SIGNIN, $request->ip());

        $challenge = $this->codes->challengeToken();

        Cache::put($this->cacheKey($challenge), $user->getKey(), now()->addMinutes(self::CHALLENGE_TTL));

        return ['status' => 'code_required', 'user' => $user, 'challenge' => $challenge];
    }

    /**
     * Step 2 — the emailed code.
     *
     * @return array{status: 'signed_in'|'failed', reason?: string, user?: User, token?: string, device_token?: string}
     */
    public function verify(Request $request, string $challenge, string $code, bool $rememberDevice): array
    {
        $user = $this->pendingUser($challenge);

        if ($user === null) {
            return ['status' => 'failed', 'reason' => 'expired_challenge'];
        }

        // The account can be locked or deactivated between the two steps. The
        // web path is protected by the session guard's next request; here the
        // check has to be explicit, or the code would let a just-deactivated
        // account through.
        if ($user->isLocked() || ! $user->isActive()) {
            Cache::forget($this->cacheKey($challenge));

            return ['status' => 'failed', 'reason' => $user->isLocked() ? 'locked' : 'deactivated'];
        }

        $result = $this->codes->verify($user, LoginCode::PURPOSE_SIGNIN, $code);

        if (! $result['ok']) {
            $this->throttle->record($user->email, 'bad_code', $user, $request);
            $this->audit->failedSignin($user->email, 'bad_code:'.($result['reason'] ?? 'unknown'), $user);

            // AUTH-3 — an invalidated code cannot be retried, so the handle goes
            // with it rather than sitting there inviting a guess.
            if (in_array($result['reason'], ['too_many_attempts', 'expired'], true)) {
                Cache::forget($this->cacheKey($challenge));
            }

            return ['status' => 'failed', 'reason' => $result['reason'] ?? 'mismatch'];
        }

        Cache::forget($this->cacheKey($challenge));

        $device = null;
        $deviceToken = null;

        // AUTH-2
        if ($rememberDevice) {
            ['device' => $device, 'token' => $deviceToken] = $this->devices->issue($user, $request);
        }

        return $this->complete($request, $user, $device, viaTrustedDevice: false, deviceToken: $deviceToken);
    }

    /** Revokes the calling token only — other devices keep working. */
    public function signOut(User $user, ?ApiToken $token): void
    {
        $token?->revoke('signout');

        $this->audit->signin($user, $user->name.' signed out of AgentConnect');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{status: 'signed_in', user: User, token: string, device_token?: string}
     */
    private function complete(
        Request $request,
        User $user,
        ?Device $device,
        bool $viaTrustedDevice,
        ?string $deviceToken = null,
    ): array {
        // AUTH-7 — decided before this device is recorded, or it is never new.
        $isNewDevice = ! $viaTrustedDevice && $this->devices->isNewDevice($user, $request);

        $issued = $this->tokens->issue($user, $request, $device);

        $this->throttle->clear($user);
        $user->forceFill(['last_signed_in_at' => Wat::now()])->save();

        $this->audit->signin($user, $user->name.' signed in on AgentConnect', [
            'via_trusted_device' => $viaTrustedDevice,
            'new_device' => $isNewDevice,
            'surface' => 'mobile',
            'ip' => $request->ip(),
        ]);

        if ($isNewDevice) {
            $user->notify(new NewDeviceSigninNotification(
                deviceLabel: $issued['record']->name,
                ip: $request->ip(),
                whenWat: Wat::dateTime(Wat::now()),
            ));
        }

        $result = ['status' => 'signed_in', 'user' => $user, 'token' => $issued['token']];

        if ($deviceToken !== null) {
            $result['device_token'] = $deviceToken;
        }

        return $result;
    }

    private function pendingUser(string $challenge): ?User
    {
        $id = Cache::get($this->cacheKey($challenge));

        return $id === null ? null : User::query()->find($id);
    }

    private function cacheKey(string $challenge): string
    {
        return 'mobile-signin:'.hash('sha256', $challenge);
    }
}
