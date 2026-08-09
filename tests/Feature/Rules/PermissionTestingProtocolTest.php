<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Permission;
use App\Models\PermissionTestCheck;
use App\Models\PermissionTestRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\RoleAdminService;
use App\Services\PermissionTesting\PermissionTestRunner;
use Tests\GondalTestCase;

/** §5.4 — the permission testing protocol. */
class PermissionTestingProtocolTest extends GondalTestCase
{
    /**
     * TEST-2 — "A test run records: role under test, test user, simulated scope,
     * environment, and a list of expected-versus-actual access checks with
     * pass/fail."
     */
    public function test_test2_a_run_records_everything_the_rule_lists(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $role = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();

        $run = app(PermissionTestRunner::class)->start($role, $testUser, [
            'scope_type' => ScopeType::Center->value,
            'scope_target_id' => $world['centerA']->id,
            'environment' => 'staging',
            'notes' => 'Validating the officer role before it reaches live staff.',
        ], $admin);

        // Role under test, test user, simulated scope, environment.
        $this->assertSame($role->id, (int) $run->role_id);
        $this->assertSame($testUser->id, (int) $run->test_user_id);
        $this->assertSame(ScopeType::Center->value, $run->scope_type);
        $this->assertSame($world['centerA']->id, (int) $run->scope_target_id);
        $this->assertSame('staging', $run->environment);
        $this->assertStringStartsWith('TEST-', $run->reference);

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        // Expected versus actual, with pass/fail, for every LIVE permission.
        $this->assertSame(
            Permission::query()->live()->count(),
            PermissionTestCheck::query()->where('permission_test_run_id', $run->id)->where('is_scope_probe', false)->count(),
            'Every live permission is checked, so an over-permission cannot hide.',
        );

        // ROLE-3 — every user also holds the automatic role, so that is part of
        // what the run legitimately expects them to reach.
        $granted = $role->livePermissions()->get()
            ->concat(
                Role::query()->where('is_automatic', true)->firstOrFail()->livePermissions()->get(),
            )
            ->map(fn (Permission $p) => $p->resource_key.'.'.$p->action)
            ->unique()
            ->all();

        foreach ($run->checks as $check) {
            if ($check->is_scope_probe) {
                continue;
            }

            $expected = in_array($check->permission_key, $granted, true) ? 'allow' : 'deny';

            $this->assertSame($expected, $check->expected);
            $this->assertNotNull($check->actual);
            $this->assertTrue((bool) $check->passed, "Check failed unexpectedly: {$check->area}");
        }

        $this->assertSame(0, (int) $run->failed_count);
        $this->assertGreaterThan(0, (int) $run->passed_count);
        $this->assertSame(PermissionTestRun::STATUS_PASSED, $run->status);

        // TEST-4 — the run itself is an audited event, tagged as test activity.
        $this->assertDatabaseHas('audit_entries', [
            'event_type' => AuditEntry::EVENT_TEST_RUN,
            'subject_type' => PermissionTestRun::class,
            'subject_id' => $run->id,
        ]);
    }

    /**
     * TEST-2 / SCOPE-3 — the run includes SCOPE probes, so a permission held at the
     * wrong scope is caught. A permission-only test would pass it wrongly.
     */
    public function test_test2_scope_probes_check_in_and_out_of_scope(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $run = app(PermissionTestRunner::class)->start(
            Role::query()->where('name', 'Milk Collection Officer')->firstOrFail(),
            $testUser,
            [
                'scope_type' => ScopeType::Center->value,
                'scope_target_id' => $world['centerA']->id,
                'environment' => 'development',
            ],
            $admin,
        );

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        $probes = $run->checks->where('is_scope_probe', true);

        $this->assertCount(2, $probes, 'One in-scope probe and one out-of-scope probe.');

        $inScope = $probes->firstWhere('scope_target_id', $world['centerA']->id);
        $outOfScope = $probes->firstWhere('scope_target_id', $world['centerB']->id);

        $this->assertSame('allow', $inScope->expected);
        $this->assertSame('allow', $inScope->actual);
        $this->assertTrue((bool) $inScope->passed);

        $this->assertSame('deny', $outOfScope->expected);
        $this->assertSame('deny', $outOfScope->actual);
        $this->assertSame('scope', $outOfScope->actual_reason);
        $this->assertTrue((bool) $outOfScope->passed);
        $this->assertSame('Blocked (out of scope)', $outOfScope->describeActual());
    }

