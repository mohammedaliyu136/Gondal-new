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
 * The real people from the review meeting of 30 Jul 2026.
 *
 * Separate from DemoDataSeeder on purpose. The demo dataset is invented staff for
 * exercising the system; these are actual named colleagues with real addresses,
 * and they need to survive a reseed so the pilot does not have to be rebuilt by
 * hand every time.
 *
 * WHERE THE ROLES COME FROM
 *
 * The meeting notes name the requisition chain (Department Head → Internal Audit →
 * Executive Director → Accounts → General Manager) and record who was asked to do
 * what, but they do not state a job title for every attendee. Each assignment
 * below says whether it is EVIDENCED by the notes or an ASSUMPTION to confirm —
 * guessing silently is how a permission model ends up wrong in a way nobody
 * notices until someone is refused.
 *
 * PASSWORDS. BR-31 says an administrator never sets one. That holds in the
 * application; this seeder is the same unavoidable exception the bootstrap
 * administrator is, and for the same reason — somebody has to be able to sign in
 * before there is anyone to send an activation code. Set GONDAL_PILOT_PASSWORD to
 * something private, or leave it and each account is created activation-pending
 * with no usable password, exactly like a real new hire.
 */
class PilotUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('GONDAL_PILOT_PASSWORD', '');
        $twoFactor = (bool) env('GONDAL_PILOT_TWO_FACTOR', false);

        $people = [
            [
                'name' => 'Muhammad Bello',
                'email' => 'm.bello@gondalfulbe.ng',
                'role' => 'General Manager',
                'scope' => ScopeType::Network,
                'department' => 'Management',
                'basis' => 'ASSUMPTION — he owns the process definitions and the outstanding '
                    .'cooperative forms and shop detail, and describes every module; the notes '
                    .'give no job title. Confirm.',
            ],
            [
                'name' => 'Mohammed Aliyu',
                'email' => 'mohammedaliyu136@gmail.com',
                'role' => 'Executive Director',
                'scope' => ScopeType::Network,
                'department' => 'Management',
                'basis' => 'ASSUMPTION — raised the risk to live accounting and collection staff '
                    .'and the unaddressed Farm Manager role; the notes give no job title. Confirm.',
            ],
            [
                'name' => 'Umar Muduru',
                'email' => 'u.muduru@gondalfulbe.ng',
                'role' => 'Internal Audit',
                'scope' => ScopeType::Network,
                'department' => 'Internal Audit',
                'basis' => 'EVIDENCED — Internal Audit is stage 3 of the requisition chain the '
                    .'meeting agreed, and the surname matches.',
            ],
            [
                'name' => 'Sadiq Ahmed',
                'email' => 's.ahmed@gondalfulbe.ng',
                'role' => 'System Administrator',
                'scope' => ScopeType::Network,
                'department' => 'Information Technology',
                'basis' => 'EVIDENCED — assigned "Implement permissions system" and proposed the '
                    .'non-hardcoded permission architecture.',
            ],
            [
                'name' => 'M. Abdulhamid',
                'email' => 'm.abdulhamid@gondalfulbe.ng',
                'role' => null,
                'scope' => ScopeType::Own,
                'department' => null,
                'basis' => 'NO ROLE ASSIGNED — invited to the meeting but not recorded speaking, '
                    .'and no responsibility is attributed. Assign a role before they can do '
                    .'anything beyond self-service.',
            ],
        ];

        $this->command?->newLine();
        $this->command?->info('Pilot users — the real people from the 30 Jul 2026 review meeting');

        foreach ($people as $person) {
            $user = User::query()->where('email', $person['email'])->first();

            if ($user === null) {
                $department = $person['department'] === null ? null : Department::query()->firstOrCreate(
                    ['name' => $person['department']],
                    ['status' => 'active'],
                );

                $user = new User([
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'department_id' => $department?->getKey(),
                    'status' => 'active',
                    'is_test' => false,
                    'two_factor_enabled' => $twoFactor,
                ]);

                // Not fillable, so set directly — see the class docblock on BR-31.
                $user->password_hash = $password === ''
                    ? Hash::make(Str::random(64))
                    : Hash::make($password);
                $user->password_changed_at = $password === '' ? null : Wat::now();
                $user->save();
            }

            $this->assign($user, 'Staff (self-service)', ScopeType::Own);

            if ($person['role'] !== null) {
                $this->assign($user, $person['role'], $person['scope']);
            }

            $this->command?->line(sprintf(
                '  %-30s %-24s %s',
                $person['email'],
                $person['role'] ?? '(no role yet)',
                $password === '' ? 'activation pending' : 'password set',
            ));
            $this->command?->line('      '.$person['basis']);
        }

        $this->command?->newLine();

        if ($password === '') {
            $this->command?->warn('No GONDAL_PILOT_PASSWORD set, so these accounts have no usable password.');
            $this->command?->line('  Each one can be activated from Admin → Users → Resend activation,');
            $this->command?->line('  or set GONDAL_PILOT_PASSWORD and re-run this seeder.');
        } else {
            $this->command?->warn('All pilot accounts share the password in GONDAL_PILOT_PASSWORD.');
            $this->command?->line('  Change it per person before this reaches anyone outside the pilot.');
        }

        $this->command?->newLine();
    }

    private function assign(User $user, string $roleName, ScopeType $scope): void
    {
        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null) {
            $this->command?->warn('  role not found, skipped: '.$roleName);

            return;
        }

        $exists = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->exists();

        if ($exists) {
            return;
        }

        RoleAssignment::query()->create([
            'role_id' => $role->getKey(),
            'user_id' => $user->getKey(),
            'scope_type' => $scope->value,
            'assigned_at' => Wat::now(),
        ]);
    }
}
