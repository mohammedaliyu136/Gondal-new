<?php

namespace Database\Seeders;

use App\Authorization\ScopeType;
use App\Models\Department;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one account that must exist for anybody to be able to sign in at all.
 *
 * AUTH-8 — there is no self-registration, so without this seeder a fresh install
 * has no way in.
 *
 * BR-31 — "Administrators never see or set a user's password." That holds for
 * every account created through the UI. This first account is the unavoidable
 * exception: somebody has to be able to sign in before there is an administrator
 * to send an activation code. It is therefore given a RANDOM password and
 * `password_changed_at = null`, which AUTH-5 treats as expired — so the first
 * action after signing in is forced to be choosing a real password. The
 * bootstrap password is printed to the console once and stored nowhere.
 */
class BootstrapAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('GONDAL_BOOTSTRAP_ADMIN_EMAIL', 'admin@gondalfulbe.ng');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $department = Department::query()->firstOrCreate(
            ['name' => 'Information Technology'],
            ['cost_centre' => 'IT-001', 'status' => 'active'],
        );

        $password = (string) env('GONDAL_BOOTSTRAP_ADMIN_PASSWORD', Str::password(16));

        $admin = new User([
            'name' => (string) env('GONDAL_BOOTSTRAP_ADMIN_NAME', 'Bootstrap Administrator'),
            'email' => $email,
            'phone' => null,
            'department_id' => $department->getKey(),
            'position' => 'System Administrator',
            'status' => 'active',
            'is_test' => false,
            /*
             * Off, matching the system default set in the 001400 migration.
             *
             * This is the account with the most authority in the system, so it
             * is the one where a second factor is most defensible — but it is
             * also the account somebody has to sign into before there is anyone
             * to email a code to, and leaving it on made the bootstrap
             * dependent on working mail on a fresh install. Turn it on from
             * Admin → Users once real administrators exist.
             */
            'two_factor_enabled' => false,
        ]);

        // The column is NOT NULL and deliberately not fillable, so it is set
        // directly rather than mass-assigned. AUTH-5 — password_changed_at stays
        // null, which counts as expired and forces a change on first sign-in.
        $admin->password_hash = Hash::make($password);
        $admin->password_changed_at = null;
        $admin->save();

        $this->assign($admin, 'System Administrator', ScopeType::Network);
        $this->assign($admin, 'Staff (self-service)', ScopeType::Own);

        $this->command?->newLine();
        $this->command?->warn('Bootstrap administrator created — this password is shown once and stored nowhere:');
        $this->command?->line("  email:    {$email}");
        $this->command?->line("  password: {$password}");
        $this->command?->line('  You will be required to change it on first sign-in.');
        $this->command?->newLine();
    }

    private function assign(User $user, string $roleName, ScopeType $scopeType, ?int $targetId = null): void
    {
        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null) {
            return;
        }

        RoleAssignment::query()->updateOrCreate(
            ['role_id' => $role->getKey(), 'user_id' => $user->getKey()],
            [
                'scope_type' => $scopeType->value,
                'scope_target_id' => $targetId,
                'assigned_at' => Wat::now(),
            ],
        );
    }
}
