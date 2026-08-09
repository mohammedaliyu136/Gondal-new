<?php

namespace App\Services\PermissionTesting;

use App\Authorization\Access;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Cooperative;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PermissionTestCheck;
use App\Models\PermissionTestRun;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleUserScopeTarget;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §5.4 — the permission testing protocol agreed at the review meeting.
 *
 * TEST-1 accounts may be flagged is_test and are excluded from every report,
 *        aggregate and payroll
 * TEST-2 a run records role under test, test user, simulated scope, environment,
 *        and expected-versus-actual with pass/fail
 * TEST-3 PRODUCTION MUST NOT BE OFFERABLE as an environment
 * TEST-4 every action taken as a test user is tagged is_test in the audit log
 * TEST-5 a passing run is what blesses a role change
 *
 * How the checks are generated matters. Rather than a hand-written list that
 * would drift from the permission catalogue, the run builds one check per LIVE
 * permission: expected `allow` where the role grants it, expected `deny` where it
 * does not. That makes an over-permission (the failure mode the prototype shows)
 * impossible to miss, and it stays correct when the catalogue grows.
 *
 * On top of that it adds SCOPE PROBES: for each scopeable resource the role can
 * see, one check against an in-scope target and one against an out-of-scope
 * target, which is the SCOPE-3 case a permission-only test would pass wrongly.
 */
class PermissionTestRunner
{
    /**
     * What each targeted scope type is probed with: the model whose rows the
     * scope narrows, a permission that acts on one of those rows, and the words
     * for the check line an operator reads on the run.
     *
     * A scope type absent from this table gets no probe — which is honest, and
     * visible, rather than a run that reports a clean scope it never exercised.
     *
     * @var array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     */
    private const PROBE_SUBJECTS = [
        ScopeType::Center->value => [
            CollectionCenter::class, 'milk.consignment.confirm.edit', 'Milk Collection', 'Confirm consignment',
        ],
        ScopeType::Point->value => [
            CollectionPoint::class, 'milk.points.edit', 'Milk Collection', 'Edit collection point',
        ],
        ScopeType::Lga->value => [
            CollectionCenter::class, 'milk.consignment.confirm.edit', 'Milk Collection', 'Confirm consignment',
        ],
        ScopeType::Department->value => [
            Employee::class, 'hr.employees.edit', 'Human Resources', 'Edit employee',
        ],
        ScopeType::Communities->value => [
            Cooperative::class, 'community.cooperatives.edit', 'Community', 'Edit cooperative',
        ],
    ];

    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * TEST-3 — the environment list comes from config and deliberately excludes
     * production. This is checked here as well as in the form request, because
     * the API can reach it too.
     *
     * @param  array<string, mixed>  $data
     */
    public function start(Role $role, User $testUser, array $data, User $actor): PermissionTestRun
    {
        $allowed = (array) config('gondal.permission_test_environments', ['development', 'staging']);
        $environment = (string) $data['environment'];

        if (! in_array($environment, $allowed, true)) {
            throw RuleViolationException::make(
                'TEST-3',
                'A test run may target the development or staging environment only. Production is never offerable.',
                ['allowed' => $allowed, 'requested' => $environment],
                'environment',
            );
        }

        // TEST-1 — the protocol exists so changes never touch live staff.
        if (! $testUser->is_test) {
            throw RuleViolationException::make(
                'TEST-1',
                sprintf('%s is not a test account. Flag an account as a test user before running a test against it.', $testUser->name),
                ['user' => $testUser->email],
                'test_user_id',
            );
        }

        $scopeType = ScopeType::tryFrom((string) ($data['scope_type'] ?? $role->scope_type)) ?? $role->defaultScopeType();

        $run = PermissionTestRun::query()->create([
            'reference' => Sequences::next('permission_tests'),
            'role_id' => $role->getKey(),
            'test_user_id' => $testUser->getKey(),
            'run_by_user_id' => $actor->getKey(),
            'scope_type' => $scopeType->value,
            'scope_target_id' => $data['scope_target_id'] ?? null,
            'scope_target_ids' => array_values(array_map('intval', $data['scope_target_ids'] ?? [])),
            'environment' => $environment,
            'signin_result' => 'not_attempted',
            'status' => PermissionTestRun::STATUS_IN_PROGRESS,
            'notes' => $data['notes'] ?? null,
            'started_at' => Wat::now(),
        ]);

        $this->audit->testRun(
            $run,
            sprintf('Test run %s started — %s as %s (%s)', $run->reference, $role->name, $testUser->email, $environment),
            ['rule' => 'TEST-2', 'scope_type' => $scopeType->value],
            $actor,
        );

        return $run;
    }

