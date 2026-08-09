<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AUTH-5 — the 90-day expiry lands here; BR-33 — the change revokes all other
 * sessions. Also reachable from the profile screen.
 */
class PasswordChangeController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $reset,
        private readonly PasswordPolicy $policy,
    ) {}

    public function show(): View
    {
        return view('auth.change-password', ['policyDescription' => $this->policy->describe()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => $this->policy->rules(),
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $this->reset->changePassword(
            $request,
            $user,
            (string) $request->string('current_password'),
            (string) $request->string('password'),
        );

        if ($result['ok']) {
            return redirect()->route('profile')
                ->with('success', 'Password changed. Your other sessions were signed out.');
        }

        return match ($result['reason'] ?? '') {
            'current_incorrect' => back()->withErrors([
                'current_password' => 'That is not your current password.',
            ]),
            default => back()->withErrors([
                'password' => 'Choose a password you have not used in your last '
                    .config('gondal.auth.password_history').'.',
            ]),
        };
    }
}
