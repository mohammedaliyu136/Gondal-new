<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * profile.html.
 *
 * AUTH-2 — "Trust is revocable by the user and by an administrator." The user's
 *   half is here.
 * BR-31 — there is no field on this screen that sets somebody else's password,
 *   and none that reveals the user's own.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $user = $this->currentUser();

        return view('profile', [
            'user' => $user->load(['department', 'roles', 'employee']),
            'devices' => $user->devices()->latest('last_seen_at')->get(),
            'sessions' => $user->authSessions()->live()->latest('last_seen_at')->get(),
            /*
             * ARCH-2 — a phone sign-in writes no session register row, so a live
             * bearer token appeared on none of the three lists above. A user who
             * loses a phone must be able to SEE it here before the revoke button
             * below means anything to them.
             */
            'apiTokens' => $user->apiTokens()->usable()->with('device')->latest('last_used_at')->get(),
            'assignments' => $user->roleAssignments()->with('role')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            // AUTH-1 — a user may not switch off their own second factor; that is
            // an administrator's decision, recorded against them.
        ]);

        $user = $this->currentUser();
        $before = $user->only(['name', 'phone']);

        $user->fill($validated)->save();

        $this->audit->edited(
            $user,
            $user->name.' updated their profile',
            'Account',
            $before,
            $user->only(['name', 'phone']),
            $user,
        );

        return back()->with('success', 'Profile updated.');
    }

    /** AUTH-2 */
    public function revokeDevice(Device $device): RedirectResponse
    {
        $user = $this->currentUser();

        abort_unless($device->user_id === $user->getKey(), 404);

        $device->revoke($user);

        $this->audit->edited(
            $device,
            sprintf('%s revoked trust for device "%s"', $user->name, (string) $device->label),
            'Account',
            ['revoked_at' => null],
            ['revoked_at' => $device->revoked_at?->toDateTimeString(), 'rule' => 'AUTH-2'],
            $user,
        );

        return back()->with('success', 'That device will need a verification code next time.');
    }

    /**
     * BR-33 — the same revocation the password change performs, on demand.
     *
     * "Other" includes the phones. A mobile token is a live credential against
     * the whole /api/v1 write surface, so a user who presses this because they
     * left a session open somewhere and gets told "1 other session signed out"
     * while their token keeps syncing has been told something false.
     */
    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $user = $this->currentUser();

        $count = $this->sessions->endOthersFor($user, $request, 'user_revoke');
        $tokens = $this->tokens->revokeAllFor($user, 'user_revoke');

        $this->audit->edited(
            $user,
            sprintf('%s signed out %d other session(s) and %d mobile token(s)', $user->name, $count, $tokens),
            'Account',
            [],
            ['sessions_revoked' => $count, 'tokens_revoked' => $tokens],
            $user,
        );

        return back()->with('success', sprintf(
            '%d other session(s) and %d mobile sign-in(s) ended.', $count, $tokens,
        ));
    }
}