    /**
     * Applies the simulated assignment to the test user, evaluates every check,
     * and records the results.
     *
     * TEST-2 — expected versus actual, with pass/fail.
     */
    public function execute(PermissionTestRun $run, User $actor): PermissionTestRun
    {
        $role = $run->role;
        $testUser = $run->testUser;
        $scopeType = ScopeType::tryFrom((string) $run->scope_type) ?? ScopeType::Network;

        DB::transaction(function () use ($run, $role, $testUser, $scopeType): void {
            // The simulated assignment. Everything the test user held before is
            // replaced, so the run measures THIS role and nothing else — which is
            // what "one role per test scenario" means on roles.html.
            RoleAssignment::query()->where('user_id', $testUser->getKey())->forceDelete();

            /*
             * The simulated scope must carry ALL of the run's targets, stored the
             * same way a real assignment stores them — one in the column, several
             * in the child table. A run that quietly dropped the second centre
             * would report a clean scope for access it never exercised.
             */
            $targets = $run->simulatedTargetIds();
            $single = count($targets) === 1 ? $targets[0] : null;

            $assignment = RoleAssignment::query()->create([
                'role_id' => $role->getKey(),
                'user_id' => $testUser->getKey(),
                'scope_type' => $scopeType->value,
                'scope_target_id' => $single,
                'assigned_by_user_id' => $run->run_by_user_id,
                'assigned_at' => Wat::now(),
            ]);

            if ($single === null) {
                foreach ($targets as $target) {
                    RoleUserScopeTarget::query()->create([
                        'role_user_id' => $assignment->getKey(),
                        'target_id' => $target,
                    ]);
                }
            }

            $testUser->forgetAccessMemo();

            $run->checks()->delete();

            /*
             * What the test user SHOULD reach is the role under test PLUS the
             * automatic role, because ROLE-3 gives every user
             * "Staff (self-service)" whether anyone assigns it or not. Expecting
             * the role's grants alone would report every self-service permission as
             * an over-permission — a false alarm on every single run, which is the
             * fastest way to teach an administrator to ignore the report.
             */
            $automatic = Role::query()
                ->where('is_automatic', true)
                ->where('status', Role::STATUS_ACTIVE)
                ->get()
                ->flatMap(fn (Role $automaticRole) => $automaticRole->livePermissions()->get());

            $granted = $role->livePermissions()->get()
                ->concat($automatic)
                ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action)
                ->unique()
                ->values()
                ->all();

            $position = 0;
            $passed = 0;
            $failed = 0;

            foreach (Permission::query()->live()->orderBy('position')->get() as $permission) {
                $key = $permission->resource_key.'.'.$permission->action;
                $expected = in_array($key, $granted, true)
                    ? PermissionTestCheck::EXPECT_ALLOW
                    : PermissionTestCheck::EXPECT_DENY;

                $allowed = $this->access->allows($testUser, $key);
                $actual = $allowed ? PermissionTestCheck::EXPECT_ALLOW : PermissionTestCheck::EXPECT_DENY;
                $ok = $actual === $expected;

                $ok ? $passed++ : $failed++;

                PermissionTestCheck::query()->create([
                    'permission_test_run_id' => $run->getKey(),
                    'module' => $permission->module(),
                    'area' => $permission->label.' — '.$permission->action,
                    'permission_key' => $key,
                    'expected' => $expected,
                    'actual' => $actual,
                    'actual_reason' => $allowed ? null : 'permission',
                    'passed' => $ok,
                    'note' => $ok ? null : ($allowed
                        ? 'Over-permission: the role grants access it should not.'
                        : 'Missing permission: the role should grant this.'),
                    'position' => $position++,
                ]);
            }

            // SCOPE-3 probes — the case a permission-only test would pass wrongly.
            foreach ($this->scopeProbes($testUser, $scopeType, $run->simulatedTargetIds()) as $probe) {
                $ok = $probe['actual'] === $probe['expected'];
                $ok ? $passed++ : $failed++;

                PermissionTestCheck::query()->create([
                    'permission_test_run_id' => $run->getKey(),
                    'module' => $probe['module'],
                    'area' => $probe['area'],
                    'permission_key' => $probe['permission_key'],
                    'scope_target_id' => $probe['target_id'],
                    'is_scope_probe' => true,
                    'expected' => $probe['expected'],
                    'actual' => $probe['actual'],
                    'actual_reason' => $probe['actual'] === PermissionTestCheck::EXPECT_DENY ? 'scope' : null,
                    'passed' => $ok,
                    'note' => $ok ? null : 'Data scope did not behave as expected.',
                    'position' => $position++,
                ]);
            }

            $run->forceFill([
                'passed_count' => $passed,
                'failed_count' => $failed,
                'signin_result' => 'succeeded',
                'status' => $failed === 0 ? PermissionTestRun::STATUS_PASSED : PermissionTestRun::STATUS_FAILED,
                'completed_at' => Wat::now(),
            ])->save();
        });

