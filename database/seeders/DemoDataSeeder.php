<?php

namespace Database\Seeders;

use App\Authorization\ScopeType;
use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\Batch;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Consignment;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\CooperativeEntry;
use App\Models\Delegation;
use App\Models\Delivery;
use App\Models\Department;
use App\Models\DiscrepancyCause;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\Lga;
use App\Models\Position;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\QualityFollowup;
use App\Models\QualityTest;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\Route as TransportRoute;
use App\Models\Sequence;
use App\Models\StockMovement;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollService;
use App\Services\Milk\AdjustmentService;
use App\Services\Milk\BatchService;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Services\Milk\QualityFollowupService;
use App\Services\PermissionTesting\PermissionTestRunner;
use App\Services\Purchases\RequisitionService;
use App\Services\Shop\SaleService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * NFR-12 — "Seeded demo data behind a flag, so the prototype's figures can be
 * reproduced for review."
 *
 * §17 lists the figures that MUST reconcile. Every one of them is derived here,
 * not asserted:
 *
 *   Network      12,480 L confirmed across 6 centers; 514 deliveries;
 *                42 points (38 active); 1,842 farmers
 *   Rejections   142 L total — 62 adulteration, 48 spoilage, 32 late;
 *                112 L at points, 30 L at centers
 *   Kumbotso     3,444 L dispatched − 14 L adjustments − 30 L rejected
 *                = 3,400 L confirmed; Grade A 2,822 L, Grade B 578 L;
 *                140 farmers; 6 consignments; BATCH-0087
 *   DEL-0009     Zainab Idris, 34 L presented, 6 L adulteration, 28 L accepted,
 *                −1 L adjustment, Grade A, 27 L payable at ₦250/L,
 *                2% levy → ₦6,615 net
 *   REQ-2026-0142 ₦3,400,000, Logistics, at stage 3 of 6
 *
 * The Kumbotso chain is built through the real SERVICES, so the seeded data is
 * itself a demonstration that BR-1 to BR-16 hold. The rest of the network is
 * written directly, which is faster and equally valid — the database's own
 * constraints (DM-1) still police the arithmetic.
 *
 * §17 also notes: "Demo values in the HTML are seed-data suggestions, not
 * requirements." Where the prototype's counts and this seeder disagree (the
 * "96 permissions" figure, for example), the PRD's own catalogue wins per rule 2.
 */
class DemoDataSeeder extends Seeder
{
    /** §17 — the confirmed litres each center must show. */
    private const CENTER_CONFIRMED = [
        'Kumbotso' => '3400.00',
        'Dawakin Tofa' => '2150.00',
        'Bunkure' => '1890.00',
        'Wudil' => '1760.00',
        'Garko' => '1720.00',
        'Rano' => '1560.00',
    ];

    /** §9 / settings.html — distance and tariff per center. */
    private const CENTER_LOGISTICS = [
        'Kumbotso' => ['km' => '22.00', 'tariff' => 850_000, 'cold' => '5000.00'],
        'Dawakin Tofa' => ['km' => '31.00', 'tariff' => 1_100_000, 'cold' => '4000.00'],
        'Bunkure' => ['km' => '38.00', 'tariff' => 1_250_000, 'cold' => '3500.00'],
        'Wudil' => ['km' => '41.00', 'tariff' => 1_300_000, 'cold' => '3500.00'],
        'Rano' => ['km' => '48.00', 'tariff' => 1_500_000, 'cold' => '3000.00'],
        'Garko' => ['km' => '52.00', 'tariff' => 1_600_000, 'cold' => '3000.00'],
    ];

    /**
     * §17 — Kumbotso's 6 consignments, engineered so the totals reconcile:
     * dispatched 3,444 · adjustments −14 · rejected 30 · confirmed 3,400,
     * of which Grade A 2,822 and Grade B 578.
     *
     * [dispatched, adjustment, rejected_at_center, grade code, deliveries]
     */
    private const KUMBOTSO_CONSIGNMENTS = [
        ['700.00', '0.00', '0.00', 'GRD-A', 24],
        ['640.00', '0.00', '0.00', 'GRD-A', 24],
        ['612.00', '-14.00', '0.00', 'GRD-A', 24],
        ['500.00', '0.00', '0.00', 'GRD-A', 23],
        ['414.00', '0.00', '30.00', 'GRD-A', 22],
        ['578.00', '0.00', '0.00', 'GRD-B', 23],
    ];

    /**
     * §17 — the rejection split. Point rejections total 112 L and center
     * rejections 30 L, which together give adulteration 62, spoilage 48, late 32.
     *
     * At Kumbotso's points: 12 adulteration (6 of them Zainab's), 10 spoilage,
     * 8 late. The remaining 82 L sit at the other five centers' points.
     */
    private const KUMBOTSO_POINT_REJECTIONS = [
        'REJ-ADU' => '12.00',
        'REJ-SPO' => '10.00',
        'REJ-LATE' => '8.00',
    ];

    private const OTHER_POINT_REJECTIONS = [
        'REJ-ADU' => '20.00',
        'REJ-SPO' => '38.00',
        'REJ-LATE' => '24.00',
    ];

    /** §17 — 514 deliveries: 140 at Kumbotso, 374 elsewhere. */
    private const KUMBOTSO_DELIVERIES = 140;

    private const OTHER_DELIVERIES = 374;

    /** §16 / roles.html — how many users hold each role. */
    private const ROLE_USER_COUNTS = [
        'System Administrator' => 2,
        'Delivery Lead' => 1,
        'Milk Collection Supervisor' => 2,
        'Milk Collection Officer' => 14,
        'Collection Agent' => 12,
        'Logistics Officer' => 3,
        'Community Engagement Officer' => 2,
        'Extension Agent' => 9,
        'One-Stop Shop Manager' => 1,
        'Sales Officer' => 3,
        'Inventory Officer' => 2,
        'Department Head' => 6,
        'Internal Audit' => 2,
        'Executive Director' => 1,
        'Accounts' => 3,
        'General Manager' => 1,
        'HR Manager' => 1,
    ];

    /**
     * Pairings the four-eyes design forbids one person holding. The chain's
     * integrity comes from different people at consecutive stages — record →
     * confirm → carry → reconcile, sell → count, write → audit — so the filler
     * logic must never bundle those stages into one account the way it bundles
     * harmless combinations. An earlier version of fillRemainingStaff() did
     * exactly that: staff members who recorded deliveries as agents also held
     * the officer role that confirms them.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_CONFLICTS = [
        'Collection Agent' => ['Milk Collection Officer', 'Milk Collection Supervisor', 'Logistics Officer'],
        'Milk Collection Officer' => ['Collection Agent', 'Milk Collection Supervisor'],
        'Milk Collection Supervisor' => ['Collection Agent', 'Milk Collection Officer', 'Logistics Officer'],
        'Logistics Officer' => ['Collection Agent', 'Milk Collection Supervisor'],
        'Sales Officer' => ['Inventory Officer', 'One-Stop Shop Manager'],
        'Inventory Officer' => ['Sales Officer'],
        'Internal Audit' => ['Collection Agent', 'Milk Collection Officer', 'Milk Collection Supervisor', 'Logistics Officer', 'Sales Officer', 'Inventory Officer', 'Accounts'],
        'Accounts' => ['Sales Officer', 'Internal Audit'],
        'Extension Agent' => ['Community Engagement Officer'],
        'Community Engagement Officer' => ['Extension Agent'],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /**
     * Role names held per user, tracked in memory. The `roles` relation on a User
     * we created earlier in this run is stale the moment we assign another role,
     * so bookkeeping here is more reliable than reloading on every lookup.
     *
     * @var array<string, array<int, string>>
     */
    private array $held = [];

    private User $admin;

    public function run(): void
    {
        /*
         * The demo dataset is generated, not reconciled: it writes deliveries,
         * consignments and batches with sequence-numbered references. Running it
         * twice cannot merge — DEL-0001 already exists — so a second run used to
         * die on a unique constraint partway through, leaving the database half
         * seeded and the operator reading a stack trace to learn that.
         *
         * Say so instead, and leave what is already there alone. `migrate:fresh
         * --seed` remains the way to rebuild it.
         */
        if (Delivery::query()->withoutGlobalScopes()->exists()) {
            $this->command?->warn(
                'DemoDataSeeder skipped: demo data is already present. Use `php artisan migrate:fresh --seed` to rebuild it.'
            );

            return;
        }

        $this->admin = User::query()->where('email', 'like', 'admin@%')->firstOrFail();

        // Sequences are pre-wound so §17's exact references come out: DEL-0009 is
        // simply the ninth delivery of the day, CNS-0438 the 438th consignment,
        // BATCH-0087 the 87th batch, REQ-2026-0142 the 142nd requisition of 2026.
        $this->windSequences();

        $this->seedOrganisation();
        $this->seedInfrastructure();
        $this->seedLogistics();
        $this->seedShop();
        $this->seedCooperativesAndFarmers();
        $this->seedExtension();

        // The traceable chain, built through the real services (§14 Phase 3).
        $this->seedKumbotsoChain();
        $this->seedOtherCenters();

        $this->seedRequisitions();
        $this->seedSalesAndActivities();

        // Everything above reproduces §17. The rest fills the screens §17 says
        // nothing about, so a reviewer opening Leave, Payroll, Quality follow-ups,
        // the cooperative ledger or the permission-test register finds rows there
        // rather than an empty state that could equally mean "not built".
        $this->seedQualityFollowups();
        $this->seedCooperativeLedger();
        $this->seedLeave();
        $this->seedPayroll();
        $this->seedPermissionTestRun();

        $this->report();
    }

    /* ---------------------------------------------------------------------
     | Sequences
     * ------------------------------------------------------------------ */

