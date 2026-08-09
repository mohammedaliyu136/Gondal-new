<?php

namespace Tests\Feature\Rules;

use App\Authorization\Access;
use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\ApiToken;
use App\Models\AppNotification;
use App\Models\AuditEntry;
use App\Models\AuthSession;
use App\Models\Batch;
use App\Models\CollectionCenter;
use App\Models\Consignment;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\Department;
use App\Models\Device;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LoginCode;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\EmailAddressChangedNotification;
use App\Notifications\PasswordResetByAdminNotification;
use App\Notifications\TemporaryPasswordSetNotification;
use App\Services\Admin\RoleAdminService;
use App\Services\Admin\UserAdminService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\SessionRegistry;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollService;
use App\Services\Milk\DeliveryService;
use App\Services\Reporting\DashboardMetrics;
use App\Support\Navigation;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\GondalTestCase;

/** §7.6 — access and accounts, plus §5's permission and scope model. */
class AccessAndAccountRulesTest extends GondalTestCase
{
    /**
     * BR-31 — "Administrators never see or set a user's password. Creation and
     * reset both send a code; the user chooses their own password."
     */
    public function test_br31_creating_a_user_never_sets_a_password(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Sadiq Ahmed');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $created = app(UserAdminService::class)->create([
            'name' => 'New Officer',
            'email' => 'new.officer@gondalfulbe.ng',
        ], $admin);

        // The hash is random and nobody knows it, so the administrator's own
        // password does not work, and neither does any guessable value.
        foreach (['Correct-Horse-9', 'password', ''] as $guess) {
            $this->assertFalse(
                Hash::check($guess, $created->password_hash),
                'No known value may unlock a newly created account.',
            );
        }

        // AUTH-5 treats a null password_changed_at as expired.
        $this->assertNull($created->password_changed_at);
        $this->assertTrue($created->passwordHasExpired());

        // An activation code was issued and emailed.
        $this->assertDatabaseHas('login_codes', [
            'user_id' => $created->id,
            'purpose' => LoginCode::PURPOSE_RESET,
        ]);

        Notification::assertSentTo($created, AccountCreatedNotification::class);

        /*
         * CREATION offers no password field, and neither does the detail screen of
         * an account still pending activation — there is nothing to reset, and the
         * only thing on offer is re-sending the code.
         *
         * Narrower than it reads at first glance, and deliberately so: an ACTIVATED
         * account's screen does now carry one, because an administrator may type a
         * temporary password for somebody who cannot reach their mailbox. That is
         * the owner-approved exception to BR-31, it is bounded by a forced change at
         * next sign-in, and it is proven by
         * test_br31_a_temporary_password_forces_the_change_screen_before_anything_else.
         * Asserting "no password field anywhere" here would have gone on passing on
         * the accident that this fixture has never signed in, while claiming
         * something about the system that stopped being true.
         */
        $this->get(route('admin.users.index'))->assertOk()->assertDontSee('name="password"', false);
        $this->get(route('admin.users.show', $created))->assertOk()->assertDontSee('name="password"', false);

        // And no screen ever shows an existing password, activated or not — a hash
        // is one-way, so there is nothing to show.
        $this->get(route('admin.users.show', $created))->assertOk()
            ->assertDontSee('value="Correct-Horse-9"', false);
    }

    /**
     * BR-32 — "Deactivating a user blocks sign-in and revokes sessions but
     * preserves all attribution on their historical records."
     */
    public function test_br32_deactivation_preserves_attribution(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Departing Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $this->actingAs($agent);
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'delivered_at' => Wat::todayAt(6, 10),
        ], $agent);

        // A live session and a trusted device.
        $session = app(SessionRegistry::class)
            ->start($agent, request(), null);
        $device = Device::query()->create([
            'user_id' => $agent->id,
            'label' => 'Test device',
            'token_hash' => hash('sha256', 'token'),
            'trusted_until' => Wat::now()->addDays(30),
        ]);

        $admin = $this->makeUser('Deactivating Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->flushSession();
        $this->actingAs($admin);

        app(UserAdminService::class)->deactivate($agent, 'Left the company', $admin);

        $agent->refresh();

        $this->assertFalse($agent->isActive());
        $this->assertSame('Left the company', $agent->deactivated_reason);
        $this->assertNotNull($session->refresh()->ended_at, 'Sessions are revoked.');
        $this->assertNotNull($device->refresh()->revoked_at, 'Trusted devices are revoked.');

        // Attribution survives, and the row is not deleted.
        $this->assertDatabaseHas('users', ['id' => $agent->id, 'deleted_at' => null]);
        $this->assertSame($agent->id, (int) $delivery->refresh()->recorded_by_user_id);
        $this->assertSame('Departing Agent', $delivery->recordedBy->name);
    }

    /** BR-32 — and sign-in is refused afterwards. */
    public function test_br32_a_deactivated_user_cannot_sign_in(): void
    {
        $agent = $this->makeUser('Blocked Agent');
        $agent->forceFill([
            'status' => 'deactivated',
            'deactivated_at' => Wat::now(),
            'deactivated_reason' => 'Contract ended',
        ])->save();

        $this->post(route('login.attempt'), [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->assertDatabaseHas('failed_signins', [
            'email' => $agent->email,
            'reason' => 'deactivated',
        ]);
    }

    /** BR-33 — "Changing a password revokes all other sessions." */
    public function test_br33_changing_a_password_revokes_other_sessions(): void
    {
        $user = $this->makeUser('Rotating User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);
        $this->actingAs($user);

        // Two other live sessions on the register.
        $others = collect(range(1, 2))->map(fn () => AuthSession::query()->create([
            'user_id' => $user->id,
            'http_session_id' => 'other-'.uniqid(),
            'started_at' => Wat::now()->subHours(2),
            'last_seen_at' => Wat::now()->subMinutes(5),
        ]));

        $this->post(route('password.change.store'), [
            'current_password' => 'Correct-Horse-9',
            'password' => 'Sunrise-Kano-42',
            'password_confirmation' => 'Sunrise-Kano-42',
        ])->assertRedirect(route('profile'));

        foreach ($others as $session) {
            $this->assertNotNull($session->refresh()->ended_at, 'Every other session is ended.');
            $this->assertSame('password_change', $session->ended_reason);
        }

        $this->assertTrue(Hash::check('Sunrise-Kano-42', $user->refresh()->password_hash));
    }

    /** AUTH-5 — a password may not repeat the last three. */
    public function test_auth5_password_history_is_enforced(): void
    {
        $user = $this->makeUser('History User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);
        $this->actingAs($user);

        $policy = app(PasswordPolicy::class);

        $this->assertTrue($policy->isReused($user, 'Correct-Horse-9'), 'The current password counts.');

        $policy->apply($user, 'Second-Password-1');
        $policy->apply($user->refresh(), 'Third-Password-2');

        $user->refresh();

        // The last three are all refused.
        foreach (['Correct-Horse-9', 'Second-Password-1', 'Third-Password-2'] as $old) {
            $this->assertTrue($policy->isReused($user, $old), "{$old} must be refused.");
        }

        $this->assertFalse($policy->isReused($user, 'Fourth-Password-3'));

        // And the HTTP path refuses it too.
        $this->flushSession();
        $this->actingAs($user);

        $this->post(route('password.change.store'), [
            'current_password' => 'Third-Password-2',
            'password' => 'Second-Password-1',
            'password_confirmation' => 'Second-Password-1',
        ])->assertSessionHasErrors('password');
    }

    /** AUTH-5 — the policy's minimum length and character mix. */
    public function test_auth5_password_policy_rejects_weak_passwords(): void
    {
        $user = $this->makeUser('Weak Password User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);
        $this->actingAs($user);

        // Nine characters, so below the configured minimum of ten.
        $this->post(route('password.change.store'), [
            'current_password' => 'Correct-Horse-9',
            'password' => 'Abcdefg1',
            'password_confirmation' => 'Abcdefg1',
        ])->assertSessionHasErrors('password');

        // Long enough but no number.
        $this->post(route('password.change.store'), [
            'current_password' => 'Correct-Horse-9',
            'password' => 'Abcdefghijkl',
            'password_confirmation' => 'Abcdefghijkl',
        ])->assertSessionHasErrors('password');
    }

    /** AUTH-5 — a password past its maximum age forces a change. */
    public function test_auth5_expired_password_forces_a_change(): void
    {
        $user = $this->makeUser('Stale Password User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);

        $user->forceFill([
            'password_changed_at' => Wat::now()->subDays((int) config('gondal.auth.password_max_age_days') + 1),
        ])->save();

        $this->actingAs($user);

        $this->assertTrue($user->refresh()->passwordHasExpired());

        // Any other screen redirects to the change-password page.
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        // But the change-password page itself is reachable.
        $this->get(route('password.change'))->assertOk();
    }

    /**
     * BR-34 — "Every blocked access attempt writes an audit_entries row with the
     * missing permission."
     * AUDIT-5 — "Blocked access entries record the missing permission key and a
     * reference the user can quote."
     * SCR-1 — the 403 renders access-denied, populated.
     */
    public function test_br34_blocked_access_is_audited_and_the_page_is_populated(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Halima Yusuf');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        // §14 Phase 1 acceptance — refused payroll.
        $response = $this->get(route('payroll.index'));

        $response->assertStatus(403);
        $response->assertSee('You don&rsquo;t have access to this page', false);
        // SCR-1 — the missing permission, the role and the data scope, all real.
        $response->assertSee('hr.payroll.view');
        $response->assertSee('Milk Collection Officer');
        $response->assertSee('Kumbotso Center only');

        $entry = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_BLOCKED_ACCESS)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('hr.payroll.view', $entry->missing_permission);
        $this->assertSame('permission', $entry->deny_reason);
        $this->assertStringStartsWith('DENY-', $entry->reference);
        // The reference is on the page for the user to quote (AUDIT-5).
        $response->assertSee($entry->reference);
    }

    /**
     * SCOPE-3 — "Passing the permission check but failing the scope check produces
     * the same 403 and the same access-denied.html as a missing permission, and is
     * logged identically."
     */
    public function test_scope3_a_scope_failure_is_the_same_403_and_the_same_log(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Scoped Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        // The permission IS held — it is the record that is out of reach.
        $this->assertTrue($officer->hasPermission('milk.consignment.confirm.view'));

        $response = $this->get(route('collection-centers.show', $world['centerB']));

        $response->assertStatus(403);
        $response->assertSee('You don&rsquo;t have access to this page', false);
        // The wording differs from a missing permission: the page names the scope
        // rather than telling them to ask for the permission they already hold.
        $response->assertSee('Your Data Scope', false);
        $response->assertSee('Kumbotso Center only', false);
        $response->assertSee('Collection center → Dawakin Tofa', false);

        $entry = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_BLOCKED_ACCESS)
            ->latest('id')
            ->firstOrFail();

        // Logged identically, with only the reason distinguishing it.
        $this->assertSame('milk.consignment.confirm.view', $entry->missing_permission);
        $this->assertSame('scope', $entry->deny_reason);
        $this->assertStringStartsWith('DENY-', $entry->reference);

        // And their own center is still reachable.
        $this->get(route('collection-centers.show', $world['centerA']))->assertOk();
    }

    /**
     * SCOPE-2 — the global scope keeps a list from leaking, and the policy stops
     * a direct-ID read the list would have hidden.
     */
    public function test_scope2_both_layers_are_enforced(): void
    {
        $world = $this->makeMilkWorld();

        $agentA = $this->makeUser('Point A Agent');
        $this->assignRole($agentA, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $agentB = $this->makeUser('Point B Agent');
        $this->assignRole($agentB, 'Collection Agent', ScopeType::Point, $world['pointB']->id);

        // Each agent records at their own point.
        $this->actingAs($agentA);
        $deliveryA = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00', 'delivered_at' => Wat::todayAt(6, 0),
        ], $agentA);

        $this->flushSession();
        $this->actingAs($agentB);
        $deliveryB = app(DeliveryService::class)->record($world['pointB'], $world['farmerB'], [
            'litres_presented' => '30.00', 'delivered_at' => Wat::todayAt(6, 0),
        ], $agentB);

        // Layer 1 — the LIST is narrowed by the global scope.
        $this->assertSame(
            [$deliveryB->id],
            Delivery::query()->pluck('id')->all(),
            'Agent B sees only their own point.',
        );

        // Layer 2 — a direct-ID read of the other point's delivery is a 403.
        $this->get(route('deliveries.show', $deliveryA))->assertStatus(403);
        $this->get(route('deliveries.show', $deliveryB))->assertOk();
    }

    /**
     * SCOPE-4 — "Aggregates respect scope. A collection officer scoped to Kumbotso
     * who loads the dashboard sees Kumbotso's totals, not the network's — and only
     * if they hold milk.totals.network.view do they see network figures at all."
     */
    public function test_scope4_aggregates_respect_scope(): void
    {
        $world = $this->makeMilkWorld();

        // Volume at both centers.
        $this->asSystem(function () use ($world): void {
            foreach ([[$world['centerA'], $world['pointA'], '300.00'], [$world['centerB'], $world['pointB'], '900.00']] as [$center, $point, $litres]) {
                Consignment::query()->create([
                    'reference' => 'CNS-'.uniqid(),
                    'collection_point_id' => $point->id,
                    'collection_center_id' => $center->id,
                    'dispatched_at' => Wat::todayAt(7, 0),
                    'litres_dispatched' => $litres,
                    'confirmed_at' => Wat::todayAt(8, 0),
                    'litres_confirmed' => $litres,
                    'status' => Consignment::STATUS_CONFIRMED,
                ]);
            }
        });

        $officer = $this->makeUser('Kumbotso Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $metrics = app(DashboardMetrics::class)->for($officer->fresh());

        $this->assertSame('300.00', $metrics['milk']['litres_confirmed'], 'Their center only, not 1,200 L.');
        $this->assertFalse($metrics['sees_network_totals'], 'And no network flag without the permission.');

        // A supervisor with milk.totals.network.view and network scope sees both.
        $supervisor = $this->makeUser('Network Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Network);

        $this->flushSession();
        $this->actingAs($supervisor);

        $networkMetrics = app(DashboardMetrics::class)->for($supervisor->fresh());

        $this->assertSame('1200.00', $networkMetrics['milk']['litres_confirmed']);
        $this->assertTrue($networkMetrics['sees_network_totals']);
    }

    /**
     * BR-35 — "Test accounts are excluded from all reports, aggregates and
     * payroll."
     */
    public function test_br35_test_accounts_are_excluded_from_aggregates_and_payroll(): void
    {
        $world = $this->makeMilkWorld();

        $realAgent = $this->makeUser('Real Agent');
        $this->assignRole($realAgent, 'Collection Agent', ScopeType::Network);

        $testAgent = $this->makeUser('Test Agent', ['is_test' => true]);
        $this->assignRole($testAgent, 'Collection Agent', ScopeType::Network);

        $this->actingAs($realAgent);
        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '100.00', 'delivered_at' => Wat::todayAt(6, 0),
        ], $realAgent);

        $this->flushSession();
        $this->actingAs($testAgent);
        $testDelivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '500.00', 'delivered_at' => Wat::todayAt(6, 5),
        ], $testAgent);

        // TEST-4 — the test user's activity is tagged.
        $this->assertTrue($testDelivery->is_test);

        // The aggregate excludes it. The sums are normalised through Volume because
        // a raw driver sum comes back in whatever shape the engine likes; ARCH-6's
        // decimal(10,2) contract lives in the application layer.
        $litres = fn ($sum) => Volume::fromCentilitres((int) round(100 * (float) $sum));

        $this->assertSame(
            '100.00',
            $litres(Delivery::withoutDataScope()->excludingTestData()->sum('litres_accepted')),
        );
        $this->assertSame(
            '600.00',
            $litres(Delivery::withoutDataScope()->sum('litres_accepted')),
            'The row itself is not hidden — the test user must still see their own work.',
        );

        // Payroll excludes an employee whose account is a test account.
        $this->asSystem(function () use ($testAgent, $realAgent): void {
            foreach ([[$testAgent, 'TST-0001'], [$realAgent, 'REA-0001']] as [$user, $code]) {
                $employee = Employee::query()->create([
                    'code' => $code,
                    'name' => $user->name,
                    'gross_monthly_minor' => 300_000_00,
                    'status' => 'confirmed',
                ]);
                $user->forceFill(['employee_id' => $employee->id])->save();
            }
        });

        $accounts = $this->makeUser('Payroll Runner');
        $this->assignRole($accounts, 'Accounts');
        $this->flushSession();
        $this->actingAs($accounts);

        $run = app(PayrollService::class)->generate(2026, 7, $accounts);

        $names = Payslip::withoutDataScope()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->get()
            ->pluck('employee.name')
            ->all();

        $this->assertContains('Real Agent', $names);
        $this->assertNotContains('Test Agent', $names, 'A test account never receives a payslip.');
        $this->assertSame(1, (int) $run->employee_count);
    }

    /** TEST-4 — every audit entry written as a test user is tagged. */
    public function test_test4_test_user_activity_is_tagged_in_the_audit_log(): void
    {
        $world = $this->makeMilkWorld();

        $testAgent = $this->makeUser('Tagged Test Agent', ['is_test' => true]);
        $this->assignRole($testAgent, 'Collection Agent', ScopeType::Network);
        $this->actingAs($testAgent);

        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '10.00', 'delivered_at' => Wat::todayAt(6, 0),
        ], $testAgent);

        $entries = AuditEntry::query()->where('actor_user_id', $testAgent->id)->get();

        $this->assertTrue($entries->isNotEmpty());
        $this->assertTrue(
            $entries->every(fn (AuditEntry $entry) => (bool) $entry->is_test),
            'TEST-4 — every entry written as a test user is tagged.',
        );

        // A denial by a test user is tagged too.
        $this->get(route('payroll.index'))->assertStatus(403);

        $this->assertDatabaseHas('audit_entries', [
            'event_type' => AuditEntry::EVENT_BLOCKED_ACCESS,
            'actor_user_id' => $testAgent->id,
            'is_test' => true,
        ]);
    }

    /* ---------------------------------------------------------------------
     | §5 — the permission and role model
     * ------------------------------------------------------------------ */

    /** PERM-1 — permissions are rows; nothing in the code enumerates them. */
    public function test_perm1_permissions_are_rows(): void
    {
        // A real catalogue, not a handful of examples. The exact figure moves
        // whenever the catalogue is reshaped, so the assertion is on the order of
        // magnitude rather than on a number that has to be edited to stay green.
        $this->assertGreaterThan(50, Permission::query()->live()->count());

        // A brand new permission is honoured the moment it exists and is granted.
        $permission = Permission::query()->create([
            'resource_key' => 'brand.new',
            'action' => 'view',
            'label' => 'A permission that did not exist when the code was written',
        ]);

        $role = Role::query()->create([
            'name' => 'Brand New Role',
            'scope_type' => ScopeType::Network->value,
            'status' => Role::STATUS_ACTIVE,
        ]);
        $role->permissions()->attach($permission->id);

        $user = $this->makeUser('Brand New User');
        $this->assignRole($user, 'Brand New Role');

        $this->assertTrue($user->fresh()->hasPermission('brand.new.view'));
        $this->assertTrue(app(Access::class)->allows($user->fresh(), 'brand.new.view'));
    }

    /** PERM-3 — a retired permission is never granted and never deleted. */
    public function test_perm3_retired_permissions_cannot_be_granted(): void
    {
        $admin = $this->makeUser('Retirement Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        /*
         * PERM-3 — retired, never deleted. Two retirements have happened: the 11
         * Project-module permissions, and the 20 operational `delete` actions
         * withdrawn because nothing hard-deletes those records. Both sets of rows
         * must still be there, or the audit entries naming them stop resolving.
         */
        $retired = Permission::query()->retired()->get();

        $this->assertGreaterThanOrEqual(31, $retired->count(),
            'PERM-3 — a retired permission is kept, so its history still resolves.');

        $this->assertTrue(
            $retired->contains(fn (Permission $p) => str_starts_with($p->resource_key, 'project.')),
            'The Project-module retirement is still on the record.',
        );

        $this->assertTrue(
            $retired->contains(fn (Permission $p) => $p->resource_key === 'shop.sales' && $p->action === 'delete'),
            'The withdrawn operational deletes are on the record.',
        );

        // ...and none of them is granted to a role that is still in use.
        $this->assertSame(0, \DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->whereNotNull('permissions.retired_at')
            ->where('roles.status', '!=', Role::STATUS_RETIRED)
            ->count(), 'PERM-3 — a live role never holds a retired permission.');

        $role = Role::query()->where('name', 'Farm Manager')->firstOrFail();

        try {
            app(RoleAdminService::class)->syncPermissions($role, [$retired->first()->id], $admin);
            $this->fail('A retired permission must not be grantable.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('PERM-3', $exception->ruleId);
        }

        // Retired permissions are also absent from the live matrix.
        $this->assertFalse(
            Permission::matrix()->flatten()->contains(fn ($p) => $p->isRetired()),
        );
    }

    /** PERM-2 — granting a sensitive permission warns. */
    public function test_perm2_granting_a_sensitive_permission_warns(): void
    {
        $admin = $this->makeUser('Sensitive Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $role = Role::query()->where('name', 'Collection Agent')->firstOrFail();
        $holder = $this->makeUser('Sensitive Holder');
        $this->assignRole($holder, 'Collection Agent', ScopeType::Network);

        $payroll = Permission::query()
            ->where('resource_key', 'hr.payroll')->where('action', 'view')->firstOrFail();

        $this->assertTrue((bool) $payroll->is_sensitive);

        $result = app(RoleAdminService::class)->syncPermissions(
            $role,
            $role->permissions->pluck('id')->push($payroll->id)->all(),
            $admin,
        );

        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('Sensitive access granted', $result['warning']);
        $this->assertStringContainsString('hr.payroll', $result['warning']);
        $this->assertSame(1, $role->refresh()->sensitivePermissionCount());
    }

    /**
     * ROLE-2 — "A user may hold multiple roles. Effective permissions are the
     * union. There is no deny rule — absence of a grant is the denial."
     */
    public function test_role2_effective_permissions_are_the_union(): void
    {
        $user = $this->makeUser('Two Hat User');
        $this->assignRole($user, 'Collection Agent', ScopeType::Network);

        $agentOnly = $user->fresh()->effectivePermissionKeys();
        $this->assertNotContains('shop.sales.create', $agentOnly);

        $this->assignRole($user, 'Sales Officer', ScopeType::Own);

        $both = $user->fresh()->effectivePermissionKeys();

        $this->assertContains('milk.deliveries.create', $both);
        $this->assertContains('shop.sales.create', $both);
        $this->assertGreaterThan(count($agentOnly), count($both));

        // No deny rule exists: there is no way to subtract a grant except by
        // removing it, which is what the union means.
        $this->assertSame(
            count($both),
            count(array_unique($both)),
            'The union is a set — no duplicates, no negatives.',
        );
    }

    /** ROLE-3 — every user automatically holds Staff (self-service). */
    public function test_role3_every_user_holds_the_automatic_role(): void
    {
        $user = $this->makeUser('Plain Staff Member');

        // Not a single role assigned by hand.
        $this->assertSame(0, $user->roleAssignments()->count());

        $permissions = $user->fresh()->effectivePermissionKeys();

        $this->assertContains('hr.leave.own.view', $permissions);
        $this->assertContains('hr.leave.own.create', $permissions);
        $this->assertContains('hr.payslip.own.view', $permissions);

        // And nothing else.
        $this->assertCount(3, $permissions);

        // The self-service screen is therefore reachable by anyone.
        $this->actingAs($user->fresh());
        $this->get(route('leave.index'))->assertOk();
        $this->get(route('payroll.index'))->assertStatus(403);
    }

    /** ROLE-5 — a draft role has no permissions and cannot be assigned (§15.2). */
    public function test_role5_a_draft_role_cannot_be_assigned(): void
    {
        $farmManager = Role::query()->where('name', 'Farm Manager')->firstOrFail();

        $this->assertSame(Role::STATUS_DRAFT, $farmManager->status);
        $this->assertSame(0, $farmManager->permissions()->count());

        $admin = $this->makeUser('Draft Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $target = $this->makeUser('Would-be Farm Manager');

        try {
            app(UserAdminService::class)->assignRole(
                $target, $farmManager, ScopeType::Network, null, [], $admin,
            );

            $this->fail('A draft role must not reach staff.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ROLE-5', $exception->ruleId);
        }
    }

    /** ROLE-4 — a retired role cannot be assigned but is kept for the trail. */
    public function test_role4_retired_roles_are_kept_but_unassignable(): void
    {
        $retired = Role::query()->retired()->get();

        $this->assertCount(2, $retired, 'Administrator and HR / IT Admin.');
        $this->assertEqualsCanonicalizing(
            ['Administrator', 'HR / IT Admin'],
            $retired->pluck('name')->all(),
        );

        $admin = $this->makeUser('Retired Role Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        try {
            app(UserAdminService::class)->assignRole(
                $this->makeUser('Nope'), $retired->first(), ScopeType::Network, null, [], $admin,
            );

            $this->fail('A retired role must not be assignable.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ROLE-4', $exception->ruleId);
        }
    }

    /** ROLE-6 — a role edit takes effect on the next request, with no re-login. */
    public function test_role6_a_role_change_applies_on_the_next_request(): void
    {
        $admin = $this->makeUser('Live Edit Admin');
        $this->assignRole($admin, 'System Administrator');

        $officer = $this->makeUser('Live Edit Officer');
        $this->assignRole($officer, 'Inventory Officer', ScopeType::Network);

        $this->actingAs($officer);
        $this->get(route('shop.sales.index'))->assertStatus(403);

        // The administrator grants shop.sales.view to the role.
        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();
        $salesView = Permission::query()
            ->where('resource_key', 'shop.sales')->where('action', 'view')->firstOrFail();

        $this->asSystem(fn () => app(RoleAdminService::class)->syncPermissions(
            $role,
            $role->permissions->pluck('id')->push($salesView->id)->all(),
            $admin,
        ));

        // Same session, next request: it works. No re-login.
        $this->get(route('shop.sales.index'))->assertOk();
    }

    /** ROLE-7 — a role with users can only be disabled, never deleted. */
    public function test_role7_a_role_with_users_cannot_be_deleted(): void
    {
        $role = Role::query()->where('name', 'Collection Agent')->firstOrFail();
        $holder = $this->makeUser('Role Holder');
        $this->assignRole($holder, 'Collection Agent', ScopeType::Network);

        try {
            $role->guardAgainstDeletion();
            $this->fail('A role with assigned users must not be deletable.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ROLE-7', $exception->ruleId);
        }

        $admin = $this->makeUser('Disabling Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        app(RoleAdminService::class)->disable($role, $admin);

        $this->assertSame(Role::STATUS_DISABLED, $role->refresh()->status);
        // Disabled, not deleted, and the assignment survives.
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $holder->id]);
    }

    /**
     * SCOPE-1 — "a targeted scope with no target can never match a record."
     * It fails closed rather than widening to the network.
     */
    public function test_scope1_a_targeted_scope_with_no_target_denies(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Targetless Officer');
        // Center scope, no target — a misconfiguration.
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, null);
        $this->actingAs($officer);

        $this->assertTrue($officer->fresh()->hasPermission('milk.consignment.confirm.view'));

        // Nothing is visible, and a direct read is refused.
        $this->assertSame(0, CollectionCenter::query()->count());
        $this->get(route('collection-centers.show', $world['centerA']))->assertStatus(403);
    }

    /** SCOPE-1 — the `communities` scope type carries a LIST of targets. */
    public function test_scope1_communities_scope_carries_a_list(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Community Agent');
        $this->assignRole(
            $agent,
            'Extension Agent',
            ScopeType::Communities,
            null,
            [$world['communityA']->id],
        );

        $this->actingAs($agent->fresh());

        // Farmers in the assigned community are visible; others are not.
        $visible = Farmer::query()->pluck('name')->all();

        $this->assertContains('Zainab Idris', $visible);
        $this->assertNotContains('Amina Bello', $visible);

        $scopes = $agent->fresh()->scopeSetFor('community.farmers.view');
        $this->assertSame([$world['communityA']->id], $scopes->targetIdsFor(ScopeType::Communities));
    }

    /** SCR-2 — navigation is rendered from effective permissions. */
    public function test_scr2_navigation_is_permission_filtered(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Nav Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $navigation = Navigation::forUser($agent->fresh());
        $labels = collect($navigation)->pluck('label')->all();

        $this->assertContains('Milk Collection', $labels);
        $this->assertNotContains('One-Stop Shop', $labels, 'No shop.inventory.view, so no nav item at all.');
        $this->assertNotContains('Administration', $labels, 'A group with no visible children is omitted.');

        // The rendered page agrees.
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('shop.inventory'));
        $response->assertDontSee(route('admin.users.index'));
        $response->assertSee(route('deliveries.index'));
    }

    /**
     * DM-3 / AUDIT-6 — the audit log is append-only. No route, no model path and
     * no database path can change or remove an entry.
     */
    public function test_dm3_the_audit_log_is_append_only(): void
    {
        $user = $this->makeUser('Audited User');
        $this->assignRole($user, 'System Administrator');
        $this->actingAs($user);

        $entry = app(AuditLogger::class)->signin($user, 'Signed in for the test');

        // The model refuses.
        try {
            $entry->update(['summary' => 'Tampered']);
            $this->fail('An audit entry must not be updatable.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-3', $exception->ruleId);
        }

        try {
            $entry->delete();
            $this->fail('An audit entry must not be deletable.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-3', $exception->ruleId);
        }

        // And so does the database, even bypassing Eloquent entirely.
        try {
            \Illuminate\Support\Facades\DB::table('audit_entries')
                ->where('id', $entry->id)
                ->update(['summary' => 'Tampered at the database']);

            $this->fail('The database must refuse an update to audit_entries.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame('Signed in for the test', $entry->refresh()->summary);
    }

    /**
     * AUDIT-3 — "Permission and role changes record the number of affected users
     * and the before/after grant sets."
     */
    public function test_audit3_permission_changes_record_before_after_and_affected_users(): void
    {
        $admin = $this->makeUser('Audit3 Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        foreach (range(1, 3) as $n) {
            $this->assignRole($this->makeUser('Inventory Holder '.$n), 'Inventory Officer', ScopeType::Network);
        }

        $before = $role->permissions->pluck('id')->all();
        $extra = Permission::query()
            ->where('resource_key', 'shop.sales')->where('action', 'view')->firstOrFail();

        app(RoleAdminService::class)->syncPermissions($role, array_merge($before, [$extra->id]), $admin);

        $entry = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_PERMISSION_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(3, $entry->detail['affected_users']);
        $this->assertContains('shop.sales.view', $entry->detail['granted']);
        $this->assertNotContains('shop.sales.view', $entry->detail['before']);
        $this->assertContains('shop.sales.view', $entry->detail['after']);
        $this->assertSame([], $entry->detail['revoked']);
    }

    /** AUDIT-6 — the log needs admin.audit.view, and nothing else opens it. */
    public function test_audit6_the_log_is_readable_only_with_the_permission(): void
    {
        $agent = $this->makeUser('Curious Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Network);
        $this->actingAs($agent);

        $this->get(route('admin.audit-log'))->assertStatus(403);

        $auditor = $this->makeUser('Umar Muduru');
        $this->assignRole($auditor, 'Internal Audit');

        $this->flushSession();
        $this->actingAs($auditor);

        $this->get(route('admin.audit-log'))->assertOk();
    }

    /**
     * ROLE-1 — "A role is a name, description, data-scope type, status, and a set
     * of granted permissions."
     *
     * Asserted on the shape of the model rather than on a seeded row, because the
     * point of the rule is that a role carries no other authority: no hardcoded
     * privilege, no implicit scope, nothing but the five things listed.
     */
    public function test_role1_a_role_is_exactly_the_five_things_listed(): void
    {
        $admin = $this->makeUser('Role Shape Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $view = Permission::query()->where('resource_key', 'milk.deliveries')->where('action', 'view')->firstOrFail();

        $role = app(RoleAdminService::class)->create([
            'name' => 'Night Shift Recorder',
            'description' => 'Records deliveries on the early shift only.',
            'scope_type' => ScopeType::Point->value,
            'status' => Role::STATUS_ACTIVE,
        ], $admin->fresh());

        app(RoleAdminService::class)->syncPermissions($role, [$view->id], $admin->fresh());

        $this->assertSame('Night Shift Recorder', $role->name);
        $this->assertSame('Records deliveries on the early shift only.', $role->description);
        $this->assertSame(ScopeType::Point->value, $role->scope_type);
        // A new role is a draft until someone activates it, because a role with no
        // grants that looks live is a trap for whoever assigns it (ROLE-5).
        $this->assertSame(Role::STATUS_DRAFT, $role->status);

        app(RoleAdminService::class)->update($role, ['status' => Role::STATUS_ACTIVE], $admin->fresh());

        $this->assertSame(Role::STATUS_ACTIVE, $role->refresh()->status);
        $this->assertSame(['milk.deliveries.view'], $role->livePermissions()->get()
            ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action)
            ->all());

        /*
         * And that is the whole of it: a freshly created role grants exactly what was
         * passed, so nothing about a role's NAME confers authority. A role called
         * "System Administrator" with no permissions can do nothing.
         */
        $impostor = app(RoleAdminService::class)->create([
            'name' => 'Systems Administrator (Deputy)',
            'description' => 'Sounds powerful, grants nothing.',
            'scope_type' => ScopeType::Network->value,
            'status' => Role::STATUS_ACTIVE,
        ], $admin->fresh());

        $nobody = $this->makeUser('Sounds Important');
        $impostor->forceFill(['status' => Role::STATUS_ACTIVE])->save();
        $nobody->roles()->attach($impostor->id, [
            'scope_type' => ScopeType::Network->value,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => Wat::now(),
        ]);

        $this->assertFalse($nobody->fresh()->hasPermission('admin.users.edit'));
        $this->assertFalse($nobody->fresh()->hasPermission('admin.roles.edit'));

        // A role's status is what decides whether it grants anything at all (ROLE-4).
        app(RoleAdminService::class)->update($role, ['status' => Role::STATUS_DISABLED], $admin->fresh());

        $holder = $this->makeUser('Shift Recorder');
        $holder->roles()->attach($role->id, [
            'scope_type' => ScopeType::Network->value,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => Wat::now(),
        ]);

        $this->assertFalse($holder->fresh()->hasPermission('milk.deliveries.view'));
    }

    /**
     * USER-1 — "Farmers, cooperative officials, riders, drivers and vendors are
     * records, not accounts. They have no credentials and cannot sign in. Staff
     * record on their behalf."
     */
    public function test_user1_the_people_the_business_serves_are_records_not_accounts(): void
    {
        $world = $this->makeMilkWorld();

        // None of these tables can hold a credential: there is no column to hold one.
        foreach ([
            'farmers' => Farmer::class,
            'drivers' => Driver::class,
            'cooperatives' => Cooperative::class,
            'extension_agents' => ExtensionAgent::class,
        ] as $table => $model) {
            foreach (['password', 'password_hash', 'remember_token', 'signin_code_hash'] as $credential) {
                $this->assertFalse(
                    Schema::hasColumn($table, $credential),
                    sprintf('USER-1 — %s must not be able to hold a %s.', $table, $credential),
                );
            }

            // And none is Authenticatable, so no guard could sign one in.
            $this->assertNotInstanceOf(
                Authenticatable::class,
                new $model,
                'USER-1 — '.$model.' must not be an authenticatable identity.',
            );
        }

        /*
         * A farmer's record is created BY staff, and the record says who. That is the
         * "staff record on their behalf" half of the rule, and it is why every farmer
         * row carries an enrolling officer.
         */
        $officer = $this->makeUser('Enrolling Officer');
        $this->assignRole(
            $officer,
            'Community Engagement Officer',
            ScopeType::Communities,
            null,
            [$world['communityA']->id],
        );
        $this->actingAs($officer->fresh());

        $this->post(route('farmers.store'), [
            'code' => 'FRM-9001',
            'name' => 'Hauwa Ibrahim',
            'community_id' => $world['communityA']->id,
        ])->assertRedirect();

        $enrolled = Farmer::withoutDataScope()->where('code', 'FRM-9001')->firstOrFail();

        $this->assertSame($officer->id, $enrolled->enrolled_by_user_id);
        $this->assertSame('Enrolling Officer', $enrolled->enrolledBy->name);

        /*
         * Nor is there any guard a farmer could authenticate against.
         *
         * There is more than one guard now — ARCH-2's `api` token guard carries
         * the mobile client — so the assertion is not "there is exactly one".
         * It is the thing USER-1 actually forbids: every guard, whatever its
         * driver, resolves identities out of the STAFF table and no other. A
         * guard pointed at `farmers` is what this test exists to catch, and it
         * would still fail here.
         */
        $providers = array_map(
            fn (array $provider) => $provider['model'] ?? null,
            config('auth.providers'),
        );

        $this->assertSame([User::class], array_values(array_filter($providers)));

        foreach (config('auth.guards') as $name => $guard) {
            $this->assertSame(
                'users',
                $guard['provider'] ?? null,
                sprintf('USER-1 — the %s guard must authenticate staff, and only staff.', $name),
            );
        }
    }

    /**
     * SCOPE-2 / ROLE-3 — a model reachable through two permission resources must
     * union both, or the self-service holder is denied entirely.
     *
     * This is a regression test for a live defect: an ordinary member of staff
     * could file a leave request and then not see it. The global scope resolved
     * from `hr.leave.view` alone, which they do not hold; the empty scope set then
     * failed closed, exactly as ROLE-2 requires. The rule was right and the
     * resource key was wrong.
     */
    public function test_self_service_records_are_visible_to_the_staff_member_who_owns_them(): void
    {
        $department = $this->asSystem(fn () => Department::query()->create([
            'name' => 'Milk Collection', 'status' => 'active',
        ]));

        $staff = $this->makeUser('Ordinary Staff', ['department_id' => $department->id]);
        $employee = $this->asSystem(fn () => Employee::query()->create([
            'code' => 'EMP-9001',
            'name' => $staff->name,
            'department_id' => $department->id,
            'gross_monthly_minor' => 250_000_00,
            'status' => 'active',
            'hired_on' => Wat::today()->subYear()->toDateString(),
        ]));
        $staff->forceFill(['employee_id' => $employee->id])->save();

        // They hold ONLY the automatic self-service role — no hr.leave.view.
        $this->assertFalse($staff->fresh()->hasPermission('hr.leave.view'));
        $this->assertTrue($staff->fresh()->hasPermission('hr.leave.own.view'));

        $this->actingAs($staff->fresh());

        $type = $this->asSystem(fn () => LeaveType::query()->orderBy('position')->firstOrFail());

        $own = app(LeaveService::class)->create($employee, [
            'leave_type_id' => $type->id,
            'starts_on' => Wat::today()->addDays(30)->toDateString(),
            'ends_on' => Wat::today()->addDays(31)->toDateString(),
            'reason' => 'Family matters.',
        ], $staff->fresh());

        // The list they are sent to must contain the request they just filed.
        $visible = LeaveRequest::query()->pluck('id')->all();

        $this->assertContains(
            $own->id,
            $visible,
            'A member of staff must be able to see their own leave request.',
        );

        // The row is on the screen. The list shows the employee, type and dates —
        // the reason itself is only on the form, so assert on what is rendered.
        $this->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Ordinary Staff')
            ->assertSee($type->name);

        // And still only their own: another employee's request stays hidden.
        $other = $this->makeUser('Someone Else', ['department_id' => $department->id]);
        $otherEmployee = $this->asSystem(fn () => Employee::query()->create([
            'code' => 'EMP-9002',
            'name' => $other->name,
            'department_id' => $department->id,
            'gross_monthly_minor' => 250_000_00,
            'status' => 'active',
            'hired_on' => Wat::today()->subYear()->toDateString(),
        ]));
        $other->forceFill(['employee_id' => $otherEmployee->id])->save();

        $this->flushSession();
        $this->actingAs($other->fresh());

        $theirs = app(LeaveService::class)->create($otherEmployee, [
            'leave_type_id' => $type->id,
            'starts_on' => Wat::today()->addDays(40)->toDateString(),
            'ends_on' => Wat::today()->addDays(41)->toDateString(),
            'reason' => 'Not visible to the first employee.',
        ], $other->fresh());

        $this->flushSession();
        $this->actingAs($staff->fresh());

        $visibleNow = LeaveRequest::query()->pluck('id')->all();

        $this->assertContains($own->id, $visibleNow);
        $this->assertNotContains(
            $theirs->id,
            $visibleNow,
            'Self-service means own records only, not everyone\'s.',
        );
    }

    /** The same union governs payslips, the other self-service resource. */
    public function test_a_staff_member_can_reach_their_own_payslip_and_no_one_else(): void
    {
        $this->assertSame(
            ['hr.leave', 'hr.leave.own'],
            (new LeaveRequest)->scopeResourceKeys(),
        );

        $this->assertSame(
            ['hr.payroll', 'hr.payslip.own'],
            (new Payslip)->scopeResourceKeys(),
        );

        // Every other scopeable model keeps exactly one route in, so this stays a
        // deliberate exception rather than a habit.
        foreach ([
            Delivery::class,
            Consignment::class,
            Batch::class,
            Farmer::class,
        ] as $class) {
            $model = new $class;
            $this->assertSame([$model->scopeResourceKey()], $model->scopeResourceKeys(), $class);
        }
    }

    /**
     * SCOPE-1 — a scope may name several targets, and every one of them counts.
     *
     * A supervisor covering two centres is ordinary. Before this, the only way to
     * express it was to assign the same role twice, which made revoking one centre
     * look identical to revoking both.
     */
    public function test_scope1_a_two_center_scope_reaches_both_centers(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Two Center Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, null, [
            $world['centerA']->id,
            $world['centerB']->id,
        ]);
        $this->actingAs($officer);

        $scopes = $officer->scopeSetFor('milk.consignment.confirm.view');

        $this->assertFalse($scopes->isNetwork(), 'Two centres is not the whole network.');
        $this->assertEqualsCanonicalizing(
            [$world['centerA']->id, $world['centerB']->id],
            $scopes->targetIdsFor(ScopeType::Center),
        );

        // Both centres reachable; a third would not be.
        $visible = CollectionCenter::query()->pluck('id')->all();

        $this->assertContains($world['centerA']->id, $visible);
        $this->assertContains($world['centerB']->id, $visible);
    }

    /**
     * SCOPE-1 — an assignment written the old way means exactly what it always did.
     *
     * The single target still lives in `role_user.scope_target_id`; the multi-target
     * work reads a union of that column and the child table, so a row written before
     * any of this existed must still resolve to one centre and no more.
     */
    public function test_scope1_a_single_target_assignment_is_unchanged(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('One Center Officer');
        $assignment = $this->assignRole(
            $officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id
        );
        $this->actingAs($officer);

        // Still the plain column, with no child rows — nothing was migrated.
        $this->assertSame((int) $world['centerA']->id, (int) $assignment->scope_target_id);
        $this->assertSame(0, $assignment->scopeTargets()->count());

        $this->assertSame(
            [$world['centerA']->id],
            $officer->scopeSetFor('milk.consignment.confirm.view')->targetIdsFor(ScopeType::Center),
        );

        $visible = CollectionCenter::query()->pluck('id')->all();

        $this->assertContains($world['centerA']->id, $visible);
        $this->assertNotContains($world['centerB']->id, $visible);
    }

    /**
     * SCOPE-1 — dropping one centre from a two-centre scope really drops it.
     *
     * The child rows are replaced wholesale on every write. If they were merged
     * instead, a revoked centre would stay reachable and the screen would still
     * say the assignment covers it.
     */
    public function test_scope1_narrowing_a_multi_target_scope_revokes_the_dropped_target(): void
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Scope Admin');
        $this->assignRole($admin, 'System Administrator');

        $officer = $this->makeUser('Narrowing Officer');
        $role = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();

        app(UserAdminService::class)->assignRole(
            $officer, $role, ScopeType::Center, null,
            [$world['centerA']->id, $world['centerB']->id], $admin,
        );

        $officer->forgetAccessMemo();
        $this->assertCount(2, $officer->scopeSetFor('milk.consignment.confirm.view')->targetIdsFor(ScopeType::Center));

        // Now cover centre A only.
        app(UserAdminService::class)->assignRole(
            $officer, $role, ScopeType::Center, null, [$world['centerA']->id], $admin,
        );

        $officer->forgetAccessMemo();

        $this->assertSame(
            [$world['centerA']->id],
            $officer->scopeSetFor('milk.consignment.confirm.view')->targetIdsFor(ScopeType::Center),
        );

        $this->actingAs($officer);
        $this->assertNotContains($world['centerB']->id, CollectionCenter::query()->pluck('id')->all());
    }

    /**
     * SCOPE-1 — a targeted scope with no target at all is refused, whichever way
     * the caller tried to spell it. Saving it would produce an assignment that
     * denies everything while appearing on the screen as a grant.
     */
    public function test_scope1_a_targeted_scope_with_no_targets_is_refused(): void
    {
        $admin = $this->makeUser('Empty Scope Admin');
        $this->assignRole($admin, 'System Administrator');

        $officer = $this->makeUser('Targetless Officer');
        $role = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();

        $this->expectException(RuleViolationException::class);

        app(UserAdminService::class)->assignRole(
            $officer, $role, ScopeType::Center, null, [], $admin,
        );
    }

    /**
     * ROLE-2 — one person must not both assign a grade and be able to change one.
     *
     * The re-grade break (BR-4) puts a second pair of eyes on a change that moves
     * money. Holding the clerk role and the quality role together hands both
     * pairs of eyes to the same person, and the control is gone without anybody
     * editing a permission.
     */
    /**
     * Monitoring & Evaluation writes the SCHEDULE and never the record.
     *
     * The role was originally read-only in every grant, and this test asserted
     * exactly that. Revalidation gave it work to direct — assigning a check is
     * a write — so the blunt property is gone and the one that mattered is
     * stated instead: M&E may not touch the DATA being evaluated. They say who
     * should be checked and accept what came back; a field worker records what
     * they found. An evaluator who could edit a farmer's herd size directly
     * would be evaluating their own entries.
     *
     * It also pins what the role must never see — payroll, the employee
     * register, members' money — so widening it later (docs/OPEN-DECISIONS.md
     * §5) is a decision somebody makes rather than a line that slips in.
     */
    public function test_monitoring_and_evaluation_schedules_checks_but_never_records_them(): void
    {
        $evaluator = $this->makeUser('Programme Evaluator');
        $this->assignRole($evaluator, 'Monitoring & Evaluation');
        $evaluator = $evaluator->fresh();

        // The one place M&E writes: the revalidation queue.
        $this->assertTrue($evaluator->hasPermission('community.validation.create'), 'M&E assigns the checks.');
        $this->assertTrue($evaluator->hasPermission('community.validation.approve'), 'M&E accepts what came back.');

        /*
         * Every OTHER write is refused. Asserted by walking the whole grant set
         * rather than by listing suspects, so a future widening of this role has
         * to come here and say so out loud.
         */
        foreach ($evaluator->effectivePermissionKeys() as $key) {
            if (str_contains($key, '.own.') || str_starts_with($key, 'community.validation.')) {
                continue;   // ROLE-3's automatic grants, and the queue itself.
            }

            $this->assertStringEndsWith('.view', $key,
                "Monitoring & Evaluation must not write outside the validation queue; it holds {$key}.");
        }

        // Specifically: they cannot close their own assignment from a desk.
        $this->assertFalse($evaluator->hasPermission('community.farmers.validate'),
            'M&E must not be able to carry out a check they scheduled.');
        $this->assertFalse($evaluator->hasPermission('community.farmers.edit'));

        // The job: targets, actuals, and the quality story behind them.
        $this->assertTrue($evaluator->hasPermission('community.extension.view'));
        $this->assertTrue($evaluator->hasPermission('community.farmers.view'));
        $this->assertTrue($evaluator->hasPermission('milk.rejection.view'));
        $this->assertTrue($evaluator->hasPermission('milk.totals.network.view'));

        // The boundary. Payroll and the employee register are not an evaluator's
        // business, and neither is members' money.
        foreach ([
            'hr.payroll.view',
            'hr.employees.view',
            'community.coop.savings.view',
            'shop.revenue.view',
            'admin.audit.view',
        ] as $withheld) {
            $this->assertFalse($evaluator->hasPermission($withheld),
                "Monitoring & Evaluation must not hold {$withheld} — see docs/OPEN-DECISIONS.md §5.");
        }
    }

    public function test_role2_a_clerk_cannot_also_be_the_quality_officer(): void
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Pairing Admin');
        $this->assignRole($admin, 'System Administrator');

        $user = $this->makeUser('Doubly Graded');
        $this->assignRole($user, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);

        $quality = Role::query()->where('name', 'Quality Officer')->firstOrFail();

        try {
            app(UserAdminService::class)->assignRole(
                $user->fresh(), $quality, ScopeType::Center, $world['centerA']->id, [], $admin,
            );
            $this->fail('Holding both the grading and the re-grading role must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ROLE-2', $exception->ruleId);
            $this->assertStringContainsString('assign a grade and then change it', $exception->getMessage());
        }
    }

    /** ROLE-2 — the pair is refused whichever way round it is granted. */
    public function test_role2_the_incompatible_pair_is_symmetric(): void
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Symmetry Admin');
        $this->assignRole($admin, 'System Administrator');

        $user = $this->makeUser('Quality First');
        $this->assignRole($user, 'Quality Officer', ScopeType::Center, $world['centerA']->id);

        $clerk = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();

        $this->expectException(RuleViolationException::class);

        app(UserAdminService::class)->assignRole(
            $user->fresh(), $clerk, ScopeType::Center, $world['centerA']->id, [], $admin,
        );
    }

    /** ROLE-2 — a recorder must not also reconcile and release what they recorded. */
    public function test_role2_a_recorder_cannot_also_be_the_supervisor(): void
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Duty Admin');
        $this->assignRole($admin, 'System Administrator');

        $user = $this->makeUser('Self Approver');
        $this->assignRole($user, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $supervisor = Role::query()->where('name', 'Milk Collection Supervisor')->firstOrFail();

        try {
            app(UserAdminService::class)->assignRole(
                $user->fresh(), $supervisor, ScopeType::Network, null, [], $admin,
            );
            $this->fail('Recording and releasing must not sit with one person.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ROLE-2', $exception->ruleId);
        }
    }

    /** ROLE-2 — an unrelated pair is still perfectly allowed; this is not a blanket ban. */
    public function test_role2_unrelated_roles_may_still_be_combined(): void
    {
        $admin = $this->makeUser('Permissive Admin');
        $this->assignRole($admin, 'System Administrator');

        $user = $this->makeUser('Wearer Of Hats');
        $this->assignRole($user, 'Sales Officer', ScopeType::Own);

        $inventory = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        $assignment = app(UserAdminService::class)->assignRole(
            $user->fresh(), $inventory, ScopeType::Network, null, [], $admin,
        );

        $this->assertNotNull($assignment->getKey());
    }

    /**
     * BR-34 — an administrator granting themselves an operational role is
     * announced to Internal Audit and the General Manager.
     *
     * It is not forbidden — a small organisation has one administrator and they
     * may have to cover a role. It is the change least likely to be questioned by
     * anybody else, so it is surfaced rather than left to be found later.
     */
    public function test_br34_self_assignment_is_announced_to_audit_and_the_gm(): void
    {
        $auditor = $this->makeUser('Watching Auditor');
        $this->assignRole($auditor, 'Internal Audit');

        $gm = $this->makeUser('Watching GM');
        $this->assignRole($gm, 'General Manager');

        $admin = $this->makeUser('Self Serving Admin');
        $this->assignRole($admin, 'System Administrator');

        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        app(UserAdminService::class)->assignRole(
            $admin->fresh(), $role, ScopeType::Network, null, [], $admin->fresh(),
        );

        $entry = AuditEntry::query()
            ->where('summary', 'like', '%TO THEMSELVES%')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A self-assignment is recorded as one.');
        $this->assertStringContainsString('Inventory Officer', $entry->summary);

        foreach ([$auditor, $gm] as $watcher) {
            $this->assertTrue(
                AppNotification::query()->where('user_id', $watcher->getKey())
                    ->where('title', 'like', '%granted themselves%')->exists(),
                $watcher->name.' should have been told.',
            );
        }
    }

    /** BR-34 — granting somebody ELSE a role is ordinary and is not announced. */
    public function test_br34_an_ordinary_grant_is_not_announced_as_self_assignment(): void
    {
        $auditor = $this->makeUser('Quiet Auditor');
        $this->assignRole($auditor, 'Internal Audit');

        $admin = $this->makeUser('Ordinary Admin');
        $this->assignRole($admin, 'System Administrator');

        $other = $this->makeUser('Somebody Else');
        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        app(UserAdminService::class)->assignRole($other, $role, ScopeType::Network, null, [], $admin);

        $this->assertFalse(
            AuditEntry::query()->where('summary', 'like', '%TO THEMSELVES%')->exists(),
        );
    }

    /**
     * AUTH-8 — an administrator cannot become a colleague by moving their e-mail
     * address and then re-sending the activation code.
     *
     * Two ordinary edits, both gated on admin.users.edit alone, chained into a
     * full account takeover: change the address, press "resend activation", the
     * code arrives in the administrator's mailbox, redeem it through BR-31's
     * flow, choose a password. HR / IT Admin holds admin.users => '*', so the
     * reachable accounts included Internal Audit — the role that exists to
     * review what HR / IT Admin does.
     */
    public function test_auth8_an_email_change_plus_resend_activation_cannot_take_over_an_account(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Ambitious IT Admin');
        $this->assignRole($admin, 'System Administrator');

        $director = $this->makeUser('Executive Director Account');
        $this->assignRole($director, 'Executive Director');

        $service = app(UserAdminService::class);
        $originalEmail = $director->email;

        // Half one is allowed: fixing a typo in somebody's address is real work.
        $service->update($director, [
            'name' => $director->name,
            'email' => 'ambitious.admin@gondalfulbe.ng',
        ], $admin);

        $director = $director->fresh();
        $this->assertSame('ambitious.admin@gondalfulbe.ng', $director->email);
        $this->assertSame($admin->getKey(), $director->email_changed_by_user_id);

        // Half two is not.
        try {
            $service->sendActivation($director, $admin);

            $this->fail('An activation code must not be deliverable to an address the actor chose.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('AUTH-8', $exception->ruleId);
        }

        // Nothing was issued — the refusal happens before the code is written.
        $this->assertFalse(
            LoginCode::query()->where('user_id', $director->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)->usable()->exists(),
        );

        // The old address was told, the log says SECURITY rather than "details
        // updated", and the watchers were notified.
        Notification::assertSentOnDemand(
            EmailAddressChangedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $originalEmail,
        );

        $this->assertTrue(
            AuditEntry::query()->where('summary', 'like', 'SECURITY — sign-in address%')->exists(),
            'The one change that redirects every future credential must be greppable.',
        );
    }

    /**
     * AUTH-8 — the same two edits on an account nobody has ever signed into are
     * ordinary administration, and are allowed.
     *
     * The rule refuses a takeover, not the job. There is nothing to take over on
     * an account that has never been used, and correcting a typo in a new hire's
     * address and re-sending their invitation is the only reason the button
     * exists — users/show.blade.php renders it exactly while
     * `password_changed_at` is null.
     */
    public function test_auth8_resending_an_activation_to_a_new_hire_is_still_ordinary_work(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Ordinary IT Admin');
        $this->assignRole($admin, 'System Administrator');

        // BR-31 — an account created by an administrator has no password until
        // the user chooses one, which is what "pending activation" means.
        $newHire = $this->makeUser('Mistyped New Hire');
        $newHire->forceFill(['password_changed_at' => null])->save();

        $service = app(UserAdminService::class);

        $service->update($newHire, [
            'name' => $newHire->name,
            'email' => 'corrected.new.hire@gondalfulbe.ng',
        ], $admin);

        $service->sendActivation($newHire->fresh(), $admin);

        $this->assertTrue(
            LoginCode::query()->where('user_id', $newHire->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)->usable()->exists(),
        );
    }

    /**
     * BR-31 — "Creation AND RESET both send a code." Only creation did.
     *
     * The administration screen offered "resend activation", and only while
     * `password_changed_at` was null — that is, only to somebody who had never
     * signed in. From a user's second day onwards an administrator had no lever on
     * their password at all. A collection agent who has forgotten theirs at 05:30
     * with milk in the churn left two options and both were wrong:
     * deactivate-then-reactivate stops them working and revokes every trusted
     * device, and "sign out everywhere" ends the sessions while leaving the
     * password working, so whoever knows it signs straight back in.
     *
     * What this proves is the shape of the fix as much as its existence: the
     * administrator takes the old password OUT of use and never supplies a new
     * one. Nothing they can see, type or read from a log opens the account.
     */
    public function test_br31_an_administrator_can_reset_any_users_password_without_ever_setting_one(): void
    {
        Notification::fake();

        $auditor = $this->makeUser('Reset Watching Auditor');
        $this->assignRole($auditor, 'Internal Audit');

        $admin = $this->makeUser('Resetting IT Admin');
        $this->assignRole($admin, 'System Administrator');

        // An ESTABLISHED account: signed in, password chosen by them, months ago.
        // This is the case the screen could not reach.
        $agent = $this->makeUser('Forgetful Collection Agent');
        $agent->forceFill(['password_changed_at' => Wat::now()->subDays(40)])->save();
        $chosenAt = $agent->password_changed_at;

        $session = AuthSession::query()->create([
            'user_id' => $agent->getKey(),
            'http_session_id' => 'a-browser-at-the-centre',
            'started_at' => Wat::now()->subHours(2),
            'last_seen_at' => Wat::now()->subMinutes(5),
        ]);

        $token = ApiToken::query()->create([
            'user_id' => $agent->getKey(),
            'name' => 'Android phone',
            'token_hash' => hash('sha256', 'whatever'),
            'expires_at' => Wat::now()->addDays(30),
        ]);

        $ended = app(UserAdminService::class)->resetPassword(
            $agent, 'forgot password, called IT from Yola centre', $admin,
        );

        $agent = $agent->fresh();

        // The password they knew is gone, and nothing knowable replaced it.
        $this->assertFalse(
            Hash::check('Correct-Horse-9', (string) $agent->password_hash),
            'The password the user knew must stop working immediately.',
        );

        foreach (['Correct-Horse-9', 'password', 'forgot password, called IT from Yola centre', ''] as $guess) {
            $this->assertFalse(
                Hash::check($guess, (string) $agent->password_hash),
                'BR-31 — no value anybody has seen may open the account, including the reason typed by the admin.',
            );
        }

        $this->assertTrue($agent->awaitingPasswordReset());
        $this->assertSame($admin->getKey(), $agent->password_reset_by_user_id);
        $this->assertSame('forgot password, called IT from Yola centre', $agent->password_reset_reason);

        /*
         * AUTH-8's guard reads `password_changed_at` to mean "this account has
         * never been used". A forced reset must not touch it, or an administrator
         * could clear the flag protecting the account and then walk through the
         * door it was holding shut (change e-mail → reset → send activation).
         */
        $this->assertNotNull($agent->password_changed_at);
        $this->assertTrue($chosenAt->equalTo($agent->password_changed_at));

        // AUTH-4 — the reset revokes all sessions, and the phones are sessions.
        $this->assertSame(1, $ended['sessions']);
        $this->assertSame(1, $ended['tokens']);
        $this->assertSame('admin_password_reset', $session->refresh()->ended_reason);
        $this->assertNotNull($token->refresh()->revoked_at);

        // A redeemable code went to the ACCOUNT HOLDER, not to the administrator.
        $this->assertTrue(
            LoginCode::query()->where('user_id', $agent->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)->usable()->exists(),
        );

        Notification::assertSentTo(
            $agent,
            PasswordResetByAdminNotification::class,
        );

        // Not the welcome message. "Your account is ready" landing on an
        // eight-month employee reads as a mistake, or as phishing.
        Notification::assertNotSentTo($agent, AccountCreatedNotification::class);

        $this->assertTrue(
            AuditEntry::query()->where('summary', 'like', 'SECURITY — password reset%')
                ->where('actor_user_id', $admin->getKey())->exists(),
            'Ending somebody else\'s credential must be greppable, not a routine data_edit.',
        );

        // BR-34's watchers — the same people told about a self-granted role.
        $this->assertDatabaseHas('notifications', ['user_id' => $auditor->getKey()]);
    }

    /**
     * BR-31 — and the user really can get back in with a password of their own
     * choosing, through AUTH-4's ordinary flow.
     *
     * The half that is easy to get wrong: the emailed code is only redeemable if
     * something seeds the reset session for that user, which is what the signed
     * link in the email is for. Issue the code without it and the administrator
     * sees "reset done", the user gets a code, and the verify screen refuses it —
     * a control that reports success and achieves nothing.
     */
    public function test_br31_the_user_redeems_the_reset_code_and_chooses_their_own_password(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Round Trip Admin');
        $this->assignRole($admin, 'System Administrator');

        $agent = $this->makeUser('Recovering Agent');

        app(UserAdminService::class)->resetPassword($agent, 'forgot password', $admin);

        // The signed link the email carries, rebuilt exactly as the service does.
        $minutes = (int) config('gondal.auth.activation_code_ttl_minutes', 4320);
        $this->get(URL::temporarySignedRoute(
            'activate', Wat::now()->addMinutes($minutes), ['user' => $agent->getKey()],
        ))->assertRedirect(route('password.verify'));

        $this->post(route('password.verify.store'), ['code' => $this->resetCodeFor($agent)])
            ->assertRedirect(route('password.reset.form'));

        $this->post(route('password.reset.store'), [
            'password' => 'Their-Own-Choice-7',
            'password_confirmation' => 'Their-Own-Choice-7',
        ])->assertRedirect(route('login'));

        $agent = $agent->fresh();

        $this->assertTrue(
            Hash::check('Their-Own-Choice-7', (string) $agent->password_hash),
            'The password the USER chose is the one in force.',
        );

        // The screen must stop saying it is waiting, or the badge becomes
        // permanent furniture and stops meaning anything.
        $this->assertFalse($agent->awaitingPasswordReset());
        $this->assertNull($agent->password_reset_by_user_id);
        $this->assertNull($agent->password_reset_reason);
        $this->assertNotNull($agent->password_changed_at);
    }

    /**
     * AUTH-5 — a forced reset is not a way to launder an old password back into
     * the allowed set.
     *
     * The reset displaces the current hash, so unless it is filed in history first
     * the user's answer to "choose a new password" could be the very password an
     * administrator had just decided should stop working.
     */
    public function test_auth5_a_forced_reset_still_refuses_the_password_it_replaced(): void
    {
        Notification::fake();

        $admin = $this->makeUser('History Keeping Admin');
        $this->assignRole($admin, 'System Administrator');

        $user = $this->makeUser('Reused Password User');

        app(UserAdminService::class)->resetPassword($user, 'suspected shared password', $admin);

        $this->assertTrue(
            app(PasswordPolicy::class)->isReused($user->fresh(), 'Correct-Horse-9'),
            'The password taken out of use must still be refused as a replacement.',
        );
    }

    /**
     * AUTH-8 — the takeover has a second spelling now, and it is refused too.
     *
     * "Change their e-mail, then resend activation" is blocked on an activated
     * account. Adding a reset that reaches ANY account would have reopened exactly
     * that chain with one word changed — and on established accounts, which is the
     * only case where there is somebody to impersonate.
     */
    public function test_auth8_an_email_change_plus_a_password_reset_cannot_take_over_an_account(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Ambitious Resetting Admin');
        $this->assignRole($admin, 'System Administrator');

        $director = $this->makeUser('Reset Target Director');
        $this->assignRole($director, 'Executive Director');

        $service = app(UserAdminService::class);

        // Half one is allowed: fixing a typo in somebody's address is real work.
        $service->update($director, [
            'name' => $director->name,
            'email' => 'ambitious.resetter@gondalfulbe.ng',
        ], $admin);

        try {
            $service->resetPassword($director->fresh(), 'they asked me to', $admin);

            $this->fail('A reset code must not be deliverable to an address the actor chose.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('AUTH-8', $exception->ruleId);
        }

        // The refusal happens BEFORE anything is done: no code, and — the part
        // that would matter most — the director's password still works, so the
        // refused attempt is not a way to lock them out either.
        $this->assertFalse(
            LoginCode::query()->where('user_id', $director->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)->usable()->exists(),
        );
        $this->assertTrue(Hash::check('Correct-Horse-9', (string) $director->fresh()->password_hash));
        $this->assertFalse($director->fresh()->awaitingPasswordReset());
    }

    /**
     * BR-31 / BR-32 — the two resets that would achieve nothing but damage.
     *
     * Your own, because the only account holding '*' clearing its own credential
     * and then depending on the mail queue is a self-inflicted lockout with no
     * second administrator to undo it; the profile screen and "Forgot password?"
     * both exist. And a deactivated account, because BR-32 blocks its sign-in
     * anyway, so the code would be turned away at the door while the screen
     * reported a successful reset — reactivation is the operation that was meant.
     */
    public function test_br31_a_reset_is_refused_for_yourself_and_for_a_deactivated_account(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Sole Administrator');
        $this->assignRole($admin, 'System Administrator');

        $service = app(UserAdminService::class);

        try {
            $service->resetPassword($admin, 'locked myself out', $admin);

            $this->fail('An administrator must not reset their own password from this screen.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-31', $exception->ruleId);
        }

        $this->assertTrue(
            Hash::check('Correct-Horse-9', (string) $admin->fresh()->password_hash),
            'The refusal must leave the administrator able to sign in.',
        );

        $leaver = $this->makeUser('Departed Colleague');
        $service->deactivate($leaver, 'left the cooperative', $admin);

        try {
            $service->resetPassword($leaver->fresh(), 'tidying up', $admin);

            $this->fail('A reset code for a deactivated account would be refused at sign-in.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-32', $exception->ruleId);
        }
    }

    /**
     * BR-31, qualified — an administrator may TYPE a password, and it is temporary.
     *
     * The owner-approved exception. The emailed code assumes the user can reach
     * their mailbox, and a collection agent at a centre at 05:30 with no data
     * cannot; a code they will read tonight is not help. So this exists, and what
     * this test pins down is that the exception ENDS: the administrator's knowledge
     * of the password is good for one sign-in, after which they know nothing.
     */
    public function test_br31_an_administrator_can_type_a_temporary_password_the_user_must_replace(): void
    {
        Notification::fake();

        $auditor = $this->makeUser('Temp Password Auditor');
        $this->assignRole($auditor, 'Internal Audit');

        $admin = $this->makeUser('Password Typing Admin');
        $this->assignRole($admin, 'System Administrator');

        $agent = $this->makeUser('Stranded Collection Agent');

        $session = AuthSession::query()->create([
            'user_id' => $agent->getKey(),
            'http_session_id' => 'a-browser-somewhere',
            'started_at' => Wat::now()->subHours(2),
            'last_seen_at' => Wat::now()->subMinutes(5),
        ]);

        $token = ApiToken::query()->create([
            'user_id' => $agent->getKey(),
            'name' => 'Android phone',
            'token_hash' => hash('sha256', 'whatever'),
            'expires_at' => Wat::now()->addDays(30),
        ]);

        $ended = app(UserAdminService::class)->setPassword(
            $agent, 'Temp-Horse-42', 'no mobile data at Yola centre', $admin,
        );

        $agent = $agent->fresh();

        // The password the administrator typed works — that is the whole point.
        $this->assertTrue(Hash::check('Temp-Horse-42', (string) $agent->password_hash));
        // And the one it replaced does not.
        $this->assertFalse(Hash::check('Correct-Horse-9', (string) $agent->password_hash));

        /*
         * The bound on the exception. passwordHasExpired() is what
         * EnsureAccountIsUsable reads to force the change screen, and it must be
         * true even though the password was set seconds ago — AUTH-5's 90-day
         * clock would otherwise say this password is as fresh as they come.
         */
        $this->assertTrue($agent->passwordIsTemporary());
        $this->assertTrue($agent->passwordHasExpired());

        // It is a real password, not the unknowable placeholder the code path
        // writes; the distinction is what keeps it out of AUTH-5's blind spot.
        $this->assertFalse($agent->passwordIsUnknowable());
        $this->assertTrue($agent->awaitingPasswordReset());
        $this->assertSame($admin->getKey(), $agent->password_reset_by_user_id);

        // AUTH-4 — sessions and phones go, which is also what forces the user
        // through the change screen rather than carrying on in a live tab.
        $this->assertSame(1, $ended['sessions']);
        $this->assertSame(1, $ended['tokens']);
        $this->assertSame('admin_set_password', $session->refresh()->ended_reason);
        $this->assertNotNull($token->refresh()->revoked_at);

        // No code, either: one would let the user round the change screen.
        $this->assertFalse(
            LoginCode::query()->where('user_id', $agent->getKey())
                ->forPurpose(LoginCode::PURPOSE_RESET)->usable()->exists(),
        );

        /*
         * The user is told, and the password is NOT in what they are told. The
         * administrator says it down the phone; mailing it would put a live
         * credential in an inbox, which is the exposure the code flow exists to
         * avoid and would be absurd to reintroduce here.
         */
        Notification::assertSentTo(
            $agent,
            TemporaryPasswordSetNotification::class,
            function ($notification) use ($agent) {
                $mail = $notification->toMail($agent);
                $body = $mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

                return ! str_contains($body, 'Temp-Horse-42')
                    && str_contains($body, 'Password Typing Admin');
            },
        );

        $this->assertTrue(
            AuditEntry::query()->where('summary', 'like', 'SECURITY — temporary password SET BY%')
                ->where('actor_user_id', $admin->getKey())->exists(),
            'The only action where somebody else knows a working credential must be greppable.',
        );

        // The password itself must not have leaked into the audit detail.
        $this->assertFalse(
            AuditEntry::query()->where('detail', 'like', '%Temp-Horse-42%')->exists(),
            'NFR-9 — a password must never reach the audit log.',
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $auditor->getKey()]);

        /*
         * The screen has to render both new states and say which one it is. An
         * administrator who cannot tell "I know this password" from "a code is on
         * its way" has no way to know whether they still owe somebody a phone call.
         */
        $this->actingAs($admin)->get(route('admin.users.show', $agent))->assertOk()
            ->assertSee('Temporary password set', false)
            ->assertSee('Password Typing Admin', false)
            ->assertSee('no mobile data at Yola centre', false);

        // An account waiting on an emailed code says the other thing. Asserted on
        // the banner rather than on the words "temporary password", which also
        // appear in the set-a-password modal on every activated account's screen.
        $other = $this->makeUser('Emailed Code Colleague');
        app(UserAdminService::class)->resetPassword($other, 'forgot password', $admin);

        $this->actingAs($admin)->get(route('admin.users.show', $other->fresh()))->assertOk()
            ->assertSee('awaiting their new one', false)
            ->assertDontSee('Temporary password set', false);
    }

    /**
     * BR-31, qualified — the forced change is what bounds the exception, so it has
     * to actually happen on the next request rather than being a badge on a screen.
     *
     * Without this, "temporary" is a word in a modal. The administrator's password
     * would keep working for 90 days, the user would never be asked to replace it,
     * and the feature would be indistinguishable from handing out a permanent
     * credential.
     */
    public function test_br31_a_temporary_password_forces_the_change_screen_before_anything_else(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Handover Admin');
        $this->assignRole($admin, 'System Administrator');

        // AUTH-1's second factor off, so the sign-in is one step and this test is
        // about the redirect rather than about emailed codes.
        $agent = $this->makeUser('Returning Agent', ['two_factor_enabled' => false]);

        app(UserAdminService::class)->setPassword($agent, 'Temp-Horse-42', 'forgot password', $admin);

        $this->post(route('login.attempt'), [
            'email' => $agent->email,
            'password' => 'Temp-Horse-42',
        ]);

        $this->assertAuthenticatedAs($agent->fresh());

        // Anything they try to reach lands on the change screen instead.
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));

        // AUTH-5 — and they cannot simply keep the password they were given.
        $this->post(route('password.change.store'), [
            'current_password' => 'Temp-Horse-42',
            'password' => 'Temp-Horse-42',
            'password_confirmation' => 'Temp-Horse-42',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($agent->fresh()->passwordIsTemporary(), 'A rejected change must not clear the flag.');

        $this->post(route('password.change.store'), [
            'current_password' => 'Temp-Horse-42',
            'password' => 'My-Own-Choice-7',
            'password_confirmation' => 'My-Own-Choice-7',
        ])->assertRedirect(route('profile'));

        $agent = $agent->fresh();

        $this->assertTrue(Hash::check('My-Own-Choice-7', (string) $agent->password_hash));

        // The exception is over: the administrator knows nothing that works.
        $this->assertFalse($agent->passwordIsTemporary());
        $this->assertFalse($agent->awaitingPasswordReset());
        $this->assertNull($agent->password_reset_by_user_id);
        $this->assertFalse($agent->passwordHasExpired());

        // And the way out is now open.
        $this->get(route('dashboard'))->assertOk();
    }

    /**
     * BR-31 / BR-32 — the two accounts an administrator must not type a password
     * for.
     *
     * Your own, because the profile screen's change flow asks for your current
     * password first and that check is the only thing between a borrowed unlocked
     * laptop and a permanently stolen account. And a deactivated one, because BR-32
     * refuses the sign-in anyway, so the screen would report success while nothing
     * had been achieved.
     */
    public function test_br31_typing_a_password_is_refused_for_yourself_and_for_a_deactivated_account(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Self Serving Admin');
        $this->assignRole($admin, 'System Administrator');

        $service = app(UserAdminService::class);

        try {
            $service->setPassword($admin, 'Temp-Horse-42', 'convenience', $admin);

            $this->fail('An administrator must not set their own password without proving the current one.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-31', $exception->ruleId);
        }

        $this->assertTrue(Hash::check('Correct-Horse-9', (string) $admin->fresh()->password_hash));

        $leaver = $this->makeUser('Deactivated Colleague For Password');
        $service->deactivate($leaver, 'left the cooperative', $admin);

        try {
            $service->setPassword($leaver->fresh(), 'Temp-Horse-42', 'tidying up', $admin);

            $this->fail('A password cannot help an account whose sign-in is blocked.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-32', $exception->ruleId);
        }
    }

    /**
     * BR-31, qualified — an administrator's typed password is held to AUTH-5.
     *
     * The route validates with the same PasswordPolicy::rules() the user's own
     * screen uses. Without that, "Password1" on somebody else's account would be a
     * live weak credential that the account holder never chose and cannot be blamed
     * for, and the policy would apply to everyone except the one password a second
     * person knows.
     */
    public function test_auth5_an_administrators_typed_password_must_meet_the_policy(): void
    {
        Notification::fake();

        $admin = $this->makeUser('Lazy Password Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $agent = $this->makeUser('Weak Password Victim');

        $this->post(route('admin.users.set-password', $agent), [
            'password' => 'abc',
            'password_confirmation' => 'abc',
            'reason' => 'in a hurry',
        ])->assertSessionHasErrors('password');

        // Nothing was changed by the refused attempt.
        $agent = $agent->fresh();
        $this->assertTrue(Hash::check('Correct-Horse-9', (string) $agent->password_hash));
        $this->assertFalse($agent->passwordIsTemporary());

        // A confirmation that does not match is refused too — a typo here would
        // mean nobody knows the password, including the administrator.
        $this->post(route('admin.users.set-password', $agent), [
            'password' => 'Temp-Horse-42',
            'password_confirmation' => 'Temp-Horse-43',
            'reason' => 'in a hurry',
        ])->assertSessionHasErrors('password');

        $this->assertFalse($agent->fresh()->passwordIsTemporary());
    }

    /**
     * The plaintext behind the newest reset code.
     *
     * NFR-9 keeps it out of the database and out of every log, so a test that has
     * to redeem one recovers it the only way anybody could: by hashing candidates
     * until one matches.
     */
    private function resetCodeFor(User $user): string
    {
        $hash = LoginCode::query()
            ->where('user_id', $user->getKey())
            ->forPurpose(LoginCode::PURPOSE_RESET)
            ->latest('id')
            ->value('code_hash');

        $length = (int) config('gondal.auth.code_length', 6);

        for ($candidate = 0; $candidate < 10 ** $length; $candidate++) {
            $code = str_pad((string) $candidate, $length, '0', STR_PAD_LEFT);

            if (hash('sha256', $code) === $hash) {
                return $code;
            }
        }

        $this->fail('No code matched the stored hash.');
    }

    /**
     * AUTH-1 — a user may not switch off their own second factor, and an
     * administrator who switches off somebody else's is announced.
     *
     * ProfileController::update() deliberately refuses to let a user do this to
     * themselves; PUT /admin/users/{user} handed the same capability back on the
     * quietest possible path, logged as a routine data_edit next to a phone
     * number.
     */
    public function test_auth1_disabling_someone_elses_second_factor_is_announced(): void
    {
        $auditor = $this->makeUser('Watching Auditor');
        $this->assignRole($auditor, 'Internal Audit');

        $admin = $this->makeUser('Second Factor Admin');
        $this->assignRole($admin, 'System Administrator');

        $director = $this->makeUser('Two Factor Director');
        $this->assignRole($director, 'Executive Director');

        app(UserAdminService::class)->update($director, [
            'name' => $director->name,
            'email' => $director->email,
            'two_factor_enabled' => false,
        ], $admin);

        $this->assertFalse((bool) $director->fresh()->two_factor_enabled);

        $this->assertTrue(
            AuditEntry::query()->where('summary', 'like', 'SECURITY — two-factor sign-in disabled%')->exists(),
        );

        $this->assertTrue(
            AppNotification::query()->where('user_id', $auditor->getKey())
                ->where('title', 'like', '%second factor%')->exists(),
            'Internal Audit should have been told.',
        );
    }

    /**
     * SCOPE-1 — taking a role off somebody and putting it back must work.
     *
     * The soft delete leaves the row, and therefore its unique key, in role_user.
     * Before the partial index and the withTrashed() lookup, the second grant
     * INSERTed and the database refused it — the most ordinary administrative
     * correction there is was an unhandled 500, and the workaround an
     * administrator finds for that is a second account.
     */
    public function test_scope1_a_revoked_role_can_be_granted_again(): void
    {
        $admin = $this->makeUser('Regrant Admin');
        $this->assignRole($admin, 'System Administrator');

        $officer = $this->makeUser('Reinstated Officer');
        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        $service = app(UserAdminService::class);

        $first = $service->assignRole($officer, $role, ScopeType::Network, null, [], $admin);
        $service->removeRole($first, $admin);

        $officer->forgetAccessMemo();
        $this->assertFalse($officer->fresh()->hasPermission('shop.inventory.view'), 'The revoke must really revoke.');

        $second = $service->assignRole($officer, $role, ScopeType::Network, null, [], $admin);

        // The SAME row came back, so the audit entries naming the first grant
        // still resolve to a live assignment.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertNull($second->fresh()->deleted_at);

        $this->assertSame(
            1,
            RoleAssignment::withTrashed()
                ->where('user_id', $officer->getKey())
                ->where('role_id', $role->getKey())
                ->count(),
            'Exactly one row, revived rather than duplicated.',
        );

        $officer->forgetAccessMemo();
        $this->assertTrue($officer->fresh()->hasPermission('shop.inventory.view'));
    }
}