        $run->refresh();

        // TEST-4 — a test run is itself an audited event, tagged is_test.
        $this->audit->testRun(
            $run,
            sprintf(
                'Test run %s completed — %d passed, %d failed',
                $run->reference,
                $run->passed_count,
                $run->failed_count,
            ),
            [
                'rule' => 'TEST-2',
                'role' => $role->name,
                'test_user' => $testUser->email,
                'failures' => $run->checks()->where('passed', false)->pluck('area')->all(),
            ],
            $actor,
        );

        return $run;
    }

    /**
     * TEST-5 — a passing run blesses the role's current grant set.
     */
    public function approveForLive(PermissionTestRun $run, User $actor): PermissionTestRun
    {
        if (! $run->hasPassed()) {
            throw RuleViolationException::make(
                'TEST-5',
                sprintf('%s has %d failing check(s). Resolve them before approving the configuration for live use.', $run->reference, $run->failed_count),
                ['failed' => $run->failed_count],
            );
        }

        $run->forceFill(['status' => PermissionTestRun::STATUS_APPROVED_FOR_LIVE])->save();

        $run->role->forceFill(['last_passing_test_run_id' => $run->getKey()])->save();

        $this->audit->testRun(
            $run,
            sprintf('Test run %s approved for live — %s validated', $run->reference, $run->role->name),
            ['rule' => 'TEST-5'],
            $actor,
        );

        return $run;
    }

    /**
     * SCOPE-3 — one in-scope check and one out-of-scope check, so a scope failure
     * is visible as "Blocked (out of scope)" rather than hiding behind a passing
     * permission.
     *
     * The in-scope set is read from the test user's OWN resolved scope rather than
     * from the run's target column. Two things follow. A scope naming several
     * targets is probed against all of them, and the out-of-scope probe is a
     * `whereNotIn` over that whole set — with `!= one id` it could pick the
     * assignment's second centre and report a scope breach that never happened.
     *
     * Every targeted scope type is probed, not just `center`. A point-scoped or
     * department-scoped role got no probe at all before, so the run said "passed"
     * having tested nothing about its scope.
     *
     * @param  array<int, int>  $targetIds  every target the run simulates
     * @return array<int, array<string, mixed>>
     */
    private function scopeProbes(User $testUser, ScopeType $scopeType, array $targetIds): array
    {
        $subject = self::PROBE_SUBJECTS[$scopeType->value] ?? null;

        if ($subject === null) {
            return [];
        }

        [$modelClass, $permissionKey, $module, $noun] = $subject;

        /** @var class-string<Model&Scopeable> $modelClass */
        if ($targetIds === []) {
            return [];
        }

        /*
         * The targets are not the probe model's own ids for every scope type: a
         * department scope names departments and the probe is an employee, an LGA
         * scope names LGAs and the probe is a centre. Asking the model to narrow
         * ITSELF — the same closure the global scope uses — is the only way to get
         * this right for all five types, and keeps the probe honest by construction.
         */
        $constraint = (new $modelClass)->scopeConstraints()[$scopeType->value] ?? null;

        if ($constraint === null) {
            return [];
        }

        $inScopeIds = $modelClass::withoutDataScope()
            ->where(fn ($query) => $constraint($query, $targetIds))
            ->pluck('id')
            ->all();

        if ($inScopeIds === []) {
            return [];
        }

        $inScope = $modelClass::withoutDataScope()->whereIn('id', $inScopeIds)->first();
        $outOfScope = $modelClass::withoutDataScope()->whereNotIn('id', $inScopeIds)->first();

        $probes = [];

        if ($inScope !== null) {
            $probes[] = [
                'module' => $module,
                'area' => sprintf('%s at own %s (%s)', $noun, $scopeType->label(), $inScope->name),
                'permission_key' => $permissionKey,
                'target_id' => $inScope->getKey(),
                'expected' => PermissionTestCheck::EXPECT_ALLOW,
                'actual' => $this->access->allows($testUser, $permissionKey, $inScope)
                    ? PermissionTestCheck::EXPECT_ALLOW
                    : PermissionTestCheck::EXPECT_DENY,
            ];
        }

        if ($outOfScope !== null) {
            $probes[] = [
                'module' => $module,
                'area' => sprintf('%s at another %s (%s)', $noun, $scopeType->label(), $outOfScope->name),
                'permission_key' => $permissionKey,
                'target_id' => $outOfScope->getKey(),
                'expected' => PermissionTestCheck::EXPECT_DENY,
                'actual' => $this->access->allows($testUser, $permissionKey, $outOfScope)
                    ? PermissionTestCheck::EXPECT_ALLOW
                    : PermissionTestCheck::EXPECT_DENY,
            ];
        }

        return $probes;
    }
}