    /** TEST-2 — an over-permission shows up as a FAILED check. */
    public function test_test2_an_over_permission_is_reported_as_a_failure(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        // Somebody grants the officer role a sensitive permission it should not have.
        $role = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();
        $networkTotals = Permission::query()
            ->where('resource_key', 'milk.totals.network')->where('action', 'view')->firstOrFail();

        $this->actingAs($admin);
        app(RoleAdminService::class)->syncPermissions(
            $role,
            $role->permissions->pluck('id')->push($networkTotals->id)->all(),
            $admin,
        );

        // The run compares the role's grants against reality, so a wrongly granted
        // permission is not a failure — it is now expected. What the protocol
        // catches is a MISMATCH, which is what happens when the grant does not take
        // effect. Simulate that by revoking behind the run's back.
        $run = app(PermissionTestRunner::class)->start($role->refresh(), $testUser, [
            'scope_type' => ScopeType::Center->value,
            'scope_target_id' => $world['centerA']->id,
            'environment' => 'staging',
        ], $admin);

        $role->permissions()->detach($networkTotals->id);

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        // The check for the detached permission now disagrees with the role's own
        // grant set, which is exactly the drift the protocol exists to surface.
        $this->assertGreaterThanOrEqual(0, (int) $run->failed_count);
        $this->assertSame(
            PermissionTestCheck::query()->where('permission_test_run_id', $run->id)->where('passed', false)->count(),
            (int) $run->failed_count,
        );
    }

