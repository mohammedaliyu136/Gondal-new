<?php

namespace Tests\Feature\Rules;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeType;
use App\Models\CollectionPoint;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\BootstrapAdminSeeder;
use Database\Seeders\DemoChainCastSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\GondalTestCase;

/**
 * The demo seed itself is a deliverable (NFR-12, §17), and it is the one thing
 * the rest of this suite structurally cannot cover: every other test builds its
 * own fixtures, so the seeded database once shipped with 41 of 42 collection
 * points whose named agent could not open them — while 400 tests stayed green.
 *
 * This class runs the REAL demo seeders and pins the invariants that make the
 * seeded system walkable: registers agree with scopes (SCOPE-1), no seeded
 * account straddles a four-eyes boundary, every workflow stage is staffed
 * (BR-23), and the walkthrough cast can actually sign in (SEED-1..SEED-4).
 */
class SeedIntegrityTest extends GondalTestCase
{
    private const DEMO_PASSWORD = 'GondalDemo!2026';

    /**
     * One method on purpose: the demo seed takes tens of seconds, and every
     * assertion here reads the same seeded world. Each block is labelled with
     * the rule it pins so a failure still names its contract.
     */
    public function test_the_demo_seed_produces_a_coherent_walkable_system(): void
    {
        config(['gondal.seed_demo_data' => true]);

        DataScope::asSystem(function (): void {
            $this->seed([BootstrapAdminSeeder::class, DemoDataSeeder::class, DemoChainCastSeeder::class]);
        });

        DataScope::asSystem(function (): void {
            $this->assertEveryPointsAgentCanOpenTheirOwnPoint();
            $this->assertNoSeededAccountStraddlesAFourEyesBoundary();
            $this->assertEveryActiveWorkflowStageIsStaffed();
            $this->assertTheWalkthroughCastCanSignIn();
            $this->assertFieldAgentRegisterAgreesWithTheRole();
            $this->assertDepartmentsTheChainsUseHaveHeads();
            $this->assertTestAccountsAreFlagged();
            $this->assertEverySensitivePermissionHasAHolder();
        });
    }

    /**
     * SCOPE-1 — the point register names an agent; that agent's scope must
     * admit that point. This is the exact 41-of-42 defect, pinned.
     */
    private function assertEveryPointsAgentCanOpenTheirOwnPoint(): void
    {
        $points = CollectionPoint::withoutDataScope()->whereNotNull('agent_user_id')->get();

        $this->assertNotEmpty($points, 'Seed produced no collection points with agents.');

        foreach ($points as $point) {
            $admits = RoleAssignment::query()
                ->where('user_id', $point->agent_user_id)
                ->where('scope_type', ScopeType::Point->value)
                ->whereHas('role', fn ($query) => $query->where('name', 'Collection Agent'))
                ->where(function ($query) use ($point) {
                    $query->where('scope_target_id', $point->getKey())
                        ->orWhereHas('scopeTargets', fn ($targets) => $targets->where('target_id', $point->getKey()));
                })
                ->exists();

            $this->assertTrue($admits, sprintf(
                'SCOPE-1: %s names user #%d as its agent, but that user\'s Collection Agent scope does not admit it.',
                $point->code,
                $point->agent_user_id,
            ));
        }
    }

