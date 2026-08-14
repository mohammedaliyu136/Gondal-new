<?php

namespace Database\Seeders;

use App\Authorization\ScopeType;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\Department;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\Farmer;
use App\Models\Lga;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\Route as TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Wat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Milk collect test" — the smallest Adamawa dataset that lets the two
 * walkthroughs be driven end to end from an empty transaction log.
 *
 * Deliberately NOT DemoDataSeeder. That seeder builds a network already in
 * motion — 1,842 farmers and months of deliveries — which is the wrong start
 * for a walkthrough whose whole point is to watch one record move through
 * every stage. Here nothing has happened yet: the register exists, the
 * transaction tables are empty, and the first delivery recorded is DEL-0001.
 *
 * WHERE THE PLACES COME FROM
 *
 * Adamawa State, out of Yola. ReferenceDataSeeder seeds the six LGAs and the
 * 26 communities; this seeder puts one collection centre and two points on top
 * of them. Two points, not one, because the scope boundary is only walkable if
 * there is somewhere Musa is meant to be refused (PT-002).
 *
 * SCOPE-1 — an agent's point scope is derived from the point register, never
 * handed out independently, so the register is written first and the scope is
 * re-derived from it at the end.
 *
 * Idempotent: safe to re-run.
 *
 * NOT REGISTERED IN DatabaseSeeder, AND DELIBERATELY SO. It issues PT-001..003
 * and CTR-YOLA, and DemoDataSeeder issues PT-001..042 across six centres — the
 * codes are unique keys, so running both against one database means this seeder
 * silently re-points DemoDataSeeder's first three points at a different centre
 * and a different agent. The two are alternative worlds, not layers:
 *
 *     php artisan db:seed --class=MilkCollectTestSeeder
 *
 * on a database whose reference data is seeded and whose milk tables are empty.
 * Use DemoDataSeeder instead when you want the full 42-point network.
 */
class MilkCollectTestSeeder extends Seeder
{
    /** The centre every point in this dataset feeds. */
    private const CENTER_CODE = 'CTR-YOLA';

    private const CENTER_NAME = 'Yola North';

    private ?User $actor = null;

    public function run(): void
    {
        $this->actor = User::query()->where('email', 'sadiq.ahmed@gondalfulbe.ng')->first()
            ?? User::query()->where('email', 'admin@gondalfulbe.ng')->first();

        $this->seedDepartments();
        $this->relinkUsersToDepartments();

        $center = $this->seedCenter();
        $points = $this->seedPoints($center);

        $cooperative = $this->seedCooperative($points['PT-003']);
        $this->seedFarmers($points, $cooperative);
        $this->seedFleet($center);
        $this->seedExtensionAgents();

        $this->repointScopes($center);

        $this->report($center, $points, $cooperative);
    }

    /* ------------------------------------------------------------------ */

    private function seedDepartments(): void
    {
        $departments = [
            'Milk Collection' => 'MC-001',
            'Logistics' => 'LG-001',
            'Community Engagement' => 'CE-001',
            'One-Stop Shop' => 'OS-001',
            'Finance & Accounts' => 'FA-001',
            'Human Resources' => 'HR-001',
            'Internal Audit' => 'IA-001',
            'Executive' => 'EX-001',
        ];

        foreach ($departments as $name => $costCentre) {
            Department::query()->firstOrCreate(['name' => $name], [
                'cost_centre' => $costCentre,
                'status' => 'active',
            ]);
        }
    }

