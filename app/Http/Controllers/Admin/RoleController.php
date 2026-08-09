<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\ScopeType;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PermissionTestRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\RoleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * roles.html and role-detail.html.
 *
 * This is the screen the whole rebuild exists for: "Nothing about access is
 * hardcoded. Roles are named sets of permissions, editable at runtime."
 *
 * PERM-2 — a sensitive grant is counted and warned about.
 * ROLE-7 — a role with users can only be disabled.
 * TEST-5 — saving a change that affects live users warns about an unvalidated
 *   configuration; the administrator may override, and the override is logged.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleAdminService $roles) {}

    public function index(Request $request): View
    {
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderByRaw("case status when 'active' then 0 when 'draft' then 1 when 'disabled' then 2 else 3 end")
            ->orderBy('name')
            ->get();

        $liveTotal = Permission::query()->live()->count();

        return view('admin.roles.index', [
            'roles' => $roles,
            'permissionTotal' => $liveTotal,
            'retiredPermissionCount' => Permission::query()->retired()->count(),
            'sensitiveByRole' => $roles->mapWithKeys(fn (Role $role) => [
                $role->getKey() => $role->sensitivePermissionCount(),
            ]),
            'counts' => [
                'active' => $roles->where('status', Role::STATUS_ACTIVE)->count(),
                'retired' => $roles->where('status', Role::STATUS_RETIRED)->count(),
                'draft' => $roles->where('status', Role::STATUS_DRAFT)->count(),
                'assignments' => (int) $roles->sum('users_count'),
                'testUsers' => User::query()->where('is_test', true)->count(),
            ],
            'scopeTypes' => ScopeType::cases(),
            'canCreate' => $this->allows('admin.roles.create'),
            // §5.1 — the matrix is grouped by resource, with `—` where an action
            // does not apply to that resource.
            'matrix' => Permission::matrix(),
            'matrixRoles' => $roles->where('status', Role::STATUS_ACTIVE)->values(),
        ]);
    }

    public function show(Role $role): View
    {
        $this->authorizeAccess('admin.roles.view', $role, 'Role → '.$role->name);

        return view('admin.roles.show', [
            'role' => $role->load(['permissions', 'users.department', 'lastPassingTestRun']),
            'matrix' => Permission::matrix(),
            'grantedIds' => $role->permissions->pluck('id')->all(),
            'sensitiveCount' => $role->sensitivePermissionCount(),
            'sensitivePermissions' => Permission::query()->sensitive()->live()->orderBy('position')->get(),
            'liveUserCount' => $role->liveUserCount(),
            // TEST-5
            'needsTestRun' => $role->hasUnvalidatedChanges(),
            'recentRuns' => PermissionTestRun::query()
                ->where('role_id', $role->getKey())
                ->latest('id')
                ->limit(5)
                ->with(['testUser', 'runBy'])
                ->get(),
            'scopeTypes' => ScopeType::cases(),
            'assignments' => $role->assignments()->with('user')->get(),
            'canEdit' => $this->allows('admin.roles.edit', $role),
            'canDisable' => $this->allows('admin.roles.delete', $role),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // roles.html: "The form now validates inline and names the field blocking
        // submission instead of failing silently."
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scope_type' => ['required', 'in:'.implode(',', ScopeType::values())],
        ], [], [
            'name' => 'role name',
            'scope_type' => 'data scope',
        ]);

        $role = $this->roles->create($validated, $this->currentUser());

        return redirect()->route('admin.roles.show', $role)->with(
            'success',
            $role->name.' created as a draft. Grant its permissions, then validate with a test run before activating it.',
        );
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeAccess('admin.roles.edit', $role, 'Role → '.$role->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->getKey()],
            'description' => ['nullable', 'string', 'max:1000'],
            'scope_type' => ['required', 'in:'.implode(',', ScopeType::values())],
            'status' => ['required', 'in:active,disabled,draft,retired'],
        ]);

        $this->roles->update($role, $validated, $this->currentUser());

        return back()->with('success', $role->name.' updated.');
    }

    /** AUDIT-3 / TEST-5 */
    public function updatePermissions(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeAccess('admin.roles.edit', $role, 'Role permissions → '.$role->name);

        $validated = $request->validate([
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            // TEST-5 — the override is optional, and logged when used.
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->roles->syncPermissions(
            $role,
            array_map('intval', $validated['permission_ids'] ?? []),
            $this->currentUser(),
            $validated['override_reason'] ?? null,
        );

        $redirect = back()->with('success', $role->name.' permissions saved. They apply on each holder\'s next page load.');

        return $result['warning'] === null
            ? $redirect
            : $redirect->with('warning', $result['warning']);
    }

    /** ROLE-7 */
    public function disable(Role $role): RedirectResponse
    {
        $this->authorizeAccess('admin.roles.delete', $role, 'Disable → '.$role->name);

        $this->roles->disable($role, $this->currentUser());

        return back()->with('success', $role->name.' disabled. Its assignments and history are preserved.');
    }
}