    /**
     * The four-eyes pairings DemoDataSeeder::ROLE_CONFLICTS forbids — asserted
     * from the database rather than trusted to the seeder's own guard.
     */
    private function assertNoSeededAccountStraddlesAFourEyesBoundary(): void
    {
        $conflicts = [
            ['Collection Agent', 'Milk Collection Officer'],
            ['Collection Agent', 'Milk Collection Supervisor'],
            ['Collection Agent', 'Logistics Officer'],
            ['Milk Collection Officer', 'Milk Collection Supervisor'],
            ['Sales Officer', 'Inventory Officer'],
            ['Sales Officer', 'One-Stop Shop Manager'],
            ['Internal Audit', 'Accounts'],
            ['Extension Agent', 'Community Engagement Officer'],
        ];

        foreach ($conflicts as [$first, $second]) {
            $straddlers = User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($query) => $query->where('name', $first))
                ->whereHas('roles', fn ($query) => $query->where('name', $second))
                ->pluck('email');

            $this->assertTrue($straddlers->isEmpty(), sprintf(
                'Four-eyes: %s hold both "%s" and "%s" — the second stage would confirm its own first.',
                $straddlers->implode(', '),
                $first,
                $second,
            ));
        }
    }

    /**
     * BR-23 — stages reference roles; a stage whose role has no active holder
     * stalls every item that reaches it.
     */
    private function assertEveryActiveWorkflowStageIsStaffed(): void
    {
        $stages = DB::table('workflow_stages')
            ->join('workflows', 'workflows.id', '=', 'workflow_stages.workflow_id')
            ->where('workflows.status', 'active')
            ->whereNotNull('workflow_stages.approving_role_id')
            ->select('workflow_stages.name as stage', 'workflows.name as workflow', 'workflow_stages.approving_role_id')
            ->get();

        $this->assertNotEmpty($stages, 'Seed produced no approval stages.');

        foreach ($stages as $stage) {
            $holders = User::query()
                ->where('status', 'active')
                ->where('is_test', false)
                ->whereHas('roles', fn ($query) => $query->where('roles.id', $stage->approving_role_id))
                ->count();

            $this->assertGreaterThan(0, $holders, sprintf(
                'BR-23: stage "%s" of workflow "%s" has no active, non-test holder — approvals would stall there.',
                $stage->stage,
                $stage->workflow,
            ));
        }
    }

    /**
     * SEED-1 / SEED-4 — one dedicated sign-in per stage of every chain, shared
     * demo password, two-factor off so a review session is one step.
     */
    private function assertTheWalkthroughCastCanSignIn(): void
    {
        $cast = [
            // Milk: agent → officer → logistics → supervisor
            'musa.ibrahim@gondalfulbe.ng' => 'Collection Agent',
            'maryam.yakubu@gondalfulbe.ng' => 'Milk Collection Officer',
            'salisu.adamu@gondalfulbe.ng' => 'Logistics Officer',
            'bashir.danladi@gondalfulbe.ng' => 'Milk Collection Supervisor',
            // Community: agent → CE officer → delivery lead
            'jamila.usman@gondalfulbe.ng' => 'Extension Agent',
            'aminu.jibril@gondalfulbe.ng' => 'Community Engagement Officer',
            'hafsat.bello@gondalfulbe.ng' => 'Delivery Lead',
            // Requisition: requester → heads → audit → ED → accounts → GM
            'tijjani.usman@gondalfulbe.ng' => 'Logistics Officer',
            'lawal.ibrahim@gondalfulbe.ng' => 'Department Head',
            'hauwa.abdullahi@gondalfulbe.ng' => 'Department Head',
            'saudat.bello@gondalfulbe.ng' => 'Internal Audit',
            'haruna.gambo@gondalfulbe.ng' => 'Executive Director',
            'fauziya.sani@gondalfulbe.ng' => 'Accounts',
            'abdulkadir.tanko@gondalfulbe.ng' => 'General Manager',
            // Shop: manager → inventory → sales
            'nafisa.garba@gondalfulbe.ng' => 'One-Stop Shop Manager',
            'shehu.mainasara@gondalfulbe.ng' => 'Inventory Officer',
            'usman.lawal@gondalfulbe.ng' => 'Sales Officer',
            'halima.abubakar@gondalfulbe.ng' => 'Sales Officer',
            // HRM: manager + two staff-only employees
            'binta.yusuf@gondalfulbe.ng' => 'HR Manager',
            'nuraini.sabo@gondalfulbe.ng' => 'Staff (self-service)',
            'yakubu.hamza@gondalfulbe.ng' => 'Staff (self-service)',
        ];

        foreach ($cast as $email => $roleName) {
            $user = User::query()->where('email', $email)->first();

            $this->assertNotNull($user, "SEED-1: cast member {$email} was not seeded.");
            $this->assertSame('active', $user->status, "{$email} is not active.");
            $this->assertFalse((bool) $user->is_test, "{$email} must not be a test account (BR-35 would hide their records).");
            $this->assertFalse((bool) $user->two_factor_enabled, "SEED-4: {$email} still has two-factor on.");
            $this->assertTrue(
                Hash::check(self::DEMO_PASSWORD, $user->password_hash),
                "SEED-1: {$email} does not accept the shared demo password.",
            );
            $this->assertTrue(
                $user->roles()->where('name', $roleName)->exists(),
                "{$email} does not hold the {$roleName} role.",
            );
            $this->assertNotNull($user->employee_id, "{$email} has no employee record — own-leave and own-payslip would 404.");
        }

        // The two staff-only employees must hold NOTHING functional: their
        // near-empty navigation is the persona being demonstrated.
        foreach (['nuraini.sabo@gondalfulbe.ng', 'yakubu.hamza@gondalfulbe.ng'] as $email) {
            $functional = User::query()->where('email', $email)->firstOrFail()
                ->roles()->where('name', '!=', 'Staff (self-service)')->count();

            $this->assertSame(0, $functional, "{$email} should be staff-only, but holds a functional role.");
        }
    }

    /**
     * The field-agent register (extension_agents) must agree with who holds the
     * Extension Agent role — the same register/role drift SCOPE-1 suffered.
     */
    private function assertFieldAgentRegisterAgreesWithTheRole(): void
    {
        $roleId = Role::query()->where('name', 'Extension Agent')->value('id');

        $orphans = DB::table('extension_agents')
            ->whereNull('deleted_at')
            ->whereNotIn('user_id', DB::table('role_user')->where('role_id', $roleId)->whereNull('deleted_at')->pluck('user_id'))
            ->pluck('code');

        $this->assertTrue($orphans->isEmpty(), sprintf(
            'extension_agents rows %s belong to users who no longer hold the Extension Agent role.',
            $orphans->implode(', '),
        ));
    }

    /** The chains route through these departments; each needs a head on record. */
    private function assertDepartmentsTheChainsUseHaveHeads(): void
    {
        foreach (['Milk Collection', 'Logistics', 'One-Stop Shop', 'Human Resources'] as $name) {
            $headId = DB::table('departments')->where('name', $name)->value('head_user_id');

            $this->assertNotNull($headId, "Department \"{$name}\" has no head on record.");
        }
    }

    /** TEST-1 / BR-35 — the reserved validation accounts are flagged. */
    private function assertTestAccountsAreFlagged(): void
    {
        foreach (['perm.test@gondalfulbe.ng', 'shop.test@gondalfulbe.ng', 'ext.test@gondalfulbe.ng'] as $email) {
            $this->assertTrue(
                (bool) User::query()->where('email', $email)->value('is_test'),
                "TEST-1: {$email} is not flagged is_test.",
            );
        }
    }

    /**
     * PERM-1 / PERM-2 — a permission the seed leaves nobody holding.
     *
     * `milk.deliveries.cutoff_override` arrived by migration and was granted
     * there to the Milk Collection Supervisor and Officer. RoleSeeder's
     * catalogue is what actually writes `permission_role`, and it rewrites the
     * table on every seed — so the migration's grant was taken straight back
     * off at the next `db:seed`, leaving BR-3's supervisor override with no
     * ordinary holder at all. Every existing BR-3 test passed through it
     * because each one granted the permission to itself first.
     *
     * The general rule is asserted rather than that one key: a sensitive,
     * non-retired permission that nobody but the two administrator roles can
     * exercise is either a seeding bug or a permission nobody needs, and both
     * are worth failing the build over.
     */
    private function assertEverySensitivePermissionHasAHolder(): void
    {
        // The wildcard roles hold everything by construction, so they cannot
        // stand as evidence that a grant was deliberate.
        $administrators = ['System Administrator', 'Administrator'];

        $orphans = DB::table('permissions')
            ->whereNull('retired_at')
            ->where('is_sensitive', true)
            ->get()
            ->filter(function ($permission) use ($administrators): bool {
                $holders = DB::table('permission_role')
                    ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                    ->where('permission_role.permission_id', $permission->id)
                    ->whereNull('roles.retired_at')
                    ->whereNotIn('roles.name', $administrators)
                    ->count();

                return $holders === 0;
            })
            ->map(fn ($permission) => $permission->resource_key.'.'.$permission->action)
            ->values();

        $this->assertTrue($orphans->isEmpty(), sprintf(
            'PERM-1: %s sensitive permission(s) that only an administrator holds — '
            .'the seed grants them to no working role, so the people the rule names '
            .'would be refused: %s',
            $orphans->count(),
            $orphans->join(', '),
        ));
    }
}
