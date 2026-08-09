<?php

namespace Database\Seeders;

use App\Authorization\ScopeType;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The walkthrough cast: one dedicated, correctly-scoped user for every stage of
 * every chain, so a reviewer can sign in and walk a record end to end without
 * borrowing an account that also does something else.
 *
 *   Milk        agent (one per Kumbotso point) → officer → logistics → supervisor
 *   Community   extension agents (disjoint communities) → CE officer → delivery lead
 *   Requisition requester → dept head ×2 → audit → ED → accounts → GM
 *   Shop        manager → inventory officer → sales officers ×2 (scope `own`)
 *   HRM         HR manager → two staff-only employees whose leave routes to
 *               the two seeded department heads
 *
 * These accounts exist to be signed into (SEED-1); they carry the same shared
 * demo password as DemoDataSeeder's staff and have two-factor OFF so a review
 * session is one step (SEED-4). Gated behind the same demo flag — never in
 * production (SEED-2). Everything here is idempotent.
 */
class DemoChainCastSeeder extends Seeder
{
    private const PASSWORD = 'GondalDemo!2026';

    private User $actor;

    public function run(): void
    {
        $this->actor = User::query()->where('email', 'sadiq.ahmed@gondalfulbe.ng')->firstOrFail();

        $kumbotso = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->firstOrFail();

        /*
         * Milk collection — a dedicated agent per Kumbotso point. PRD §16:
         * "each point is run by one collection agent". The register is updated
         * here and the scope is re-derived from it below, so the two can never
         * disagree.
         */
        $agents = [
            1 => ['Musa Ibrahim', 'musa.ibrahim'],
            2 => ['Auwal Sule', 'auwal.sule'],
            3 => ['Bilkisu Garba', 'bilkisu.garba'],
            4 => ['Nuhu Adamu', 'nuhu.adamu'],
            5 => ['Hadiza Kabir', 'hadiza.kabir'],
            6 => ['Isa Muhammad', 'isa.muhammad'],
            7 => ['Rukayya Sani', 'rukayya.sani'],
            8 => ['Kabiru Yusuf', 'kabiru.yusuf'],
        ];

        foreach ($agents as $ordinal => [$name, $handle]) {
            $point = CollectionPoint::withoutDataScope()
                ->where('code', 'PT-'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT))
                ->first();

            if ($point === null) {
                continue;
            }

            $user = $this->makeUser($name, $handle, 'Milk Collection', 'Collection Agent');
            $this->assign($user, 'Collection Agent', ScopeType::Point, $point->getKey());

            $point->forceFill(['agent_user_id' => $user->getKey()])->save();
        }

        $officers = [
            ['Maryam Yakubu', 'maryam.yakubu', 'Milk Collection Officer'],
            ['Zainab Lawal', 'zainab.lawal', 'Milk Collection Officer'],
            ['Salisu Adamu', 'salisu.adamu', 'Logistics Officer'],
        ];

        foreach ($officers as [$name, $handle, $role]) {
            $user = $this->makeUser($name, $handle, $role === 'Logistics Officer' ? 'Logistics' : 'Milk Collection', $role);
            $this->assign($user, $role, ScopeType::Center, $kumbotso->getKey());
        }

        $bashir = $this->makeUser('Bashir Danladi', 'bashir.danladi', 'Milk Collection', 'Milk Collection Supervisor');
        $this->assign($bashir, 'Milk Collection Supervisor', ScopeType::Network);

        // Displaced agents keep only the points the register still gives them.
        $this->syncAgentScopesFromRegister();

        /*
         * Community engagement — three agents with DISJOINT community sets, so
         * the scope boundary between two holders of the same role is walkable.
         */
        $communityIds = Community::query()->orderBy('id')->pluck('id')->all();

        $lead = $this->makeUser('Hafsat Bello', 'hafsat.bello', 'Community Engagement', 'Delivery Lead');
        $this->assign($lead, 'Delivery Lead', ScopeType::Communities, null, $communityIds);

        $ceo = $this->makeUser('Aminu Jibril', 'aminu.jibril', 'Community Engagement', 'Community Engagement Officer');
        $this->assign($ceo, 'Community Engagement Officer', ScopeType::Communities, null, $communityIds);

        $extension = [
            ['Jamila Usman', 'jamila.usman', 'EXT-010', array_slice($communityIds, 0, 3)],
            ['Garba Mustapha', 'garba.mustapha', 'EXT-011', array_slice($communityIds, 3, 3)],
            ['Sadiya Habibu', 'sadiya.habibu', 'EXT-012', array_slice($communityIds, 6, 3)],
        ];

