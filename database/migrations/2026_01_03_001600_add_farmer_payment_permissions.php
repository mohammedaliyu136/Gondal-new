<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERM-1 — "New permissions arrive by migration."
 *
 * The five grants that govern paying a farmer. Four of them are PERM-2
 * sensitive: they are the only permissions in the system that cause money to
 * leave it.
 *
 * THE GRANTS ARE STATED TWICE, ON PURPOSE. RoleSeeder's catalogue rewrites
 * `permission_role` on every seed, so a grant that exists only here is removed
 * the next time anyone runs `db:seed`. This exact bug already happened once in
 * this codebase — `milk.deliveries.cutoff_override` was created by migration,
 * granted by migration, absent from the catalogue, and therefore held by nobody
 * but an administrator, so BR-3's supervisor override refused the supervisors it
 * names. `SeedIntegrityTest::assertEverySensitivePermissionHasAHolder()` now
 * fails the build if it recurs, and the companion entries in RoleSeeder are what
 * make these permanent.
 *
 * WHO HOLDS WHAT, AND WHY:
 *
 *   view       — wide. Accounts, GM, ED, Internal and External Audit. Reading
 *                what a farmer is owed is oversight, not authority.
 *   create     — Accounts only. Generating a run is a bookkeeping act.
 *   approve    — Accounts and the General Manager, mirroring WF-004 payroll.
 *                BR-18 stops the preparer approving their own run; the engine
 *                enforces it, not this list.
 *   disburse   — the Collection Officer, who is at the centre where cash is
 *                handed over. Deliberately NOT the Collection Agent: the agent
 *                records the deliveries, and letting the same person produce
 *                both sides of the reconciliation is the largest fraud surface
 *                in the module (docs/PLAN-FARMER-PAYMENTS.md §1.3).
 *   reverse    — Accounts and the General Manager. Taking money back is at
 *                least as consequential as sending it.
 */
return new class extends Migration
{
    /** [action, label, description, is_sensitive, roles] */
    private const PERMISSIONS = [
        /*
         * The Collection Officer is on this list because they hold `disburse`,
         * and the screen carrying the Pay control is gated on `view`. Granting
         * the act without the sight of it produced a 403 on the only page from
         * which the act can be performed — a permission that reads as held and
         * behaves as denied.
         */
        ['view', 'Farmer payments', 'See what farmers are owed and what has been paid', false,
            ['System Administrator', 'Accounts', 'General Manager', 'Executive Director',
                'Internal Audit', 'External Audit', 'Milk Collection Officer']],
        ['create', 'Farmer payments', 'Generate a farmer payment run', false,
            ['System Administrator', 'Accounts']],
        ['approve', 'Farmer payments', 'Approve a farmer payment run for disbursement', true,
            ['System Administrator', 'Accounts', 'General Manager']],
        ['disburse', 'Farmer payments', 'Record money handed to a farmer', true,
            ['System Administrator', 'Milk Collection Officer']],
        ['reverse', 'Farmer payments', 'Reverse a farmer payment or a whole run', true,
            ['System Administrator', 'Accounts', 'General Manager']],
    ];

    /*
     * System Administrator is named on every line even though RoleSeeder gives
     * that role `['*']`, which already means "every permission".
     *
     * `*` expands over LIVE permissions only. When these five were sitting
     * retired — see the note in up() — the next db:seed rewrote
     * `permission_role` and simply skipped them, so the admin role silently
     * lost the whole module while Accounts and the General Manager kept theirs
     * for the one reason that they are named explicitly. Naming the admin role
     * here costs nothing and removes its dependence on the retirement state of
     * a row at the moment somebody happened to reseed.
     */
    private const RESOURCE = 'finance.farmer_payments';

    public function up(): void
    {
        $position = (int) DB::table('permissions')->max('position');

        foreach (self::PERMISSIONS as [$action, $label, $description, $sensitive, $roles]) {
            $permissionId = DB::table('permissions')
                ->where('resource_key', self::RESOURCE)
                ->where('action', $action)
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'resource_key' => self::RESOURCE,
                    'action' => $action,
                    'label' => $label,
                    'description' => $description,
                    'is_sensitive' => $sensitive,
                    'position' => ++$position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                /*
                 * The row survives a rollback — PERM-3 forbids deleting it, so
                 * `down()` retires it instead. Coming back up therefore has to
                 * clear that retirement, and this branch did nothing at all
                 * before.
                 *
                 * The consequence was not a missing permission, which anyone
                 * would notice. It was five permissions present in the table,
                 * granted to the right roles, listed on the role screen — and
                 * filtered out of every authorisation query by
                 * `whereNull('permissions.retired_at')`. The whole farmer
                 * payment module answered 403 to everybody, including the
                 * General Manager, with the access-denied screen naming a
                 * permission the user could see they held.
                 *
                 * Exactly the "reads as held, behaves as denied" failure the
                 * note above this list warns about, arrived at by a different
                 * road.
                 */
                DB::table('permissions')->where('id', $permissionId)->update([
                    'label' => $label,
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

        /*
         * §9 — the run reference is a sequence row an administrator can edit,
         * not a format baked into code. Yearly, like requisitions: a payment run
         * is naturally spoken about as "the third run of 2026".
         */
        if (! DB::table('sequences')->where('key', 'payment_runs')->exists()) {
            DB::table('sequences')->insert([
                'key' => 'payment_runs',
                'label' => 'Farmer payment run',
                'prefix' => 'PRUN',
                'digits' => 4,
                'reset_period' => 'yearly',
                'reference_format' => '{prefix}-{year}-{number}',
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * PERM-3 forbids deleting a permission — the audit entries these refusals
     * will have written must still resolve to a real row. Retire and strip the
     * grants instead.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->where('resource_key', self::RESOURCE)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();

        DB::table('permissions')->whereIn('id', $ids)->update([
            'retired_at' => now(),
            'retired_reason' => 'Phase 7 farmer payment rolled back',
            'updated_at' => now(),
        ]);

        DB::table('sequences')->where('key', 'payment_runs')->delete();
    }
};