    private function windSequences(): void
    {
        $wind = [
            // DEL resets daily, so today starts at 0 and Zainab's is the 9th.
            'consignments' => 437,
            'batches' => 86,
            'trips' => 1051,
            'requisitions' => 141,
            'field_activities' => 2240,
        ];

        foreach ($wind as $key => $value) {
            Sequence::query()->where('key', $key)->update([
                'current_value' => $value,
                'last_reset_on' => Wat::today()->toDateString(),
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     | People
     * ------------------------------------------------------------------ */

    private function seedOrganisation(): void
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

        // §16 — the named personas, so the screens read like the prototype.
        $people = [
            ['Sadiq Ahmed', 'sadiq.ahmed', 'Executive', 'System Administrator', ['System Administrator' => ScopeType::Network]],
            ['Rahma Sule', 'rahma.sule', 'Human Resources', 'HR Manager', ['HR Manager' => ScopeType::Network]],
            ['Muhammad Bello', 'muhammad.bello', 'Milk Collection', 'Milk Collection Supervisor', ['Milk Collection Supervisor' => ScopeType::Network]],
            ['Halima Yusuf', 'halima.yusuf', 'Milk Collection', 'Collection Officer', ['Milk Collection Officer' => ScopeType::Center]],
            ['Sani Bello', 'sani.bello', 'Milk Collection', 'Collection Agent', ['Collection Agent' => ScopeType::Point]],
            ['Idris Kabir', 'idris.kabir', 'Logistics', 'Logistics Officer', ['Logistics Officer' => ScopeType::Center]],
            ['Fatima Aliyu', 'fatima.aliyu', 'Community Engagement', 'Community Engagement Officer', ['Community Engagement Officer' => ScopeType::Communities]],
            ['Yusuf Garba', 'yusuf.garba', 'Community Engagement', 'Extension Agent', ['Extension Agent' => ScopeType::Communities]],
            ['Amina Kabir', 'amina.kabir', 'One-Stop Shop', 'Shop Manager', ['One-Stop Shop Manager' => ScopeType::Network]],
            ['Hauwa Ibrahim', 'hauwa.ibrahim', 'One-Stop Shop', 'Sales Officer', ['Sales Officer' => ScopeType::Own]],
            ['Ibrahim Sale', 'ibrahim.sale', 'One-Stop Shop', 'Inventory Officer', ['Inventory Officer' => ScopeType::Network]],
            ['Umar Muduru', 'umar.muduru', 'Internal Audit', 'Internal Auditor', ['Internal Audit' => ScopeType::Network]],
            ['Aliyu Danjuma', 'aliyu.danjuma', 'Finance & Accounts', 'Accountant', ['Accounts' => ScopeType::Network]],
            ['Mohammed Aliyu', 'mohammed.aliyu', 'Executive', 'Executive Director', ['Executive Director' => ScopeType::Network]],
            ['Musa Abdulhamid', 'musa.abdulhamid', 'Executive', 'General Manager', ['General Manager' => ScopeType::Network]],
            ['Zubaida Nuhu', 'zubaida.nuhu', 'Community Engagement', 'Delivery Lead', ['Delivery Lead' => ScopeType::Communities]],
        ];

        foreach ($people as [$name, $handle, $department, $position, $roles]) {
            $user = $this->makeUser($name, $handle, $department, $position);

            foreach ($roles as $roleName => $scopeType) {
                $this->assign($user, $roleName, $scopeType);
            }

            $this->staff[$name] = $user;
        }

        // Fill out to 38 active staff with the role counts §16 records.
        $this->fillRemainingStaff();

        // TEST-1 — three test accounts, excluded from every report and payroll.
        foreach ([
            ['Permission Test', 'perm.test'],
            ['Shop Test', 'shop.test'],
            ['Extension Test', 'ext.test'],
        ] as [$name, $handle]) {
            $this->makeUser($name, $handle, 'Executive', 'Test account', isTest: true);
        }

        // BR-32 — four deactivated accounts whose attribution is preserved.
        foreach ([
            ['Bilkisu Tanko', 'bilkisu.tanko', 'Left the company'],
            ['Nasir Lawal', 'nasir.lawal', 'Contract ended'],
            ['Salamatu Yaro', 'salamatu.yaro', 'Transferred out'],
            ['Kabiru Danladi', 'kabiru.danladi', 'Left the company'],
        ] as [$name, $handle, $reason]) {
            $user = $this->makeUser($name, $handle, 'Milk Collection', 'Collection Agent');
            $user->forceFill([
                'status' => 'deactivated',
                'deactivated_at' => Wat::now()->subDays(random_int(20, 200)),
                'deactivated_reason' => $reason,
            ])->save();
        }

        // §6.8 — vacancies (§15.5: the register only; no applicant tracking).
        foreach ([
            ['Collection Officer', 'Milk Collection', 2],
            ['Extension Agent', 'Community Engagement', 3],
            ['Accounts Clerk', 'Finance & Accounts', 1],
        ] as [$title, $department, $openings]) {
            Position::query()->firstOrCreate(
                ['title' => $title, 'department_id' => Department::query()->where('name', $department)->value('id')],
                [
                    'grade_level' => 'GL-07',
                    'openings' => $openings,
                    'posted_on' => Wat::today()->subDays(14)->toDateString(),
                    'closes_on' => Wat::today()->addDays(14)->toDateString(),
                    'status' => 'open',
                ],
            );
        }
    }

    /**
     * §16 — 38 active staff holding 103 role assignments (65 functional plus the
     * automatic Staff role every user carries, ROLE-3).
     */
    private function fillRemainingStaff(): void
    {
        $filler = 1;

        foreach (self::ROLE_USER_COUNTS as $roleName => $target) {
            while ($this->staffHolding($roleName)->count() < $target) {
                // Reuse existing staff for a second functional role where we can,
                // so the head-count settles at 38 while the assignment count
                // reaches the 103 §16 records.
                $existing = collect($this->staff)
                    ->reject(fn (User $user) => $this->holds($user, $roleName))
                    ->filter(function (User $user) use ($roleName) {
                        $functional = array_diff($this->rolesOf($user), ['Staff (self-service)']);

                        /*
                         * Keep the named personas single-purpose: their "Cannot
                         * see" lists in §16 only make sense if they hold one job.
                         * Unnamed filler staff may hold up to three, which is what
                         * lets 38 active staff carry the 65 functional assignments
                         * §16 records without inflating the head-count.
                         */
                        return count($functional) >= 1
                            && count($functional) < 3
                            && str_starts_with($user->email, 'staff')
                            && $roleName !== 'System Administrator'
                            && ! $this->rolesConflict($functional, $roleName);
                    })
                    ->first();

                if ($existing !== null && count($this->staff) >= 38) {
                    $this->assign($existing, $roleName, $this->scopeForRole($roleName));

                    continue;
                }

                $name = 'Staff Member '.$filler;
                $user = $this->makeUser(
                    $name,
                    'staff'.$filler,
                    $this->departmentForRole($roleName),
                    $roleName,
                );
                $filler++;

                $this->assign($user, $roleName, $this->scopeForRole($roleName));
                $this->staff[$name] = $user;
            }
        }
    }

    private function makeUser(
        string $name,
        string $handle,
        string $department,
        string $position,
        bool $isTest = false,
    ): User {
        $email = $handle.'@gondalfulbe.ng';

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $departmentId = Department::query()->where('name', $department)->value('id');

        $user = new User([
            'name' => $name,
            'email' => $email,
            'phone' => '080'.random_int(10_000_000, 99_999_999),
            'department_id' => $departmentId,
            'position' => $position,
            'status' => 'active',
            'is_test' => $isTest,
            'two_factor_enabled' => true,
            'created_by_user_id' => $this->admin->getKey(),
        ]);

        /*
         * BR-31 — an administrator never sets a password. Demo accounts are given
         * a known one ONLY because this seeder exists to be signed into for
         * review, and it is printed at the end rather than hidden. Nothing in the
         * application can do this; see UserAdminService, which has no password
         * parameter at all.
         */
        $user->password_hash = Hash::make('GondalDemo!2026');
        $user->password_changed_at = Wat::now()->subDays(random_int(1, 60));
        $user->save();

        // ROLE-3 — the automatic role, written so it is visible on screen.
        $this->assign($user, 'Staff (self-service)', ScopeType::Own);

        // §6.8 — a matching employee record, so payroll and own-payslip work.
        if (! $isTest) {
            $employee = Employee::query()->create([
                'code' => 'EMP-'.str_pad((string) (Employee::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'name' => $name,
                'phone' => $user->phone,
                'email' => $email,
                'department_id' => $departmentId,
                'position' => $position,
                'grade_level' => 'GL-'.str_pad((string) random_int(5, 14), 2, '0', STR_PAD_LEFT),
                'employment_type' => 'permanent',
                'duty_station' => 'Kano',
                'joined_on' => Wat::today()->subDays(random_int(200, 2000))->toDateString(),
                'confirmed_on' => Wat::today()->subDays(random_int(60, 180))->toDateString(),
                'gross_monthly_minor' => random_int(1_800, 9_500) * 100 * 100,
                'bank_name' => 'First Bank of Nigeria',
                'bank_account_masked' => '****'.random_int(1000, 9999),
                'next_of_kin_name' => 'Next of kin',
                'next_of_kin_phone' => '080'.random_int(10_000_000, 99_999_999),
                'status' => 'confirmed',
            ]);

            $user->forceFill(['employee_id' => $employee->getKey()])->save();
        }

        return $user;
    }

    private function assign(User $user, string $roleName, ScopeType $scopeType, ?int $targetId = null): void
    {
        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null || $role->status === Role::STATUS_RETIRED) {
            return;
        }

        RoleAssignment::query()->updateOrCreate(
            ['role_id' => $role->getKey(), 'user_id' => $user->getKey()],
            [
                'scope_type' => $scopeType->value,
                'scope_target_id' => $targetId,
                'assigned_by_user_id' => $this->admin->getKey(),
                'assigned_at' => Wat::now()->subDays(random_int(1, 30)),
            ],
        );

        $this->held[$user->email] = array_values(array_unique(
            array_merge($this->held[$user->email] ?? [], [$roleName]),
        ));
    }

    /**
     * Would adding $candidate to a user already holding $held cross a
     * four-eyes boundary? Checked in both directions, since the map lists
     * each conflict from one side only where the pairing is symmetric.
     *
     * @param  array<int, string>  $held
     */
    private function rolesConflict(array $held, string $candidate): bool
    {
        foreach ($held as $existing) {
            if (in_array($candidate, self::ROLE_CONFLICTS[$existing] ?? [], true)
                || in_array($existing, self::ROLE_CONFLICTS[$candidate] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function rolesOf(User $user): array
    {
        return $this->held[$user->email] ?? [];
    }

    private function holds(User $user, string $roleName): bool
    {
        return in_array($roleName, $this->rolesOf($user), true);
    }

    /**
     * @return Collection<int, User>
     */
    private function staffHolding(string $roleName): Collection
    {
        return collect($this->staff)
            ->filter(fn (User $user) => $this->holds($user, $roleName))
            ->values();
    }

    private function scopeForRole(string $roleName): ScopeType
    {
        $role = Role::query()->where('name', $roleName)->first();

        return $role?->defaultScopeType() ?? ScopeType::Network;
    }

    private function departmentForRole(string $roleName): string
    {
        return match (true) {
            str_contains($roleName, 'Collection'), str_contains($roleName, 'Milk') => 'Milk Collection',
            str_contains($roleName, 'Logistics') => 'Logistics',
            str_contains($roleName, 'Extension'), str_contains($roleName, 'Community'), $roleName === 'Delivery Lead' => 'Community Engagement',
            str_contains($roleName, 'Shop'), str_contains($roleName, 'Sales'), str_contains($roleName, 'Inventory') => 'One-Stop Shop',
            str_contains($roleName, 'Accounts') => 'Finance & Accounts',
            str_contains($roleName, 'HR') => 'Human Resources',
            str_contains($roleName, 'Audit') => 'Internal Audit',
            default => 'Executive',
        };
    }

    /* ---------------------------------------------------------------------
     | Centers and points
     * ------------------------------------------------------------------ */

    private function seedInfrastructure(): void
    {
        $officers = $this->staffHolding('Milk Collection Officer');
        $logistics = $this->staffHolding('Logistics Officer');

        $index = 0;

        foreach (self::CENTER_CONFIRMED as $name => $confirmed) {
            $lga = Lga::query()->where('name', $name)->first();
            $details = self::CENTER_LOGISTICS[$name];

            $center = CollectionCenter::query()->firstOrCreate(
                ['code' => 'CTR-'.strtoupper(Str::substr(Str::slug($name), 0, 4))],
                [
                    'name' => $name,
                    'lga_id' => $lga?->getKey() ?? Lga::query()->value('id'),
                    'officer_user_id' => $officers->get($index % max(1, $officers->count()))?->getKey(),
                    'logistics_user_id' => $logistics->get($index % max(1, $logistics->count()))?->getKey(),
                    'cold_storage_litres' => $details['cold'],
                    'distance_to_factory_km' => $details['km'],
                    'transport_fee_minor' => $details['tariff'],
                    'status' => 'active',
                ],
            );

            $index++;
        }

        // §17 — 42 points, 38 active. Kumbotso takes 8 (§16: "8 points feed in").
        $agents = $this->staffHolding('Collection Agent');

        $pointsPerCenter = [
            'Kumbotso' => 8, 'Dawakin Tofa' => 8, 'Bunkure' => 7,
            'Wudil' => 7, 'Garko' => 6, 'Rano' => 6,
        ];

        $created = 0;
        $inactiveTargets = [11, 22, 33, 41];   // four points not active (42 − 38)

        foreach ($pointsPerCenter as $centerName => $count) {
            $center = CollectionCenter::withoutDataScope()->where('name', $centerName)->first();
            $communities = Community::query()->where('lga_id', $center->lga_id)->get();

            for ($i = 1; $i <= $count; $i++) {
                $created++;
                $community = $communities->get(($i - 1) % max(1, $communities->count()))
                    ?? Community::query()->first();

                // Tudun Wada is the point §16 names for Sani Bello.
                $pointName = $centerName === 'Kumbotso' && $i === 1
                    ? 'Tudun Wada'
                    : $community->name.' '.$i;

                CollectionPoint::query()->firstOrCreate(
                    ['code' => 'PT-'.str_pad((string) $created, 3, '0', STR_PAD_LEFT)],
                    [
                        'name' => $pointName,
                        'community_id' => $community->getKey(),
                        'lga_id' => $center->lga_id,
                        'agent_user_id' => $agents->get(($created - 1) % max(1, $agents->count()))?->getKey(),
                        'collection_center_id' => $center->getKey(),
                        // BR-3 — most points use the 07:00 default; a couple override.
                        'cutoff_time' => $i === 2 ? '07:30' : null,
                        'transport_fee_minor' => $i > 5 ? 80_000 : 50_000,
                        'status' => in_array($created, $inactiveTargets, true)
                            ? ($created === 41 ? 'suspended' : 'idle')
                            : 'active',
                        'opened_on' => Wat::today()->subDays(random_int(120, 1200))->toDateString(),
                    ],
                );
            }
        }

        // §16 — Sani Bello is the Tudun Wada agent; Halima Yusuf the Kumbotso officer.
        $tudunWada = CollectionPoint::withoutDataScope()->where('name', 'Tudun Wada')->first();
        $kumbotso = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->first();

        if ($tudunWada !== null && isset($this->staff['Sani Bello'])) {
            $tudunWada->forceFill(['agent_user_id' => $this->staff['Sani Bello']->getKey()])->save();
            $this->assign($this->staff['Sani Bello'], 'Collection Agent', ScopeType::Point, $tudunWada->getKey());
        }

        if ($kumbotso !== null) {
            if (isset($this->staff['Halima Yusuf'])) {
                $kumbotso->forceFill(['officer_user_id' => $this->staff['Halima Yusuf']->getKey()])->save();
                $this->assign($this->staff['Halima Yusuf'], 'Milk Collection Officer', ScopeType::Center, $kumbotso->getKey());
            }

            if (isset($this->staff['Idris Kabir'])) {
                $this->assign($this->staff['Idris Kabir'], 'Logistics Officer', ScopeType::Center, $kumbotso->getKey());
            }
        }

        /*
         * SCOPE-1 — an agent's point scope is DERIVED from the point register,
         * never handed out independently. The register names the agent on each
         * point (`agent_user_id`); an earlier version of this block assigned
         * scope targets by a second, differently-ordered round-robin, which left
         * 41 of 42 agents unable to open their own point while every automated
         * test stayed green — tests build their own fixtures and never read
         * seeded data. SeedIntegrityTest now pins this agreement.
         */
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
                continue;   // an agent named on no point keeps a closed scope
            }

            $assignment->forceFill(['scope_target_id' => $points->first()->getKey()])->save();

            foreach ($points as $point) {
                RoleUserScopeTarget::query()->firstOrCreate([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $point->getKey(),
                ]);
            }

            // Points the register no longer gives this agent must leave the
            // scope too, or a reassignment widens access instead of moving it.
            $assignment->scopeTargets()
                ->whereNotIn('target_id', $points->pluck('id')->all())
                ->delete();
        }

        // Center-scoped officers get a real target, so no assignment is left
        // unsatisfiable (SCOPE-1 fails closed on a missing target).
        $centers = CollectionCenter::withoutDataScope()->get();
        $position = 0;

        foreach (RoleAssignment::query()
            ->where('scope_type', ScopeType::Center->value)
            ->whereNull('scope_target_id')
            ->get() as $assignment) {
            $assignment->forceFill(['scope_target_id' => $centers[$position % $centers->count()]->getKey()])->save();
            $position++;
        }

        // A `communities` assignment needs its list, or it admits nothing.
        $communities = Community::query()->pluck('id')->all();

        foreach (RoleAssignment::query()
            ->where('scope_type', ScopeType::Communities->value)
            ->with('scopeTargets')
            ->get() as $assignment) {
            if ($assignment->scopeTargets->isNotEmpty()) {
                continue;
            }

            // The Extension Agent persona covers 4 communities; oversight roles
            // cover all of them.
            $slice = $assignment->role?->name === 'Extension Agent'
                ? array_slice($communities, random_int(0, 20), 4)
                : $communities;

            foreach ($slice as $communityId) {
                RoleUserScopeTarget::query()->create([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $communityId,
                ]);
            }
        }

        // Department-scoped assignments (the Department Head persona).
        $departments = Department::query()->pluck('id')->all();
        $position = 0;

        foreach (RoleAssignment::query()
            ->where('scope_type', ScopeType::Department->value)
            ->whereNull('scope_target_id')
            ->get() as $assignment) {
            $assignment->forceFill(['scope_target_id' => $departments[$position % count($departments)]])->save();
            $position++;
        }
    }

    private function seedLogistics(): void
    {
        foreach (['KN-221-ABC' => 'motorcycle', 'KN-889-XYZ' => 'commercial', 'KN-104-GFD' => 'company'] as $registration => $type) {
            Vehicle::query()->firstOrCreate(['registration' => $registration], [
                'type' => $type,
                'capacity_litres' => $type === 'motorcycle' ? '120.00' : '4000.00',
                'status' => 'active',
            ]);
        }

        // USER-1 — riders and drivers are records, not accounts.
        foreach ([
            ['Auwal Rabiu', 'rider'], ['Shehu Garba', 'rider'], ['Danjuma Ali', 'driver'],
            ['Kabir Sule', 'driver'], ['Yakubu Musa', 'rider'],
        ] as [$name, $type]) {
            Driver::query()->firstOrCreate(['name' => $name], [
                'phone' => '080'.random_int(10_000_000, 99_999_999),
                'licence_no' => 'LIC-'.random_int(10_000, 99_999),
                'type' => $type,
                'status' => 'active',
            ]);
        }

        // §9 — the route tariffs from settings.html, as reference data.
        TransportRoute::query()->firstOrCreate(['name' => 'Point → center (motorcycle, standard)'], [
            'from_type' => TransportRoute::ENDPOINT_POINT,
            'to_type' => TransportRoute::ENDPOINT_CENTER,
            'distance_km' => '8.00',
            'tariff_minor' => 50_000,
            'vehicle_type' => 'motorcycle',
            'status' => 'active',
        ]);

        TransportRoute::query()->firstOrCreate(['name' => 'Point → center (over 15 km)'], [
            'from_type' => TransportRoute::ENDPOINT_POINT,
            'to_type' => TransportRoute::ENDPOINT_CENTER,
            'distance_km' => '18.00',
            'tariff_minor' => 80_000,
            'vehicle_type' => 'motorcycle',
            'status' => 'active',
        ]);

        foreach (self::CENTER_LOGISTICS as $centerName => $details) {
            $center = CollectionCenter::withoutDataScope()->where('name', $centerName)->first();

            TransportRoute::query()->firstOrCreate(['name' => $centerName.' → Factory'], [
                'from_type' => TransportRoute::ENDPOINT_CENTER,
                'from_id' => $center?->getKey(),
                'to_type' => TransportRoute::ENDPOINT_FACTORY,
                'distance_km' => $details['km'],
                'tariff_minor' => $details['tariff'],
                'vehicle_type' => 'commercial',
                'status' => 'active',
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     | Shop
     * ------------------------------------------------------------------ */

    private function seedShop(): void
    {
        // G-5 / BR-25 — categories are rows. These are the shop's opening set;
        // the manager adds or retires more without a deployment.
        $categories = [
            ['VET', 'Veterinary drugs', 'dose', 20, true, true, false, true],
            ['FEED', 'Animal feed', 'bag', 40, false, false, true, false],
            ['MANURE', 'Manure & fertiliser', 'bag', 30, false, false, true, false],
            ['EQUIP', 'Dairy equipment', 'unit', 10, false, false, false, true],
            ['CONSUM', 'Consumables', 'pack', 50, false, true, false, false],
        ];

        foreach ($categories as $index => [$code, $name, $unit, $reorder, $rx, $expiry, $credit, $approval]) {
            ProductCategory::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'default_unit' => $unit,
                'default_reorder_level' => $reorder,
                'requires_prescription' => $rx,
                'track_expiry' => $expiry,
                'allow_credit' => $credit,
                'requires_manager_approval' => $approval,
                'status' => 'active',
                'position' => $index + 1,
            ]);
        }

        $products = [
            ['VET-OXY', 'Oxytetracycline injection', 'VET', 4_500_00, 6_200_00, 60],
            ['VET-DEW', 'Dewormer bolus', 'VET', 800_00, 1_150_00, 200],
            ['FEED-CON', 'Dairy concentrate 25kg', 'FEED', 9_800_00, 12_500_00, 120],
            ['FEED-BRAN', 'Wheat bran 50kg', 'FEED', 4_200_00, 5_600_00, 90],
            ['MAN-NPK', 'NPK 20:10:10 50kg', 'MANURE', 22_000_00, 27_500_00, 40],
            ['EQ-CAN', 'Aluminium milk can 40L', 'EQUIP', 28_000_00, 34_000_00, 18],
            ['EQ-LACT', 'Lactometer', 'EQUIP', 6_500_00, 9_000_00, 12],
            ['CON-GLOVE', 'Nitrile gloves (100)', 'CONSUM', 3_200_00, 4_500_00, 25],
        ];

        foreach ($products as [$sku, $name, $categoryCode, $cost, $price, $quantity]) {
            $category = ProductCategory::query()->where('code', $categoryCode)->first();

            $product = Product::query()->firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'product_category_id' => $category->getKey(),
                'unit' => $category->default_unit,
                'cost_price_minor' => $cost,
                'selling_price_minor' => $price,
                'reorder_level' => $category->default_reorder_level,
                'preferred_supplier' => 'Kano Agro Supplies Ltd',
                'quantity_on_hand' => $quantity,
                'status' => 'active',
            ]);

            ProductBatch::query()->firstOrCreate(
                ['product_id' => $product->getKey(), 'batch_no' => 'B-'.Str::upper(Str::random(6))],
                [
                    'supplier' => 'Kano Agro Supplies Ltd',
                    'received_on' => Wat::today()->subDays(random_int(5, 60))->toDateString(),
                    'expiry_on' => $category->track_expiry
                        ? Wat::today()->addDays(random_int(60, 500))->toDateString()
                        : null,
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'unit_cost_minor' => $cost,
                    'status' => 'active',
                ],
            );

            StockMovement::query()->firstOrCreate(
                ['product_id' => $product->getKey(), 'movement_type' => 'stock_in', 'reference' => 'opening'],
                [
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'balance_after' => $quantity,
                ],
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Cooperatives and farmers
     * ------------------------------------------------------------------ */

    private function seedCooperativesAndFarmers(): void
    {
        // §17 / personas.html — 5 cooperatives.
        $cooperatives = [
            ['COOP-KUM', 'Kumbotso Dairy Cooperative', 'Kumbotso'],
            ['COOP-DAW', 'Dawakin Tofa Milk Producers', 'Dawakin Tofa'],
            ['COOP-WUD', 'Wudil Multipurpose Coop', 'Wudil'],
            ['COOP-BUN', 'Bunkure Women Dairy Group', 'Bunkure'],
            ['COOP-RAN', 'Rano Fulbe Dairy Union', 'Rano'],
        ];

        foreach ($cooperatives as [$code, $name, $lgaName]) {
            $lga = Lga::query()->where('name', $lgaName)->first();
            $community = Community::query()->where('lga_id', $lga?->getKey())->first();
            $point = CollectionPoint::withoutDataScope()->where('lga_id', $lga?->getKey())->first();

            $cooperative = Cooperative::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'registered_on' => Wat::today()->subDays(random_int(400, 2500))->toDateString(),
                'community_id' => $community?->getKey(),
                'lga_id' => $lga?->getKey(),
                'chairman_name' => 'Alhaji '.Str::before($name, ' '),
                'secretary_name' => 'Malam Secretary',
                'treasurer_name' => 'Hajiya Treasurer',
                'contact_phone' => '080'.random_int(10_000_000, 99_999_999),
                'collection_point_id' => $point?->getKey(),
                // §9 defaults: 5% savings, 2% levy, ₦250/member/month social.
                'savings_deduction_pct' => '5.00',
                'levy_pct' => '2.00',
                'social_contribution_minor' => 25_000,
                'status' => 'active',
            ]);

            foreach ([Cooperative::ACCOUNT_GENERAL, Cooperative::ACCOUNT_SOCIAL, Cooperative::ACCOUNT_SAVINGS] as $kind) {
                CooperativeAccount::query()->firstOrCreate(
                    ['cooperative_id' => $cooperative->getKey(), 'kind' => $kind],
                    ['balance_minor' => $kind === Cooperative::ACCOUNT_GENERAL ? 4_820_000 : 1_140_000],
                );
            }
        }

        // §17 — 1,842 farmers, 140 of them delivering to Kumbotso's points.
        $existing = Farmer::query()->count();

        if ($existing >= 1_842) {
            return;
        }

        $kumbotso = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->first();
        $kumbotsoPoints = CollectionPoint::withoutDataScope()
            ->where('collection_center_id', $kumbotso->getKey())
            ->where('status', 'active')
            ->get();

        $otherPoints = CollectionPoint::withoutDataScope()
            ->where('collection_center_id', '!=', $kumbotso->getKey())
            ->where('status', 'active')
            ->get();

        $cooperativeIds = Cooperative::withoutDataScope()->pluck('id')->all();
        $enroller = $this->staff['Yusuf Garba'] ?? $this->admin;

        $given = ['Zainab', 'Amina', 'Hauwa', 'Fatima', 'Aisha', 'Maryam', 'Halima', 'Rukayya',
            'Ibrahim', 'Musa', 'Sani', 'Umar', 'Yusuf', 'Kabir', 'Nasir', 'Idris', 'Bashir', 'Auwal'];
        $family = ['Idris', 'Bello', 'Yusuf', 'Garba', 'Kabir', 'Muduru', 'Danjuma', 'Aliyu',
            'Sule', 'Lawal', 'Tanko', 'Rabiu', 'Musa', 'Shehu', 'Nuhu', 'Sale'];

        $rows = [];
        $now = now()->toDateTimeString();

        for ($i = $existing + 1; $i <= 1_842; $i++) {
            // The first 140 farmers deliver at Kumbotso; #1 is Zainab Idris,
            // whose delivery §17 traces end to end.
            $atKumbotso = $i <= self::KUMBOTSO_DELIVERIES;
            $point = $atKumbotso
                ? $kumbotsoPoints->get(($i - 1) % max(1, $kumbotsoPoints->count()))
                : $otherPoints->get(($i - 1) % max(1, $otherPoints->count()));

            $name = $i === 1
                ? 'Zainab Idris'
                : $given[$i % count($given)].' '.$family[($i * 7) % count($family)];

            $rows[] = [
                'code' => 'FRM-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => $name,
                'gender' => $i % 3 === 0 ? 'male' : 'female',
                'year_of_birth' => 1960 + ($i % 40),
                'phone' => '080'.str_pad((string) (10_000_000 + $i), 8, '0'),
                'community_id' => $point->community_id,
                'lga_id' => $point->lga_id,
                'cooperative_id' => $i % 4 === 0 ? null : $cooperativeIds[$i % count($cooperativeIds)],
                'cooperative_member_no' => $i % 4 === 0 ? null : 'M-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'default_collection_point_id' => $point->getKey(),
                'herd_size' => 4 + ($i % 18),
                'lactating_count' => 1 + ($i % 7),
                'enrolled_by_user_id' => $enroller->getKey(),
                'enrolled_on' => Wat::today()->subDays(30 + ($i % 900))->toDateString(),
                'status' => 'active',
                'created_by_user_id' => $enroller->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 300) {
                Farmer::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Farmer::query()->insert($rows);
        }
    }

    private function seedExtension(): void
    {
        // §16 — 9 extension agents.
        $candidates = $this->staffHolding('Extension Agent');

        $lead = $this->staff['Fatima Aliyu'] ?? $this->admin;
        $communities = Community::query()->get();

        foreach ($candidates as $index => $user) {
            $agent = ExtensionAgent::query()->firstOrCreate(['user_id' => $user->getKey()], [
                'code' => 'EXT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'reports_to_user_id' => $lead->getKey(),
                'visit_target_monthly' => 20,
                'enrolment_target_monthly' => 8,
                'status' => 'active',
            ]);

            // Four communities each, matching the persona.
            $slice = $communities->slice(($index * 4) % max(1, $communities->count() - 4), 4);

            foreach ($slice as $community) {
                $agent->communities()->syncWithoutDetaching([
                    $community->getKey() => ['assigned_at' => Wat::now()->subDays(60)],
                ]);
            }
        }
    }

    /* ---------------------------------------------------------------------
     | §17 — the Kumbotso chain, through the real services
     * ------------------------------------------------------------------ */

    private function seedKumbotsoChain(): void
    {
        $deliveries = app(DeliveryService::class);
        $consignments = app(ConsignmentService::class);
        $batches = app(BatchService::class);
        $adjustments = app(AdjustmentService::class);

        $center = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->first();
        $officer = $this->staff['Halima Yusuf'] ?? $this->admin;
        $agent = $this->staff['Sani Bello'] ?? $this->admin;
        $supervisor = $this->staff['Muhammad Bello'] ?? $this->admin;

        $points = CollectionPoint::withoutDataScope()
            ->where('collection_center_id', $center->getKey())
            ->where('status', 'active')
            ->get();

        $farmers = Farmer::withoutDataScope()
            ->orderBy('id')
            ->limit(self::KUMBOTSO_DELIVERIES)
            ->get();

        $adulteration = RejectionReason::query()->where('code', 'REJ-ADU')->first();
        $spoilage = RejectionReason::query()->where('code', 'REJ-SPO')->first();
        $late = RejectionReason::query()->where('code', 'REJ-LATE')->first();

        // Rejections at Kumbotso's points: 12 adulteration (6 of them Zainab's),
        // 10 spoilage, 8 late — 30 L in total.
        $rejectionPlan = [
            // delivery index (1-based) => [reason, litres]
            9 => [$adulteration, '6.00'],       // §17 — DEL-0009, Zainab Idris
            14 => [$adulteration, '6.00'],
            21 => [$spoilage, '4.00'],
            35 => [$spoilage, '6.00'],
            48 => [$late, '5.00'],
            61 => [$late, '3.00'],
        ];

        $farmerIndex = 0;
        $deliveryNumber = 0;
        $consignmentRecords = [];

        foreach (self::KUMBOTSO_CONSIGNMENTS as $slot => [$dispatched, $adjustment, $rejectedAtCenter, $gradeCode, $count]) {
            $point = $points->get($slot % max(1, $points->count()));

            // Distribute the consignment's accepted litres across its deliveries.
            $accepted = $this->distribute(Volume::toCentilitres($dispatched), $count);
            $deliveryIds = [];

            for ($i = 0; $i < $count; $i++) {
                $deliveryNumber++;
                $farmer = $farmers->get($farmerIndex % max(1, $farmers->count()));
                $farmerIndex++;

                // §17 — DEL-0009 is Zainab Idris. She is farmer #1, so place her
                // on the ninth delivery of the day.
                if ($deliveryNumber === 9) {
                    $farmer = $farmers->first();
                }

                [$reason, $rejected] = $rejectionPlan[$deliveryNumber] ?? [null, '0.00'];

                $acceptedLitres = Volume::fromCentilitres($accepted[$i]);
                $presented = Volume::add($acceptedLitres, $rejected);

                // §17 — Zainab: 34 L presented, 6 L adulteration, 28 L accepted.
                if ($deliveryNumber === 9) {
                    $presented = '34.00';
                    $rejected = '6.00';
                    $acceptedLitres = '28.00';
                    // Keep the consignment total intact by moving the difference
                    // onto the next delivery in this consignment.
                    $difference = $accepted[$i] - Volume::toCentilitres($acceptedLitres);

                    if (isset($accepted[$i + 1])) {
                        $accepted[$i + 1] += $difference;
                    }
                }

                $data = [
                    'litres_presented' => $presented,
                    'litres_rejected' => $rejected,
                    'rejection_reason_id' => $reason?->getKey(),
                    'containers' => 2,
                    'delivered_at' => Wat::todayAt(5, 40)->addMinutes($deliveryNumber % 70),
                ];

                // BR-3 — a delivery rejected in full for the cut-off reason needs
                // no override; anything else after 07:00 would.
                $delivery = $this->actingAs($agent, fn () => $deliveries->record($point, $farmer, $data, $agent));

                $deliveryIds[] = $delivery->getKey();
            }

            $consignment = $this->actingAs($agent, fn () => app(ConsignmentService::class)->dispatch(
                $point,
                $deliveryIds,
                ['containers' => 12, 'dispatched_at' => Wat::todayAt(7, 20)],
                $agent,
            ));

            // BR-12 — the −14 L adjustment §17 records, with a reason and an
            // explanation. It is applied BEFORE confirmation so BR-8's
            // arithmetic picks it up.
            if (Volume::toCentilitres($adjustment) !== 0) {
                $reasonRow = AdjustmentReason::query()->where('code', 'ADJ-MEAS')->first();

                $this->actingAs($officer, fn () => $adjustments->record(
                    $consignment,
                    $adjustment,
                    $reasonRow->getKey(),
                    'Re-measured at the center after decanting; the point over-reported by 14 litres.',
                    $officer,
                ));
            }

            // BR-4 — every required quality test, then the grade.
            foreach (QualityTestDefinition::query()->required()->get() as $definition) {
                $reading = match ($definition->code) {
                    'DENSITY' => '1.031',
                    'TEMPERATURE' => '18',
                    default => '1',
                };

                $this->actingAs($officer, fn () => $consignments->recordQualityTest(
                    $consignment,
                    $definition,
                    $reading,
                    $officer,
                ));
            }

            $grade = Grade::query()->where('code', $gradeCode)->first();

            $this->actingAs($officer, fn () => $consignments->confirm($consignment, [
                'litres_rejected_at_center' => $rejectedAtCenter,
                'rejection_reason_id' => Volume::toCentilitres($rejectedAtCenter) > 0
                    ? $adulteration->getKey()
                    : null,
                'grade_id' => $grade->getKey(),
                'intake_temperature_c' => '18.00',
                'officer_notes' => Volume::toCentilitres($rejectedAtCenter) > 0
                    ? 'Two cans showed added water on the lactometer; 30 L rejected at intake.'
                    : null,
                'confirmed_at' => Wat::todayAt(8, 10),
            ], $officer));

            $consignmentRecords[] = $consignment->refresh();
        }

        // §17 — BATCH-0087, carrying all six confirmed consignments.
        $trip = $this->makeTrip($center, 'commercial');

        $batch = $this->actingAs($supervisor, fn () => $batches->dispatch(
            $center,
            collect($consignmentRecords)->map->getKey()->all(),
            [
                'containers' => 68,
                'trip_id' => $trip->getKey(),
                'dispatched_at' => Wat::todayAt(8, 45),
            ],
            $supervisor,
        ));

        // BR-10 / BR-11 — reconcile with an 8 L shortfall, which is 0.24% of
        // 3,400 L and therefore INSIDE the 1% tolerance: reconciled, not
        // discrepancy, and no note is demanded.
        $this->actingAs($supervisor, fn () => $batches->reconcile($batch, [
            'litres_received' => '3392.00',
            'containers_received' => 68,
            'discrepancy_cause_id' => DiscrepancyCause::query()->where('code', 'DIS-CONT')->value('id'),
            'litres_rejected_at_factory' => '0.00',
            'reconciled_at' => Wat::todayAt(9, 40),
        ], $supervisor));

        $this->actingAs($supervisor, fn () => $batches->release(
            $batch->refresh(),
            'Container change at intake accounts for the 8 L difference; within tolerance.',
            $supervisor,
        ));

        // §17 — DEL-0009 also carries a −1 L adjustment of its own.
        $zainab = Delivery::withoutDataScope()->where('reference', 'DEL-0009')->first();

        if ($zainab !== null) {
            $this->actingAs($officer, fn () => $adjustments->record(
                $zainab,
                '-1.00',
                AdjustmentReason::query()->where('code', 'ADJ-CONT')->value('id'),
                'One litre lost decanting into the center can; payable volume is 27 L.',
                $officer,
            ));
        }
    }

    /**
     * The other five centers. Written directly rather than through the services:
     * the point of this data is the network totals, and the database's own
     * constraints (DM-1) still enforce the arithmetic on every row.
     */
    private function seedOtherCenters(): void
    {
        $adulteration = RejectionReason::query()->where('code', 'REJ-ADU')->first();
        $spoilage = RejectionReason::query()->where('code', 'REJ-SPO')->first();
        $late = RejectionReason::query()->where('code', 'REJ-LATE')->first();

        $rejectionPool = [
            [$adulteration, Volume::toCentilitres(self::OTHER_POINT_REJECTIONS['REJ-ADU'])],
            [$spoilage, Volume::toCentilitres(self::OTHER_POINT_REJECTIONS['REJ-SPO'])],
            [$late, Volume::toCentilitres(self::OTHER_POINT_REJECTIONS['REJ-LATE'])],
        ];

        $centers = collect(self::CENTER_CONFIRMED)->except('Kumbotso');
        $deliveriesPerCenter = (int) floor(self::OTHER_DELIVERIES / $centers->count());
        $remainder = self::OTHER_DELIVERIES - ($deliveriesPerCenter * $centers->count());

        $farmerOffset = self::KUMBOTSO_DELIVERIES;
        $gradeA = Grade::query()->where('code', 'GRD-A')->first();
        $gradeB = Grade::query()->where('code', 'GRD-B')->first();
        $officer = $this->staff['Muhammad Bello'] ?? $this->admin;

        $centerIndex = 0;

        foreach ($centers as $centerName => $confirmed) {
            $center = CollectionCenter::withoutDataScope()->where('name', $centerName)->first();
            $points = CollectionPoint::withoutDataScope()
                ->where('collection_center_id', $center->getKey())
                ->where('status', 'active')
                ->get();

            $count = $deliveriesPerCenter + ($centerIndex < $remainder ? 1 : 0);
            $centerIndex++;

            // This center's share of the remaining point rejections.
            $rejections = [];
            foreach ($rejectionPool as $poolIndex => [$reason, $totalCl]) {
                $share = (int) floor($totalCl / $centers->count());

                if ($poolIndex === 0 && $centerIndex === 1) {
                    $share += $totalCl - ($share * $centers->count());
                }

                if ($share > 0) {
                    $rejections[] = [$reason, $share];
                }
            }

            $rejectedTotalCl = array_sum(array_column($rejections, 1));
            $accepted = $this->distribute(Volume::toCentilitres($confirmed), $count);

            // Two consignments per center, split by grade so the network's grade
            // mix is not uniformly Grade A.
            $splitAt = (int) ceil($count * 0.75);
            $groups = [
                ['grade' => $gradeA, 'indexes' => range(0, $splitAt - 1)],
                ['grade' => $gradeB, 'indexes' => range($splitAt, $count - 1)],
            ];

            $rejectionCursor = 0;

            foreach ($groups as $groupIndex => $group) {
                if ($group['indexes'] === []) {
                    continue;
                }

                $point = $points->get($groupIndex % max(1, $points->count()));
                $groupAccepted = 0;
                $deliveryIds = [];

                foreach ($group['indexes'] as $offset => $index) {
                    $acceptedCl = $accepted[$index];
                    $groupAccepted += $acceptedCl;

                    // Spread this center's rejections across the first few rows.
                    $rejectedCl = 0;
                    $reasonId = null;

                    if ($rejectionCursor < count($rejections) && $offset === $rejectionCursor) {
                        [$reason, $rejectedCl] = $rejections[$rejectionCursor];
                        $reasonId = $reason?->getKey();
                        $rejectionCursor++;
                    }

                    $farmer = Farmer::withoutDataScope()
                        ->orderBy('id')
                        ->skip(($farmerOffset + $index) % 1_842)
                        ->first();

                    $presentedCl = $acceptedCl + $rejectedCl;

                    $delivery = Delivery::query()->create([
                        'reference' => Sequences::next('deliveries'),
                        'collection_point_id' => $point->getKey(),
                        'farmer_id' => $farmer->getKey(),
                        'recorded_by_user_id' => $point->agent_user_id,
                        'delivered_at' => Wat::todayAt(6, 5)->addMinutes($index % 60),
                        'litres_presented' => Volume::fromCentilitres($presentedCl),
                        'litres_rejected' => Volume::fromCentilitres($rejectedCl),
                        // DM-1 — the database check constraint verifies this.
                        'litres_accepted' => Volume::fromCentilitres($acceptedCl),
                        'rejection_reason_id' => $reasonId,
                        'containers' => 2,
                        'status' => Delivery::deriveStatus(
                            Volume::fromCentilitres($presentedCl),
                            Volume::fromCentilitres($rejectedCl),
                        ),
                        'cutoff_applied' => $point->effectiveCutoff(),
                        'created_by_user_id' => $point->agent_user_id,
                    ]);

                    $deliveryIds[] = $delivery->getKey();
                }

                $rate = $group['grade']->currentRate();

                $consignment = Consignment::query()->create([
                    'reference' => Sequences::next('consignments'),
                    'collection_point_id' => $point->getKey(),
                    'collection_center_id' => $center->getKey(),
                    'dispatched_by_user_id' => $point->agent_user_id,
                    'dispatched_at' => Wat::todayAt(7, 15),
                    'litres_dispatched' => Volume::fromCentilitres($groupAccepted),
                    'containers' => 10,
                    'confirmed_by_user_id' => $center->officer_user_id,
                    'confirmed_at' => Wat::todayAt(8, 5),
                    // No adjustments and no center rejection outside Kumbotso, so
                    // confirmed equals dispatched (BR-8).
                    'litres_confirmed' => Volume::fromCentilitres($groupAccepted),
                    'grade_id' => $group['grade']->getKey(),
                    // BR-14 — the snapshot, exactly as the service would write it.
                    'grade_rate_id' => $rate?->getKey(),
                    'rate_per_litre_minor' => $rate?->rate_per_litre_minor,
                    'litres_rejected_at_center' => '0.00',
                    'intake_temperature_c' => '18.50',
                    'status' => Consignment::STATUS_CONFIRMED,
                    'created_by_user_id' => $point->agent_user_id,
                ]);

                Delivery::query()->whereIn('id', $deliveryIds)->update(['consignment_id' => $consignment->getKey()]);

                foreach (QualityTestDefinition::query()->required()->get() as $definition) {
                    QualityTest::query()->create([
                        'consignment_id' => $consignment->getKey(),
                        'quality_test_definition_id' => $definition->getKey(),
                        'test_type' => $definition->code,
                        'reading' => $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                        'acceptable_range' => $definition->describeRange(),
                        'passed' => true,
                        'recorded_by_user_id' => $center->officer_user_id,
                        'recorded_at' => Wat::todayAt(8, 0),
                    ]);
                }
            }

            $farmerOffset += $count;

            // A batch per center, still in transit so the reconciliation screen
            // has something to work on.
            $trip = $this->makeTrip($center, 'commercial');

            $centerConsignments = Consignment::withoutDataScope()
                ->where('collection_center_id', $center->getKey())
                ->whereNull('batch_id')
                ->get();

            $batch = Batch::query()->create([
                'reference' => Sequences::next('batches'),
                'collection_center_id' => $center->getKey(),
                'dispatched_by_user_id' => $center->officer_user_id,
                'dispatched_at' => Wat::todayAt(8, 50),
                'litres_dispatched' => Volume::sum($centerConsignments->pluck('litres_confirmed')->all()),
                'containers' => 40,
                'trip_id' => $trip->getKey(),
                'status' => Batch::STATUS_IN_TRANSIT,
                'created_by_user_id' => $center->officer_user_id,
            ]);

            Consignment::query()
                ->whereIn('id', $centerConsignments->pluck('id'))
                ->update(['batch_id' => $batch->getKey()]);
        }
    }

    private function makeTrip(CollectionCenter $center, string $vehicleType): Trip
    {
        $route = TransportRoute::query()->where('name', $center->name.' → Factory')->first()
            ?? TransportRoute::query()->first();

        return Trip::query()->create([
            'reference' => Sequences::next('trips'),
            'route_id' => $route->getKey(),
            'vehicle_id' => Vehicle::query()->where('type', $vehicleType)->value('id'),
            'driver_id' => Driver::query()->inRandomOrder()->value('id'),
            'logged_by_user_id' => $center->logistics_user_id ?? $this->admin->getKey(),
            'departed_at' => Wat::todayAt(8, 50),
            'litres_carried' => '0.00',
            'fee_minor' => (int) $route->tariff_minor,
            'route_tariff_minor_snapshot' => (int) $route->tariff_minor,
            'payment_status' => Trip::PAYMENT_QUEUED,
            'created_by_user_id' => $this->admin->getKey(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | §17 — REQ-2026-0142 and the approval queue
     * ------------------------------------------------------------------ */

    private function seedRequisitions(): void
    {
        $service = app(RequisitionService::class);
        $engine = app(WorkflowEngine::class);

        $logistics = Department::query()->where('name', 'Logistics')->first();
        $requester = $this->staff['Idris Kabir'] ?? $this->admin;
        $deptHead = $this->staffHolding('Department Head')->first();
        $audit = $this->staff['Umar Muduru'] ?? null;
        $ed = $this->staff['Mohammed Aliyu'] ?? null;
        $accounts = $this->staff['Aliyu Danjuma'] ?? null;

        // Give the Department Head the Logistics department as their scope so the
        // approval is actually within their reach (SCOPE-1).
        if ($deptHead !== null && $logistics !== null) {
            $this->assign($deptHead, 'Department Head', ScopeType::Department, $logistics->getKey());
        }

        /*
         * §17 — REQ-2026-0142: ₦3,400,000, Logistics, at stage 3 of 6.
         *
         * BR-19 puts it in the Major band (above ₦500,000), so all six stages
         * apply. Stage 1 is satisfied by submission and stage 2 by the Department
         * Head's approval, which leaves it sitting at stage 3, Internal Audit.
         */
        $major = $this->actingAs($requester, fn () => $service->create([
            'title' => 'Diesel — 2,500 L',
            'department_id' => $logistics?->getKey(),
            'category' => 'Fuel & lubricants',
            'urgency' => 'high',
            'needed_by' => Wat::today()->addDays(5)->toDateString(),
            'suggested_vendor' => 'Kano Petroleum Services',
        ], [
            ['item' => 'Diesel (AGO)', 'purpose' => 'Center-to-factory haulage for the month',
                'quantity' => 2_500, 'unit' => 'litre', 'unit_price_minor' => 1_360_00],
        ], $requester));

        $this->actingAs($requester, fn () => $service->submit($major, $requester));

        if ($deptHead !== null) {
            $this->actingAs($deptHead, fn () => $engine->approve(
                $major->refresh()->workflowInstance,
                $deptHead,
                null,
                'Budgeted under the monthly haulage line.',
            ));
        }

        $service->syncFromWorkflow($major->refresh());

        // Six more requisitions so the queue looks like the prototype's:
        // 3 at Internal Audit, 2 at ED, and one already with Accounts.
        $others = [
            ['Milk cans — 40 units', 'Milk Collection', 'Equipment', 40, 'unit', 21_500_00, 2],
            ['Animal feed for the shop', 'One-Stop Shop', 'Stock', 100, 'bag', 12_000_00, 2],
            ['Lab reagents', 'Milk Collection', 'Consumables', 20, 'pack', 32_000_00, 2],
            ['Motorcycle servicing', 'Logistics', 'Maintenance', 8, 'service', 45_000_00, 3],
            ['Cold room repair', 'Milk Collection', 'Maintenance', 1, 'job', 780_000_00, 3],
            ['Office stationery', 'Human Resources', 'Consumables', 30, 'ream', 4_500_00, 4],
        ];

        foreach ($others as [$title, $departmentName, $category, $quantity, $unit, $price, $stopAtStage]) {
            $department = Department::query()->where('name', $departmentName)->first();
            $raiser = collect($this->staff)
                ->first(fn (User $u) => $u->department_id === $department?->getKey()
                    && $u->fresh()->hasPermission('purchase.requisitions.create'))
                ?? $requester;

            $requisition = $this->actingAs($raiser, fn () => $service->create([
                'title' => $title,
                'department_id' => $department?->getKey(),
                'category' => $category,
                'urgency' => 'normal',
                'needed_by' => Wat::today()->addDays(random_int(7, 30))->toDateString(),
            ], [
                ['item' => $title, 'purpose' => 'Operational requirement',
                    'quantity' => $quantity, 'unit' => $unit, 'unit_price_minor' => $price],
            ], $raiser));

            $this->actingAs($raiser, fn () => $service->submit($requisition, $raiser));

            $instance = $requisition->refresh()->workflowInstance;

            // Walk it forward to the stage the prototype shows it at.
            $approvers = [2 => $deptHead, 3 => $audit, 4 => $ed, 5 => $accounts];

            for ($stage = 2; $stage < $stopAtStage; $stage++) {
                $approver = $approvers[$stage] ?? null;

                if ($approver === null || $instance === null || ! $instance->isOpen()) {
                    break;
                }

                // BR-18 — never let the requester approve their own.
                if ($instance->requester_user_id === $approver->getKey()) {
                    break;
                }

                try {
                    $this->actingAs($approver, fn () => $engine->approve($instance, $approver, null, 'Verified.'));
                } catch (\Throwable) {
                    break;
                }

                $instance = $instance->refresh();
            }

            $service->syncFromWorkflow($requisition->refresh());
        }

        // BR-24 — one active delegation, so the workflow screen's "single point"
        // warning has a counter-example.
        if ($ed !== null && $audit !== null) {
            Delegation::query()->firstOrCreate([
                'from_user_id' => $ed->getKey(),
                'to_user_id' => $audit->getKey(),
                'role_id' => Role::query()->where('name', 'Executive Director')->value('id'),
            ], [
                'starts_on' => Wat::today()->subDays(2)->toDateString(),
                'ends_on' => Wat::today()->addDays(5)->toDateString(),
                'reason' => 'ED on leave; Internal Audit covering stage 4.',
            ]);
        }
    }

    private function seedSalesAndActivities(): void
    {
        $sales = app(SaleService::class);
        $officer = $this->staff['Hauwa Ibrahim'] ?? $this->admin;

        $feed = Product::query()->where('sku', 'FEED-CON')->first();
        $bran = Product::query()->where('sku', 'FEED-BRAN')->first();
        $gloves = Product::query()->where('sku', 'CON-GLOVE')->first();
        $dewormer = Product::query()->where('sku', 'VET-DEW')->first();

        $farmers = Farmer::withoutDataScope()->orderBy('id')->limit(6)->get();
        $cooperative = Cooperative::withoutDataScope()->first();

        $plan = [
            ['walkin', null, null, 'cash', [[$feed, 2], [$bran, 1]], null],
            ['farmer', $farmers[1] ?? null, null, 'milk_deduction', [[$bran, 3]], null],
            ['cooperative', null, $cooperative, 'credit', [[$feed, 10]], null],
            // BR-27 — a prescription category needs a reference.
            ['farmer', $farmers[2] ?? null, null, 'cash', [[$dewormer, 4]], 'RX-2026-0031'],
            ['internal', null, null, 'transfer', [[$gloves, 2]], null],
        ];

        foreach ($plan as [$customerType, $farmer, $coop, $method, $lines, $prescription]) {
            $items = array_map(fn (array $line) => [
                'product_id' => $line[0]->getKey(),
                'quantity' => (float) $line[1],
            ], $lines);

            $this->actingAs($officer, fn () => $sales->record([
                'customer_type' => $customerType,
                'farmer_id' => $farmer?->getKey(),
                'cooperative_id' => $coop?->getKey(),
                'customer_name' => $customerType === 'walkin' ? 'Walk-in customer' : null,
                'payment_method' => $method,
                'prescription_reference' => $prescription,
                'amount_received_minor' => 0,
                'sold_at' => Wat::todayAt(random_int(9, 16), random_int(0, 59)),
            ], $items, $officer));
        }

        // §6.6 — field activities, including one that closed a follow-up.
        $agents = ExtensionAgent::withoutDataScope()->with('communities')->get();
        $visit = ActivityType::query()->where('code', 'VISIT')->first();
        $training = ActivityType::query()->where('code', 'TRAINING')->first();

        foreach ($agents as $index => $agent) {
            foreach (range(1, 3) as $n) {
                $community = $agent->communities->first();

                if ($community === null) {
                    continue;
                }

                FieldActivity::query()->create([
                    'reference' => Sequences::next('field_activities'),
                    'extension_agent_id' => $agent->getKey(),
                    'activity_type_id' => ($n % 2 === 0 ? $training : $visit)->getKey(),
                    'community_id' => $community->getKey(),
                    'activity_date' => Wat::today()->subDays($n * 3)->toDateString(),
                    'farmers_reached' => $n % 2 === 0 ? random_int(12, 30) : random_int(1, 4),
                    'topic' => $n % 2 === 0 ? 'Clean milk production' : 'Household visit and herd check',
                    'findings' => 'Recorded during the routine visit cycle.',
                    'source' => 'web',
                    'synced_at' => Wat::now(),
                    'created_by_user_id' => $agent->user_id,
                ]);
            }
        }
    }

    /* ---------------------------------------------------------------------
     | Quality follow-ups (BR-5)
     * ------------------------------------------------------------------ */

    /**
     * BR-5 — "Three rejections for the same reason within the configured window
     * opens a quality follow-up automatically and notifies the extension team."
     *
     * The follow-ups are NOT written directly. Three rejected deliveries are
     * recorded through DeliveryService on three consecutive days, and the rule
     * opens the follow-up itself — which is the only way a demo dataset can show
     * that the rule works rather than that a row can be inserted.
     *
     * The history sits BEHIND today, so §17's figures for Thursday 31 Jul 2026 are
     * untouched.
     */
    private function seedQualityFollowups(): void
    {
        $deliveries = app(DeliveryService::class);
        $followups = app(QualityFollowupService::class);

        /*
         * "A reason that opens follow-ups" is not a flag — it is a threshold and a
         * window both being set, which is what RejectionReason::opensFollowups()
         * reads. Selecting on the same two columns keeps §9 in charge: an
         * administrator who clears the threshold stops the follow-ups, here and in
         * the rule alike.
         */
        $adulteration = RejectionReason::query()
            ->where('followup_threshold', '>', 0)
            ->where('followup_window_days', '>', 0)
            ->orderBy('position')
            ->first();

        if ($adulteration === null) {
            return;
        }

        // Two farmers at two different points, so the extension team's list is not
        // a single community's problem.
        $subjects = Farmer::withoutDataScope()
            ->whereNotNull('default_collection_point_id')
            ->orderBy('id')
            ->limit(2)
            ->get();

        foreach ($subjects as $index => $farmer) {
            $point = CollectionPoint::withoutDataScope()->find($farmer->default_collection_point_id);

            if ($point === null) {
                continue;
            }

            $agent = collect($this->staff)
                ->first(fn (User $user) => $user->fresh()->hasPermission('milk.deliveries.create'))
                ?? $this->admin;

            // Days 4, 3 and 2 back: the third rejection opens the follow-up, and
            // nothing lands on today.
            foreach ([4, 3, 2] as $daysAgo) {
                $this->actingAs($agent, fn () => $deliveries->record($point, $farmer, [
                    'litres_presented' => '18.00',
                    'litres_rejected' => '5.00',
                    'rejection_reason_id' => $adulteration->getKey(),
                    'delivered_at' => Wat::todayAt(6, 30)->subDays($daysAgo),
                ], $agent));
            }
        }

        /*
         * One of the two is closed by a logged field visit, which is the Phase 5
         * acceptance criterion: "closing it requires a logged field activity".
         * The other stays open so the screen shows both states.
         */
        $open = QualityFollowup::query()->with('subject')->orderBy('id')->get();
        $toClose = $open->first();

        if ($toClose === null) {
            return;
        }

        $agent = ExtensionAgent::withoutDataScope()->with('communities')->first();
        $visit = ActivityType::query()->where('code', 'VISIT')->first();

        if ($agent === null || $visit === null || $agent->user_id === null) {
            return;
        }

        $actor = User::query()->find($agent->user_id) ?? $this->admin;

        $activity = FieldActivity::query()->create([
            'reference' => Sequences::next('field_activities'),
            'extension_agent_id' => $agent->getKey(),
            'activity_type_id' => $visit->getKey(),
            'community_id' => $agent->communities->first()?->getKey(),
            'activity_date' => Wat::today()->subDay()->toDateString(),
            'farmers_reached' => 1,
            'topic' => 'Follow-up on repeated adulteration rejections',
            'findings' => 'Milking hygiene reviewed with the household; water container removed from the milking area.',
            'source' => 'web',
            'synced_at' => Wat::now(),
            'created_by_user_id' => $actor->getKey(),
        ]);

        $this->actingAs($actor, fn () => app(QualityFollowupService::class)->close($toClose, $activity, $actor));
    }

    /* ---------------------------------------------------------------------
     | Cooperative ledger (§6.6)
     * ------------------------------------------------------------------ */

    /**
     * §6.6 — a cooperative's account is a running ledger, and `balance_after_minor`
     * is stored per entry rather than recomputed on read, so a later correction
     * cannot silently rewrite history.
     *
     * The entries are derived from what the demo already contains: the credit sale
     * recorded in seedSalesAndActivities, plus the month's milk supply and a
     * part-payment against it.
     */
    private function seedCooperativeLedger(): void
    {
        $accounts = CooperativeAccount::query()->with('cooperative')->get();
        $accountant = $this->staff['Aliyu Danjuma'] ?? $this->admin;

        foreach ($accounts as $account) {
            $balance = 0;
            $day = Wat::today()->startOfMonth();

            $entries = match ($account->kind) {
                Cooperative::ACCOUNT_GENERAL => [
                    [CooperativeEntry::DIRECTION_IN, 'Milk supplied — first fortnight', 1_842_500_00, 3],
                    [CooperativeEntry::DIRECTION_OUT, 'Concentrate feed — 10 bags on credit', 120_000_00, 12],
                    [CooperativeEntry::DIRECTION_IN, 'Milk supplied — second fortnight', 1_611_250_00, 17],
                    [CooperativeEntry::DIRECTION_OUT, 'Part payment released against supply', 2_500_000_00, 26],
                ],
                default => [
                    [CooperativeEntry::DIRECTION_IN, 'Monthly social levy from members', 84_000_00, 5],
                    [CooperativeEntry::DIRECTION_OUT, 'Bereavement support — one member household', 50_000_00, 19],
                    [CooperativeEntry::DIRECTION_IN, 'Monthly social levy from members', 84_000_00, 28],
                ],
            };

            foreach ($entries as [$direction, $description, $amount, $dayOfMonth]) {
                $balance += $direction === CooperativeEntry::DIRECTION_IN ? $amount : -$amount;

                CooperativeEntry::query()->create([
                    'cooperative_account_id' => $account->getKey(),
                    'entry_date' => $day->copy()->addDays($dayOfMonth - 1)->toDateString(),
                    'description' => $description,
                    'direction' => $direction,
                    'amount_minor' => $amount,
                    'balance_after_minor' => $balance,
                    'created_by_user_id' => $accountant->getKey(),
                ]);
            }

            $account->forceFill(['balance_minor' => $balance])->save();
        }
    }

    /* ---------------------------------------------------------------------
     | Leave (WF-002)
     * ------------------------------------------------------------------ */

    /**
     * §4 `/leave` shows a queue, so the queue needs to contain each state a
     * reviewer would want to see: one draft, two awaiting a decision at different
     * stages, one approved and one rejected.
     *
     * BR-20 decides how many stages apply — the leave workflow's second band cuts
     * in above five days — so the long request below is deliberately longer than
     * the short one.
     */
    private function seedLeave(): void
    {
        $service = app(LeaveService::class);
        $engine = app(WorkflowEngine::class);

        $annual = LeaveType::query()->where('code', 'ANNUAL')->first()
            ?? LeaveType::query()->orderBy('position')->first();
        $compassionate = LeaveType::query()->where('code', 'COMPASSIONATE')->first() ?? $annual;

        if ($annual === null) {
            return;
        }

        $deptHead = $this->staffHolding('Department Head')->first();
        $hrManager = $this->staffHolding('HR Manager')->first();

        // Employees who are not the approvers, so BR-18 never blocks a decision.
        $employees = Employee::withoutDataScope()
            ->whereNotNull('department_id')
            ->whereNotIn('id', array_filter([$deptHead?->employee_id, $hrManager?->employee_id]))
            ->orderBy('id')
            ->limit(5)
            ->get();

        if ($employees->isEmpty()) {
            return;
        }

        $plan = [
            // [type, days, start offset, submit?, decisions to take]
            [$compassionate, 2, 4, false, []],
            [$annual, 3, 7, true, []],
            [$annual, 10, 14, true, ['approve']],
            [$annual, 4, -21, true, ['approve']],
            [$compassionate, 2, -10, true, ['reject']],
        ];

        foreach ($plan as $index => [$type, $days, $offset, $submit, $decisions]) {
            $employee = $employees[$index % $employees->count()];
            $actor = $employee->user ?? $this->admin;
            $starts = Wat::today()->addDays($offset);

            $request = $this->actingAs($actor, fn () => $service->create($employee, [
                'leave_type_id' => $type->getKey(),
                'starts_on' => $starts->toDateString(),
                'ends_on' => $starts->copy()->addDays($days - 1)->toDateString(),
                'reason' => match (true) {
                    $days >= 10 => 'Annual leave — family travel.',
                    $offset < 0 => 'Taken and recorded after the fact.',
                    default => 'Personal matters.',
                },
            ], $actor));

            if (! $submit) {
                continue;
            }

            $this->actingAs($actor, fn () => $service->submit($request, $actor));

            foreach ($decisions as $decision) {
                $instance = $request->refresh()->workflowInstance;

                if ($instance === null || ! $instance->isOpen()) {
                    break;
                }

                $approver = match ($instance->currentStage?->approvingRole?->name) {
                    'HR Manager' => $hrManager,
                    default => $deptHead,
                };

                if ($approver === null || $instance->requester_user_id === $approver->getKey()) {
                    break;
                }

                try {
                    $this->actingAs($approver, fn () => $decision === 'approve'
                        ? $engine->approve($instance, $approver, null, 'Cover arranged.')
                        : $engine->reject($instance, $approver, 'Peak collection week — please re-plan.'));
                } catch (\Throwable) {
                    break;
                }

                $service->syncFromWorkflow($request->refresh());
            }

            // A ten-day request crosses the band break, so approving stage 2 leaves
            // it with the HR Manager rather than finishing it. Walk it once more so
            // one request on the screen is fully approved.
            $instance = $request->refresh()->workflowInstance;

            if ($decisions === ['approve'] && $instance !== null && $instance->isOpen() && $hrManager !== null) {
                try {
                    $this->actingAs($hrManager, fn () => $engine->approve($instance, $hrManager, null, 'Entitlement confirmed.'));
                    $service->syncFromWorkflow($request->refresh());
                } catch (\Throwable) {
                    // Leave it where it is; an in-review row is a valid demo state.
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     | Payroll (WF-004)
     * ------------------------------------------------------------------ */

    /**
     * §15.1 — FARMER and transport payments are blocked on an open decision, so
     * this is staff payroll only, and the payroll screen says so.
     *
     * BR-35 — the three test accounts are excluded by the service, not here. The
     * run's employee_count is therefore the honest answer to "who gets paid", and
     * the reconciliation line below prints it so a reviewer can check it against
     * the staff list.
     */
    private function seedPayroll(): void
    {
        $service = app(PayrollService::class);
        $engine = app(WorkflowEngine::class);

        $hr = $this->staffHolding('HR Manager')->first()
            ?? $this->staffHolding('HR Officer')->first()
            ?? $this->admin;
        $accounts = $this->staff['Aliyu Danjuma'] ?? null;
        $gm = $this->staffHolding('General Manager')->first();

        $previous = Wat::today()->startOfMonth()->subMonth();

        // Last month: approved and paid, so payslips exist to open.
        $paid = $this->actingAs($hr, fn () => $service->generate(
            (int) $previous->format('Y'),
            (int) $previous->format('n'),
            $hr,
        ));

        $this->actingAs($hr, fn () => $service->submitForApproval($paid, $hr));

        foreach ([$accounts, $gm] as $approver) {
            $instance = $paid->refresh()->workflowInstance;

            if ($approver === null || $instance === null || ! $instance->isOpen()) {
                continue;
            }

            try {
                $this->actingAs($approver, fn () => $engine->approve($instance, $approver, null, 'Verified against the staff list.'));
            } catch (\Throwable) {
                break;
            }
        }

        $service->syncFromWorkflow($paid->refresh());

        // This month: prepared and sitting with Accounts, which is the state the
        // payroll screen is most useful in.
        $current = Wat::today()->startOfMonth();

        $pending = $this->actingAs($hr, fn () => $service->generate(
            (int) $current->format('Y'),
            (int) $current->format('n'),
            $hr,
        ));

        $this->actingAs($hr, fn () => $service->submitForApproval($pending, $hr));
        $service->syncFromWorkflow($pending->refresh());
    }

    /* ---------------------------------------------------------------------
     | Permission testing protocol (§5.4)
     * ------------------------------------------------------------------ */

    /**
     * TEST-1..TEST-5 — the register on `/admin/permission-tests` is only meaningful
     * with a completed run in it, and a run is the one thing a reviewer cannot
     * safely create for themselves on a live system.
     *
     * The run below is a real one: the runner applies a simulated assignment to a
     * TEST account, evaluates every live permission plus the scope probes, and
     * records expected-versus-actual. TEST-3 keeps the environment to staging.
     */
    private function seedPermissionTestRun(): void
    {
        $runner = app(PermissionTestRunner::class);

        $role = Role::query()->where('name', 'Milk Collection Officer')->first();
        $testUser = User::query()->where('is_test', true)->orderBy('id')->first();
        $center = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->first();

        if ($role === null || $testUser === null || $center === null) {
            return;
        }

        $administrator = $this->staffHolding('System Administrator')->first() ?? $this->admin;

        try {
            $run = $this->actingAs($administrator, fn () => $runner->start($role, $testUser, [
                'environment' => 'staging',
                'scope_type' => ScopeType::Center->value,
                'scope_target_id' => $center->getKey(),
                'notes' => 'Routine check before the quarterly role review.',
            ], $administrator));

            $this->actingAs($administrator, fn () => $runner->execute($run, $administrator));

            $run->refresh();

            if ($run->hasPassed()) {
                $this->actingAs($administrator, fn () => $runner->approveForLive($run, $administrator));
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Permission test run skipped: '.$e->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * Split a total (in centilitres) into $count parts that sum EXACTLY to it.
     * Used so §17's totals reconcile to the litre rather than approximately.
     *
     * @return array<int, int>
     */
    private function distribute(int $totalCentilitres, int $count): array
    {
        $base = intdiv($totalCentilitres, $count);
        $remainder = $totalCentilitres - ($base * $count);

        $parts = array_fill(0, $count, $base);

        for ($i = 0; $i < $remainder; $i++) {
            $parts[$i]++;
        }

        return $parts;
    }

    /**
     * Run a callback as a specific user, so the services record the right actor
     * and the audit trail reads like real activity.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function actingAs(User $user, \Closure $callback): mixed
    {
        $previous = auth()->user();

        auth()->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previous instanceof User) {
                auth()->setUser($previous);
            } else {
                auth()->forgetUser();
            }
        }
    }

    /** Print the §17 reconciliation so a reviewer can check it at a glance. */
    private function report(): void
    {
        $confirmed = Consignment::withoutDataScope()->whereNotNull('confirmed_at')->sum('litres_confirmed');
        $kumbotso = CollectionCenter::withoutDataScope()->where('name', 'Kumbotso')->first();

        $kumbotsoConsignments = Consignment::withoutDataScope()
            ->where('collection_center_id', $kumbotso->getKey())
            ->get();

        $zainab = Delivery::withoutDataScope()->where('reference', 'DEL-0009')->first();
        $payable = $zainab === null
            ? '0.00'
            : Volume::add($zainab->litres_accepted, $zainab->adjustments()->sum('litres_delta'));

        $rate = $zainab?->consignment?->rate_per_litre_minor ?? 0;
        $gross = Money::valueVolume($payable, (int) $rate);
        $levy = Money::percentageOf($gross, '2');

        $this->command?->newLine();
        $this->command?->info('§17 reconciliation — demo dataset');
        $this->command?->line(sprintf('  Network confirmed:      %s L (target 12,480.00)', number_format((float) $confirmed, 2)));
        $this->command?->line(sprintf('  Deliveries today:       %d (target 514)', Delivery::withoutDataScope()->whereDate('delivered_at', Wat::today())->count()));
        $this->command?->line(sprintf('  Collection points:      %d, %d active (target 42 / 38)',
            CollectionPoint::withoutDataScope()->count(),
            CollectionPoint::withoutDataScope()->where('status', 'active')->count()));
        $this->command?->line(sprintf('  Farmers:                %s (target 1,842)', number_format(Farmer::withoutDataScope()->count())));
        // §17's rejection figures are TODAY's, as the dashboard shows them. The
        // sums are date-scoped for the same reason the dashboard's are: the demo
        // dataset also carries a few days of history behind today (see
        // seedQualityFollowups), and an unscoped total would quietly include it.
        $this->command?->line(sprintf('  Rejected at points:     %s L (target 112.00)', number_format((float) Delivery::withoutDataScope()
            ->whereDate('delivered_at', Wat::today())->sum('litres_rejected'), 2)));
        $this->command?->line(sprintf('  Rejected at centers:    %s L (target 30.00)', number_format((float) Consignment::withoutDataScope()
            ->whereDate('confirmed_at', Wat::today())->sum('litres_rejected_at_center'), 2)));
        $this->command?->line(sprintf('  Kumbotso dispatched:    %s L (target 3,444.00)', number_format((float) $kumbotsoConsignments->sum('litres_dispatched'), 2)));
        $this->command?->line(sprintf('  Kumbotso confirmed:     %s L (target 3,400.00)', number_format((float) $kumbotsoConsignments->sum('litres_confirmed'), 2)));
        $this->command?->line(sprintf('  Kumbotso consignments:  %d (target 6)', $kumbotsoConsignments->count()));
        $this->command?->line(sprintf('  DEL-0009 payable:       %s L at %s → gross %s, less 2%% levy = %s (target ₦6,615.00)',
            $payable,
            Money::format((int) $rate),
            Money::format($gross),
            Money::format($gross - $levy)));
        $this->command?->line(sprintf('  Batch reference:        %s (target BATCH-0087)',
            Batch::withoutDataScope()->where('collection_center_id', $kumbotso->getKey())->value('reference') ?? '—'));
        $this->command?->line(sprintf('  Major requisition:      %s (target REQ-2026-0142)',
            Requisition::withoutDataScope()->where('total_minor', 340_000_000)->value('reference') ?? '—'));
        $this->command?->newLine();
        $this->command?->warn('Demo accounts sign in with: GondalDemo!2026 (e.g. sadiq.ahmed@gondalfulbe.ng)');
        $this->command?->newLine();
    }
}
