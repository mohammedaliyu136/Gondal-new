<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERM-1 — new permissions arrive by migration.
 *
 * `finance.cash` — the cash book.
 *
 *   view      — see who is holding money and what came back. Wide, because the
 *               control only works if more than one person can look at it.
 *   issue     — hand a float out. Accounts and the General Manager: this is the
 *               act of taking money out of the safe.
 *   reconcile — sign a float back in and record any variance. Deliberately NOT
 *               granted to the Milk Collection Officer, who is the person
 *               carrying the money. A float somebody signs back in themselves
 *               is a spreadsheet, not a control.
 *
 * The Milk Collection Officer gets `view` alone: they must be able to see their
 * own outstanding float and what the system thinks they have disbursed, because
 * being unable to check your own position before handing the bag back is how an
 * honest officer ends up unable to explain a shortfall.
 *
 * Grants are stated here and again in RoleSeeder — that catalogue rewrites
 * `permission_role` wholesale on every seed. System Administrator is named for
 * the reason set out in 2026_01_03_001600.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: bool, 3: array<int, string>}> */
    private const PERMISSIONS = [
        ['view', 'See cash floats drawn, disbursed and returned', false,
            ['System Administrator', 'Accounts', 'General Manager', 'Executive Director',
                'Internal Audit', 'External Audit', 'Milk Collection Officer', 'Milk Collection Supervisor']],
        ['issue', 'Hand a cash float to an officer', true,
            ['System Administrator', 'Accounts', 'General Manager']],
        ['reconcile', 'Sign a float back in and record the variance', true,
            ['System Administrator', 'Accounts', 'General Manager', 'Internal Audit']],
    ];

    private const RESOURCE = 'finance.cash';

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
                    'label' => 'Cash book',
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                    'position' => ++$position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('permissions')->where('id', $permissionId)->update([
                    'label' => 'Cash book',
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                    'retired_at' => null,
                    'retired_reason' => null,
                    'updated_at' => now(),
                ]);
            }

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
    }

    public function down(): void
    {
        // PERM-3 — retired, never deleted.
        DB::table('permissions')
            ->where('resource_key', self::RESOURCE)
            ->update([
                'retired_at' => now(),
                'retired_reason' => 'Cash book rolled back',
                'updated_at' => now(),
            ]);
    }
};
