<?php

namespace App\Services\Admin;

use App\Exceptions\RuleViolationException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §5.2 — role administration.
 *
 * AUDIT-3 — every grant change records the before/after sets AND the number of
 *   affected users.
 * ROLE-6  — a change takes effect on the assigned users' next request. Nothing is
 *   cached beyond a request, so there is nothing to bust.
 * ROLE-7  — a role with users can only be disabled.
 * TEST-5  — "Saving a role change that affects live users should prompt for a
 *   passing test run first. This is a warning, not a hard block — the
 *   administrator may override, and the override is logged."
 */
class RoleAdminService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Role
    {
        $role = Role::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope_type' => $data['scope_type'],
            // A new role starts as a draft: it has no grants yet, so making it
            // active would be a role that grants nothing while looking live.
            'status' => Role::STATUS_DRAFT,
            'created_by_user_id' => $actor->getKey(),
        ]);

        $this->audit->roleChanged(
            $role,
            sprintf('Role "%s" created as a draft', $role->name),
            ['scope_type' => $role->scope_type],
            $actor,
        );

        return $role;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data, User $actor): Role
    {
        $before = $role->only(['name', 'description', 'scope_type', 'status']);

        if (($data['status'] ?? $role->status) === Role::STATUS_ACTIVE
            && $role->livePermissions()->count() === 0) {
            throw RuleViolationException::make(
                'ROLE-5',
                "{$role->name} has no permissions, so activating it would grant nothing. Add permissions first.",
                ['role' => $role->name],
                'status',
            );
        }

        $role->fill([
            'name' => $data['name'] ?? $role->name,
            'description' => $data['description'] ?? $role->description,
            'scope_type' => $data['scope_type'] ?? $role->scope_type,
            'status' => $data['status'] ?? $role->status,
        ])->save();

        $this->audit->roleChanged(
            $role,
            sprintf('Role "%s" updated', $role->name),
            ['before' => $before, 'after' => $role->only(array_keys($before))],
            $actor,
        );

        return $role;
    }

    /**
     * AUDIT-3 / TEST-5 — the grant set is replaced wholesale so the before and
     * after sets are unambiguous.
     *
     * @param  array<int, int>  $permissionIds
     * @return array{role: Role, warning: ?string}
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actor, ?string $overrideReason = null): array
    {
        if ($role->is_automatic && $permissionIds === []) {
            throw RuleViolationException::make(
                'ROLE-3',
                "{$role->name} is held by every user; it must keep its own-records permissions.",
                ['role' => $role->name],
            );
        }

        $before = $this->grantKeys($role);

        // PERM-3 — a retired permission can never be granted.
        $live = Permission::query()->live()->whereIn('id', $permissionIds)->get();
        $rejected = array_diff($permissionIds, $live->pluck('id')->all());

        if ($rejected !== []) {
            throw RuleViolationException::make(
                'PERM-3',
                'Retired permissions cannot be granted. Reinstate them first if they are needed.',
                ['rejected_permission_ids' => array_values($rejected)],
            );
        }

        $liveUsers = $role->liveUserCount();

        DB::transaction(function () use ($role, $live): void {
            $role->permissions()->sync(
                $live->mapWithKeys(fn (Permission $permission) => [
                    $permission->getKey() => ['granted_by_user_id' => auth()->id()],
                ])->all(),
            );

            $role->forceFill(['permissions_changed_at' => Wat::now()])->save();
        });

        $role->refresh()->load('permissions');
        $after = $this->grantKeys($role);

        // AUDIT-3
        $this->audit->rolePermissionsChanged($role, $before, $after, $actor, $overrideReason);

        // PERM-2 — granting a sensitive permission deserves to be said out loud.
        $sensitiveGranted = Permission::query()
            ->sensitive()
            ->live()
            ->get()
            ->filter(fn (Permission $permission) => in_array($permission->resource_key.'.'.$permission->action, array_diff($after, $before), true));

        // NOTIF-3 — tell the affected users their access changed.
        if ($before !== $after) {
            $this->notifications->send(
                eventCode: 'role.changed',
                recipients: $role->users()->get(),
                title: 'Your access changed',
                body: sprintf('The %s role was updated. Your permissions take effect on your next page load.', $role->name),
                actionUrl: route('profile'),
                subject: $role,
            );
        }

        $warning = null;

        // TEST-5 — a warning, not a block.
        if ($liveUsers > 0 && $overrideReason === null && $role->hasUnvalidatedChanges()) {
            $warning = sprintf(
                'This change affects %d live user(s) and has not been validated by a passing permission test run. Run one before it reaches staff.',
                $liveUsers,
            );
        }

        if ($sensitiveGranted->isNotEmpty()) {
            $warning = trim(($warning ?? '').' Sensitive access granted: '
                .$sensitiveGranted->map(fn (Permission $p) => $p->resource_key)->unique()->implode(', ').'.');
        }

        return ['role' => $role, 'warning' => $warning];
    }

    /** ROLE-7 — "A role with assigned users cannot be deleted, only disabled." */
    public function disable(Role $role, User $actor): Role
    {
        if ($role->is_automatic) {
            throw RuleViolationException::make(
                'ROLE-3',
                "{$role->name} is held by every user and cannot be disabled.",
                ['role' => $role->name],
            );
        }

        $role->forceFill(['status' => Role::STATUS_DISABLED])->save();

        $this->audit->roleChanged(
            $role,
            sprintf('Role "%s" disabled', $role->name),
            ['rule' => 'ROLE-7'],
            $actor,
        );

        return $role;
    }

    /**
     * @return array<int, string>
     */
    private function grantKeys(Role $role): array
    {
        return $role->permissions()
            ->get()
            ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action)
            ->sort()
            ->values()
            ->all();
    }
}
