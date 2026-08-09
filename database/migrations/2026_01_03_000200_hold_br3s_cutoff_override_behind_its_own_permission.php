<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BR-3 — "a delivery after the cut-off may be accepted with an explicit
 * SUPERVISOR override that is logged." PERM-1 — "New permissions arrive by
 * migration."
 *
 * THE PROBLEM. The override was logged and attributed, and that was all. Nothing
 * checked that the person overriding was a supervisor, or even a second person:
 * `DeliveryService::guardCutoff()` asked only that the boolean was set and the
 * reason was non-blank, then stamped `cutoff_override_by_user_id` with the
 * RECORDING agent's own id. A Collection Agent scoped to one point could accept
 * 09:45 milk against a 07:00 cut-off by typing a sentence, on the web, on the
 * REST API and through the offline sync alike. BR-3 exists to keep milk that has
 * been standing in the sun out of the pool and off the payroll; as built it asked
 * the person carrying that milk to authorise itself.
 *
 * `milk.deliveries.cutoff_override` is a fourth action on the delivery resource
 * rather than a flavour of `edit`, for the same reason `community.farmers.
 * validate` is a fourth action rather than a flavour of `edit` (see
 * PermissionSeeder): "record what arrived" and "overrule the rule about when it
 * may arrive" are different authorities, and the agent is trusted with the first
 * and deliberately not the second.
 *
 * PERM-2 — marked sensitive. It is the one grant that lets a holder move milk
 * into the payable pool that the rule says should not be there, so granting it
 * should carry the warning the role screen already renders for sensitive rows.
 *
 * ALSO HERE: `milk.delivery_backdate_limit_days`. `delivered_at` was validated as
 * nothing more than `['nullable','date']`, so a delivery could be dated into next
 * month or back into a day whose totals have already been reported, dispatched
 * and reconciled — and BR-3's cut-off comparison would then be run against a day
 * nobody is standing in. §9 keeps this kind of knob as a row an administrator
 * edits, never as a constant (§18.7); 7 days is the value in force today, not a
 * rule, and the business changes it in Settings.
 */
return new class extends Migration
{
    private const PERMISSION = ['milk.deliveries', 'cutoff_override'];

    /**
     * The roles §5.1 puts above the point. Deliberately NOT Collection Agent:
     * the whole defect was that the recorder authorised themself.
     */
    private const GRANTED_TO = ['Milk Collection Supervisor', 'Milk Collection Officer'];

    public function up(): void
    {
        [$resourceKey, $action] = self::PERMISSION;

        $permissionId = DB::table('permissions')
            ->where('resource_key', $resourceKey)
            ->where('action', $action)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'resource_key' => $resourceKey,
                'action' => $action,
                'label' => 'Override the delivery cut-off',
                'description' => 'Sensitive — accepts milk presented after the point’s cut-off (BR-3)',
                'is_sensitive' => true,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * The grants are re-stated here so an EXISTING install keeps working the
         * moment this migration runs. On a fresh `migrate --seed` the RoleSeeder
         * runs afterwards and rewrites `permission_role` from its own catalogue,
         * so the catalogue itself is where this grant has to live permanently —
         * see the note in DeliveryService::guardCutoff.
         */
        $roleIds = DB::table('roles')->whereIn('name', self::GRANTED_TO)->pluck('id');

        foreach ($roleIds as $roleId) {
            $alreadyGranted = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($alreadyGranted) {
                continue;
            }

            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('settings')->where('key', 'milk.delivery_backdate_limit_days')->doesntExist()) {
            DB::table('settings')->insert([
                'key' => 'milk.delivery_backdate_limit_days',
                'value' => json_encode(['v' => 7]),
                'group' => 'milk',
                'label' => 'Furthest a delivery may be backdated (days)',
                'value_type' => 'integer',
                'help_text' => 'A delivery dated earlier than this is refused: its day has already been dispatched, reconciled and reported. Set to 0 to allow any past date.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * PERM-3 forbids deleting a permission, so `down()` retires it and strips the
     * grants rather than removing the row — the audit entries this migration's
     * refusals will have written must still resolve to a real permission.
     */
    public function down(): void
    {
        [$resourceKey, $action] = self::PERMISSION;

        $permissionId = DB::table('permissions')
            ->where('resource_key', $resourceKey)
            ->where('action', $action)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();

            DB::table('permissions')->where('id', $permissionId)->update([
                'retired_at' => now(),
                'retired_reason' => 'BR-3 override rolled back to self-service',
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->where('key', 'milk.delivery_backdate_limit_days')->delete();
    }
};
