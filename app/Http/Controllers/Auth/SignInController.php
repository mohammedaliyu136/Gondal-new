<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\User;
use App\Services\Auth\SigninService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * §10 — login.html and verify-code.html.
 *
 * AUTH-8 — there is no register action here, and there never will be in v1.
 * NFR-8 — the per-IP limiter is applied on the route; the per-account rule lives
 *         in SigninThrottle (AUTH-6).
 */
class SignInController extends Controller
{
    public function __construct(private readonly SigninService $signin) {}

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function attempt(LoginRequest $request): RedirectResponse
    {
        $result = $this->signin->attempt(
            $request,
            (string) $request->string('email'),
            (string) $request->string('password'),
            $request->boolean('remember_device'),
        );

        return match ($result['status']) {
            'signed_in' => redirect()->intended(route('dashboard')),
            'code_sent' => redirect()->route('login.verify'),
            default => back()
                ->withInput($request->only('email', 'remember_device'))
                ->withErrors(['email' => $this->failureMessage($result['reason'] ?? 'credentials', $result['user'] ?? null)]),
        };
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $user = $this->signin->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Your sign-in attempt expired. Please start again.']);
        }

        return view('auth.verify-code', ['maskedEmail' => $this->mask($user->email)]);
    }

    public function verify(VerifyCodeRequest $request): RedirectResponse
    {
        $result = $this->signin->verifyCode($request, (string) $request->string('code'));

        if ($result['status'] === 'signed_in') {
            return redirect()->intended(route('dashboard'));
        }

        return match ($result['reason'] ?? 'mismatch') {
            'expired_challenge' => redirect()->route('login')
                ->withErrors(['email' => 'Your sign-in attempt expired. Please start again.']),
            'too_many_attempts' => redirect()->route('login')->withErrors([
                'code' => 'That code was entered incorrectly too many times and is no longer valid. Sign in again to get a new one.',
            ]),
            'expired' => back()->withErrors([
                'code' => 'That code has expired. Ask for a new one.',
            ]),
            default => back()->withErrors(['code' => 'That code is not correct.']),
        };
    }

    public function resend(Request $request): RedirectResponse
    {
        // NFR-8 — a resend is a send; it is limited per account.
        $user = $this->signin->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $key = 'resend-code:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors([
                'code' => 'Too many code requests. Wait '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 300);
        $this->signin->resendCode($request);

        return back()->with('status', 'A new code is on its way.');
    }

    public function signOut(Request $request): RedirectResponse
    {
        $this->signin->signOut($request);

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    private function failureMessage(string $reason, ?User $user = null): string
    {
        // The lock message states the time actually remaining, not the configured
        // total — a user retrying at minute 25 was being told to wait half an hour.
        $minutesLeft = $user?->locked_until !== null
            ? max(1, (int) ceil(now()->diffInSeconds($user->locked_until, false) / 60))
            : (int) config('gondal.auth.lockout_minutes', 30);

        return match ($reason) {
            // BR-32
            'deactivated' => 'This account has been deactivated. Contact your system administrator.',
            // AUTH-6
            'locked' => sprintf(
                'Too many failed attempts. This account is locked for about %d more %s and the account holder has been notified.',
                $minutesLeft,
                $minutesLeft === 1 ? 'minute' : 'minutes',
            ),
            default => 'Those details do not match an active account.',
        };
    }

    /** NFR-9 — the full address is never echoed back to an unauthenticated form. */
    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
