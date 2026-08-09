<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERM-3 — retire the `delete` action on every operational record type.
 *
 * THE PROBLEM. Twenty `delete` permissions were seeded, granted to live roles,
 * and checked by no route, controller, service or view. Nothing in the milk
 * chain, the shop, HR, logistics, purchasing or the community programme is ever
 * hard-deleted — a sale is voided, a point is suspended, a consignment is
 * adjusted, and DM-3 keeps the audit log append-only. So the grant appeared on
 * the roles screen as real authority, was counted when a reviewer tallied who
 * could destroy what, and enabled nothing at all.
 *
 * Administration keeps its deletes (`admin.users`, `admin.roles`,
 * `shop.categories`): those genuinely remove a row, and are genuinely checked.
 *
 * PERM-3 forbids deleting a permission, so the rows stay with `retired_at` set.
 * A historical grant, and the audit entry that recorded it, still resolve to a
 * real permission and still read correctly years later.
 *
 * The grants themselves ARE removed from live roles, because PERM-3's other half
 * is that a retired permission is never held by one. Retired roles keep theirs,
 * so the before/after grant sets in the audit log continue to resolve.
 */
return new class extends Migration
{
    private const RESOURCES = [
        'milk.points', 'milk.deliveries', 'milk.consignment.confirm', 'milk.adjustment',
        'milk.grade', 'milk.rejection', 'milk.batch.dispatch', 'milk.reconciliation',
        'logistics.trips', 'logistics.payments', 'purchase.requisitions',
        'community.farmers', 'community.cooperatives', 'community.coop.savings',
        'community.extension', 'shop.inventory', 'shop.sales',
        'hr.employees', 'hr.leave', 'hr.payroll',
    ];

    public function up(): void
    {
        DB::table('permissions')
            ->whereIn('resource_key', self::RESOURCES)
            ->where('action', 'delete')
            ->whereNull('retired_at')
            ->update([
                'retired_at' => '2026-08-01 09:00:00',
                'retired_reason' => 'No delete path exists: the record is voided or deactivated instead',
                'updated_at' => now(),
            ]);

        /*
         * Strip every retired grant from every role that is not itself retired.
         * Written against `retired_at` rather than the list above so that the
         * eleven Project-module permissions retired earlier are cleaned up by the
         * same pass — they were left granted, which is the same defect.
         */
        $retiredPermissionIds = DB::table('permissions')
            ->whereNotNull('retired_at')
            ->pluck('id');

        $liveRoleIds = DB::table('roles')
            ->where('status', '!=', 'retired')
            ->whereNull('deleted_at')
            ->pluck('id');

        DB::table('permission_role')
            ->whereIn('permission_id', $retiredPermissionIds)
            ->whereIn('role_id', $liveRoleIds)
            ->delete();
    }

    /**
     * Reversible as far as it can honestly be: the permissions come back to life.
     * The grants do not, because which live role held which retired permission is
     * not recoverable from this table once the rows are gone — and re-running the
     * role seeder restores them from the catalogue, which is the real source.
     */
    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('resource_key', self::RESOURCES)
            ->where('action', 'delete')
            ->update([
                'retired_at' => null,
                'retired_reason' => null,
                'updated_at' => now(),
            ]);
    }
};