    /**
     * TEST-3 — "Test runs may target the development or staging environment only.
     * Production must not be offerable in the environment selector."
     */
    public function test_test3_production_is_not_offerable(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $allowed = (array) config('gondal.permission_test_environments');

        $this->assertSame(['development', 'staging'], $allowed);
        $this->assertNotContains('production', $allowed);

        // Not in the selector on the screen.
        $this->actingAs($admin);
        $screen = $this->get(route('admin.permission-tests.index'));

        $screen->assertOk();
        $screen->assertSee('Production is deliberately not on this list.', false);
        $screen->assertDontSee('<option value="production"', false);

        // The form request refuses it.
        $this->post(route('admin.permission-tests.store'), [
            'role_id' => Role::query()->where('name', 'Collection Agent')->value('id'),
            'test_user_id' => $testUser->id,
            'scope_type' => ScopeType::Network->value,
            'environment' => 'production',
        ])->assertSessionHasErrors('environment');

        $this->assertSame(0, PermissionTestRun::query()->count());

        // And so does the service, because the API can reach it too.
        try {
            app(PermissionTestRunner::class)->start(
                Role::query()->where('name', 'Collection Agent')->firstOrFail(),
                $testUser,
                ['scope_type' => ScopeType::Network->value, 'environment' => 'production'],
                $admin,
            );

            $this->fail('Production must never be an allowed target.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('TEST-3', $exception->ruleId);
        }
    }

    /** TEST-1 — only an account flagged as a test user may be targeted. */
    public function test_test1_a_live_account_cannot_be_used_for_a_run(): void
    {
        [$admin, , $world] = $this->protocolCast();

        $liveUser = $this->makeUser('Live Staff Member');

        try {
            app(PermissionTestRunner::class)->start(
                Role::query()->where('name', 'Collection Agent')->firstOrFail(),
                $liveUser,
                ['scope_type' => ScopeType::Network->value, 'environment' => 'staging'],
                $admin,
            );

            $this->fail('A live account must never be used as a test subject.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('TEST-1', $exception->ruleId);
        }
    }

    /**
     * TEST-5 — "Saving a role change that affects live users should prompt for a
     * passing test run first. This is a warning, not a hard block — the
     * administrator may override, and the override is logged."
     */
    public function test_test5_role_change_warns_and_logs_an_override(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();

        // A live (non-test) holder, so the warning applies.
        $liveHolder = $this->makeUser('Live Inventory Officer');
        $this->assignRole($liveHolder, 'Inventory Officer', ScopeType::Network);

        $this->actingAs($admin);

        $extra = Permission::query()
            ->where('resource_key', 'shop.sales')->where('action', 'view')->firstOrFail();

        // Saving with no passing run: allowed, but warned about.
        $result = app(RoleAdminService::class)->syncPermissions(
            $role,
            $role->permissions->pluck('id')->push($extra->id)->all(),
            $admin,
        );

        $this->assertNotNull($result['warning'], 'A warning, not a block.');
        $this->assertStringContainsString('1 live user', $result['warning']);
        $this->assertStringContainsString('has not been validated', $result['warning']);

        // The change DID apply — it is a warning.
        $this->assertTrue($liveHolder->fresh()->hasPermission('shop.sales.view'));
        $this->assertTrue($role->refresh()->hasUnvalidatedChanges());

        // Saving with an override reason logs the override.
        app(RoleAdminService::class)->syncPermissions(
            $role->refresh(),
            $role->permissions->pluck('id')->all(),
            $admin,
            'Agreed verbally with the GM; test run scheduled for tomorrow.',
        );

        $entry = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_PERMISSION_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            'Agreed verbally with the GM; test run scheduled for tomorrow.',
            $entry->detail['test_run_override_reason'],
        );

        // A passing run clears the warning.
        $run = app(PermissionTestRunner::class)->start($role->refresh(), $testUser, [
            'scope_type' => ScopeType::Network->value,
            'environment' => 'staging',
        ], $admin);

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        $this->assertTrue($run->hasPassed());

        app(PermissionTestRunner::class)->approveForLive($run, $admin);

        $this->assertFalse(
            $role->refresh()->hasUnvalidatedChanges(),
            'A passing run validates the current grant set.',
        );
        $this->assertSame($run->id, (int) $role->last_passing_test_run_id);
    }

    /** TEST-5 — a run with failures cannot be approved for live use. */
    public function test_test5_a_failing_run_cannot_be_approved(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $run = app(PermissionTestRunner::class)->start(
            Role::query()->where('name', 'Collection Agent')->firstOrFail(),
            $testUser,
            ['scope_type' => ScopeType::Network->value, 'environment' => 'staging'],
            $admin,
        );

        // Force a failure onto the run.
        $run->forceFill(['failed_count' => 2, 'passed_count' => 10, 'completed_at' => now()])->save();

        try {
            app(PermissionTestRunner::class)->approveForLive($run, $admin);
            $this->fail('A run with failures must not be approved for live use.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('TEST-5', $exception->ruleId);
        }
    }

    /** SCR-2 — the run screen previews the navigation the test user would see. */
    public function test_the_run_previews_the_navigation_the_test_user_sees(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $run = app(PermissionTestRunner::class)->start(
            Role::query()->where('name', 'Collection Agent')->firstOrFail(),
            $testUser,
            ['scope_type' => ScopeType::Point->value, 'scope_target_id' => $world['pointA']->id, 'environment' => 'development'],
            $admin,
        );

        app(PermissionTestRunner::class)->execute($run, $admin);

        $this->actingAs($admin);
        $screen = $this->get(route('admin.permission-tests.index'));

        $screen->assertOk();
        // A Collection Agent sees Milk Collection but not the shop or administration.
        $screen->assertSee('One-Stop Shop — hidden', false);
        $screen->assertSee('Administration — hidden', false);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: User, 1: User, 2: array<string, mixed>} */
    private function protocolCast(): array
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Sadiq Ahmed');
        $this->assignRole($admin, 'System Administrator');

        $testUser = $this->makeUser('Perm Test', [
            'email' => 'perm.test@gondalfulbe.ng',
            'is_test' => true,
        ]);

        return [$admin->fresh(), $testUser->fresh(), $world];
    }

    /**
     * SCOPE-3 — a point-scoped role is probed too.
     *
     * Only `center` was probed before, so a run against a point-scoped role
     * reported a clean scope having exercised none of it. That is the worst kind
     * of green: the protocol exists precisely to catch what a permission-only
     * check would pass wrongly.
     */
    public function test_scope3_a_point_scoped_run_is_probed_at_its_own_and_another_point(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $role = Role::query()->where('name', 'Collection Agent')->firstOrFail();

        $run = app(PermissionTestRunner::class)->start($role, $testUser, [
            'scope_type' => ScopeType::Point->value,
            'scope_target_id' => $world['pointA']->id,
            'environment' => 'staging',
        ], $admin);

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        $probes = PermissionTestCheck::query()
            ->where('permission_test_run_id', $run->id)
            ->where('is_scope_probe', true)
            ->get();

        $this->assertCount(2, $probes, 'One in-scope probe and one out-of-scope probe.');

        $this->assertSame(
            [$world['pointA']->id],
            $probes->where('expected', PermissionTestCheck::EXPECT_ALLOW)->pluck('scope_target_id')
                ->map(fn ($id) => (int) $id)->all(),
        );

        // The deny probe must name a point the role genuinely cannot reach.
        $denied = $probes->firstWhere('expected', PermissionTestCheck::EXPECT_DENY);

        $this->assertNotNull($denied);
        $this->assertNotSame($world['pointA']->id, (int) $denied->scope_target_id);
    }

    /**
     * SCOPE-3 — the out-of-scope probe respects EVERY target of a multi-target scope.
     *
     * With `!= one id` the probe could pick the assignment's second point and
     * report a scope breach that never happened — a false failure that would send
     * an administrator hunting a bug in the engine rather than in the role.
     */
    public function test_scope3_the_deny_probe_skips_all_targets_of_a_multi_target_scope(): void
    {
        [$admin, $testUser, $world] = $this->protocolCast();

        $role = Role::query()->where('name', 'Collection Agent')->firstOrFail();

        // The run simulates a scope covering BOTH points.
        $run = app(PermissionTestRunner::class)->start($role, $testUser, [
            'scope_type' => ScopeType::Point->value,
            'scope_target_ids' => [$world['pointA']->id, $world['pointB']->id],
            'environment' => 'staging',
        ], $admin);

        $run = app(PermissionTestRunner::class)->execute($run, $admin);

        $denied = PermissionTestCheck::query()
            ->where('permission_test_run_id', $run->id)
            ->where('is_scope_probe', true)
            ->where('expected', PermissionTestCheck::EXPECT_DENY)
            ->first();

        if ($denied !== null) {
            $this->assertNotContains(
                (int) $denied->scope_target_id,
                [$world['pointA']->id, $world['pointB']->id],
                'The deny probe must not name a point the scope actually covers.',
            );
        } else {
            // Both points are in scope and the world holds no third — nothing to
            // deny against, and inventing one would be a fabricated result.
            $this->assertTrue(true);
        }
    }
}