        foreach ($extension as [$name, $handle, $code, $slice]) {
            $user = $this->makeUser($name, $handle, 'Community Engagement', 'Extension Agent');
            $this->assign($user, 'Extension Agent', ScopeType::Communities, null, $slice);

            $agentId = DB::table('extension_agents')->where('code', $code)->value('id')
                ?? DB::table('extension_agents')->insertGetId([
                    'user_id' => $user->getKey(),
                    'code' => $code,
                    'reports_to_user_id' => $ceo->getKey(),
                    'visit_target_monthly' => 30,
                    'enrolment_target_monthly' => 10,
                    'status' => 'active',
                    'created_by_user_id' => $this->actor->getKey(),
                    'created_at' => Wat::now(),
                    'updated_at' => Wat::now(),
                ]);

            foreach ($slice as $communityId) {
                DB::table('agent_community')->insertOrIgnore([
                    'extension_agent_id' => $agentId,
                    'community_id' => $communityId,
                    'assigned_at' => Wat::now(),
                    'created_at' => Wat::now(),
                    'updated_at' => Wat::now(),
                ]);
            }
        }

        // The register of field agents must agree with who holds the role —
        // the same class of drift SCOPE-1 suffered on collection points.
        $this->retireOrphanedExtensionAgents();

        /*
         * Requisition approvals — every stage staffed by a dedicated person,
         * with the two department heads the walkable requisitions need.
         */
        $tijjani = $this->makeUser('Tijjani Usman', 'tijjani.usman', 'Logistics', 'Logistics Officer');
        $this->assign($tijjani, 'Logistics Officer', ScopeType::Center, $kumbotso->getKey());

        foreach ([
            ['Lawal Ibrahim', 'lawal.ibrahim', 'Logistics'],
            ['Hauwa Abdullahi', 'hauwa.abdullahi', 'Milk Collection'],
        ] as [$name, $handle, $departmentName]) {
            $department = Department::query()->where('name', $departmentName)->firstOrFail();
            $user = $this->makeUser($name, $handle, $departmentName, 'Department Head');
            $this->assign($user, 'Department Head', ScopeType::Department, $department->getKey());
            $department->forceFill(['head_user_id' => $user->getKey()])->save();
        }

        foreach ([
            ['Saudat Bello', 'saudat.bello', 'Internal Audit', 'Internal Audit'],
            ['Haruna Gambo', 'haruna.gambo', 'Executive', 'Executive Director'],
            ['Fauziya Sani', 'fauziya.sani', 'Finance & Accounts', 'Accounts'],
            ['Abdulkadir Tanko', 'abdulkadir.tanko', 'Executive', 'General Manager'],
        ] as [$name, $handle, $departmentName, $role]) {
            $user = $this->makeUser($name, $handle, $departmentName, $role);
            $this->assign($user, $role, ScopeType::Network);
        }

        /*
         * One-Stop Shop — two sales officers because Sales Officer is scoped
         * `own`: proving one cannot see the other's transactions needs two.
         */
        $nafisa = $this->makeUser('Nafisa Garba', 'nafisa.garba', 'One-Stop Shop', 'One-Stop Shop Manager');
        $this->assign($nafisa, 'One-Stop Shop Manager', ScopeType::Network);
        Department::query()->where('name', 'One-Stop Shop')->update(['head_user_id' => $nafisa->getKey()]);

        $shehu = $this->makeUser('Shehu Mainasara', 'shehu.mainasara', 'One-Stop Shop', 'Inventory Officer');
        $this->assign($shehu, 'Inventory Officer', ScopeType::Network);

        foreach ([
            ['Usman Lawal', 'usman.lawal'],
            ['Halima Abubakar', 'halima.abubakar'],
        ] as [$name, $handle]) {
            $user = $this->makeUser($name, $handle, 'One-Stop Shop', 'Sales Officer');
            $this->assign($user, 'Sales Officer', ScopeType::Own);
        }

        /*
         * HRM — the HR manager who prepares payroll, and two ordinary staff-only
         * employees (three permissions each) whose leave requests route to the
         * two department heads seeded above.
         */
        $binta = $this->makeUser('Binta Yusuf', 'binta.yusuf', 'Human Resources', 'HR Manager');
        $this->assign($binta, 'HR Manager', ScopeType::Network);
        Department::query()->where('name', 'Human Resources')->update(['head_user_id' => $binta->getKey()]);

        $this->makeUser('Nuraini Sabo', 'nuraini.sabo', 'Logistics', 'Transport Clerk');
        $this->makeUser('Yakubu Hamza', 'yakubu.hamza', 'Milk Collection', 'Collection Clerk');

        /*
         * SEED-4 — a review session is one step. The cast is created with
         * two-factor off; the named personas from DemoDataSeeder get the same
         * treatment so no chain stage needs a mail-log lookup.
         */
        User::query()->whereIn('email', array_map(fn (string $handle) => $handle.'@gondalfulbe.ng', [
            'sani.bello', 'halima.yusuf', 'idris.kabir', 'muhammad.bello',
            'umar.muduru', 'mohammed.aliyu', 'aliyu.danjuma', 'musa.abdulhamid',
            'rahma.sule', 'amina.kabir', 'hauwa.ibrahim', 'ibrahim.sale',
            'yusuf.garba', 'fatima.aliyu', 'zubaida.nuhu',
        ]))->update(['two_factor_enabled' => false]);

