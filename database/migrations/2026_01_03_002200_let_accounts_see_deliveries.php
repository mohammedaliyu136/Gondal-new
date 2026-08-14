<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounts could not see a single delivery, and the cost report showed them
 * zeros.
 *
 * SCOPE-4 says aggregates respect scope, and every report therefore runs its
 * queries through the models' global scope. `Delivery` narrows on
 * `milk.deliveries` — a permission the Accounts role did not hold at all — so
 * the constraint resolved to an empty set and the cost-per-litre report
 * answered "₦0.00 per litre" to the only role that had a reason to run it. No
 * error, no empty state, just a confident wrong number.
 *
 * This is the same failure mode as the payment run that silently paid nobody:
 * a permission gate that passes while the data scope behind it is empty.
 *
 * The grant is right on its own merits rather than as a workaround. Accounts
 * already views consignments, batches and factory reconciliation — the whole
 * chain either side of a delivery — and they cannot check whether a farmer was
 * paid for the right litres without seeing the litres. It is `view` only: the
 * cut-off override and the ability to record or amend a delivery stay with the
 * people at the point.
 */
return new class extends Migration
{
    private const GRANTS = [
        'milk.deliveries' => ['view'],
    ];

    private const ROLE = 'Accounts';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        if ($roleId === null) {
            return;
        }

        foreach (self::GRANTS as $resource => $actions) {
            $ids = DB::table('permissions')
                ->where('resource_key', $resource)
                ->whereIn('action', $actions)
                ->whereNull('retired_at')
                ->pluck('id');

            foreach ($ids as $permissionId) {
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
        $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');

        if ($roleId === null) {
            return;
        }

        foreach (self::GRANTS as $resource => $actions) {
            $ids = DB::table('permissions')
                ->where('resource_key', $resource)
                ->whereIn('action', $actions)
                ->pluck('id');

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $ids)
                ->delete();
        }
    }
};
