<?php

namespace Database\Seeders;

use App\Authorization\Scopes\DataScope;
use Illuminate\Database\Seeder;

/**
 * Seeding order matters:
 *
 *   1. Permissions    PERM-1 — roles grant them, so they exist first.
 *   2. Roles          ROLE-4 — 19 roles plus the 2 retired ones.
 *   3. Reference data §9 — locations, grades, reasons, sequences, settings.
 *   4. Workflows      BR-23 — stages reference roles, so roles come first.
 *   5. Bootstrap      the first administrator, so the system is usable at all.
 *   6. Demo data      NFR-12 — behind a flag, never in production.
 *
 * The whole run happens inside DataScope::asSystem() because a seeder acts for
 * the system rather than for a signed-in user; without it the first queries
 * would be filtered by nobody's scope.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DataScope::asSystem(function (): void {
            $this->call([
                PermissionSeeder::class,
                RoleSeeder::class,
                ReferenceDataSeeder::class,
                WorkflowSeeder::class,
                BootstrapAdminSeeder::class,
            ]);

            // NFR-12 — "Seeded demo data behind a flag, so the prototype's
            // figures can be reproduced for review."
            if (config('gondal.seed_demo_data')) {
                $this->call(DemoDataSeeder::class);

                // §16 — the walkthrough cast: a dedicated, correctly-scoped
                // sign-in for every stage of every chain. Runs after the demo
                // data because it re-points part of the register (PT-001..008)
                // at its own agents.
                $this->call(DemoChainCastSeeder::class);
            }

            /*
             * The real people from the review meeting. Runs alongside the demo
             * data rather than inside it: invented staff exist to exercise the
             * system, these exist to use it, and a reseed must not delete the
             * pilot's own accounts.
             */
            if (config('gondal.seed_pilot_users')) {
                $this->call(PilotUsersSeeder::class);
            }
        });
    }
}