        $this->command?->info('Chain cast seeded — every stage of every chain has a dedicated sign-in.');
        $this->command?->warn('Shared demo password (SEED-1): '.self::PASSWORD);
    }

    /**
     * SCOPE-1 — re-derive every agent's point scope from the register, exactly
     * as DemoDataSeeder does, because this seeder moves PT-001..PT-008 to the
     * cast and the displaced agents must not keep access they no longer have.
     */
    private function syncAgentScopesFromRegister(): void
    {
        $pointsByAgent = CollectionPoint::withoutDataScope()
            ->whereNotNull('agent_user_id')
            ->get()
            ->groupBy('agent_user_id');

        foreach (RoleAssignment::query()
            ->where('scope_type', ScopeType::Point->value)
            ->whereHas('role', fn ($query) => $query->where('name', 'Collection Agent'))
            ->get() as $assignment) {
            $points = $pointsByAgent->get($assignment->user_id, collect());

            if ($points->isEmpty()) {
                $assignment->scopeTargets()->delete();

                continue;   // named on no point → scope fails closed
            }

            $assignment->forceFill(['scope_target_id' => $points->first()->getKey()])->save();

            foreach ($points as $point) {
                RoleUserScopeTarget::query()->firstOrCreate([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $point->getKey(),
                ]);
            }

            $assignment->scopeTargets()
                ->whereNotIn('target_id', $points->pluck('id')->all())
                ->delete();
        }
    }

    /**
     * An extension_agents row whose user no longer holds the Extension Agent
     * role is register/role drift — retire it rather than leave a field agent
     * on the books who cannot sign in as one.
     */
    private function retireOrphanedExtensionAgents(): void
    {
        $holderIds = RoleAssignment::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'Extension Agent'))
            ->pluck('user_id')->all();

        DB::table('extension_agents')
            ->whereNull('deleted_at')
            ->whereNotIn('user_id', $holderIds)
            ->update(['deleted_at' => Wat::now(), 'updated_at' => Wat::now()]);
    }

    private function makeUser(string $name, string $handle, string $departmentName, string $position): User
    {
        $email = $handle.'@gondalfulbe.ng';

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $departmentId = Department::query()->where('name', $departmentName)->value('id');

        $user = new User([
            'name' => $name,
            'email' => $email,
            'phone' => '080'.random_int(10_000_000, 99_999_999),
            'department_id' => $departmentId,
            'position' => $position,
            'status' => 'active',
            'is_test' => false,
            'two_factor_enabled' => false,
            'created_by_user_id' => $this->actor->getKey(),
        ]);

        // SEED-1 — same shared review password as DemoDataSeeder, same BR-31
        // caveat: only a seeder may do this, and only behind the demo flag.
        $user->password_hash = Hash::make(self::PASSWORD);
        $user->password_changed_at = Wat::now()->subDays(random_int(1, 30));
        $user->save();

        // ROLE-3 — the automatic role every user carries.
        $this->assign($user, 'Staff (self-service)', ScopeType::Own);

        // §6.8 — an employee record, so own-leave and own-payslip work.
        $employee = Employee::query()->firstOrCreate(['email' => $email], [
            'code' => 'EMP-'.str_pad((string) (Employee::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'phone' => $user->phone,
            'department_id' => $departmentId,
            'position' => $position,
            'grade_level' => 'GL-08',
            'employment_type' => 'permanent',
            'duty_station' => 'Kano',
            'joined_on' => Wat::today()->subDays(400)->toDateString(),
            'confirmed_on' => Wat::today()->subDays(220)->toDateString(),
            'gross_monthly_minor' => 250_000_00,
            'bank_name' => 'First Bank of Nigeria',
            'bank_account_masked' => '****'.random_int(1000, 9999),
            'next_of_kin_name' => 'Next of kin',
            'next_of_kin_phone' => '080'.random_int(10_000_000, 99_999_999),
            'status' => 'confirmed',
        ]);

        $user->forceFill(['employee_id' => $employee->getKey()])->save();

        return $user;
    }

    /**
     * @param  array<int, int>  $targetIds
     */
    private function assign(User $user, string $roleName, ScopeType $scopeType, ?int $targetId = null, array $targetIds = []): void
    {
        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null || $role->status === Role::STATUS_RETIRED) {
            return;
        }

        $assignment = RoleAssignment::query()->updateOrCreate(
            ['role_id' => $role->getKey(), 'user_id' => $user->getKey()],
            [
                'scope_type' => $scopeType->value,
                'scope_target_id' => $targetId,
                'assigned_by_user_id' => $this->actor->getKey(),
                'assigned_at' => Wat::now(),
            ],
        );

        foreach ($targetIds as $id) {
            RoleUserScopeTarget::query()->firstOrCreate([
                'role_user_id' => $assignment->getKey(),
                'target_id' => $id,
            ]);
        }
    }
}
