<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\Actions\RequisitionAdjustItemsAction;
use App\Services\Workflow\StageActionRegistry;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use Tests\GondalTestCase;

class RequisitionStageActionTest extends GondalTestCase
{
    public function test_stage_action_registry_resolves_and_filters_handlers(): void
    {
        $registry = app(StageActionRegistry::class);

        $this->assertTrue($registry->has('requisition.adjust_items'));
        $handler = $registry->get('requisition.adjust_items');
        $this->assertInstanceOf(RequisitionAdjustItemsAction::class, $handler);

        $requisitionActions = $registry->forAppliesTo('requisition');
        $this->assertTrue($requisitionActions->has('requisition.adjust_items'));

        $otherActions = $registry->forAppliesTo('unrelated_module');
        $this->assertFalse($otherActions->has('requisition.adjust_items'));
    }

    public function test_can_attach_stage_action_to_workflow_stage(): void
    {
        $role = Role::firstOrCreate(['name' => 'Department Head'], ['status' => 'active']);

        $workflow = Workflow::create([
            'code' => 'WF-REQ-TEST-' . substr(uniqid(), 0, 5),
            'name' => 'Requisition Test Workflow',
            'applies_to' => 'requisition',
            'status' => 'active',
            'options' => ['strict_sequence' => true, 'approver_may_reduce_amount' => true],
        ]);

        $stage = $workflow->stages()->create([
            'position' => 1,
            'name' => 'Department Head Review',
            'approving_role_id' => $role->id,
            'condition_type' => 'always',
            'can_reject' => true,
            'is_submission' => false,
            'stage_action' => 'requisition.adjust_items',
        ]);

        $this->assertTrue($stage->hasStageAction());
        $this->assertInstanceOf(RequisitionAdjustItemsAction::class, $stage->stageActionHandler());
        $this->assertSame('Adjust Line Items & Quantities (HOD)', $stage->stageActionHandler()->label());
    }

