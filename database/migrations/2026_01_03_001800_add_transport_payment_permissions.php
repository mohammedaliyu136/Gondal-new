<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERM-1 — new permissions arrive by migration.
 *
 * `logistics.payments` already existed with view / create / edit / approve, all
 * marked sensitive, and all of them gated a screen that was never built. Two
 * actions are missing for the run to be payable end to end:
 *
 *   disburse — record money handed to a rider. Held by the Milk Collection
 *              Officer, exactly as on the farmer side: the person who pays at a
 *              centre is not the person who prepares the sheet in the office.
 *   reverse  — undo a payment. Accounts and the General Manager only. Taking
 *              money back from a rider is at least as consequential as paying it.
 *
 * The role grants below are stated HERE and again in RoleSeeder, because that
 * catalogue rewrites `permission_role` wholesale on every seed and a grant that
 * lives only in a migration is taken straight back off at the next db:seed.
 *
 * System Administrator is named on every line for the reason set out in
 * 2026_01_03_001600: RoleSeeder gives that role `['*']`, `*` expands over LIVE
 * permissions only, and a permission sitting retired at the moment somebody
 * reseeds is silently dropped from the admin role.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: bool, 3: array<int, string>}> */
    private const PERMISSIONS = [
        ['disburse', 'Record money handed to a rider or driver', true,
            ['System Administrator', 'Milk Collection Officer', 'Accounts']],
        ['reverse', 'Reverse a transport payment or a whole run', true,
            ['System Administrator', 'Accounts', 'General Manager']],
    ];

    private const RESOURCE = 'logistics.payments';

    /**
     * Roles that should hold the FOUR pre-existing actions now that the screen
     * behind them exists. Until this migration they were held by whoever
     * RoleSeeder said, gating nothing.
     *
     * @var array<string, array<int, string>>
     */
    private const EXISTING = [
        'view' => ['System Administrator', 'Accounts', 'General Manager', 'Executive Director',
            'Internal Audit', 'External Audit', 'Milk Collection Officer', 'Milk Collection Supervisor'],
        'create' => ['System Administrator', 'Accounts'],
        'approve' => ['System Administrator', 'Accounts', 'General Manager'],
    ];

    public function up(): void
    {
        $position = (int) DB::table('permissions')->max('position');

        foreach (self::PERMISSIONS as [$action, $description, $sensitive, $roles]) {
            $permissionId = DB::table('permissions')
                ->where('resource_key', self::RESOURCE)
                ->where('action', $action)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'resource_key' => self::RESOURCE,
                    'action' => $action,
                    'label' => 'Transport payments',
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                    'position' => ++$position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Coming back up after a rollback has to clear the retirement
                // `down()` set — see 2026_01_03_001600 for what leaving it costs.
                DB::table('permissions')->where('id', $permissionId)->update([
                    'label' => 'Transport payments',
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                    'retired_at' => null,
                    'retired_reason' => null,
                    'updated_at' => now(),
                ]);
            }

            $this->grant($permissionId, $roles);
        }

        foreach (self::EXISTING as $action => $roles) {
            $permissionId = DB::table('permissions')
                ->where('resource_key', self::RESOURCE)
                ->where('action', $action)
                ->value('id');

            if ($permissionId !== null) {
                $this->grant($permissionId, $roles);
            }
        }
    }

    public function down(): void
    {
        // PERM-3 — retired, never deleted. The grants stay so the before/after
        // sets in the audit log still resolve.
        DB::table('permissions')
            ->where('resource_key', self::RESOURCE)
            ->whereIn('action', array_column(self::PERMISSIONS, 0))
            ->update([
                'retired_at' => now(),
                'retired_reason' => 'Transport payment rolled back',
                'updated_at' => now(),
            ]);
    }

    /** @param  array<int, string>  $roles */
    private function grant(int $permissionId, array $roles): void
    {
        foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
            $held = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($held) {
                continue;
            }

            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
