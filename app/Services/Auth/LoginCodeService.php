<?php

namespace App\Services\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\SigninCodeNotification;
use App\Support\Wat;
use Illuminate\Support\Str;

/**
 * AUTH-1 / AUTH-3 / AUTH-4 — the 6-digit emailed code.
 *
 *  - 6 digits, from a cryptographically secure source
 *  - 10-minute expiry for sign-in, 15 for a reset
 *  - single use
 *  - 5 attempts, then the code is invalidated (not merely rejected)
 *  - STORED HASHED; NFR-9 keeps the plaintext out of every log
 */
class LoginCodeService
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * Issuing a new code invalidates any outstanding one for the same purpose,
     * so a user who asks twice cannot be confused by which code still works.
     */
    public function issue(User $user, string $purpose, ?string $ip = null): LoginCode
    {
        LoginCode::query()
            ->where('user_id', $user->getKey())
            ->forPurpose($purpose)
            ->usable()
            ->update(['invalidated_at' => Wat::now()]);

        $length = (int) config('gondal.auth.code_length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $minutes = $purpose === LoginCode::PURPOSE_RESET
            ? (int) config('gondal.auth.reset_code_ttl_minutes', 15)
            : (int) config('gondal.auth.signin_code_ttl_minutes', 10);

        $record = LoginCode::query()->create([
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Wat::now()->addMinutes($minutes),
            'ip' => $ip,
        ]);

        // NOTIF-5 — queued, never synchronous with the request.
        $user->notify(new SigninCodeNotification($code, $purpose, $minutes));

        return $record;
    }

    /**
     * @return array{ok: bool, reason: ?string}
     */
    public function verify(User $user, string $purpose, string $code): array
    {
        $record = LoginCode::query()
            ->where('user_id', $user->getKey())
            ->forPurpose($purpose)
            ->usable()
            ->latest('id')
            ->first();

        if ($record === null) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $maxAttempts = (int) config('gondal.auth.code_max_attempts', 5);

        if ($record->attempts >= $maxAttempts) {
            $record->forceFill(['invalidated_at' => Wat::now()])->save();

            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }

        if (! hash_equals((string) $record->code_hash, hash('sha256', $code))) {
            $record->increment('attempts');

            // AUTH-3 — the fifth wrong attempt invalidates the code outright.
            if ($record->attempts + 1 > $maxAttempts) {
                $record->forceFill(['invalidated_at' => Wat::now()])->save();
            }

            return ['ok' => false, 'reason' => 'mismatch'];
        }

        // Single use.
        $record->forceFill(['consumed_at' => Wat::now()])->save();

        return ['ok' => true, 'reason' => null];
    }

    /** A short-lived opaque handle so the e-mail address never rides in a URL. */
    public function challengeToken(): string
    {
        return Str::random(40);
    }
}