    public function test_approving_with_requisition_adjust_items_action_updates_items_and_totals(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Operations'], ['code' => 'OPS']);
        $role = Role::firstOrCreate(['name' => 'Department Head'], ['status' => 'active']);

        $requester = $this->makeUser('Requisition Requester', ['department_id' => $dept->id]);
        $approver = $this->makeUser('Department Head Approver', ['department_id' => $dept->id]);
        $this->assignRole($approver, 'Department Head');

        $workflow = Workflow::query()->where('code', 'WF-001')->firstOrFail();
        $stage2 = $workflow->stages()->where('position', 2)->firstOrFail();
        $stage2->update(['stage_action' => 'requisition.adjust_items']);

        // Create a requisition with 2 items: 10 * 1,000 (10,000) + 5 * 2,000 (10,000) = total 20,000 NGN
        $service = app(RequisitionService::class);
        $requisition = $service->create(
            [
                'department_id' => $dept->id,
                'title' => 'Office Stationeries',
                'category' => 'stationery',
            ],
            [
                [
                    'item' => 'Notebooks',
                    'quantity' => 10,
                    'unit' => 'pcs',
                    'unit_price_minor' => Money::fromMajor(1000),
                ],
                [
                    'item' => 'Ballpoint Pens',
                    'quantity' => 5,
                    'unit' => 'packs',
                    'unit_price_minor' => Money::fromMajor(2000),
                ],
            ],
            $requester
        );

        $this->assertSame(2000000, (int) $requisition->total_minor);

        // Submit requisition
        $service->submit($requisition, $requester);
        $requisition->refresh();
        $instance = $requisition->workflowInstance;

        $this->assertNotNull($instance);
        $this->assertSame($stage2->id, $instance->current_stage_id);

        // Approver approves and modifies existing items:
        // Item 1: Reduce Notebooks quantity to 5 (5 * 1,000 = 5,000)
        // Item 2: Adjust Pens estimated unit price to 2,500 (5 * 2,500 = 12,500)
        // New total = 17,500 NGN (1,750,000 minor)
        $response = $this->actingAs($approver)->post(route('approvals.approve', $instance), [
            'approved_amount' => '17500.00',
            'comment' => 'Adjusted notebook quantity and pen unit price.',
            'stage_action_items' => [
                [
                    'status' => 'accept',
                    'item' => 'Notebooks',
                    'purpose' => 'Office use',
                    'quantity' => 5,
                    'unit' => 'pcs',
                    'unit_price' => '1000.00',
                ],
                [
                    'status' => 'accept',
                    'item' => 'Ballpoint Pens',
                    'purpose' => 'Office use',
                    'quantity' => 5,
                    'unit' => 'packs',
                    'unit_price' => '2500.00',
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $requisition->refresh()->load('items');
        $instance->refresh();

        // Workflow advanced to next stage (Internal Audit) with approved_amount updated
        $this->assertSame(WorkflowInstance::STATUS_IN_PROGRESS, $instance->status);
        $this->assertSame('Internal Audit', $instance->currentStage->name);
        $this->assertSame(1750000, (int) $instance->approved_amount_minor);

        // Verify action payload recorded in workflow_actions table
        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'action' => 'approve',
            'amount_minor' => 1750000,
        ]);
    }

    public function test_approver_can_accept_and_reject_individual_items(): void
    {
        $dept = Department::firstOrCreate(['name' => 'IT Department'], ['code' => 'ITD']);
        $role = Role::firstOrCreate(['name' => 'Department Head'], ['status' => 'active']);

        $requester = $this->makeUser('IT Requester', ['department_id' => $dept->id]);
        $approver = $this->makeUser('IT Head Approver', ['department_id' => $dept->id]);
        $this->assignRole($approver, 'Department Head');

        $workflow = Workflow::query()->where('code', 'WF-001')->firstOrFail();
        $stage2 = $workflow->stages()->where('position', 2)->firstOrFail();
        $stage2->update(['stage_action' => 'requisition.adjust_items']);

        $service = app(RequisitionService::class);
        $requisition = $service->create(
            ['title' => 'Hardware Purchase', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [
                ['item' => 'USB Flash Drives', 'quantity' => 10, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(3000)],
                ['item' => 'Gaming Mouse', 'quantity' => 2, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(15000)],
            ],
            $requester
        );

        $service->submit($requisition, $requester);
        $requisition->refresh();
        $instance = $requisition->workflowInstance;

        // Approver Accepts Flash Drives (10 * 3,000 = 30,000) and Rejects Gaming Mouse
        $response = $this->actingAs($approver)->post(route('approvals.approve', $instance), [
            'stage_action_items' => [
                [
                    'status' => 'accept',
                    'item' => 'USB Flash Drives',
                    'quantity' => 10,
                    'unit' => 'pcs',
                    'unit_price' => '3000.00',
                ],
                [
                    'status' => 'reject',
                    'item' => 'Gaming Mouse',
                    'quantity' => 2,
                    'unit' => 'pcs',
                    'unit_price' => '15000.00',
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $requisition->refresh()->load('items');
        $instance->refresh();

        // Only accepted items remain
        $this->assertCount(1, $requisition->items);
        $this->assertSame('USB Flash Drives', $requisition->items->first()->item);
        $this->assertSame(3000000, (int) $requisition->total_minor);
        $this->assertSame(3000000, (int) $instance->approved_amount_minor);
    }

    public function test_can_approve_pricing_and_quantities_with_requisition_approve_pricing_action(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Finance'], ['code' => 'FIN']);
        $role = Role::firstOrCreate(['name' => 'Department Head'], ['status' => 'active']);

        $requester = $this->makeUser('Procurement Requester', ['department_id' => $dept->id]);
        $approver = $this->makeUser('Procurement Officer Approver', ['department_id' => $dept->id]);
        $this->assignRole($approver, 'Department Head');

        $workflow = Workflow::query()->where('code', 'WF-001')->firstOrFail();
        $stage2 = $workflow->stages()->where('position', 2)->firstOrFail();
        $stage2->update(['stage_action' => 'requisition.approve_pricing']);

        $service = app(RequisitionService::class);
        $requisition = $service->create(
            ['title' => 'Office Laptops & Monitors', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [
                ['item' => 'Dell Laptop', 'quantity' => 4, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(500000)], // Est 2,000,000
                ['item' => 'Curved Monitor', 'quantity' => 2, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(150000)], // Est 300,000
            ],
            $requester
        );

        $service->submit($requisition, $requester);
        $requisition->refresh();
        $instance = $requisition->workflowInstance;

        // Approver Approves Laptops: Final approved qty 3 @ 480,000 = 1,440,000
        // Rejects Curved Monitors
        $response = $this->actingAs($approver)->post(route('approvals.approve', $instance), [
            'stage_action_items' => [
                [
                    'status' => 'accept',
                    'item' => 'Dell Laptop',
                    'quantity' => 3,
                    'unit' => 'pcs',
                    'unit_price' => '480000.00',
                ],
                [
                    'status' => 'reject',
                    'item' => 'Curved Monitor',
                    'quantity' => 2,
                    'unit' => 'pcs',
                    'unit_price' => '150000.00',
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $requisition->refresh()->load('items');
        $instance->refresh();

        $this->assertCount(1, $requisition->items);
        $item = $requisition->items->first();
        $this->assertSame('Dell Laptop', $item->item);
        $this->assertEquals(3, (float) $item->quantity);
        $this->assertSame(48000000, (int) $item->unit_price_minor);
        $this->assertSame(144000000, (int) $item->amount_minor);
        $this->assertSame(144000000, (int) $requisition->total_minor);
        $this->assertSame(144000000, (int) $instance->approved_amount_minor);
    }

    public function test_can_assign_service_provider_with_requisition_assign_service_provider_action(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Finance'], ['code' => 'FIN']);
        $role = Role::firstOrCreate(['name' => 'Department Head'], ['status' => 'active']);

        $requester = $this->makeUser('Requisition Requester', ['department_id' => $dept->id]);
        $approver = $this->makeUser('Accounts Approver', ['department_id' => $dept->id]);
        $this->assignRole($approver, 'Department Head');

        $provider = ServiceProvider::create([
            'name' => 'Kano Diesel & Power Ltd',
            'bank_name' => 'Zenith Bank',
            'bank_account' => '1002938475',
            'account_name' => 'Kano Diesel and Power Limited',
            'is_active' => true,
        ]);

        $workflow = Workflow::query()->where('code', 'WF-001')->firstOrFail();
        $stage2 = $workflow->stages()->where('position', 2)->firstOrFail();
        $stage2->update(['stage_action' => 'requisition.assign_service_provider']);

        $service = app(RequisitionService::class);
        $requisition = $service->create(
            ['title' => 'Generator Diesel Refill', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [
                ['item' => 'Diesel 500L', 'quantity' => 500, 'unit' => 'litres', 'unit_price_minor' => Money::fromMajor(1200)],
            ],
            $requester
        );

        $service->submit($requisition, $requester);
        $requisition->refresh();
        $instance = $requisition->workflowInstance;

        // Accounts approver assigns Service Provider
        $response = $this->actingAs($approver)->post(route('approvals.approve', $instance), [
            'service_provider_id' => $provider->id,
            'account_notes' => 'Confirmed vendor bank details with treasury.',
        ]);

        $response->assertSessionHasNoErrors();
        $requisition->refresh();
        $instance->refresh();

        $this->assertSame($provider->id, $requisition->service_provider_id);
        $this->assertSame('Kano Diesel & Power Ltd', $requisition->suggested_vendor);
        $this->assertSame('Kano Diesel & Power Ltd', $requisition->serviceProvider->name);
    }
}
