<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Http\Middleware\RequireApprovalQueueAccess;
use App\Models\AuditEntry;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\GondalTestCase;

/** §7.4 — approvals. */
class ApprovalRulesTest extends GondalTestCase
{
    /** ₦500,000 in kobo — BR-19's band break. */
    private const BAND_BREAK = 50_000_000;

    /**
     * BR-19 — "Which stages apply is determined by the matching workflow_band for
     * the subject's amount. Seeded requisition bands: up to ₦500,000 → User, Dept
     * Head, Internal Audit, Accounts. Above ₦500,000 → all six stages."
     */
    public function test_br19_amount_decides_which_stages_apply(): void
    {
        $cast = $this->cast();

        $small = $this->submit($cast, 200_000_00);          // ₦200,000
        $large = $this->submit($cast, 3_400_000_00);        // ₦3,400,000 (§17)

        $smallStages = $small->workflowInstance->applicableStages();
        $largeStages = $large->workflowInstance->applicableStages();

        $this->assertSame(4, $smallStages->count(), 'Up to ₦500,000: four stages.');
        $this->assertSame(6, $largeStages->count(), 'Above ₦500,000: all six.');

        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Accounts'],
            $smallStages->pluck('name')->all(),
        );

        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Executive Director', 'Accounts', 'General Manager'],
            $largeStages->pluck('name')->all(),
        );

        $this->assertSame('Standard', $small->workflowInstance->band->name);
        $this->assertSame('Major', $large->workflowInstance->band->name);
    }

    /**
     * BR-19 — "up to ₦500,000 → four stages. Above ₦500,000 → all six."
     *
     * The boundary itself, which neither this class nor PhaseAcceptanceTest ever
     * submitted: both tested ₦200,000 and ₦3,400,000, so a `<` where `<=` is
     * meant in WorkflowBand's range — or in WorkflowStage::conditionHolds's
     * `amount_above` — would either drag a routine ₦500,000 purchase through six
     * stages or, worse, skip the Executive Director and the General Manager on
     * one at ₦500,000.01. "Up to" is inclusive, so exactly ₦500,000 takes four.
     */
    public function test_br19_the_band_boundary_at_500000_is_inclusive(): void
    {
        $cast = $this->cast();

        $onTheLine = $this->submit($cast, self::BAND_BREAK);
        $oneKoboOver = $this->submit($cast, self::BAND_BREAK + 1);

        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Accounts'],
            $onTheLine->workflowInstance->applicableStages()->pluck('name')->all(),
            'Exactly ₦500,000 is "up to ₦500,000" — four stages.',
        );

        $this->assertSame('Standard', $onTheLine->workflowInstance->band->name);

        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Executive Director', 'Accounts', 'General Manager'],
            $oneKoboOver->workflowInstance->applicableStages()->pluck('name')->all(),
            'One kobo above it is "above ₦500,000" — all six.',
        );

        $this->assertSame('Major', $oneKoboOver->workflowInstance->band->name);
    }

    /**
     * BR-19 — a negative line cannot buy a cheaper approval band.
     *
     * `unit_price` was validated as a bare string, Money::fromMajor honours a
     * leading minus, and submit() guarded only that the TOTAL was above zero. So
     * a ₦2,000,000 purchase filed as ₦2,000,000 plus a −₦1,600,000 "discount"
     * banded at ₦400,000 and routed past the Executive Director and the General
     * Manager. The band is fixed at start() and never recomputed, so nothing
     * downstream would have caught it — a permission boundary defeated by
     * arithmetic.
     */
    public function test_br19_a_negative_line_cannot_lower_the_approval_band(): void
    {
        $cast = $this->cast();
        $this->actingAs($cast['requester']);

        $service = app(RequisitionService::class);

        try {
            $service->create([
                'title' => 'Generator overhaul',
                'department_id' => $cast['department']->id,
                'urgency' => 'high',
            ], [
                ['item' => 'Overhaul', 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => 2_000_000_00],
                ['item' => 'Discount', 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => -1_600_000_00],
            ], $cast['requester']);

            $this->fail('A negative requisition line must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-19', $exception->ruleId);
            $this->assertSame('items', $exception->field);
        }

        // Nothing was left half-written by the refusal.
        $this->assertSame(0, $this->asSystem(fn () => Requisition::query()->count()));

        // And the screen refuses it before the service ever sees it.
        $this->post(route('requisitions.store'), [
            'title' => 'Generator overhaul',
            'urgency' => 'high',
            'items' => [
                ['item' => 'Overhaul', 'quantity' => '1', 'unit_price' => '2000000'],
                ['item' => 'Discount', 'quantity' => '1', 'unit_price' => '-1600000'],
            ],
        ])->assertSessionHasErrors('items.1.unit_price');
    }

    /**
     * BR-17 — "Approval is strictly sequential. A stage is actionable only when
     * all prior applicable stages are approved."
     */
    public function test_br17_a_later_stage_cannot_act_before_an_earlier_one(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        // The instance sits at stage 2 (Department Head). Internal Audit is stage 3.
        $this->assertSame('Department Head', $instance->currentStage->name);

        // Forcing the instance to stage 3 while stage 2 is unapproved must fail.
        $auditStage = $instance->applicableStages()->firstWhere('name', 'Internal Audit');
        $instance->forceFill(['current_stage_id' => $auditStage->id])->save();

        try {
            app(WorkflowEngine::class)->approve($instance->refresh(), $cast['audit']);
            $this->fail('Stage 3 must not act while stage 2 is unapproved.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-17', $exception->ruleId);
            $this->assertStringContainsString('Department Head has not approved yet', $exception->getMessage());
        }
    }

    /** BR-17 — approving each stage in order advances one step at a time. */
    public function test_br17_stages_advance_one_at_a_time(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $engine = app(WorkflowEngine::class);

        $expected = ['Department Head', 'Internal Audit', 'Executive Director', 'Accounts', 'General Manager'];
        $approvers = [
            'Department Head' => $cast['deptHead'],
            'Internal Audit' => $cast['audit'],
            'Executive Director' => $cast['ed'],
            'Accounts' => $cast['accounts'],
            'General Manager' => $cast['gm'],
        ];

        $instance = $requisition->workflowInstance;

        foreach ($expected as $index => $stageName) {
            $this->assertSame($stageName, $instance->currentStage->name);
            $this->assertSame($index + 2, $instance->stageNumber());

            $instance = $engine->approve($instance, $approvers[$stageName], null, 'Verified.');
        }

        $this->assertSame(WorkflowInstance::STATUS_APPROVED, $instance->status);
        $this->assertNull($instance->current_stage_id);

        app(RequisitionService::class)->syncFromWorkflow($requisition->refresh());
        $this->assertSame(Requisition::STATUS_APPROVED, $requisition->refresh()->status);
    }

    /**
     * BR-18 — "A requester may never approve their own submission at any stage,
     * even if they hold the permission. Reject with 403."
     */
    public function test_br18_requester_cannot_approve_their_own_submission(): void
    {
        $cast = $this->cast();

        // Give the requester the Department Head role AND its scope, so the only
        // thing standing in the way is BR-18 itself.
        $this->assignRole($cast['requester'], 'Department Head', ScopeType::Department, $cast['department']->id);

        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->assertTrue($cast['requester']->fresh()->hasPermission('purchase.approve.depthead.approve'));

        try {
            app(WorkflowEngine::class)->approve($instance, $cast['requester']->fresh());
            $this->fail('A requester must never approve their own submission.');
        } catch (AccessDeniedException $exception) {
            // BR-18 says 403, not 422 — so this is a denial, and it is audited.
            $this->assertSame(AccessDeniedException::REASON_PERMISSION, $exception->reason);
            $this->assertSame('BR-18', $exception->detail['rule']);
        }

        $this->assertDatabaseHas('audit_entries', [
            'event_type' => 'blocked_access',
            'reference' => $exception->reference,
        ]);
    }

    /** BR-18 — and their own submission never appears in their own queue. */
    public function test_br18_own_submission_is_absent_from_the_queue(): void
    {
        $cast = $this->cast();
        $this->assignRole($cast['requester'], 'Department Head', ScopeType::Department, $cast['department']->id);

        $requisition = $this->submit($cast, 3_400_000_00);

        $engine = app(WorkflowEngine::class);

        $this->assertSame(
            0,
            $engine->queueFor($cast['requester']->fresh())->count(),
            'Your own submission must not appear in your queue.',
        );

        $this->assertSame(
            1,
            $engine->queueFor($cast['deptHead'])->count(),
            'It appears in the queue of whoever holds the stage role.',
        );
    }

    /**
     * BR-20 — "A rejection at any stage sets the instance to rejected and returns
     * the subject to the requester, who may revise and resubmit. Resubmission
     * starts a NEW instance; the old one is retained."
     */
    public function test_br20_rejection_returns_it_and_resubmission_starts_a_new_instance(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $firstInstance = $requisition->workflowInstance;

        app(WorkflowEngine::class)->reject(
            $firstInstance,
            $cast['deptHead'],
            'Three quotations are required for anything over ₦1m.',
        );

        app(RequisitionService::class)->syncFromWorkflow($requisition->refresh());

        $this->assertSame(WorkflowInstance::STATUS_REJECTED, $firstInstance->refresh()->status);
        $this->assertSame(Requisition::STATUS_REJECTED, $requisition->refresh()->status);

        // The requester revises and resubmits.
        $this->actingAs($cast['requester']);

        $revision = app(RequisitionService::class)->resubmit(
            $requisition,
            ['title' => 'Diesel — 2,500 L (three quotations attached)'],
            [],
            $cast['requester'],
        );

        // A NEW requisition, a NEW instance, and the old chain intact.
        $this->assertNotSame($requisition->id, $revision->id);
        $this->assertSame($requisition->id, (int) $revision->revises_requisition_id);
        $this->assertNotSame($firstInstance->id, $revision->workflowInstance->id);

        $this->assertSame(
            WorkflowInstance::STATUS_REJECTED,
            $firstInstance->refresh()->status,
            'The rejected instance is retained exactly as it was.',
        );

        $this->assertSame(
            'Three quotations are required for anything over ₦1m.',
            $firstInstance->actions()->where('action', WorkflowAction::ACTION_REJECT)->value('comment'),
        );
    }

    /** BR-20 — only a rejected requisition may be resubmitted. */
    public function test_br20_only_a_rejected_requisition_can_be_resubmitted(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 200_000_00);

        $this->actingAs($cast['requester']);

        try {
            app(RequisitionService::class)->resubmit($requisition, [], [], $cast['requester']);
            $this->fail('An in-review requisition must not be resubmitted.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-20', $exception->ruleId);
        }
    }

    /**
     * BR-21 — "request_info records an action and notifies the requester without
     * advancing or ending the instance."
     */
    public function test_br21_request_info_neither_advances_nor_ends(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $stageBefore = $instance->current_stage_id;

        app(WorkflowEngine::class)->requestInfo(
            $instance,
            $cast['deptHead'],
            'Which vehicles is this diesel for?',
        );

        $instance->refresh();

        $this->assertSame($stageBefore, $instance->current_stage_id, 'The stage must not move.');
        $this->assertSame(WorkflowInstance::STATUS_IN_PROGRESS, $instance->status, 'The instance must stay open.');

        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'action' => WorkflowAction::ACTION_REQUEST_INFO,
        ]);

        // NOTIF — the requester is told.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $cast['requester']->id,
            'type' => 'requisition.decided',
        ]);
    }

    /**
     * BR-22 — "An approver may reduce amount_minor but never increase it above the
     * requested total."
     */
    public function test_br22_an_approver_may_reduce_but_never_raise_the_amount(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;
        $engine = app(WorkflowEngine::class);

        // Raising it is refused.
        try {
            $engine->approve($instance, $cast['deptHead'], 4_000_000_00);
            $this->fail('An approver must not raise the amount.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-22', $exception->ruleId);
        }

        // Reducing it is accepted and recorded.
        $instance = $engine->approve($instance->refresh(), $cast['deptHead'], 3_000_000_00, 'Trimmed to budget.');

        $this->assertSame(3_000_000_00, (int) $instance->approved_amount_minor);
        $this->assertSame(3_400_000_00, (int) $instance->amount_minor, 'The requested total is untouched.');

        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'action' => WorkflowAction::ACTION_APPROVE,
            'amount_minor' => 3_000_000_00,
        ]);
    }

    /**
     * BR-22 — a reduction is monotonic; a later stage cannot put it back.
     *
     * The ceiling was compared against `amount_minor`, the ORIGINAL request, not
     * against the figure the chain had already settled on. So the Department Head
     * could cut ₦3,400,000 to ₦1,000,000 and Internal Audit could quietly restore
     * it — with no re-banding, because BR-19 fixes the chain at start(), and no
     * extra stage to notice. The literal words of BR-22 survived; the trail lied.
     */
    public function test_br22_a_later_stage_cannot_undo_an_earlier_reduction(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $engine = app(WorkflowEngine::class);

        $instance = $engine->approve($requisition->workflowInstance, $cast['deptHead'], 1_000_000_00, 'Trimmed to budget.');

        $this->assertSame(1_000_000_00, (int) $instance->approved_amount_minor);
        $this->assertSame('Internal Audit', $instance->currentStage->name);

        try {
            $engine->approve($instance, $cast['audit'], 3_400_000_00);
            $this->fail('A later stage must not raise the amount back to the request.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-22', $exception->ruleId);
            $this->assertSame(1_000_000_00, $exception->context['ceiling_minor']);
        }

        $this->assertSame(
            1_000_000_00,
            (int) $instance->refresh()->approved_amount_minor,
            'The Department Head\'s reduction stands.',
        );
    }

    /**
     * BR-22 — and it cannot go below zero either.
     *
     * The rule bounds one end of the range only, so a negative approved amount
     * reached `requisitions.approved_total_minor` — the figure Accounts pays
     * against — and `payroll_runs` by the same path.
     */
    public function test_br22_an_approved_amount_cannot_be_negative(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        try {
            app(WorkflowEngine::class)->approve($instance, $cast['deptHead'], -100_00);
            $this->fail('A negative approved amount must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-22', $exception->ruleId);
        }

        $this->assertSame(3_400_000_00, (int) $instance->refresh()->approved_amount_minor);
        $this->assertSame(WorkflowInstance::STATUS_IN_PROGRESS, $instance->status);
    }

    /**
     * BR-23 — "Stages reference roles, not users. Any user holding the stage's
     * role and satisfying scope sees the item in /approvals."
     */
    public function test_br23_the_queue_follows_the_role_not_the_person(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $engine = app(WorkflowEngine::class);

        // A second Department Head sees the same item, without anything being
        // reassigned on the requisition.
        $standIn = $this->makeUser('Stand-in Head', ['department_id' => $cast['department']->id]);
        $this->assignRole($standIn, 'Department Head', ScopeType::Department, $cast['department']->id);

        $this->assertSame(1, $engine->queueFor($cast['deptHead'])->count());
        $this->assertSame(1, $engine->queueFor($standIn)->count());

        // Somebody without the role sees nothing.
        $outsider = $this->makeUser('Outsider');
        $this->assignRole($outsider, 'Collection Agent', ScopeType::Network);
        $this->assertSame(0, $engine->queueFor($outsider)->count());

        // And the stand-in can act, because the stage names the ROLE.
        $instance = $engine->approve($requisition->workflowInstance, $standIn, null, 'Covering.');
        $this->assertSame('Internal Audit', $instance->currentStage->name);
    }

    /**
     * BR-23 — "any user holding the stage's role AND SATISFYING SCOPE".
     *
     * The scope half did not exist. Department Head is a Department-scoped role
     * with one holder per department, and guardActionable() checked the role and
     * the stage's permission and never once asked whether the SUBJECT fell inside
     * the actor's data scope. The test above only ever used a same-department
     * stand-in, which is why it passed while the head of Finance could approve —
     * and under BR-22 re-price — a Logistics requisition that no list of theirs
     * would ever show them.
     */
    public function test_br23_a_department_head_from_another_department_is_refused(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;
        $engine = app(WorkflowEngine::class);

        $outsider = $this->headOfAnotherDepartment();

        // Layer 1 is fully satisfied: the role, and the permission the stage names.
        $this->assertTrue($outsider->hasPermission('purchase.approve.depthead.approve'));

        $this->actingAs($outsider);

        // Layer 2 is not: the record is outside their scope, and always was.
        $this->assertFalse(
            Requisition::query()->whereKey($requisition->getKey())->exists(),
            'The data scope already hides it from every list.',
        );

        $this->assertSame(
            0,
            $engine->queueFor($outsider)->count(),
            'So the queue must not advertise an item its holder cannot act on.',
        );

        try {
            $engine->approve($instance, $outsider, 1_00, 'Trimmed.');
            $this->fail('A Department Head must not act outside their own department.');
        } catch (AccessDeniedException $exception) {
            // SCOPE-3 — the same populated 403 as a missing permission.
            $this->assertSame(AccessDeniedException::REASON_SCOPE, $exception->reason);
            $this->assertSame('BR-23', $exception->detail['rule']);
        }

        $instance->refresh();

        $this->assertSame('Department Head', $instance->currentStage->name, 'Nothing advanced.');
        $this->assertSame(3_400_000_00, (int) $instance->approved_amount_minor, 'Nothing was re-priced.');

        // BR-34 / AUDIT-5 — the attempt is on the record.
        $this->assertDatabaseHas('audit_entries', [
            'event_type' => 'blocked_access',
            'reference' => $exception->reference,
            'deny_reason' => AccessDeniedException::REASON_SCOPE,
        ]);
    }

    /**
     * BR-24 — a delegation carries the DELEGATOR's scope, not the delegate's.
     *
     * The scope check added for BR-23 could easily have broken every delegation:
     * a delegate does not hold the delegated role, so asking about their own
     * scope for the stage's permission yields an empty set, and an empty set
     * denies (ROLE-2). The authority being exercised is the delegator's, and so
     * is the scope that goes with it.
     */
    public function test_br24_a_delegate_inherits_the_delegators_scope_and_no_more(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $engine = app(WorkflowEngine::class);

        $delegate = $this->makeUser('Scope Delegate');
        $this->assignRole($delegate, 'Internal Audit');

        Delegation::query()->create([
            'from_user_id' => $cast['deptHead']->id,
            'to_user_id' => $delegate->id,
            'role_id' => Role::query()->where('name', 'Department Head')->value('id'),
            'starts_on' => Wat::today()->subDay()->toDateString(),
            'ends_on' => Wat::today()->addDays(5)->toDateString(),
            'reason' => 'Department Head on leave.',
        ]);

        $delegate->forgetAccessMemo();
        $delegate = $delegate->fresh();

        // The delegate holds no purchasing-approval scope of their own at all.
        $this->assertTrue($delegate->scopeSetFor('purchase.approve.depthead.approve')->isEmpty());

        $this->assertSame(1, $engine->queueFor($delegate)->count(), 'The delegator\'s scope reaches the delegate.');

        $instance = $engine->approve($requisition->workflowInstance, $delegate, null, 'Covering.');

        $this->assertSame('Internal Audit', $instance->currentStage->name);
    }

    /** BR-23 — someone without the stage's role is refused with a 403. */
    public function test_br23_acting_without_the_stage_role_is_denied(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);

        // Internal Audit holds a different approval permission and a different
        // role; they cannot act at the Department Head stage.
        try {
            app(WorkflowEngine::class)->approve($requisition->workflowInstance, $cast['audit']);
            $this->fail('Acting at a stage whose role you do not hold must be denied.');
        } catch (AccessDeniedException $exception) {
            $this->assertSame('BR-23', $exception->detail['rule']);
        }
    }

    /**
     * BR-24 — "An active delegation routes the delegator's queue to the delegate
     * for the period. Delegated actions record both users."
     */
    public function test_br24_an_active_delegation_routes_the_queue_and_records_both(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $engine = app(WorkflowEngine::class);

        $delegate = $this->makeUser('Delegate');
        // The delegate holds no Department Head role of their own.
        $this->assignRole($delegate, 'Internal Audit');

        $this->assertSame(0, $engine->queueFor($delegate)->count());

        Delegation::query()->create([
            'from_user_id' => $cast['deptHead']->id,
            'to_user_id' => $delegate->id,
            'role_id' => Role::query()->where('name', 'Department Head')->value('id'),
            'starts_on' => Wat::today()->subDay()->toDateString(),
            'ends_on' => Wat::today()->addDays(5)->toDateString(),
            'reason' => 'Department Head on leave.',
        ]);

        $delegate->forgetAccessMemo();

        $this->assertSame(1, $engine->queueFor($delegate->fresh())->count(), 'The delegator\'s queue reaches the delegate.');

        $instance = $engine->approve($requisition->workflowInstance, $delegate->fresh(), null, 'Approved while covering.');

        $action = $instance->actions()->where('action', WorkflowAction::ACTION_APPROVE)->latest('id')->firstOrFail();

        $this->assertSame($delegate->id, (int) $action->actor_user_id, 'Who clicked.');
        $this->assertSame($cast['deptHead']->id, (int) $action->on_behalf_of_user_id, 'Whose queue it came from.');
        $this->assertNotNull($action->delegation_id);
    }

    /** BR-24 — an EXPIRED delegation routes nothing. */
    public function test_br24_an_expired_delegation_does_not_route(): void
    {
        $cast = $this->cast();
        $this->submit($cast, 3_400_000_00);

        $delegate = $this->makeUser('Expired Delegate');
        $this->assignRole($delegate, 'Internal Audit');

        Delegation::query()->create([
            'from_user_id' => $cast['deptHead']->id,
            'to_user_id' => $delegate->id,
            'role_id' => Role::query()->where('name', 'Department Head')->value('id'),
            'starts_on' => Wat::today()->subDays(30)->toDateString(),
            'ends_on' => Wat::today()->subDays(5)->toDateString(),
        ]);

        $delegate->forgetAccessMemo();

        $this->assertSame(0, app(WorkflowEngine::class)->queueFor($delegate->fresh())->count());
    }

    /** ST-1 — an action on a closed instance returns a rule violation. */
    public function test_st1_acting_on_a_closed_approval_is_refused(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 200_000_00);
        $instance = $requisition->workflowInstance;

        app(WorkflowEngine::class)->reject($instance, $cast['deptHead'], 'No.');

        try {
            app(WorkflowEngine::class)->approve($instance->refresh(), $cast['audit']);
            $this->fail('A closed approval must not accept another action.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ST-1', $exception->ruleId);
        }
    }

    /* ---------------------------------------------------------------------
     | The endpoints themselves
     |
     | §18.3 — "assert the refusal too". Nothing anywhere used to POST to
     | approvals.approve / reject / request-info or their /api twins: every
     | approval test drove WorkflowEngine directly, so the controllers, their
     | validation, the route binding and the responses were six uncovered write
     | endpoints. The consequence was not theoretical. An out-of-scope Department
     | Head POSTing to approvals.approve on a ₦3,400,000 requisition got
     | `HTTP 500 | status=in_progress | stage=Internal Audit |
     | approved_amount_minor=100 | audit rows written=0` — the money reduced to
     | ₦1.00 and the stage advanced by somebody with no scope over the
     | department, no audit entry (AUDIT-2 and BR-34 both skipped), and a white
     | 500 for the operator.
     * ------------------------------------------------------------------ */

    /** BR-23 — the web approve endpoint, from the approver who may. */
    public function test_br23_the_approve_endpoint_advances_the_stage_for_an_approver_in_scope(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        $this->post(route('approvals.approve', $instance), [
            'approved_amount' => '3000000.00',
            'comment' => 'Trimmed to budget.',
        ])->assertRedirect();

        $instance->refresh();

        $this->assertSame('Internal Audit', $instance->currentStage->name);
        $this->assertSame(3_000_000_00, (int) $instance->approved_amount_minor);

        // AUDIT-2 — and the approval reached the log, which is the half that
        // used to be lost when the post-commit audit call threw.
        $this->assertDatabaseHas('audit_entries', [
            'event_type' => 'approval',
            'subject_type' => Requisition::class,
            'subject_id' => $requisition->getKey(),
            'actor_user_id' => $cast['deptHead']->id,
        ]);
    }

    /** BR-23 — and the same endpoint, from the approver who may not. */
    public function test_br23_the_approve_endpoint_refuses_an_out_of_scope_department_head(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $outsider = $this->headOfAnotherDepartment();

        $this->flushSession();
        $this->actingAs($outsider);

        $this->post(route('approvals.approve', $instance), [
            'approved_amount' => '1.00',
            'comment' => 'Trimmed.',
        ])->assertStatus(403);

        $instance->refresh();

        $this->assertSame('Department Head', $instance->currentStage->name);
        $this->assertSame(3_400_000_00, (int) $instance->approved_amount_minor);
        $this->assertSame(0, $instance->actions()->where('action', WorkflowAction::ACTION_APPROVE)->count());

        $this->assertDatabaseHas('audit_entries', [
            'event_type' => 'blocked_access',
            'actor_user_id' => $outsider->id,
            'deny_reason' => AccessDeniedException::REASON_SCOPE,
        ]);
    }

    /** BR-18 — the endpoint refuses the requester their own submission, 403. */
    public function test_br18_the_approve_endpoint_refuses_self_approval(): void
    {
        $cast = $this->cast();
        $this->assignRole($cast['requester'], 'Department Head', ScopeType::Department, $cast['department']->id);

        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->post(route('approvals.approve', $instance), ['comment' => 'Mine, and fine.'])
            ->assertStatus(403);

        $this->assertSame('Department Head', $instance->refresh()->currentStage->name);
    }

    /** BR-17 — a later stage's approver posting at an earlier stage is refused. */
    public function test_br17_the_approve_endpoint_refuses_an_actor_at_the_wrong_stage(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->flushSession();
        $this->actingAs($cast['audit']->fresh());

        // Internal Audit is stage 3; the instance is at stage 2.
        $this->post(route('approvals.approve', $instance), ['comment' => 'Verified.'])
            ->assertStatus(403);

        $this->assertSame('Department Head', $instance->refresh()->currentStage->name);
    }

    /**
     * BR-20 — the web reject endpoint returns the subject to the requester, and
     * refuses the approver who has no scope over it.
     */
    public function test_br20_the_reject_endpoint_returns_the_subject_and_refuses_an_outsider(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $outsider = $this->headOfAnotherDepartment();

        $this->flushSession();
        $this->actingAs($outsider);

        $this->post(route('approvals.reject', $instance), ['comment' => 'No.'])->assertStatus(403);
        $this->assertSame(WorkflowInstance::STATUS_IN_PROGRESS, $instance->refresh()->status);

        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        $this->post(route('approvals.reject', $instance), [
            'comment' => 'Three quotations are required above ₦1m.',
        ])->assertRedirect();

        $this->assertSame(WorkflowInstance::STATUS_REJECTED, $instance->refresh()->status);

        $this->assertSame(
            Requisition::STATUS_REJECTED,
            $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey())->status),
            'BR-20 returns the SUBJECT, not only the instance.',
        );
    }

    /** BR-21 — the request-info endpoint records, notifies and moves nothing. */
    public function test_br21_the_request_info_endpoint_neither_advances_nor_ends(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $outsider = $this->headOfAnotherDepartment();

        $this->flushSession();
        $this->actingAs($outsider);

        $this->post(route('approvals.request-info', $instance), ['comment' => 'Which vehicles?'])
            ->assertStatus(403);

        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        $this->post(route('approvals.request-info', $instance), ['comment' => 'Which vehicles is this for?'])
            ->assertRedirect();

        $instance->refresh();

        $this->assertSame('Department Head', $instance->currentStage->name);
        $this->assertSame(WorkflowInstance::STATUS_IN_PROGRESS, $instance->status);

        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'action' => WorkflowAction::ACTION_REQUEST_INFO,
        ]);

        // The requester is asked, and the requisition stays exactly where it was.
        $this->assertSame(
            Requisition::STATUS_IN_REVIEW,
            $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey())->status),
        );
    }

    /**
     * BR-22 — the /api approval carries the reduced amount onto the subject.
     *
     * ApprovalApiController never called the subject sync that ApprovalsController
     * did by hand, so after a full API chain the instance read `approved` at the
     * reduced figure and the requisition read `in_review` with
     * `approved_total_minor` NULL — the number Accounts pays against, absent.
     */
    public function test_br22_an_api_approval_chain_carries_the_reduced_total_onto_the_requisition(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 200_000_00);
        $instance = $requisition->workflowInstance;

        $approvers = ['deptHead' => 150_000_00, 'audit' => null, 'accounts' => null];

        foreach ($approvers as $who => $amount) {
            $this->flushSession();
            $this->actingAs($cast[$who]->fresh());

            $this->postJson(route('api.approvals.approve', $instance), array_filter([
                'approved_amount' => $amount === null ? null : (string) ($amount / 100),
                'comment' => 'Verified.',
            ], fn ($value) => $value !== null))->assertOk();
        }

        $this->assertSame(WorkflowInstance::STATUS_APPROVED, $instance->refresh()->status);
        $this->assertSame(150_000_00, (int) $instance->approved_amount_minor);

        $settled = $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey()));

        $this->assertSame(Requisition::STATUS_APPROVED, $settled->status);
        $this->assertSame(150_000_00, (int) $settled->approved_total_minor);
        $this->assertNotNull($settled->decided_at);
    }

    /**
     * BR-20 — an /api rejection returns the subject to the requester, who may
     * revise and resubmit.
     *
     * It did not. The instance went to `rejected` and the requisition stayed
     * `in_review`, so `resubmit()` refused it ("only a rejected requisition"),
     * `submit()` refused it (neither draft nor rejected) and the instance was
     * closed, so nothing could ever sync it again. The requisition was stranded
     * with no code path out. This assertion replaces tests/Feature/Rules/
     * ZzProbe4Test.php, a probe that printed the stale state and then asserted
     * `assertTrue(true)` — and which GenerateRuleIndex credited as proof of BR-20.
     */
    public function test_br20_an_api_rejection_returns_the_requisition_and_it_can_be_resubmitted(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 200_000_00);
        $instance = $requisition->workflowInstance;

        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        $this->postJson(route('api.approvals.reject', $instance), ['comment' => 'Not this quarter.'])
            ->assertOk();

        $this->assertSame(WorkflowInstance::STATUS_REJECTED, $instance->refresh()->status);

        $settled = $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey()));

        $this->assertSame(Requisition::STATUS_REJECTED, $settled->status);
        $this->assertNotNull($settled->decided_at);

        // The point of BR-20: there is a way forward from here.
        $this->flushSession();
        $this->actingAs($cast['requester']->fresh());

        $revision = app(RequisitionService::class)->resubmit($settled, [], [], $cast['requester']->fresh());

        $this->assertSame($settled->getKey(), (int) $revision->revises_requisition_id);
        $this->assertNotSame($instance->getKey(), $revision->workflowInstance->getKey());
    }

    /** BR-23 — and the /api endpoint refuses the same outsider the screen does. */
    public function test_br23_the_api_approve_endpoint_refuses_an_out_of_scope_department_head(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->flushSession();
        $this->actingAs($this->headOfAnotherDepartment());

        $this->postJson(route('api.approvals.approve', $instance), ['approved_amount' => '1.00'])
            ->assertStatus(403);

        $instance->refresh();

        $this->assertSame(3_400_000_00, (int) $instance->approved_amount_minor);
        $this->assertSame('Department Head', $instance->currentStage->name);
    }

    /** BR-22 — the /api endpoint rejects a negative approved amount as a 422. */
    public function test_br22_the_api_approve_endpoint_refuses_a_negative_amount(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        $this->postJson(route('api.approvals.approve', $instance), ['approved_amount' => '-500.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approved_amount');

        $this->assertSame(3_400_000_00, (int) $instance->refresh()->approved_amount_minor);
    }

    /**
     * §18.5 / AUDIT-2 — an approval that cannot be audited does not happen.
     *
     * The audit call sat AFTER the DB::transaction had committed, against
     * `$instance->subject` — a morphTo resolved through the subject's own data
     * scope, which returns null for a subject the actor cannot see.
     * AuditLogger::approval() types that parameter as a non-nullable Model, so
     * the call threw a TypeError with the state change already on disk: the
     * stage advanced, `approved_amount_minor` changed, ZERO audit rows were
     * written, notifyRequester() was never reached and the approver got a 500
     * and believed nothing had happened. DM-3's append-only triggers protect
     * history that was written; they can do nothing for history that was never
     * written, so the ordering is the whole guarantee.
     *
     * This drives it from the other end — an audit writer that fails for any
     * reason at all — because the scope hole that used to produce the null is
     * now closed by BR-23 before the write is ever attempted.
     */
    public function test_audit2_an_approval_whose_audit_write_fails_is_rolled_back(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 3_400_000_00);
        $instance = $requisition->workflowInstance;

        $this->app->bind(AuditLogger::class, fn ($app) => new class($app->make(AuditContext::class)) extends AuditLogger
        {
            // The `$module` parameter arrived with Phase 7: approval() and
            // rejection() hardcoded 'Purchases', which filed a payroll or a
            // farmer payment approval where an auditor looking at that module
            // would never see it. The override has to carry the same signature.
            public function approval(
                Model $subject,
                string $summary,
                array $detail = [],
                ?User $actor = null,
                string $module = 'Purchases',
            ): AuditEntry {
                throw new \RuntimeException('The audit log is unavailable.');
            }
        });

        try {
            app(WorkflowEngine::class)->approve($instance, $cast['deptHead']->fresh(), 1_000_000_00, 'Trimmed.');
            $this->fail('An approval that cannot be audited must not stand.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The audit log is unavailable.', $exception->getMessage());
        }

        $instance->refresh();

        $this->assertSame('Department Head', $instance->currentStage->name, 'The stage did not advance.');
        $this->assertSame(3_400_000_00, (int) $instance->approved_amount_minor, 'The amount was not re-priced.');
        $this->assertSame(0, $instance->actions()->where('action', WorkflowAction::ACTION_APPROVE)->count());

        $this->assertSame(
            Requisition::STATUS_IN_REVIEW,
            $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey())->status),
        );
    }

    /**
     * §8 — `in_review → cancelled`, the transition the screens have always
     * offered as a filter and nothing could produce.
     *
     * WorkflowEngine::cancel() was complete and had no route, no controller, no
     * caller and no test, so a requisition raised in error could only leave an
     * approver's queue by being rejected — which under BR-20 then forces a whole
     * new instance if the purchase is ever wanted again.
     */
    public function test_st1_a_requester_may_withdraw_an_open_requisition_but_not_a_closed_one(): void
    {
        $cast = $this->cast();
        $requisition = $this->submit($cast, 200_000_00);
        $instance = $requisition->workflowInstance;
        $engine = app(WorkflowEngine::class);

        $engine->cancel($instance, $cast['requester']->fresh(), 'Ordered against an existing contract instead.');

        $this->assertSame(WorkflowInstance::STATUS_CANCELLED, $instance->refresh()->status);
        $this->assertNull($instance->current_stage_id);

        $this->assertSame(
            Requisition::STATUS_CANCELLED,
            $this->asSystem(fn () => Requisition::query()->findOrFail($requisition->getKey())->status),
            'The subject follows the instance, as it does for every other outcome.',
        );

        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'action' => WorkflowAction::ACTION_CANCEL,
        ]);

        // ST-1 — and a closed instance takes no further action.
        try {
            $engine->cancel($instance->refresh(), $cast['requester']->fresh());
            $this->fail('A cancelled instance must not be cancelled again.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ST-1', $exception->ruleId);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * A Department Head of a DIFFERENT department: the whole role, the whole
     * permission, and no scope over the requisition under test.
     */
    private function headOfAnotherDepartment(): User
    {
        $finance = $this->asSystem(
            fn () => Department::query()->create(['name' => 'Finance', 'status' => 'active']),
        );

        $head = $this->makeUser('Finance Head', ['department_id' => $finance->getKey()]);
        $this->assignRole($head, 'Department Head', ScopeType::Department, $finance->getKey());

        return $head->fresh();
    }

    /**
     * One user per approval stage, each with the role and scope that stage needs.
     *
     * @return array<string, mixed>
     */
    private function cast(): array
    {
        return $this->asSystem(function (): array {
            $department = Department::query()->create(['name' => 'Logistics', 'status' => 'active']);

            $requester = $this->makeUser('Idris Kabir', ['department_id' => $department->id]);
            $this->assignRole($requester, 'Logistics Officer', ScopeType::Network);

            $deptHead = $this->makeUser('Dept Head', ['department_id' => $department->id]);
            $this->assignRole($deptHead, 'Department Head', ScopeType::Department, $department->id);

            $audit = $this->makeUser('Umar Muduru');
            $this->assignRole($audit, 'Internal Audit');

            $ed = $this->makeUser('Mohammed Aliyu');
            $this->assignRole($ed, 'Executive Director');

            $accounts = $this->makeUser('Aliyu Danjuma');
            $this->assignRole($accounts, 'Accounts');

            $gm = $this->makeUser('Musa Abdulhamid');
            $this->assignRole($gm, 'General Manager');

            return compact('department', 'requester', 'deptHead', 'audit', 'ed', 'accounts', 'gm');
        });
    }

    private function submit(array $cast, int $amountMinor): Requisition
    {
        $this->actingAs($cast['requester']);

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'title' => 'Diesel — 2,500 L',
            'department_id' => $cast['department']->id,
            'category' => 'Fuel & lubricants',
            'urgency' => 'high',
        ], [
            ['item' => 'Diesel (AGO)', 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => $amountMinor],
        ], $cast['requester']);

        $service->submit($requisition, $cast['requester']);

        return $requisition->refresh()->load('workflowInstance');
    }

    /**
     * §4 — the approval queue admits every workflow-stage approver.
     *
     * The queue carries leave requests and payroll runs as well as requisitions.
     * Gated on `purchase.approve.*`, the HR Manager — who is named on the leave
     * and payroll stages — was refused their own queue, so those approvals sat
     * waiting on somebody the system had told to go away.
     */
    public function test_an_hr_manager_can_open_the_approval_queue(): void
    {
        $hr = $this->makeUser('Rahma Sule');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr);

        // They hold no purchasing approval at all — that was the whole problem.
        $this->assertFalse($hr->hasPermissionMatching('purchase.approve.'));
        $this->assertTrue($hr->hasPermission('hr.leave.approve'));

        $this->get(route('approvals.index'))->assertOk();

        // And the link is shown, not just the page reachable by typing the URL.
        $this->assertStringContainsString(
            route('approvals.index'),
            $this->get(route('dashboard'))->getContent(),
        );
    }

    /** §4 — somebody named on no stage at all still gets the standard refusal. */
    public function test_a_non_approver_is_refused_the_approval_queue(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Queueless Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $this->get(route('approvals.index'))->assertStatus(403);
    }

    /**
     * §4 — the admissions list is read from the stages, so a workflow that gains
     * a stage cannot lock its own approver out of the queue.
     */
    public function test_the_queue_gate_follows_the_workflow_stages(): void
    {
        $keys = RequireApprovalQueueAccess::approvalPermissionKeys();

        $this->assertNotEmpty($keys);

        // Every stage that requires a permission is represented...
        $expected = DB::table('workflow_stages')
            ->whereNull('deleted_at')
            ->whereNotNull('required_permission')
            ->where('is_submission', false)
            ->distinct()
            ->pluck('required_permission')
            ->all();

        $this->assertEqualsCanonicalizing($expected, $keys);

        // ...and raising a requisition is not approving one.
        $submissionOnly = DB::table('workflow_stages')
            ->where('is_submission', true)
            ->whereNotNull('required_permission')
            ->pluck('required_permission')
            ->diff($expected);

        foreach ($submissionOnly as $key) {
            $this->assertNotContains($key, $keys, 'A submission stage is not an approval stage.');
        }
    }

    /**
     * BR-23 — every active workflow stage points at a role that is active and held.
     *
     * A stage binds to `approving_role_id`. Retiring or renaming a role without
     * repointing its stages does not fail loudly: the workflow keeps running,
     * reaches that stage, and finds nobody to route to. Every requisition — or
     * the whole payroll — then sits waiting on an approver who does not exist,
     * and the only symptom is that things stop moving.
     *
     * This is the guard that makes the catalogue safe to reshape.
     */
    public function test_br23_every_active_stage_has_a_live_and_held_approver(): void
    {
        $problems = [];

        $stages = DB::table('workflow_stages')
            ->whereNull('deleted_at')
            ->where('is_submission', false)
            ->get();

        $this->assertNotEmpty($stages, 'There are workflows to check.');

        /*
         * Whether a role is VACANT is a fact about the dataset, not about the
         * workflow definition. In a bare test database nobody holds anything, so
         * the vacancy check only runs where there are staff to check against —
         * otherwise every stage reports a vacancy and the real structural
         * failures below get lost in the noise.
         */
        $staffed = DB::table('role_user')->whereNull('deleted_at')->exists();

        foreach ($stages as $stage) {
            $label = 'stage "'.$stage->name.'"';

            if ($stage->approving_role_id === null) {
                $problems[] = $label.' names no approving role';

                continue;
            }

            $role = Role::query()->find($stage->approving_role_id);

            if ($role === null) {
                $problems[] = $label.' points at role #'.$stage->approving_role_id.', which no longer exists';

                continue;
            }

            if ($role->status !== Role::STATUS_ACTIVE) {
                $problems[] = $label.' is approved by '.$role->name.', which is '.$role->status;
            }

            if ($staffed && $role->users()->count() === 0) {
                $problems[] = $label.' is approved by '.$role->name.', which nobody holds';
            }

            // The permission the stage requires must be one the role actually has,
            // or the approver reaches the item and is refused by their own stage.
            if ($stage->required_permission !== null
                && ! $role->livePermissions()->get()
                    ->contains(fn ($p) => $p->resource_key.'.'.$p->action === $stage->required_permission)) {
                $problems[] = $label.' requires '.$stage->required_permission
                    .', which '.$role->name.' does not hold';
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
