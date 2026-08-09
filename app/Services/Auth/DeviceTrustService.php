<?php

namespace App\Services\Auth;

use App\Models\Device;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * AUTH-2 — "Trust this device for 30 days issues a device token that skips the
 * code step. Trust is revocable by the user and by an administrator."
 *
 * NFR-7 — the cookie is http-only, secure and same-site.
 * NFR-9 — only the token's hash reaches the database.
 */
class DeviceTrustService
{
    private const COOKIE = 'gondal_device';

    public function cookieName(): string
    {
        return self::COOKIE;
    }

    /**
     * AUTH-1 — "Both steps are required unless the device carries a valid trust
     * token."
     */
    public function trustedDeviceFor(User $user, Request $request): ?Device
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $device = Device::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->trusted()
            ->first();

        $device?->forceFill([
            'last_seen_at' => Wat::now(),
            'last_ip' => $request->ip(),
        ])->save();

        return $device;
    }

    /**
     * ARCH-2 — the same check for a client that has no cookie jar.
     *
     * A phone keeps its trust token in the OS keystore and presents it on the
     * next sign-in, which is the cookie's role played by a header. The rule
     * being tested is identical, so the lookup is too.
     */
    public function trustedDeviceForToken(User $user, ?string $token, ?Request $request = null): ?Device
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $device = Device::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->trusted()
            ->first();

        $device?->forceFill([
            'last_seen_at' => Wat::now(),
            'last_ip' => $request?->ip(),
        ])->save();

        return $device;
    }

    /**
     * Issues the trust token and queues the cookie. Returns the device so the
     * caller can attach it to the session register.
     */
    public function remember(User $user, Request $request, ?string $label = null): Device
    {
        ['device' => $device, 'token' => $token] = $this->issue($user, $request, $label);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: (int) config('gondal.auth.device_trust_days', 30) * 24 * 60,
            secure: (bool) config('session.secure', true),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $device;
    }

    /**
     * The trust record and its plaintext token, without touching cookies — for
     * a mobile client, which stores the token itself.
     *
     * @return array{device: Device, token: string}
     */
    public function issue(User $user, Request $request, ?string $label = null): array
    {
        $token = Str::random(64);
        $days = (int) config('gondal.auth.device_trust_days', 30);

        $device = Device::query()->create([
            'user_id' => $user->getKey(),
            'label' => $label ?? $this->describe($request),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'last_ip' => $request->ip(),
            'token_hash' => hash('sha256', $token),
            'trusted_until' => Wat::now()->addDays($days),
            'last_seen_at' => Wat::now(),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    /** AUTH-7 — "Sign-in from a new device notifies the user." */
    public function isNewDevice(User $user, Request $request): bool
    {
        $agent = substr((string) $request->userAgent(), 0, 500);

        return ! Device::query()
            ->where('user_id', $user->getKey())
            ->where('user_agent', $agent)
            ->exists();
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** A human label for the profile screen's device list. */
    private function describe(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'browser',
        };

        return $platform.' · '.$browser;
    }
}
