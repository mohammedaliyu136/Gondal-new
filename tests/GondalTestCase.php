<?php

namespace Tests;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeType;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Farmer;
use App\Models\Lga;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\User;
use App\Support\Settings;
use App\Support\Wat;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * Shared setup for the rule tests.
 *
 * §18.2 — "Every business rule for that module has a passing automated test,
 * referenced by rule ID." Each test method below names its rule in the method
 * name and repeats the rule's own words in a docblock, so a failure tells you
 * which contract broke rather than only which assertion did.
 *
 * The seeders that run here are the real ones: permissions, roles, reference data
 * and workflows. Testing against a hand-rolled fixture would let the tests pass
 * while the seeded system was wrong.
 */
abstract class GondalTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * The hour the suite believes it is.
     *
     * ARCH-9 — a milk day runs from before dawn to the 07:00 cut-off, so most
     * fixtures dispatch and confirm against wall-clock times in that window. Read
     * against the REAL clock those scenarios are only coherent for part of the
     * day: a fixture that dispatches at 07:00 and then confirms "now" describes a
     * confirmation before its own dispatch when the suite runs at 01:00, and
     * ST-1 rightly refuses it. Twelve tests failed that way, and the shop's day
     * aggregates failed the same way in the 00:00–01:00 window.
     *
     * Freezing the clock at a mid-morning WAT hour makes every such fixture mean
     * the same thing at every hour of the real day. The DATE is still today, so
     * nothing that reasons about the current month or year is distorted, and a
     * test that cares about a boundary travels there explicitly — which is what
     * TimeAndDateRulesTest does to pin the 00:30 WAT behaviour.
     */
    protected const CLOCK_HOUR = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Wat::todayAt(self::CLOCK_HOUR));

        DataScope::asSystem(function (): void {
            $this->seed([
                PermissionSeeder::class,
                RoleSeeder::class,
                ReferenceDataSeeder::class,
                WorkflowSeeder::class,
            ]);
        });

        Settings::flush();
    }

    /**
     * Runs a callback with the data-scope global scope suspended, for the
     * arrange step where the test is acting as the system rather than a user.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function asSystem(\Closure $callback): mixed
    {
        return DataScope::asSystem($callback);
    }

    /**
     * A staff account. BR-31 is respected everywhere in the application; tests
     * set a hash directly because they are not the application.
     */
    protected function makeUser(string $name, array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => $name,
            'email' => str($name)->slug('.')->toString().'@gondalfulbe.ng',
            'status' => 'active',
            'is_test' => false,
            'two_factor_enabled' => true,
        ], $attributes));

        $user->password_hash = Hash::make('Correct-Horse-9');
        $user->password_changed_at = Wat::now();
        $user->save();

        return $user;
    }

    /**
     * SCOPE-1 — an assignment carries its own scope.
     *
     * Mirrors UserAdminService::assignRole's storage exactly: a single target in
     * the column, several in the child table. A test that sets up a two-centre
     * supervisor must exercise the same rows production writes, or it proves
     * nothing about production.
     *
     * @param  array<int, int>  $targetIds  additional named targets
     */
    protected function assignRole(
        User $user,
        string $roleName,
        ScopeType $scopeType = ScopeType::Network,
        ?int $targetId = null,
        array $targetIds = [],
    ): RoleAssignment {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $named = array_map('intval', $targetIds);

        if ($targetId !== null) {
            $named[] = $targetId;
        }

        $named = array_values(array_unique(array_filter($named)));
        sort($named);

        $single = count($named) === 1 ? $named[0] : null;

        $assignment = RoleAssignment::query()->updateOrCreate(
            ['role_id' => $role->getKey(), 'user_id' => $user->getKey()],
            [
                'scope_type' => $scopeType->value,
                'scope_target_id' => $single,
                'assigned_at' => Wat::now(),
            ],
        );

        RoleUserScopeTarget::query()->where('role_user_id', $assignment->getKey())->delete();

        if ($single === null) {
            foreach ($named as $target) {
                RoleUserScopeTarget::query()->create([
                    'role_user_id' => $assignment->getKey(),
                    'target_id' => $target,
                ]);
            }
        }

        $user->forgetAccessMemo();

        return $assignment;
    }

    /**
     * A minimal but complete milk-collection world: two LGAs, two centers, one
     * point each, and a farmer at the first point.
     *
     * @return array<string, mixed>
     */
    protected function makeMilkWorld(): array
    {
        return $this->asSystem(function (): array {
            /*
             * Any two seeded LGAs will do — this fixture needs two distinct
             * places, not two particular ones. Naming them meant that moving
             * the network from Kano to Adamawa in ReferenceDataSeeder broke
             * every test that builds a milk world, for no reason connected to
             * what those tests assert.
             */
            [$kumbotso, $dawakin] = Lga::query()->orderBy('id')->take(2)->get()->all();

            $communityA = Community::query()->where('lga_id', $kumbotso->getKey())->firstOrFail();
            $communityB = Community::query()->where('lga_id', $dawakin->getKey())->firstOrFail();

            $centerA = CollectionCenter::query()->create([
                'code' => 'CTR-KUM', 'name' => 'Kumbotso', 'lga_id' => $kumbotso->getKey(),
                'distance_to_factory_km' => '22.00', 'transport_fee_minor' => 850_000, 'status' => 'active',
            ]);

            $centerB = CollectionCenter::query()->create([
                'code' => 'CTR-DAW', 'name' => 'Dawakin Tofa', 'lga_id' => $dawakin->getKey(),
                'distance_to_factory_km' => '31.00', 'transport_fee_minor' => 1_100_000, 'status' => 'active',
            ]);

            $pointA = CollectionPoint::query()->create([
                'code' => 'PT-001', 'name' => 'Tudun Wada',
                'community_id' => $communityA->getKey(), 'lga_id' => $kumbotso->getKey(),
                'collection_center_id' => $centerA->getKey(), 'status' => 'active',
            ]);

            $pointB = CollectionPoint::query()->create([
                'code' => 'PT-002', 'name' => 'Tumfafi',
                'community_id' => $communityB->getKey(), 'lga_id' => $dawakin->getKey(),
                'collection_center_id' => $centerB->getKey(), 'status' => 'active',
            ]);

            $cooperative = Cooperative::query()->create([
                'code' => 'COOP-KUM', 'name' => 'Kumbotso Dairy Cooperative',
                'community_id' => $communityA->getKey(), 'lga_id' => $kumbotso->getKey(),
                'collection_point_id' => $pointA->getKey(),
                'savings_deduction_pct' => '5.00', 'levy_pct' => '2.00',
                'social_contribution_minor' => 25_000, 'status' => 'active',
            ]);

            foreach ([Cooperative::ACCOUNT_GENERAL, Cooperative::ACCOUNT_SOCIAL] as $kind) {
                CooperativeAccount::query()->create([
                    'cooperative_id' => $cooperative->getKey(),
                    'kind' => $kind,
                    'balance_minor' => 0,
                ]);
            }

            $farmer = Farmer::query()->create([
                'code' => 'FRM-00001', 'name' => 'Zainab Idris',
                'community_id' => $communityA->getKey(), 'lga_id' => $kumbotso->getKey(),
                'cooperative_id' => $cooperative->getKey(), 'cooperative_member_no' => 'M-0001',
                'default_collection_point_id' => $pointA->getKey(),
                'herd_size' => 9, 'lactating_count' => 4,
                'enrolled_on' => Wat::today()->subYear()->toDateString(), 'status' => 'active',
            ]);

            $farmerB = Farmer::query()->create([
                'code' => 'FRM-00002', 'name' => 'Amina Bello',
                'community_id' => $communityB->getKey(), 'lga_id' => $dawakin->getKey(),
                'default_collection_point_id' => $pointB->getKey(),
                'herd_size' => 6, 'lactating_count' => 2,
                'enrolled_on' => Wat::today()->subYear()->toDateString(), 'status' => 'active',
            ]);

            return compact('kumbotso', 'dawakin', 'communityA', 'communityB',
                'centerA', 'centerB', 'pointA', 'pointB', 'cooperative', 'farmer', 'farmerB');
        });
    }

    /** A department plus an employee/user pair, for the HR and approval rules. */
    protected function makeDepartmentWithHead(string $name = 'Logistics'): array
    {
        return $this->asSystem(function () use ($name): array {
            $department = Department::query()->create(['name' => $name, 'status' => 'active']);

            $head = $this->makeUser($name.' Head', ['department_id' => $department->getKey()]);
            $employee = Employee::query()->create([
                'code' => 'EMP-'.str_pad((string) (Employee::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'name' => $head->name,
                'department_id' => $department->getKey(),
                'gross_monthly_minor' => 400_000_00,
                'status' => 'confirmed',
                'joined_on' => Wat::today()->subYears(2)->toDateString(),
            ]);

            $head->forceFill(['employee_id' => $employee->getKey()])->save();
            $department->forceFill(['head_user_id' => $head->getKey()])->save();

            return compact('department', 'head', 'employee');
        });
    }
}
