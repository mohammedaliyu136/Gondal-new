<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\User;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §10 — forgot-password.html, verify-reset.html, reset-password.html.
 *
 * AUTH-4 — email → 6-digit code (15 minutes) → new password; the reset revokes
 *          all sessions.
 * BR-31  — the administrator never sees or sets the password.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $reset,
        private readonly PasswordPolicy $policy,
    ) {}

    public function showForgot(): View
    {
        return view('auth.forgot-password');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->reset->requestCode($request, $validated['email']);

        // Always the same answer, whether or not the address exists.
        return redirect()->route('password.verify')
            ->with('status', 'If that address belongs to an active account, a code is on its way.');
    }

    /**
     * The activation email's front door.
     *
     * The verify screen trusts a session that names WHICH user is resetting, and
     * that session used to be seeded only by the forgot-password form — so the
     * activation email's own code could never be entered anywhere. This signed
     * route seeds it for the emailed user and lands them on the code screen,
     * making the code in their hand the code the screen expects.
     *
     * The signature carries the same expiry as the code, so the link cannot
     * outlive what it unlocks. Nothing here verifies anything by itself: the
     * code entry and the password rules still apply unchanged.
     */
    public function activate(Request $request, User $user): RedirectResponse
    {
        if (! $user->isActive()) {
            return redirect()->route('login');
        }

        $request->session()->put(PasswordResetService::SESSION_RESET_USER, $user->getKey());
        $request->session()->forget(PasswordResetService::SESSION_RESET_VERIFIED);

        return redirect()->route('password.verify')
            ->with('status', 'Enter the code from your welcome email, then choose your password.');
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $user = $this->reset->pendingUser($request);

        return view('auth.verify-reset', [
            'maskedEmail' => $user === null ? 'your email address' : $this->mask($user->email),
        ]);
    }

    public function verify(VerifyCodeRequest $request): RedirectResponse
    {
        if (! $this->reset->verifyCode($request, (string) $request->string('code'))) {
            return back()->withErrors(['code' => 'That code is not correct, or it has expired.']);
        }

        return redirect()->route('password.reset.form');
    }

    public function showReset(Request $request): View|RedirectResponse
    {
        if (! $this->reset->isVerified($request)) {
            return redirect()->route('password.forgot')
                ->withErrors(['email' => 'Start the reset again — the code was not verified.']);
        }

        return view('auth.reset-password', ['policyDescription' => $this->policy->describe()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => $this->policy->rules()]);

        $result = $this->reset->resetPassword($request, (string) $request->string('password'));

        if ($result['ok']) {
            return redirect()->route('login')
                ->with('status', 'Your password is set. Sign in with it.');
        }

        return match ($result['reason'] ?? '') {
            // AUTH-5
            'reused' => back()->withErrors([
                'password' => 'Choose a password you have not used in your last '
                    .config('gondal.auth.password_history').'.',
            ]),
            default => redirect()->route('password.forgot')
                ->withErrors(['email' => 'Start the reset again — the code was not verified.']),
        };
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
