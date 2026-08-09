<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\ScopeType;
use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Lga;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use App\Services\Auth\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * users.html and user-detail.html.
 *
 * BR-31 — creation, reactivation and resetPassword() all send a code the user
 *   redeems, and the last of those works on any account, so an administrator can
 *   reset a password for every user without ever choosing one. setPassword() is
 *   the one exception the owner asked for: a TEMPORARY password the administrator
 *   types, which the user must replace at their next sign-in. It is the only
 *   password field in this controller and there is no path that shows an existing
 *   password — those cannot be read back, only replaced.
 * BR-32 — deactivation preserves attribution; nothing is deleted.
 * TEST-1 — the is_test flag is set here and excludes the account from every
 *   report, aggregate and payroll thereafter.
 * AUTH-8 — this is the ONLY way an account is created.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAdminService $users,
        private readonly PasswordPolicy $passwords,
    ) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['department', 'roles'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($query) => $query->where('department_id', $request->integer('department')))
            ->when($request->boolean('test_only'), fn ($query) => $query->where('is_test', true))
            ->when($request->filled('role'), fn ($query) => $query->whereHas('roles', fn ($inner) => $inner->where('roles.id', $request->integer('role'))))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'employees' => Employee::query()->onPayroll()->orderBy('name')->get(),
            'roles' => Role::query()->assignable()->orderBy('name')->get(),
            'counts' => [
                'active' => User::query()->where('status', 'active')->where('is_test', false)->count(),
                'test' => User::query()->where('is_test', true)->count(),
                'deactivated' => User::query()->where('status', 'deactivated')->count(),
                /*
                 * §16 counts the assignments held by ACTIVE STAFF, which is the
                 * figure an administrator cares about — test and deactivated
                 * accounts still hold their roles (ROLE-3 gives everyone the
                 * automatic one) but they are not live access.
                 */
                'assignments' => RoleAssignment::query()
                    ->whereHas('user', fn ($query) => $query->where('status', 'active')->where('is_test', false))
                    ->count(),
            ],
            'canCreate' => $this->allows('admin.users.create'),
        ]);
    }

    public function show(User $user): View
    {
        $this->authorizeAccess('admin.users.view', $user, 'User → '.$user->name);

        return view('admin.users.show', [
            'user' => $user->load(['department', 'employee', 'roles', 'createdBy', 'emailChangedBy']),
            'assignments' => $user->roleAssignments()->with('role')->get(),
            'devices' => $user->devices()->latest('last_seen_at')->get(),
            'sessions' => $user->authSessions()->latest('started_at')->limit(10)->get(),
            /*
             * ARCH-2 — the phones. MobileSigninService writes no session register
             * row, so before this an administrator investigating a lost device
             * saw an empty session list and had nothing to act on while the token
             * kept reaching POST /sync/batch.
             */
            'apiTokens' => $user->apiTokens()->usable()->with('device')->latest('last_used_at')->get(),
            'effectivePermissions' => $user->effectivePermissionKeys(),
            'scopeDescription' => $user->overallScopeDescription(),
            'assignableRoles' => Role::query()->active()->orderBy('name')->get(),
            'scopeTypes' => ScopeType::cases(),
            'centers' => CollectionCenter::withoutDataScope()->orderBy('name')->get(),
            'points' => CollectionPoint::withoutDataScope()->orderBy('name')->get(),
            'lgas' => Lga::query()->orderBy('name')->get(),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'canManageRoles' => $this->allows('admin.roles.edit'),
            'canEdit' => $this->allows('admin.users.edit', $user),
            'canDeactivate' => $this->allows('admin.users.delete', $user),
            // AUTH-5 — the same sentence the user's own change-password screen
            // shows. An administrator typing a temporary password is held to the
            // policy, so they should be told what it is before they guess.
            'policyDescription' => $this->passwords->describe(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_test' => ['nullable', 'boolean'],
            'two_factor_enabled' => ['nullable', 'boolean'],
            // BR-31 — deliberately no password rule. There is no password input.
        ]);

        $user = $this->users->create(array_merge($validated, [
            'is_test' => $request->boolean('is_test'),
            // Off unless the box is ticked — the same default as the service and
            // the column. An unticked checkbox is not submitted at all, so the
            // second argument here was overriding the user's actual choice.
            'two_factor_enabled' => $request->boolean('two_factor_enabled'),
        ]), $this->currentUser());

        return redirect()->route('admin.users.show', $user)->with(
            'success',
            $user->name.' created. An activation code has been emailed — you never see or set their password.',
        );
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'User → '.$user->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->getKey()],
            'phone' => ['nullable', 'string', 'max:32'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'is_test' => ['nullable', 'boolean'],
            'two_factor_enabled' => ['nullable', 'boolean'],
        ]);

        $this->users->update($user, array_merge($validated, [
            'is_test' => $request->boolean('is_test'),
            'two_factor_enabled' => $request->boolean('two_factor_enabled'),
        ]), $this->currentUser());

        return back()->with('success', 'Account updated.');
    }

    /** BR-32 */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.delete', $user, 'Deactivate → '.$user->name);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->users->deactivate($user, $validated['reason'], $this->currentUser());

        return back()->with(
            'success',
            $user->name.' deactivated. Their sessions and trusted devices are revoked; every record they touched keeps their name.',
        );
    }

    /**
     * AUTH-6 locks an account for 30 minutes after five failures, and the lock
     * email tells the user to contact IT — so IT needs a lever. Without this the
     * administrator's only tool was deactivate-then-reactivate, which also
     * revoked every trusted device and sent a confusing welcome email.
     */
    public function unlock(User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Unlock '.$user->name);

        if (! $user->isLocked()) {
            return back()->with('status', $user->name.' is not locked.');
        }

        $this->users->unlock($user, $this->currentUser());

        return back()->with('success', $user->name.' is unlocked and can sign in now.');
    }

    /** AUTH-2 — the administrator's half of device-trust revocation. */
    public function revokeDevice(User $user, Device $device): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Revoke a device for '.$user->name);

        abort_unless($device->user_id === $user->getKey(), 404);

        $this->users->revokeDevice($device, $this->currentUser());

        return back()->with('success', 'That device will need a sign-in code next time.');
    }

    /** Ends open sessions and mobile tokens without deactivating the account. */
    public function signOutEverywhere(User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Sign out all sessions for '.$user->name);

        $ended = $this->users->signOutEverywhere($user, $this->currentUser());

        if ($ended['sessions'] === 0 && $ended['tokens'] === 0) {
            return back()->with('status', $user->name.' had no open sessions or mobile tokens.');
        }

        return back()->with('success', sprintf(
            '%d session(s) and %d mobile token(s) ended. %s will have to sign in again on every device.',
            $ended['sessions'], $ended['tokens'], $user->name,
        ));
    }

    public function reactivate(User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Reactivate → '.$user->name);

        $this->users->reactivate($user, $this->currentUser());

        return back()->with('success', $user->name.' reactivated — an activation code has been emailed.');
    }

    /**
     * BR-31 / AUTH-4 — reset the password of ANY user.
     *
     * Still no password field: this takes the old one out of use and emails the
     * holder a code to choose their own with. `reason` is required because it goes
     * into both the audit entry and the user's email — "your password was reset"
     * with no explanation reads as a mistake or as phishing.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Reset password → '.$user->name);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $ended = $this->users->resetPassword($user, $validated['reason'], $this->currentUser());

        return back()->with('success', sprintf(
            'Password reset for %s. A code has been emailed to %s so they can choose a new one — you never '
            .'see or set it. %d session(s) and %d mobile token(s) ended.',
            $user->name, $user->email, $ended['sessions'], $ended['tokens'],
        ));
    }

    /**
     * BR-31, qualified — the administrator types a TEMPORARY password.
     *
     * The password is validated against AUTH-5's full policy, exactly as the
     * user's own would be: an administrator-chosen password is not an excuse for a
     * weak one, and "Password1" typed here would otherwise be a live credential
     * on somebody else's account. The user is forced to replace it at their next
     * sign-in, and the service says what that window costs.
     */
    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Set password → '.$user->name);

        $validated = $request->validate([
            'password' => $this->passwords->rules(),
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $ended = $this->users->setPassword($user, $validated['password'], $validated['reason'], $this->currentUser());

        return back()->with('success', sprintf(
            'Temporary password set for %s. Tell it to them directly — it was not emailed. They must choose '
            .'their own the first time they sign in. %d session(s) and %d mobile token(s) ended, and %s has '
            .'been emailed that you did this.',
            $user->name, $ended['sessions'], $ended['tokens'], $user->name,
        ));
    }

    /** BR-31 */
    public function sendActivation(User $user): RedirectResponse
    {
        $this->authorizeAccess('admin.users.edit', $user, 'Send activation → '.$user->name);

        $this->users->sendActivation($user, $this->currentUser());

        return back()->with('success', 'A fresh activation code has been emailed to '.$user->email.'.');
    }

    /** SCOPE-1 */
    public function assignRole(Request $request, User $user): RedirectResponse
    {
        /*
         * SCOPE-1 — a scope may name several targets. Three spellings arrive here
         * and all mean the same thing: `scope_target_id` from a single-value
         * picker, `scope_target_ids[]` from a multiple one, and `community_ids[]`
         * from the communities picker. They are merged rather than chosen between,
         * so an operator who picks two centres and one LGA gets all three.
         */
        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'scope_type' => ['required', 'string'],
            'scope_target_id' => ['nullable', 'integer'],
            'scope_target_ids' => ['nullable', 'array'],
            'scope_target_ids.*' => ['integer'],
            'community_ids' => ['nullable', 'array'],
            'community_ids.*' => ['integer', 'exists:communities,id'],
        ]);

        $role = Role::query()->findOrFail($validated['role_id']);
        $scopeType = ScopeType::tryFrom($validated['scope_type']);

        if ($scopeType === null) {
            return back()->withErrors(['scope_type' => 'Choose a valid data scope.']);
        }

        $targetIds = array_map('intval', array_merge(
            $validated['scope_target_ids'] ?? [],
            $validated['community_ids'] ?? [],
        ));

        // Every target must belong to the table the chosen scope names, or the
        // assignment would silently reach nothing: a centre id in an LGA scope
        // matches no LGA, and the holder sees an empty screen with no explanation.
        if ($scopeType->requiresTarget() && ! $this->targetsBelongTo($scopeType, $targetIds, $validated['scope_target_id'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors(['scope_target_id' => sprintf(
                    'Those targets are not %s. Pick from the %s list.',
                    $scopeType->label(),
                    strtolower($scopeType->label()),
                )]);
        }

        $assignment = $this->users->assignRole(
            $user,
            $role,
            $scopeType,
            $validated['scope_target_id'] ?? null,
            $targetIds,
            $this->currentUser(),
        );

        return back()->with('success', sprintf(
            '%s assigned to %s — %s. It takes effect on their next page load.',
            $role->name,
            $user->name,
            $assignment->describeScope(),
        ));
    }

    /**
     * Do these ids exist in the table the scope type names?
     *
     * @param  array<int, int>  $targetIds
     */
    private function targetsBelongTo(ScopeType $scopeType, array $targetIds, ?int $single): bool
    {
        $table = $scopeType->targetTable();

        if ($table === null) {
            return true;
        }

        $ids = array_values(array_unique(array_filter(
            $single === null ? $targetIds : array_merge($targetIds, [$single])
        )));

        if ($ids === []) {
            // Nothing named at all — SCOPE-1 in the service reports that, and
            // says it better than "those targets are not a collection center".
            return true;
        }

        return DB::table($table)->whereIn('id', $ids)->count() === count($ids);
    }

    public function removeRole(User $user, RoleAssignment $assignment): RedirectResponse
    {
        abort_unless((int) $assignment->user_id === (int) $user->getKey(), 404);

        $this->users->removeRole($assignment, $this->currentUser());

        return back()->with('success', 'Role removed.');
    }
}