    /**
     * users.department_id was emptied with the rest of the register, but
     * users.position survived. Rebuild the link from the position each account
     * already carries rather than inventing one.
     */
    private function relinkUsersToDepartments(): void
    {
        $byPosition = [
            'Milk Collection' => ['Collection Agent', 'Milk Collection Officer', 'Milk Collection Supervisor', 'Collection Officer', 'Collection Clerk', 'Quality Officer'],
            'Logistics' => ['Logistics Officer', 'Transport Clerk'],
            'Community Engagement' => ['Extension Agent', 'Community Engagement Officer', 'Delivery Lead'],
            'One-Stop Shop' => ['Sales Officer', 'Inventory Officer', 'One-Stop Shop Manager', 'Shop Manager'],
            'Finance & Accounts' => ['Accounts', 'Accountant'],
            'Human Resources' => ['HR Manager'],
            'Internal Audit' => ['Internal Audit', 'Internal Auditor'],
            'Executive' => ['General Manager', 'Executive Director', 'System Administrator', 'Super Administrator'],
        ];

        foreach ($byPosition as $departmentName => $positions) {
            $departmentId = Department::query()->where('name', $departmentName)->value('id');

            User::query()
                ->whereIn('position', $positions)
                ->whereNull('department_id')
                ->update(['department_id' => $departmentId]);
        }

        // §6.8 — an employee record, so own-leave and own-payslip resolve.
        foreach (User::query()->whereNull('employee_id')->whereNotNull('position')->get() as $user) {
            $employee = Employee::query()->firstOrCreate(['email' => $user->email], [
                'code' => 'EMP-'.str_pad((string) (Employee::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'name' => $user->name,
                'phone' => $user->phone,
                'department_id' => $user->department_id,
                'position' => $user->position,
                'grade_level' => 'GL-08',
                'employment_type' => 'permanent',
                'duty_station' => 'Yola',
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
        }
    }

    private function seedCenter(): CollectionCenter
    {
        $lga = Lga::query()->where('name', 'Yola North')->firstOrFail();

        $center = CollectionCenter::withoutDataScope()
            ->firstOrNew(['code' => self::CENTER_CODE]);

        $center->fill([
            'name' => self::CENTER_NAME,
            'lga_id' => $lga->getKey(),
            'cold_storage_litres' => '5000.00',
            // Jimeta to the Yola factory.
            'distance_to_factory_km' => '14.00',
            'transport_fee_minor' => 120_000,
            'status' => 'active',
        ]);

        // The officer and the logistics officer are the walkthrough's own people.
        $center->officer_user_id = $this->userId('maryam.yakubu');
        $center->logistics_user_id = $this->userId('salisu.adamu');
        $center->created_by_user_id ??= $this->actor?->getKey();
        $center->save();

        return $center;
    }

    /**
     * Who runs which point.
     *
     * Tudun Wada is Sani Bello's. USER-STORIES.md §1, API-MOBILE.md, the README
     * persona list and journey-recordings/01-collection-agent all walk the
     * collection-agent story as sani.bello@gondalfulbe.ng scoped to Tudun Wada,
     * and the mobile contract literally ships "assigned_points": ["Tudun Wada"]
     * for him. DemoChainCastSeeder re-points PT-001..008 at its own cast, which
     * takes Tudun Wada off him and leaves the documented agent scoped to
     * nothing — this seeder does not repeat that.
     *
     * Musa keeps PT-001 because the walkthrough is written around him
     * recording at PT-001 and being refused PT-002; PT-001 is simply the
     * neighbouring Jimeta point rather than Sani Bello's.
     *
     * @return array<string, CollectionPoint>
     */
    private function seedPoints(CollectionCenter $center): array
    {
        $points = [
            // code, name, community, agent, cut-off override, transport fee
            ['PT-001', 'Jimeta', 'Jimeta', 'musa.ibrahim', null, 50_000],
            ['PT-002', 'Karewa', 'Karewa', 'auwal.sule', '07:30', 50_000],
            ['PT-003', 'Tudun Wada', 'Tudun Wada', 'sani.bello', null, 50_000],
        ];

        $made = [];

        foreach ($points as [$code, $name, $communityName, $handle, $cutoff, $fee]) {
            $community = Community::query()
                ->where('name', $communityName)
                ->where('lga_id', $center->lga_id)
                ->firstOrFail();

            $point = CollectionPoint::withoutDataScope()->firstOrNew(['code' => $code]);

            $point->fill([
                'name' => $name,
                'community_id' => $community->getKey(),
                'lga_id' => $center->lga_id,
                'collection_center_id' => $center->getKey(),
                // BR-3 — PT-001 runs on the 07:00 default; PT-002 overrides.
                'cutoff_time' => $cutoff,
                'transport_fee_minor' => $fee,
                'status' => 'active',
                'opened_on' => Wat::today()->subDays(280)->toDateString(),
            ]);

            $point->agent_user_id = $this->userId($handle);
            $point->created_by_user_id ??= $this->actor?->getKey();
            $point->save();

            $made[$code] = $point;
        }

        return $made;
    }

    private function seedCooperative(CollectionPoint $tudunWada): Cooperative
    {
        $cooperative = Cooperative::withoutDataScope()->firstOrNew(['code' => 'COOP-TDW']);

        $cooperative->fill([
            'name' => 'Tudun Wada Dairy Cooperative',
            'registered_on' => Wat::today()->subDays(1600)->toDateString(),
            'community_id' => $tudunWada->community_id,
            'lga_id' => $tudunWada->lga_id,
            'chairman_name' => 'Alhaji Umaru Bobbo',
            'secretary_name' => 'Malam Sale Hamman',
            'treasurer_name' => 'Hajiya Asabe Bappa',
            'contact_phone' => '08034761209',
            'collection_point_id' => $tudunWada->getKey(),
            // §9 defaults: 5% savings, 2% levy, ₦250/member/month social.
            'savings_deduction_pct' => '5.00',
            'levy_pct' => '2.00',
            'social_contribution_minor' => 25_000,
            'status' => 'active',
        ]);

        $cooperative->created_by_user_id ??= $this->actor?->getKey();
        $cooperative->save();

        /*
         * Both accounts open at zero. Aminu's step in the walkthrough is to
         * record the first savings or social-fund entry, and that reads oddly
         * against an opening balance nobody in the session put there.
         */
        foreach ([Cooperative::ACCOUNT_GENERAL, Cooperative::ACCOUNT_SOCIAL, Cooperative::ACCOUNT_SAVINGS] as $kind) {
            CooperativeAccount::query()->firstOrCreate(
                ['cooperative_id' => $cooperative->getKey(), 'kind' => $kind],
                ['balance_minor' => 0],
            );
        }

        return $cooperative;
    }

    /**
     * Enough farmers to pick from at each point, and no more. Real Fulbe names
     * from the Yola register's naming stock; herd sizes in the range a
     * smallholder in the dairy belt actually keeps.
     *
     * Jimeta and Tudun Wada farmers both belong to the Tudun Wada cooperative —
     * two adjacent settlements in the same Yola North ward sharing a society is
     * the ordinary arrangement, and it keeps the 5% savings deduction on the
     * path Musa's deliveries take.
     *
     * @param  array<string, CollectionPoint>  $points
     */
    private function seedFarmers(array $points, Cooperative $cooperative): void
    {
        $rosters = [
            // PT-001 Jimeta — Musa's point, the milk walkthrough.
            'PT-001' => [true, [
                ['Adamu Bobbo', 'M', 1974, 14, 6],
                ['Hamman Jauro', 'M', 1981, 9, 4],
                ['Asabe Ardo', 'F', 1986, 6, 3],
                ['Umaru Sambo', 'M', 1969, 22, 9],
                ['Fadimatu Buba', 'F', 1990, 5, 2],
                ['Ibrahim Njobdi', 'M', 1978, 17, 7],
                ['Ladi Danburam', 'F', 1983, 8, 4],
                ['Sale Hamidu', 'M', 1972, 25, 11],
                ['Yerima Gambo', 'M', 1988, 7, 3],
                ['Halima Ardo', 'F', 1992, 4, 2],
            ]],
            // PT-002 Karewa — the point Musa must not be able to open.
            'PT-002' => [false, [
                ['Bappa Mohammadu', 'M', 1976, 12, 5],
                ['Rakiya Usmanu', 'F', 1985, 6, 3],
                ['Jauro Aliyu', 'M', 1980, 15, 6],
            ]],
            // PT-003 Tudun Wada — Sani Bello's round, per USER-STORIES.md §1.
            'PT-003' => [true, [
                ['Buba Ardo Sambo', 'M', 1971, 19, 8],
                ['Aisha Njobdi', 'F', 1984, 7, 3],
                ['Modibbo Hamman', 'M', 1966, 28, 12],
                ['Zainabu Jauro', 'F', 1989, 5, 2],
                ['Ardo Usmanu', 'M', 1979, 16, 7],
                ['Maimuna Bobbo', 'F', 1993, 4, 2],
            ]],
        ];

        $ordinal = 0;

        foreach ($rosters as $code => [$inCooperative, $roster]) {
            $point = $points[$code];

            foreach ($roster as [$name, $gender, $yearOfBirth, $herd, $lactating]) {
                $ordinal++;
                $farmerCode = 'FRM-'.str_pad((string) $ordinal, 4, '0', STR_PAD_LEFT);

                $farmer = Farmer::withoutDataScope()->firstOrNew(['code' => $farmerCode]);

                $farmer->fill([
                    'name' => $name,
                    'gender' => $gender,
                    'year_of_birth' => $yearOfBirth,
                    'phone' => '08'.random_int(30_000_0000, 99_999_9999),
                    'community_id' => $point->community_id,
                    'lga_id' => $point->lga_id,
                    'cooperative_id' => $inCooperative ? $cooperative->getKey() : null,
                    'cooperative_member_no' => $inCooperative
                        ? 'TDW/'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT)
                        : null,
                    'default_collection_point_id' => $point->getKey(),
                    'herd_size' => $herd,
                    'lactating_count' => $lactating,
                    'enrolled_on' => Wat::today()->subDays(random_int(120, 900))->toDateString(),
                    // BR-36 — an unvalidated farmer still delivers, but payment
                    // waits. Leave these current so the walkthrough is not
                    // derailed by a hold nobody asked for.
                    'last_validated_on' => Wat::today()->subDays(random_int(20, 200))->toDateString(),
                    'status' => 'active',
                ]);

                $farmer->created_by_user_id ??= $this->actor?->getKey();
                $farmer->save();
            }
        }
    }

    private function seedFleet(CollectionCenter $center): void
    {
        // Adamawa registrations — YLA is Yola, GRE Girei.
        foreach (['YLA-412-AD' => 'motorcycle', 'YLA-733-KM' => 'commercial', 'GRE-158-JS' => 'company'] as $registration => $type) {
            Vehicle::query()->firstOrCreate(['registration' => $registration], [
                'type' => $type,
                'capacity_litres' => $type === 'motorcycle' ? '120.00' : '4000.00',
                'status' => 'active',
            ]);
        }

        // USER-1 — riders and drivers are records, not accounts.
        foreach ([
            ['Buba Danladi', 'rider'],
            ['Iliya Maigari', 'rider'],
            ['Yusufu Bitrus', 'driver'],
        ] as [$name, $type]) {
            Driver::query()->firstOrCreate(['name' => $name], [
                'phone' => '080'.random_int(10_000_000, 99_999_999),
                'licence_no' => 'LIC-'.random_int(10_000, 99_999),
                'type' => $type,
                'status' => 'active',
            ]);
        }

        // §9 — the route tariff Salisu picks in his stage of the walkthrough.
        TransportRoute::query()->firstOrCreate(['name' => 'Point → center (motorcycle, standard)'], [
            'from_type' => TransportRoute::ENDPOINT_POINT,
            'to_type' => TransportRoute::ENDPOINT_CENTER,
            'distance_km' => '8.00',
            'tariff_minor' => 50_000,
            'vehicle_type' => 'motorcycle',
            'status' => 'active',
        ]);

        TransportRoute::query()->firstOrCreate(['name' => self::CENTER_NAME.' → Factory'], [
            'from_type' => TransportRoute::ENDPOINT_CENTER,
            'from_id' => $center->getKey(),
            'to_type' => TransportRoute::ENDPOINT_FACTORY,
            'distance_km' => '14.00',
            'tariff_minor' => 120_000,
            'vehicle_type' => 'commercial',
            'status' => 'active',
        ]);
    }

    /**
     * Jamila is the field agent in the enrolment walkthrough and Aminu is who
     * she reports to; the register row is what makes her a field agent as
     * opposed to someone merely holding the role.
     */
    private function seedExtensionAgents(): void
    {
        $jamila = $this->userId('jamila.usman');
        $aminu = $this->userId('aminu.jibril');

        if ($jamila === null) {
            return;
        }

        $exists = DB::table('extension_agents')->where('code', 'EXT-010')->exists();

        if ($exists) {
            DB::table('extension_agents')->where('code', 'EXT-010')->update([
                'user_id' => $jamila,
                'reports_to_user_id' => $aminu,
                'deleted_at' => null,
                'status' => 'active',
                'updated_at' => Wat::now(),
            ]);
        } else {
            DB::table('extension_agents')->insert([
                'user_id' => $jamila,
                'code' => 'EXT-010',
                'reports_to_user_id' => $aminu,
                'visit_target_monthly' => 30,
                'enrolment_target_monthly' => 10,
                'status' => 'active',
                'created_by_user_id' => $this->actor?->getKey(),
                'created_at' => Wat::now(),
                'updated_at' => Wat::now(),
            ]);
        }

        $agentId = DB::table('extension_agents')->where('code', 'EXT-010')->value('id');

        // Her three communities, matching the scope she already holds.
        foreach (Community::query()->orderBy('id')->limit(3)->pluck('id') as $communityId) {
            DB::table('agent_community')->insertOrIgnore([
                'extension_agent_id' => $agentId,
                'community_id' => $communityId,
                'assigned_at' => Wat::now(),
                'created_at' => Wat::now(),
                'updated_at' => Wat::now(),
            ]);
        }
    }

    /**
     * The role assignments survived the data wipe but their targets pointed at
     * rows that no longer existed. Re-point them at what this seeder just
     * built, deriving the agents' point scope from the register (SCOPE-1).
     */
    private function repointScopes(CollectionCenter $center): void
    {
        // Centre-scoped roles → the one centre.
        foreach (['Milk Collection Officer', 'Logistics Officer', 'Quality Officer'] as $roleName) {
            $this->assignmentsFor($roleName)->each(function (RoleAssignment $assignment) use ($center): void {
                $assignment->forceFill(['scope_target_id' => $center->getKey()])->save();
                $assignment->scopeTargets()->delete();
                RoleUserScopeTarget::query()->firstOrCreate([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $center->getKey(),
                ]);
            });
        }

        // Point-scoped agents → whichever point the register names them on.
        $pointsByAgent = CollectionPoint::withoutDataScope()
            ->whereNotNull('agent_user_id')
            ->get()
            ->groupBy('agent_user_id');

        $this->assignmentsFor('Collection Agent')->each(function (RoleAssignment $assignment) use ($pointsByAgent): void {
            $points = $pointsByAgent->get($assignment->user_id, collect());

            $assignment->scopeTargets()->delete();

            if ($points->isEmpty()) {
                // Named on no point → the scope fails closed, which is correct:
                // an agent with no point should see nothing, not everything.
                $assignment->forceFill(['scope_target_id' => null])->save();

                return;
            }

            $assignment->forceFill(['scope_target_id' => $points->first()->getKey()])->save();

            foreach ($points as $point) {
                RoleUserScopeTarget::query()->firstOrCreate([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $point->getKey(),
                ]);
            }
        });

        // Department-scoped heads → their own department.
        $this->assignmentsFor('Department Head')->each(function (RoleAssignment $assignment): void {
            $departmentId = User::query()->whereKey($assignment->user_id)->value('department_id');

            if ($departmentId === null) {
                return;
            }

            $assignment->forceFill(['scope_target_id' => $departmentId])->save();
            $assignment->scopeTargets()->delete();
            RoleUserScopeTarget::query()->firstOrCreate([
                'role_user_id' => $assignment->getKey(),
                'target_id' => $departmentId,
            ]);
        });

        /*
         * Community-scoped roles already point at community ids 1..26, and the
         * Adamawa communities were re-seeded onto those same ids, so the wide
         * roles (Delivery Lead, CE Officer) are already correct. Only prune
         * targets that no longer resolve.
         */
        $communityIds = Community::query()->pluck('id')->all();

        RoleUserScopeTarget::query()
            ->whereIn('role_user_id', $this->communityAssignmentIds())
            ->whereNotIn('target_id', $communityIds)
            ->delete();
    }

    /** @return \Illuminate\Support\Collection<int, RoleAssignment> */
    private function assignmentsFor(string $roleName)
    {
        $roleId = Role::query()->where('name', $roleName)->value('id');

        if ($roleId === null) {
            return collect();
        }

        return RoleAssignment::query()->where('role_id', $roleId)->get();
    }

    /** @return array<int, int> */
    private function communityAssignmentIds(): array
    {
        return RoleAssignment::query()
            ->where('scope_type', ScopeType::Communities->value)
            ->pluck('id')
            ->all();
    }

    private function userId(string $handle): ?int
    {
        return User::query()->where('email', $handle.'@gondalfulbe.ng')->value('id');
    }

    /**
     * @param  array<string, CollectionPoint>  $points
     */
    private function report(CollectionCenter $center, array $points, Cooperative $cooperative): void
    {
        $this->command?->newLine();
        $this->command?->info('Milk collect test — Adamawa State, out of Yola');
        $this->command?->line(sprintf('  LGA              %s', 'Yola North (of 6 seeded)'));
        $this->command?->line(sprintf('  Centre           %s  %s', $center->code, $center->name));

        foreach ($points as $point) {
            $this->command?->line(sprintf(
                '  Point            %s  %-11s agent %-14s %d farmers',
                $point->code,
                $point->name,
                $this->handleOf($point->agent_user_id),
                Farmer::withoutDataScope()->where('default_collection_point_id', $point->getKey())->count(),
            ));
        }

        $this->command?->line(sprintf('  Cooperative      %s  %s', $cooperative->code, $cooperative->name));
        $this->command?->newLine();
        $this->command?->warn('Transaction tables are deliberately empty — the first delivery recorded is DEL-0001.');
        $this->command?->newLine();
    }

    private function handleOf(?int $userId): string
    {
        if ($userId === null) {
            return '(none)';
        }

        return (string) Str::before((string) User::query()->whereKey($userId)->value('email'), '@');
    }
}
