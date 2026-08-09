<?php

namespace App\Support;

use App\Http\Middleware\RequireApprovalQueueAccess;
use App\Models\User;

/**
 * SCR-2 — "Navigation is rendered from the user's effective permissions. A user
 * without shop.inventory.view does not see the One-Stop Shop nav item at all.
 * Collapsed nav groups with no visible children are omitted."
 *
 * §4 notes that the prototype's 45 sidebars are kept in step by
 * tools/sync-sidebar.py. In the built system there is exactly ONE sidebar — this
 * definition, rendered by partials/sidebar.blade.php — so the duplication the
 * script existed to manage no longer exists, and the sidebar becomes
 * permission-aware rather than identical everywhere.
 *
 * The `permission` on each item is the same key §4 gives for that screen. Items
 * with a null permission are visible to any authenticated user (Dashboard,
 * Notifications, Profile). A `permission_prefix` means "any of these", which is
 * how /approvals maps to "any purchase.approve.*".
 *
 * NG-1 / NG-2 — the Project Management and Cooperative Loans modules are absent
 * by decision, and `module` lets a disabled module hide its items without a code
 * change (see config('gondal.disabled_modules') and the `settings` override).
 */
final class Navigation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definition(): array
    {
        return [
            [
                'type' => 'link',
                'label' => 'Dashboard',
                'route' => 'dashboard',
                'permission' => null,
                'icon' => 'home',
            ],
            /*
             * §15.5 / NG-7 — no permission key: each report carries its own and
             * the screen lists only the ones the viewer may run, so a user with
             * none sees an empty state rather than a missing nav item they
             * cannot ask about. SCR-2's "omit what they cannot open" applies to
             * a screen that is wholly refused; this one never is.
             */
            [
                'type' => 'link',
                'label' => 'Reports',
                'route' => 'reports.index',
                'permission' => null,
                'icon' => 'chart',
            ],
            [
                'type' => 'link',
                'label' => 'My Approvals',
                'route' => 'approvals.index',
                /*
                 * §4 — any workflow-stage approver, matching the route's own gate.
                 * A prefix of `purchase.approve.` hid the link from the HR Manager,
                 * who is named on the leave and payroll stages and has items in
                 * that queue: the work waited on somebody who was never shown it.
                 */
                'permission_any' => 'approval_stages',
                'icon' => 'inbox',
            ],
            [
                'type' => 'group',
                'label' => 'Milk Collection',
                'icon' => 'droplet',
                'module' => 'milk',
                'children' => [
                    ['label' => 'Collection Points', 'route' => 'collection-points.index', 'permission' => 'milk.points.view'],
                    ['label' => 'Collection Centers', 'route' => 'collection-centers.index', 'permission' => 'milk.points.view'],
                    ['label' => 'Milk Flow', 'route' => 'deliveries.index', 'permission' => 'milk.deliveries.view'],
                    ['label' => 'Logistics & Transport', 'route' => 'logistics.index', 'permission' => 'logistics.trips.view'],
                    // §9 — the register the trip form reads. Same permission as
                    // trips: whoever logs one needs the fleet that appears in it.
                    ['label' => 'Fleet & Routes', 'route' => 'fleet.index', 'permission' => 'logistics.trips.view'],
                    ['label' => 'Factory Reconciliation', 'route' => 'reconciliation.index', 'permission' => 'milk.reconciliation.view'],
                ],
            ],
            [
                'type' => 'link',
                'label' => 'Requisitions',
                'route' => 'requisitions.index',
                'permission' => 'purchase.requisitions.view',
                'icon' => 'file',
            ],
            [
                'type' => 'group',
                'label' => 'Community Engagement',
                'icon' => 'users',
                'module' => 'community',
                'children' => [
                    ['label' => 'Farmers', 'route' => 'farmers.index', 'permission' => 'community.farmers.view'],
                    // Reachable by the engagement programme and by whoever builds
                    // the collection network — a point needs a community to be in.
                    ['label' => 'Communities', 'route' => 'communities.index', 'permission' => ['community.farmers.view', 'milk.points.view']],
                    ['label' => 'Cooperatives', 'route' => 'cooperatives.index', 'permission' => 'community.cooperatives.view'],
                    ['label' => 'Extension Agents', 'route' => 'extension-agents.index', 'permission' => 'community.extension.view'],
                    ['label' => 'Field Activities', 'route' => 'field-activities.index', 'permission' => 'community.extension.view'],
                    // BR-36 — M&E's own screen. Its permission is the queue's, not
                    // the register's, so a Collection Agent who validates never
                    // sees the list they are worked from.
                    ['label' => 'Revalidation', 'route' => 'validations.index', 'permission' => 'community.validation.view'],
                ],
            ],
            [
                'type' => 'link',
                'label' => 'One-Stop Shop',
                'route' => 'shop.inventory',
                'permission' => 'shop.inventory.view',
                'icon' => 'shop',
                'module' => 'shop',
            ],
            [
                'type' => 'group',
                'label' => 'Human Resources',
                'icon' => 'briefcase',
                'module' => 'hr',
                'children' => [
                    ['label' => 'Employees', 'route' => 'employees.index', 'permission' => 'hr.employees.view'],
                    ['label' => 'Departments', 'route' => 'departments.index', 'permission' => 'hr.employees.view'],
                    // §4 — `hr.leave.view` OR `hr.leave.own.view`
                    ['label' => 'Leave', 'route' => 'leave.index', 'permission' => ['hr.leave.view', 'hr.leave.own.view']],
                    ['label' => 'Open Positions', 'route' => 'positions.index', 'permission' => 'hr.employees.view'],
                    ['label' => 'Payroll', 'route' => 'payroll.index', 'permission' => 'hr.payroll.view'],
                ],
            ],
            [
                'type' => 'group',
                'label' => 'Administration',
                'icon' => 'shield',
                'module' => 'admin',
                'children' => [
                    ['label' => 'Users', 'route' => 'admin.users.index', 'permission' => 'admin.users.view'],
                    ['label' => 'Roles & Permissions', 'route' => 'admin.roles.index', 'permission' => 'admin.roles.view'],
                    ['label' => 'Personas', 'route' => 'admin.personas', 'permission' => 'admin.roles.view'],
                    ['label' => 'Permission Testing', 'route' => 'admin.permission-tests.index', 'permission' => 'admin.roles.edit'],
                    ['label' => 'Audit Log', 'route' => 'admin.audit-log', 'permission' => 'admin.audit.view'],
                    ['label' => 'Settings', 'route' => 'admin.settings', 'permission' => 'admin.settings.edit'],
                ],
            ],
        ];
    }

    /**
     * SCR-2 — the definition filtered to what this user may actually open, with
     * empty groups dropped.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(?User $user): array
    {
        $disabled = self::disabledModules();
        $visible = [];

        foreach (self::definition() as $item) {
            if (isset($item['module']) && in_array($item['module'], $disabled, true)) {
                continue;
            }

            if (($item['type'] ?? 'link') === 'group') {
                $children = array_values(array_filter(
                    $item['children'],
                    static fn (array $child) => self::maySee($user, $child),
                ));

                // "Collapsed nav groups with no visible children are omitted."
                if ($children === []) {
                    continue;
                }

                $item['children'] = $children;
                $visible[] = $item;

                continue;
            }

            if (self::maySee($user, $item)) {
                $visible[] = $item;
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function maySee(?User $user, array $item): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (isset($item['permission_prefix'])) {
            return $user->hasPermissionMatching((string) $item['permission_prefix']);
        }

        // The approval queue's admissions are derived from the workflow stages
        // themselves, so a new stage cannot hide the link from its own approver.
        if (($item['permission_any'] ?? null) === 'approval_stages') {
            return $user->hasAnyPermission(...RequireApprovalQueueAccess::approvalPermissionKeys());
        }

        $permission = $item['permission'] ?? null;

        if ($permission === null) {
            return true;
        }

        return $user->hasAnyPermission(...(array) $permission);
    }

    /**
     * NG-1 / NG-2 — disabled modules come from settings first, so an
     * administrator can switch one off without a deployment; config is the
     * fallback for a fresh install.
     *
     * @return array<int, string>
     */
    public static function disabledModules(): array
    {
        $configured = Settings::array('modules.disabled', []);

        if ($configured !== []) {
            return array_map(static fn ($value) => (string) $value, $configured);
        }

        return (array) config('gondal.disabled_modules', []);
    }

    /** Is a route the current one, for the `active` class? */
    public static function isActive(string $routeName): bool
    {
        $current = request()->route()?->getName();

        if ($current === null) {
            return false;
        }

        // A detail screen keeps its list's nav item highlighted.
        $base = str_replace(['.index', '.show'], '', $routeName);

        return $current === $routeName || str_starts_with($current, $base.'.');
    }
}
