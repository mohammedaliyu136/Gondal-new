<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERM-1 — `purchase.requisitions.spend`.
 *
 * Kept on the requisition resource rather than invented as a finance one,
 * because the thing being recorded is a fact about a requisition and the people
 * who need to see it are the people who already see requisitions.
 *
 * Held by Accounts and the General Manager. Deliberately NOT by the requester:
 * the person who asked for the money is not the person who confirms it left,
 * which is the same separation BR-18 makes on approvals and the cash book makes
 * on floats.
 */
return new class extends Migration
{
    private const RESOURCE = 'purchase.requisitions';

    private const ACTION = 'spend';

    private const ROLES = ['System Administrator', 'Accounts', 'General Manager'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('resource_key', self::RESOURCE)
            ->where('action', self::ACTION)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'resource_key' => self::RESOURCE,
                'action' => self::ACTION,
                'label' => 'Requisitions',
                'description' => 'Record that an approved requisition was actually paid',
                'is_sensitive' => true,
                'position' => (int) DB::table('permissions')->max('position') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('permissions')->where('id', $permissionId)->update([
                'retired_at' => null,
                'retired_reason' => null,
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            $held = DB::table('permission_role')
                ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

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

    public function down(): void
    {
        // PERM-3 — retired, never deleted.
        DB::table('permissions')
            ->where('resource_key', self::RESOURCE)
            ->where('action', self::ACTION)
            ->update([
                'retired_at' => now(),
                'retired_reason' => 'Requisition spend rolled back',
                'updated_at' => now(),
            ]);
    }
};
