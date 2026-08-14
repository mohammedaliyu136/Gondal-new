<?php

namespace Tests\Feature\Acceptance;

use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\AuditEntry;
use App\Models\Batch;
use App\Models\Community;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExtensionAgent;
use App\Models\FieldActivity;
use App\Models\Grade;
use App\Models\GradeRate;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\QualityFollowup;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Requisition;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Hr\LeaveService;
use App\Services\Milk\AdjustmentService;
use App\Services\Milk\BatchService;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Services\Milk\QualityFollowupService;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\GondalTestCase;

/**
 * §14 — the build-order acceptance criteria, one test each.
 *
 * These are the sentences the PRD says must "demonstrably pass" before a phase is
 * accepted, so each test follows its criterion literally rather than testing a
 * mechanism in isolation. §18.6 is the requirement being met here.
 */
class PhaseAcceptanceTest extends GondalTestCase
{
    /**
     * §14 Phase 1 — "a test user assigned Milk Collection Officer scoped to
     * Kumbotso can open Kumbotso's center screen, is refused Dawakin Tofa's with a
     * populated access-denied page, is refused payroll, and both refusals appear in
     * the audit log."
     */
    public function test_phase1_authorisation_foundation(): void
    {
        $world = $this->makeMilkWorld();

        // TEST-1 — a test account, as the protocol requires.
        $testUser = $this->makeUser('Perm Test', ['is_test' => true, 'email' => 'perm.test@gondalfulbe.ng']);
        $this->assignRole($testUser, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);

        $this->actingAs($testUser->fresh());

        // 1. Kumbotso's center screen opens.
        $this->get(route('collection-centers.show', $world['centerA']))
            ->assertOk()
            ->assertSee('Kumbotso Center');

        // 2. Dawakin Tofa's is refused, with the page populated.
        $refusedCenter = $this->get(route('collection-centers.show', $world['centerB']));

        $refusedCenter->assertStatus(403);
        $refusedCenter->assertSee('You don&rsquo;t have access to this page', false);
        $refusedCenter->assertSee('milk.consignment.confirm.view');
        $refusedCenter->assertSee('Milk Collection Officer');
        $refusedCenter->assertSee('Kumbotso Center only');
        $refusedCenter->assertSee('Collection center → Dawakin Tofa', false);

        // 3. Payroll is refused.
        $refusedPayroll = $this->get(route('payroll.index'));

        $refusedPayroll->assertStatus(403);
        $refusedPayroll->assertSee('hr.payroll.view');

        // 4. Both refusals are in the audit log, with the missing permission, the
        //    reason, a quotable reference, and tagged as test activity (TEST-4).
        $denials = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_BLOCKED_ACCESS)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $denials);

        $this->assertSame('milk.consignment.confirm.view', $denials[0]->missing_permission);
        $this->assertSame('scope', $denials[0]->deny_reason);

        $this->assertSame('hr.payroll.view', $denials[1]->missing_permission);
        $this->assertSame('permission', $denials[1]->deny_reason);

        foreach ($denials as $denial) {
            $this->assertStringStartsWith('DENY-', $denial->reference);
            $this->assertTrue((bool) $denial->is_test);
            $this->assertSame($testUser->id, (int) $denial->actor_user_id);
        }

        // And an administrator can find them on the audit screen by reference.
        $admin = $this->makeUser('Phase1 Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->flushSession();
        $this->actingAs($admin);

        $this->get(route('admin.audit-log', ['reference' => $denials[0]->reference, 'include_test' => 1]))
            ->assertOk()
            ->assertSee($denials[0]->reference);
    }

    /**
     * §14 Phase 2 — "changing the Grade A rate creates a new effective-dated row,
     * and a delivery confirmed yesterday still reports yesterday's rate."
     */
    public function test_phase2_reference_data_and_settings(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Phase2 Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        // A consignment confirmed YESTERDAY, at yesterday's rate.
        $yesterday = $this->confirmedConsignment(
            $world,
            $officer->fresh(),
            '100.00',
            $gradeA,
            confirmedAt: Wat::todayAt(8, 0)->subDay(),
            deliveredAt: Wat::todayAt(6, 0)->subDay(),
        );

        $this->assertSame(25_000, (int) $yesterday->rate_per_litre_minor);

        // The administrator changes the rate, effective today.
        $admin = $this->makeUser('Phase2 Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->flushSession();
        $this->actingAs($admin);

        $ratesBefore = GradeRate::query()->where('grade_id', $gradeA->id)->count();

        $this->post(route('admin.settings.grades.store'), [
            'grade_id' => $gradeA->id,
            'rate_per_litre' => '275.00',
            'effective_from' => Wat::today()->toDateString(),
        ])->assertRedirect();

        // A NEW effective-dated row, not an edit of the old one.
        $this->assertSame($ratesBefore + 1, GradeRate::query()->where('grade_id', $gradeA->id)->count());
        $this->assertSame(27_500, (int) $gradeA->refresh()->currentRate()->rate_per_litre_minor);

        // Yesterday's consignment still reports yesterday's rate.
        $this->assertSame(25_000, (int) $yesterday->refresh()->rate_per_litre_minor);
        $this->assertSame(
            Money::valueVolume('100.00', 25_000),
            $yesterday->payableValueMinor(),
            'The payable figure is unchanged, because it reads the snapshot.',
        );

        // Something confirmed TODAY picks up the new rate.
        $this->flushSession();
        $this->actingAs($officer->fresh());

        $today = $this->confirmedConsignment($world, $officer->fresh(), '100.00', $gradeA->refresh());

        $this->assertSame(27_500, (int) $today->rate_per_litre_minor);
    }

    /**
     * §14 Phase 3 — "a farmer's 34 L can be traced from DEL-#### through CNS-####
     * and BATCH-#### to factory intake, with 6 L rejected for adulteration, a −1 L
     * adjustment, Grade A, and a payable volume of 27 L at the snapshotted rate."
     */
    public function test_phase3_milk_collection_chain_is_traceable_end_to_end(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Sani Bello');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $officer = $this->makeUser('Halima Yusuf');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);

        $supervisor = $this->makeUser('Muhammad Bello');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        // 34 L presented, 6 L rejected for adulteration → 28 L accepted.
        $this->actingAs($agent->fresh());

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '34.00',
            'litres_rejected' => '6.00',
            'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            'containers' => 2,
            'delivered_at' => Wat::todayAt(6, 15),
        ], $agent->fresh());

        $this->assertStringStartsWith('DEL-', $delivery->reference);
        $this->assertSame('28.00', (string) $delivery->litres_accepted);
        $this->assertSame(Delivery::STATUS_PARTIAL, $delivery->status);

        // Dispatched as a consignment.
        $consignment = app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['containers' => 2, 'dispatched_at' => Wat::todayAt(7, 10)],
            $agent->fresh(),
        );

        $this->assertStringStartsWith('CNS-', $consignment->reference);
        $this->assertSame('28.00', (string) $consignment->litres_dispatched);

        // A −1 L adjustment at the center, with a reason and an explanation.
        $this->flushSession();
        $this->actingAs($officer->fresh());

        app(AdjustmentService::class)->record(
            $consignment,
            '-1.00',
            AdjustmentReason::query()->where('code', 'ADJ-CONT')->value('id'),
            'One litre lost decanting into the center can.',
            $officer->fresh(),
        );

        // Quality tests, then Grade A.
        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.031' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $officer->fresh(),
            );
        }

        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        $consignment = app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'grade_id' => $gradeA->id,
            'intake_temperature_c' => '18.00',
            'confirmed_at' => Wat::todayAt(8, 5),
        ], $officer->fresh());

        // 28 − 1 = 27 L payable, at the snapshotted ₦250/L.
        $this->assertSame('27.00', (string) $consignment->litres_confirmed);
        $this->assertSame(Consignment::STATUS_ADJUSTED, $consignment->status);
        $this->assertSame($gradeA->id, $consignment->grade_id);
        $this->assertSame(25_000, (int) $consignment->rate_per_litre_minor);
        $this->assertSame(675_000, $consignment->payableValueMinor(), '27 L × ₦250 = ₦6,750.00');

        // §17 — and 2% cooperative levy leaves ₦6,615.00 net.
        $levy = Money::percentageOf($consignment->payableValueMinor(), $world['cooperative']->levy_pct);
        $this->assertSame(661_500, $consignment->payableValueMinor() - $levy);

        // Batched to the factory.
        $this->flushSession();
        $this->actingAs($supervisor->fresh());

        $batch = app(BatchService::class)->dispatch(
            $world['centerA'],
            [$consignment->id],
            ['containers' => 2, 'dispatched_at' => Wat::todayAt(8, 45)],
            $supervisor->fresh(),
        );

        $this->assertStringStartsWith('BATCH-', $batch->reference);
        $this->assertSame('27.00', (string) $batch->litres_dispatched);

        // Reconciled at factory intake, within tolerance, and released.
        app(BatchService::class)->reconcile($batch, [
            'litres_received' => '27.00',
            'containers_received' => 2,
            'reconciled_at' => Wat::todayAt(9, 40),
        ], $supervisor->fresh());

        $batch->refresh();

        $this->assertSame('0.00', (string) $batch->discrepancy_litres);
        $this->assertFalse($batch->exceedsTolerance());
        $this->assertSame(Batch::STATUS_RECONCILED, $batch->status);

        app(BatchService::class)->release($batch, null, $supervisor->fresh());
        $this->assertSame(Batch::STATUS_RELEASED, $batch->refresh()->status);

        // THE TRACE: from the delivery reference to factory intake, in one walk.
        $traced = Delivery::withoutDataScope()
            ->with(['farmer', 'consignment.grade', 'consignment.batch', 'adjustments'])
            ->where('reference', $delivery->reference)
            ->firstOrFail();

        $this->assertSame('Zainab Idris', $traced->farmer->name);
        $this->assertSame('34.00', (string) $traced->litres_presented);
        $this->assertSame('6.00', (string) $traced->litres_rejected);
        $this->assertSame('Adulteration', $traced->rejectionReason->name);
        $this->assertSame($consignment->reference, $traced->consignment->reference);
        $this->assertSame('-1.00', (string) $traced->consignment->adjustments->first()->litres_delta);
        $this->assertSame('Grade A', $traced->consignment->grade->name);
        $this->assertSame($batch->reference, $traced->consignment->batch->reference);
        $this->assertSame('27.00', (string) $traced->consignment->batch->litres_received);

        // And the whole chain is on the audit trail.
        foreach ([Delivery::class, Consignment::class, Batch::class] as $subject) {
            $this->assertTrue(
                AuditEntry::query()->where('subject_type', $subject)->exists(),
                $subject.' must appear in the audit log.',
            );
        }
    }

    /**
     * §14 Phase 4 — "a ₦3.4m requisition traverses all six stages while a ₦200k one
     * skips ED and GM; the requester cannot approve their own; a rejection returns
     * it and resubmission creates a new instance."
     */
    public function test_phase4_purchases_and_workflow_engine(): void
    {
        $cast = $this->approvalCast();
        $engine = app(WorkflowEngine::class);
        $service = app(RequisitionService::class);

        // A ₦3.4m requisition: all six stages.
        $major = $this->submitRequisition($cast, 3_400_000_00, 'Diesel — 2,500 L');

        $this->assertSame('Major', $major->workflowInstance->band->name);
        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Executive Director', 'Accounts', 'General Manager'],
            $major->workflowInstance->applicableStages()->pluck('name')->all(),
        );

        // A ₦200k one skips ED and GM.
        $standard = $this->submitRequisition($cast, 200_000_00, 'Milk cans — 40 units');

        $this->assertSame('Standard', $standard->workflowInstance->band->name);
        $this->assertSame(
            ['Raised by user', 'Department Head', 'Internal Audit', 'Accounts'],
            $standard->workflowInstance->applicableStages()->pluck('name')->all(),
        );

        // The requester cannot approve their own, even holding the permission.
        $this->assignRole($cast['requester'], 'Department Head', ScopeType::Department, $cast['department']->id);

        try {
            $engine->approve($major->workflowInstance, $cast['requester']->fresh());
            $this->fail('BR-18 — a requester must never approve their own submission.');
        } catch (AccessDeniedException $exception) {
            $this->assertSame('BR-18', $exception->detail['rule']);
        }

        // The standard one runs its four stages to approval.
        $instance = $standard->workflowInstance;
        foreach (['Department Head' => $cast['deptHead'], 'Internal Audit' => $cast['audit'], 'Accounts' => $cast['accounts']] as $stage => $approver) {
            $this->assertSame($stage, $instance->currentStage->name);
            $instance = $engine->approve($instance, $approver, null, 'Verified.');
        }

        $this->assertSame(WorkflowInstance::STATUS_APPROVED, $instance->status);
        $service->syncFromWorkflow($standard->refresh());
        $this->assertSame(Requisition::STATUS_APPROVED, $standard->refresh()->status);

        // A rejection returns the major one, and resubmission creates a NEW instance.
        $firstInstance = $major->refresh()->workflowInstance;

        $engine->reject($firstInstance, $cast['deptHead'], 'Three quotations required above ₦1m.');
        $service->syncFromWorkflow($major->refresh());

        $this->assertSame(Requisition::STATUS_REJECTED, $major->refresh()->status);

        $this->flushSession();
        $this->actingAs($cast['requester']->fresh());

        $revision = $service->resubmit(
            $major->refresh(),
            ['title' => 'Diesel — 2,500 L (three quotations attached)'],
            [],
            $cast['requester']->fresh(),
        );

        $this->assertNotSame($firstInstance->id, $revision->workflowInstance->id, 'A NEW instance.');
        $this->assertSame($major->id, (int) $revision->revises_requisition_id);
        $this->assertSame(
            WorkflowInstance::STATUS_REJECTED,
            $firstInstance->refresh()->status,
            'The old instance is retained.',
        );
        $this->assertSame(
            'Three quotations required above ₦1m.',
            $firstInstance->actions()->where('action', 'reject')->value('comment'),
        );
    }

    /**
     * §14 Phase 5 — "a third adulteration rejection within 30 days opens a follow-up
     * automatically and closing it requires a logged field activity."
     */
    public function test_phase5_community_engagement(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Phase5 Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $adulteration = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();

        /*
         * BR-3's backdating backstop refuses a 20-day-old delivery, which is what
         * it is for — nobody keys three weeks of milk in on a Thursday. This test
         * is arranging the farmer's HISTORY so the 30-day window has something to
         * measure, so it turns the backstop off the way an administrator would.
         */
        Settings::put(['milk.delivery_backdate_limit_days' => 0]);

        // Three adulteration rejections inside the 30-day window.
        foreach ([20, 10, 0] as $daysAgo) {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '30.00',
                'litres_rejected' => '6.00',
                'rejection_reason_id' => $adulteration->id,
                'delivered_at' => Wat::todayAt(6, 0)->subDays($daysAgo),
            ], $agent->fresh());
        }

        // A follow-up opened itself.
        $followup = QualityFollowup::query()->firstOrFail();

        $this->assertSame(QualityFollowup::STATUS_OPEN, $followup->status);
        $this->assertSame(3, (int) $followup->trigger_count);
        $this->assertSame($world['farmer']->id, (int) $followup->subject_id);

        // And the extension team was told (NOTIF-2 filtered it to those who could
        // actually open it).
        $extensionOfficer = $this->makeUser('Phase5 Extension Officer');
        $this->assignRole(
            $extensionOfficer,
            'Community Engagement Officer',
            ScopeType::Communities,
            null,
            Community::query()->pluck('id')->all(),
        );

        // A second rejection round now that a recipient exists, to prove the notice.
        app(DeliveryService::class)->record($world['pointA'], $world['farmerB'], [
            'litres_presented' => '30.00',
            'litres_rejected' => '6.00',
            'rejection_reason_id' => $adulteration->id,
            'delivered_at' => Wat::todayAt(6, 30),
        ], $agent->fresh());

        // Closing the follow-up requires a LOGGED field activity: the service will
        // not close it on its own.
        $extensionAgent = $this->asSystem(function () use ($extensionOfficer, $world): ExtensionAgent {
            $agentRecord = ExtensionAgent::query()->create([
                'user_id' => $extensionOfficer->id,
                'code' => 'EXT-001',
                'visit_target_monthly' => 20,
                'status' => 'active',
            ]);

            $agentRecord->communities()->attach($world['communityA']->id, ['assigned_at' => Wat::now()]);

            return $agentRecord;
        });

        $this->flushSession();
        $this->actingAs($extensionOfficer->fresh());

        // An activity type the administrator has NOT allowed to close one is refused.
        $enrolment = ActivityType::query()->where('code', 'ENROLMENT')->firstOrFail();
        $this->assertFalse((bool) $enrolment->closes_quality_followup);

        $wrongKind = FieldActivity::query()->create([
            'reference' => 'ACT-9001',
            'extension_agent_id' => $extensionAgent->id,
            'activity_type_id' => $enrolment->id,
            'community_id' => $world['communityA']->id,
            'activity_date' => Wat::today()->toDateString(),
            'farmers_reached' => 1,
            'source' => 'web',
        ]);

        try {
            app(QualityFollowupService::class)->close($followup, $wrongKind, $extensionOfficer->fresh());
            $this->fail('An enrolment activity must not close a quality follow-up.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-5', $exception->ruleId);
        }

        $this->assertSame(QualityFollowup::STATUS_OPEN, $followup->refresh()->status);

        // Logging a visit through the screen closes it.
        $visit = ActivityType::query()->where('code', 'VISIT')->firstOrFail();

        $this->post(route('field-activities.store'), [
            'extension_agent_id' => $extensionAgent->id,
            'activity_type_id' => $visit->id,
            'community_id' => $world['communityA']->id,
            'farmer_id' => $world['farmer']->id,
            'activity_date' => Wat::today()->toDateString(),
            'farmers_reached' => 1,
            'topic' => 'Clean milk production',
            'findings' => 'Advised on udder hygiene and water sourcing.',
            'closes_followup_id' => $followup->id,
        ])->assertRedirect();

        $followup->refresh();

        $this->assertSame(QualityFollowup::STATUS_CLOSED, $followup->status);
        $this->assertNotNull($followup->closed_by_activity_id);
        $this->assertNotNull($followup->closed_at);
        $this->assertSame($extensionOfficer->id, (int) $followup->closed_by_user_id);

        // The closing activity is a real, referenced record.
        $activity = FieldActivity::withoutDataScope()->findOrFail($followup->closed_by_activity_id);
        $this->assertStringStartsWith('ACT-', $activity->reference);
        $this->assertSame('Clean milk production', $activity->topic);
    }

    /**
     * §14 Phase 6 — "the shop manager creates a category and it is immediately
     * sellable; a sales officer records a sale, sees no revenue total, and stock
     * decrements atomically."
     */
    public function test_phase6_one_stop_shop(): void
    {
        $manager = $this->makeUser('Amina Kabir');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager->fresh());

        // The manager creates a category — no deployment, no code change.
        $this->post(route('shop.categories.store'), [
            'code' => 'DRUGS',
            'name' => 'Veterinary drugs',
            'default_unit' => 'dose',
            'default_reorder_level' => 20,
            'requires_prescription' => '1',
            'track_expiry' => '1',
        ])->assertRedirect();

        $category = ProductCategory::query()->where('code', 'DRUGS')->firstOrFail();
        $this->assertSame('active', $category->status);

        // It is immediately sellable: a product can be filed under it and sold.
        $this->post(route('shop.products.store'), [
            'sku' => 'VET-OXY',
            'name' => 'Oxytetracycline injection',
            'product_category_id' => $category->id,
            'selling_price' => '6200.00',
            'cost_price' => '4500.00',
        ])->assertRedirect();

        $product = Product::query()->where('sku', 'VET-OXY')->firstOrFail();

        $this->post(route('shop.products.stock', $product), [
            'batch_no' => 'B-001',
            'supplier' => 'Kano Agro Supplies',
            'quantity_received' => '40',
            'expiry_on' => Wat::today()->addMonths(9)->toDateString(),
            'unit_cost' => '4500.00',
        ])->assertRedirect();

        $this->assertSame('40.00', (string) $product->refresh()->quantity_on_hand);

        // A sales officer, who is NOT granted shop.revenue.
        $officer = $this->makeUser('Hauwa Ibrahim');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);

        $this->assertTrue($officer->fresh()->hasPermission('shop.sales.create'));
        $this->assertFalse($officer->fresh()->hasPermission('shop.revenue.view'));

        $this->flushSession();
        $this->actingAs($officer->fresh());

        // BR-27 — the category requires a prescription, so a sale without one fails.
        $this->post(route('shop.sales.store'), [
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
            'items' => [['product_id' => $product->id, 'quantity' => '2']],
        ])->assertSessionHasErrors();

        $this->assertSame('40.00', (string) $product->refresh()->quantity_on_hand, 'Nothing moved.');

        // With a prescription reference it goes through, and stock decrements.
        $this->post(route('shop.sales.store'), [
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'customer_name' => 'Walk-in customer',
            'payment_method' => Sale::PAYMENT_CASH,
            'prescription_reference' => 'RX-2026-0031',
            'items' => [['product_id' => $product->id, 'quantity' => '3']],
        ])->assertRedirect();

        $this->assertSame('37.00', (string) $product->refresh()->quantity_on_hand);

        $sale = Sale::withoutDataScope()->latest('id')->firstOrFail();
        $this->assertSame(1_860_000, (int) $sale->total_minor, '3 × ₦6,200.00');

        // The ledger agrees with the balance, in the same transaction.
        $movement = StockMovement::query()
            ->where('sale_id', $sale->id)
            ->firstOrFail();

        $this->assertSame('3.00', (string) $movement->quantity_out);
        $this->assertSame('37.00', (string) $movement->balance_after);

        // And the officer sees no revenue total anywhere on the screen.
        $sales = $this->get(route('shop.sales.index'));

        $sales->assertOk();
        $sales->assertSee($sale->receipt_no);
        // The screen no longer names the raw permission key at a sales officer.
        // What BR-29 actually requires is that the figures are withheld, so that
        // is what is asserted: the tiles are present but empty, and no revenue or
        // margin total is rendered anywhere.
        $sales->assertSee('You see your own transactions, not revenue.', false);
        $sales->assertSee('not shown to your role', false);
        $sales->assertDontSee('Margin today', false);

        // A sale beyond stock is refused rather than driving it negative.
        $this->post(route('shop.sales.store'), [
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
            'prescription_reference' => 'RX-2026-0032',
            'items' => [['product_id' => $product->id, 'quantity' => '500']],
        ])->assertSessionHasErrors();

        $this->assertSame('37.00', (string) $product->refresh()->quantity_on_hand);
    }

    /**
     * §14 Phase 7 — FARMER PAYMENT, now built; TRANSPORT PAYMENT, still not.
     *
     * This test used to assert the ABSENCE of a payment module, and that was the
     * honest thing to assert for six phases. Farmer payment now exists
     * (docs/PLAN-FARMER-PAYMENTS.md), so the assertion is inverted for that half
     * and kept exactly as it was for the half that is still missing — because a
     * half-built phase is precisely where a silent gap hides.
     *
     * What is deliberately still true: the rate snapshot (BR-14) and the
     * cooperative percentages (BR-15) are captured at confirmation, before any
     * payment reads them, so the arithmetic never depends on today's rate.
     */
    public function test_phase7_farmer_payment_is_built_and_transport_payment_is_not(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->implode(' ');

        // Farmer payment: built.
        $this->assertStringContainsString('payment-runs', $routes);
        $this->assertStringContainsString('farmer-payments', $routes);

        /*
         * Transport payment: NOT built. `trips.payment_run_id` remains the
         * unconstrained placeholder it always was — it does NOT point at
         * `payment_runs`, which pays farmers, and wiring it there would silently
         * conflate two different kinds of money.
         */
        $this->assertTrue(Schema::hasColumn('trips', 'payment_run_id'));
        $this->assertSame(0, Trip::withoutDataScope()->whereNotNull('payment_run_id')->count());

        // What Phase 7 will need IS captured: the rate snapshot (BR-14), the
        // cooperative percentages (BR-15), the transport tariff snapshot, and
        // BR-30's pending deductions.
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Phase7 Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        $consignment = $this->confirmedConsignment(
            $world,
            $officer->fresh(),
            '27.00',
            Grade::query()->where('code', 'GRD-A')->firstOrFail(),
        );

        $this->assertNotNull($consignment->grade_rate_id);
        $this->assertSame(25_000, (int) $consignment->rate_per_litre_minor);
        $this->assertSame(675_000, $consignment->payableValueMinor());

        $this->assertSame('5.00', (string) $world['cooperative']->savings_deduction_pct);
        $this->assertSame('2.00', (string) $world['cooperative']->levy_pct);

        // And Accounts can now reach the farmer payment module rather than a
        // screen apologising for its absence.
        $accounts = $this->makeUser('Phase7 Accounts');
        $this->assignRole($accounts, 'Accounts');
        $this->flushSession();
        $this->actingAs($accounts->fresh());

        $this->get(route('payment-runs.index'))->assertOk()->assertSee('Farmer Payments');
    }

    /**
     * §14 Phase 8 — HR and payroll. The PRD gives no explicit acceptance sentence,
     * so this asserts the module's own contracts: leave routes through the
     * configurable workflow, payroll excludes test accounts (BR-35), and a member
     * of staff can reach their own payslip and nobody else's.
     */
    public function test_phase8_hr_and_payroll(): void
    {
        $cast = $this->approvalCast();

        // An employee raising leave through the workflow.
        $employee = $this->asSystem(fn () => Employee::query()->create([
            'code' => 'EMP-9001',
            'name' => 'Leave Taker',
            'department_id' => $cast['department']->id,
            'gross_monthly_minor' => 350_000_00,
            'status' => 'confirmed',
            'joined_on' => Wat::today()->subYears(3)->toDateString(),
        ]));

        $staff = $this->makeUser('Leave Taker', ['department_id' => $cast['department']->id]);
        $staff->forceFill(['employee_id' => $employee->id])->save();

        // ROLE-3 — no role assigned by hand, yet they can raise their own leave.
        $this->actingAs($staff->fresh());

        $this->post(route('leave.store'), [
            'leave_type_id' => LeaveType::query()->where('code', 'ANNUAL')->value('id'),
            'starts_on' => Wat::today()->addDays(7)->toDateString(),
            'ends_on' => Wat::today()->addDays(9)->toDateString(),
            'reason' => 'Family commitment',
            'submit' => '1',
        ])->assertRedirect();

        $request = LeaveRequest::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertSame(LeaveRequest::STATUS_IN_REVIEW, $request->status);
        $this->assertSame(3, (int) $request->days);
        $this->assertNotNull($request->workflow_instance_id);
        $this->assertSame('Department Head', $request->workflowInstance->currentStage->name);

        // The Department Head approves it, and the subject follows the workflow.
        $this->flushSession();
        $this->actingAs($cast['deptHead']->fresh());

        app(WorkflowEngine::class)->approve($request->workflowInstance, $cast['deptHead']->fresh(), null, 'Approved.');
        app(LeaveService::class)->syncFromWorkflow($request->refresh());

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->refresh()->status);

        // Payroll: a test account gets no payslip (BR-35).
        $testStaff = $this->makeUser('Payroll Test Account', ['is_test' => true]);
        $testEmployee = $this->asSystem(fn () => Employee::query()->create([
            'code' => 'EMP-9002',
            'name' => 'Payroll Test Account',
            'department_id' => $cast['department']->id,
            'gross_monthly_minor' => 999_999_00,
            'status' => 'confirmed',
        ]));
        $testStaff->forceFill(['employee_id' => $testEmployee->id])->save();

        $this->flushSession();
        $this->actingAs($cast['accounts']->fresh());

        $this->post(route('payroll.store'), [
            'period_year' => (int) Wat::local()->format('Y'),
            'period_month' => (int) Wat::local()->format('n'),
        ])->assertRedirect();

        $run = PayrollRun::query()->latest('id')->firstOrFail();
        $payslips = Payslip::withoutDataScope()->where('payroll_run_id', $run->id)->with('employee')->get();

        $this->assertTrue($payslips->contains(fn ($p) => $p->employee->code === 'EMP-9001'));
        $this->assertFalse($payslips->contains(fn ($p) => $p->employee->code === 'EMP-9002'));

        // NFR-5 — the totals are integers and they add up.
        $this->assertSame(
            (int) $run->gross_total_minor - (int) $run->deductions_total_minor,
            (int) $run->net_total_minor,
        );

        // The staff member reaches their OWN payslip through hr.payslip.own.
        $own = $payslips->firstWhere('employee_id', $employee->id);
        $this->assertNotNull($own);

        $this->flushSession();
        $this->actingAs($staff->fresh());

        $this->get(route('payroll.payslips.show', $own))
            ->assertOk()
            ->assertSee($own->reference)
            ->assertSee('This is your own payslip', false);

        // But not the payroll run, and not somebody else's payslip.
        $this->get(route('payroll.index'))->assertStatus(403);

        $otherPayslip = $payslips->first(fn ($p) => $p->employee_id !== $employee->id);

        if ($otherPayslip !== null) {
            $this->get(route('payroll.payslips.show', $otherPayslip))->assertStatus(403);
        }
    }

    /* ------------------------------------------------------------------ */

    private function confirmedConsignment(
        array $world,
        User $actor,
        string $litres,
        Grade $grade,
        ?Carbon $confirmedAt = null,
        ?Carbon $deliveredAt = null,
    ): Consignment {
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => $litres,
            'delivered_at' => $deliveredAt ?? Wat::todayAt(6, 0),
        ], $actor);

        $consignment = app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['dispatched_at' => $deliveredAt?->copy()->addHour() ?? Wat::todayAt(7, 0)],
            $actor,
        );

        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $actor,
            );
        }

        return app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'grade_id' => $grade->id,
            'confirmed_at' => $confirmedAt ?? Wat::todayAt(8, 0),
        ], $actor);
    }

    /** @return array<string, mixed> */
    private function approvalCast(): array
    {
        return $this->asSystem(function (): array {
            $department = Department::query()->create(['name' => 'Logistics', 'status' => 'active']);

            $requester = $this->makeUser('Idris Kabir', ['department_id' => $department->id]);
            $this->assignRole($requester, 'Logistics Officer', ScopeType::Network);

            $deptHead = $this->makeUser('Phase Dept Head', ['department_id' => $department->id]);
            $this->assignRole($deptHead, 'Department Head', ScopeType::Department, $department->id);

            $audit = $this->makeUser('Umar Muduru');
            $this->assignRole($audit, 'Internal Audit');

            $accounts = $this->makeUser('Aliyu Danjuma');
            $this->assignRole($accounts, 'Accounts');
            $this->assignRole($accounts, 'HR Manager');

            return compact('department', 'requester', 'deptHead', 'audit', 'accounts');
        });
    }

    private function submitRequisition(array $cast, int $amountMinor, string $title): Requisition
    {
        $this->flushSession();
        $this->actingAs($cast['requester']->fresh());

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'title' => $title,
            'department_id' => $cast['department']->id,
            'category' => 'Operations',
            'urgency' => 'normal',
        ], [
            ['item' => $title, 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => $amountMinor],
        ], $cast['requester']->fresh());

        $service->submit($requisition, $cast['requester']->fresh());

        return $requisition->refresh()->load('workflowInstance');
    }
}
