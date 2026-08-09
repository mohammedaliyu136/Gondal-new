<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Issue, resolve and revoke the mobile bearer tokens (ARCH-2).
 *
 * The plaintext is `<id>|<secret>`. The id half is not a secret — it is there so
 * a lookup is a primary-key read rather than a scan of every live token, and the
 * secret half is still compared in constant time against a hash. This is the
 * same shape Sanctum uses, implemented directly because the project runs on the
 * framework alone (see composer.json) and one table plus one guard is a smaller
 * dependency than a package.
 */
class ApiTokenService
{
    /**
     * @return array{token: string, record: ApiToken}
     */
    public function issue(User $user, Request $request, ?Device $device = null): array
    {
        $secret = Str::random(48);

        $record = ApiToken::query()->create([
            'user_id' => $user->getKey(),
            'name' => $this->describe($request),
            'token_hash' => hash('sha256', $secret),
            'device_id' => $device?->getKey(),
            'platform' => $this->platform($request),
            'app_version' => substr((string) $request->header('X-App-Version'), 0, 32) ?: null,
            'last_used_at' => Wat::now(),
            'last_ip' => $request->ip(),
            'expires_at' => Wat::now()->addDays($this->lifetimeDays()),
        ]);

        return ['token' => $record->getKey().'|'.$secret, 'record' => $record];
    }

    /**
     * Resolves a bearer string to its token row, or null. Touches `last_used_at`
     * so an administrator can see which phones are still in the field.
     */
    public function resolve(?string $plaintext, ?Request $request = null): ?ApiToken
    {
        if (! is_string($plaintext) || ! str_contains($plaintext, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plaintext, 2);

        if (! ctype_digit($id) || $secret === '') {
            return null;
        }

        $token = ApiToken::query()->usable()->with('user')->find((int) $id);

        if ($token === null || ! hash_equals((string) $token->token_hash, hash('sha256', $secret))) {
            return null;
        }

        /*
         * AUTH-2 — a token issued alongside a trusted device dies when that trust
         * is revoked. Without this, "revoke device" on the profile screen would
         * leave the phone signed in, which is the opposite of what the button
         * promises.
         */
        if ($token->device_id !== null && $token->device !== null && ! $token->device->isTrusted()) {
            $token->revoke('device_revoked');

            return null;
        }

        // Written at most once a minute: a sync burst is dozens of requests, and
        // an UPDATE on each of them buys nothing an approximate timestamp lacks.
        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(Wat::now()) > 60) {
            $token->forceFill([
                'last_used_at' => Wat::now(),
                'last_ip' => $request?->ip() ?? $token->last_ip,
            ])->save();
        }

        return $token;
    }

    /** BR-33 / AUTH-2 — every token this user holds, e.g. after a password change. */
    public function revokeAllFor(User $user, string $reason): int
    {
        return ApiToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Wat::now(), 'revoked_reason' => $reason]);
    }

    private function lifetimeDays(): int
    {
        return (int) config('gondal.auth.api_token_days', 30);
    }

    private function platform(Request $request): ?string
    {
        $agent = (string) $request->userAgent();

        return match (true) {
            str_contains($agent, 'Android') => 'android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad'), str_contains($agent, 'Darwin') => 'ios',
            default => null,
        };
    }

    /**
     * The label shown in the user's device list. A client that names itself is
     * believed within 120 characters; anything else falls back to the platform,
     * because "Unknown device" is still more useful than an empty row.
     */
    private function describe(Request $request): string
    {
        $claimed = trim((string) $request->input('device_name', ''));

        if ($claimed !== '') {
            return substr($claimed, 0, 120);
        }

        return match ($this->platform($request)) {
            'android' => 'Android phone · AgentConnect',
            'ios' => 'iPhone · AgentConnect',
            default => 'Mobile device · AgentConnect',
        };
    }
}
