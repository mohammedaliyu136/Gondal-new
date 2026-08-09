<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\MobileSigninService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §10 on the phone. AUTH-8 — there is no register action here either.
 *
 * The client is told which of three things happened rather than being left to
 * infer it from a status code:
 *
 *   signed_in      · token issued, go to the dashboard
 *   code_required  · AUTH-1's second step; hold the challenge and ask for the code
 *   failed         · with a reason the app can phrase for a field worker
 *
 * `is_success` is kept alongside `status` because the existing client build
 * reads it; it means exactly "status === signed_in".
 */
class MobileAuthController extends ApiController
{
    public function __construct(
        private readonly MobileSigninService $signin,
        private readonly ApiTokenService $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            // AUTH-2 — the trust token from a previous sign-in on this phone.
            'device_token' => ['nullable', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $this->signin->attempt(
            $request,
            strtolower(trim($validated['email'])),
            $validated['password'],
            $validated['device_token'] ?? null,
        );

        return match ($result['status']) {
            'signed_in' => $this->signedIn($result),
            'code_required' => response()->json([
                'is_success' => false,
                'status' => 'code_required',
                'message' => 'We sent a 6-digit code to your e-mail address.',
                'data' => [
                    'challenge' => $result['challenge'],
                    'masked_email' => $this->mask($result['user']->email),
                    'expires_in_minutes' => (int) config('gondal.auth.signin_code_ttl_minutes', 10),
                ],
            ]),
            default => response()->json([
                'is_success' => false,
                'status' => 'failed',
                'reason' => $result['reason'] ?? 'credentials',
                'message' => $this->failureMessage($result['reason'] ?? 'credentials', $result['user'] ?? null),
            ], 422),
        };
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
            // AUTH-2 — "trust this device for 30 days".
            'remember_device' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $this->signin->verify(
            $request,
            $validated['challenge'],
            $validated['code'],
            (bool) ($validated['remember_device'] ?? false),
        );

        if ($result['status'] === 'signed_in') {
            return $this->signedIn($result);
        }

        return response()->json([
            'is_success' => false,
            'status' => 'failed',
            'reason' => $result['reason'] ?? 'mismatch',
            'message' => $this->codeFailureMessage($result['reason'] ?? 'mismatch'),
        ], 422);
    }

    /**
     * Signs this device out and nothing else. A field worker whose phone is
     * being handed to a colleague must not knock the rest of their devices —
     * or their web session — offline as a side effect.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->signin->signOut($user, $this->tokens->resolve($request->bearerToken()));

        return response()->json(['is_success' => true, 'message' => 'Signed out.']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array{user: User, token: string, device_token?: string}  $result
     */
    private function signedIn(array $result): JsonResponse
    {
        $user = $result['user'];

        return response()->json([
            'is_success' => true,
            'status' => 'signed_in',
            'data' => array_filter([
                'token' => $result['token'],
                // AUTH-2 — present only when the user asked to be remembered.
                'device_token' => $result['device_token'] ?? null,
                'agent_email' => $user->email,
                'agent_name' => $user->name,
                'agent_code' => $user->extensionAgentRecords()->value('code'),
                'agent_role' => $user->primaryRoleLabel(),
            ], static fn ($value) => $value !== null),
        ]);
    }

    private function failureMessage(string $reason, ?User $user): string
    {
        return match ($reason) {
            // AUTH-6 — the attempt that trips the lock says so, rather than
            // inviting a sixth try with the generic message.
            'locked' => 'This account is locked for '.config('gondal.auth.lockout_minutes').' minutes after too many failed attempts.',
            'deactivated' => 'This account has been deactivated. Contact your system administrator.',
            default => 'Those details do not match an account.',
        };
    }

    private function codeFailureMessage(string $reason): string
    {
        return match ($reason) {
            'expired_challenge' => 'Your sign-in attempt expired. Please sign in again.',
            'expired' => 'That code has expired. Sign in again to get a new one.',
            'too_many_attempts' => 'That code was entered incorrectly too many times. Sign in again to get a new one.',
            'locked' => 'This account is now locked. Try again later or contact IT Support.',
            'deactivated' => 'This account has been deactivated. Contact your system administrator.',
            default => 'That code is not right. Check the e-mail and try again.',
        };
    }

    /** The address is shown back, but not in full — it is also a secret here. */
    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
