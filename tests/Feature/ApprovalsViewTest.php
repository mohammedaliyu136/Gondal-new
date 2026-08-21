<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Workflow\WorkflowEngine;
use Tests\GondalTestCase;

class ApprovalsViewTest extends GondalTestCase
{
    public function test_approver_can_view_requisition_approval_details_and_line_items(): void
    {
        $requester = $this->makeUser('Requisition Requester');
        $this->assignRole($requester, 'Inventory Officer');

        $dept = Department::firstOrCreate(['name' => 'Operations'], ['code' => 'OPS']);

        $requisition = Requisition::create([
            'reference' => 'REQ-TEST-001',
            'title' => 'Laboratory Reagents & Chemicals',
            'department_id' => $dept->id,
            'requester_user_id' => $requester->id,
            'justification' => 'Needed for raw milk intake quality screening.',
            'category' => 'laboratory',
            'total_minor' => 15000000, // ₦150,000
            'status' => Requisition::STATUS_DRAFT,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'item' => 'Methylene Blue Dye 500ml',
            'purpose' => 'Milk quality test dye',
            'quantity' => 10,
            'unit' => 'bottles',
            'unit_price_minor' => 1000000,
            'amount_minor' => 10000000,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'item' => 'Resazurin Tablets Pack',
            'purpose' => 'Resazurin screening',
            'quantity' => 5,
            'unit' => 'packs',
            'unit_price_minor' => 1000000,
            'amount_minor' => 5000000,
        ]);

        $engine = app(WorkflowEngine::class);
        $instance = $engine->start(
            Workflow::APPLIES_REQUISITION,
            $requisition,
            $requester,
            15000000,
        );

        $requisition->update(['status' => Requisition::STATUS_IN_REVIEW]);

        // Stage 2 approver: Department Head
        $approver = $this->makeUser('Dept Head User');
        $this->assignRole($approver, 'Department Head');

        $response = $this->actingAs($approver)->get(route('approvals.show', $instance));

        $response->assertOk();
        $response->assertSee('REQ-TEST-001');
        $response->assertSee('Laboratory Reagents &amp; Chemicals', false);
        $response->assertSee('Methylene Blue Dye 500ml');
        $response->assertSee('Resazurin Tablets Pack');
        $response->assertSee('150,000.00');
        $response->assertSee('Act on this Request');
        $response->assertSee('Approve Request');
    }

    public function test_approver_can_approve_from_detail_page(): void
    {
        $requester = $this->makeUser('Requisition Requester');
        $this->assignRole($requester, 'Inventory Officer');

        $requisition = Requisition::create([
            'reference' => 'REQ-TEST-002',
            'title' => 'Cold Chain Cooler Boxes',
            'requester_user_id' => $requester->id,
            'justification' => 'Field milk collection preservation.',
            'category' => 'equipment',
            'total_minor' => 5000000, // ₦50,000
            'status' => Requisition::STATUS_DRAFT,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'item' => 'Cooler Box 50L',
            'quantity' => 2,
            'unit' => 'units',
            'unit_price_minor' => 2500000,
            'amount_minor' => 5000000,
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_REQUISITION,
            $requisition,
            $requester,
            5000000,
        );

        $approver = $this->makeUser('Dept Head User');
        $this->assignRole($approver, 'Department Head');

        $response = $this->actingAs($approver)->post(route('approvals.approve', $instance), [
            'comment' => 'Approved for immediate procurement.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'actor_user_id' => $approver->id,
            'action' => 'approve',
            'comment' => 'Approved for immediate procurement.',
        ]);
    }

    public function test_approver_can_reject_from_detail_page(): void
    {
        $requester = $this->makeUser('Requisition Requester');
        $this->assignRole($requester, 'Inventory Officer');

        $requisition = Requisition::create([
            'reference' => 'REQ-TEST-003',
            'title' => 'Office Furniture Set',
            'requester_user_id' => $requester->id,
            'justification' => 'New chairs.',
            'category' => 'furniture',
            'total_minor' => 8000000,
            'status' => Requisition::STATUS_DRAFT,
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'item' => 'Ergonomic Desk Chair',
            'quantity' => 2,
            'unit' => 'units',
            'unit_price_minor' => 4000000,
            'amount_minor' => 8000000,
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_REQUISITION,
            $requisition,
            $requester,
            8000000,
        );

        $approver = $this->makeUser('Dept Head User');
        $this->assignRole($approver, 'Department Head');

        $response = $this->actingAs($approver)->post(route('approvals.reject', $instance), [
            'comment' => 'Budget currently restricted for office furniture.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('workflow_actions', [
            'workflow_instance_id' => $instance->id,
            'actor_user_id' => $approver->id,
            'action' => 'reject',
            'comment' => 'Budget currently restricted for office furniture.',
        ]);
    }

    public function test_approver_can_view_leave_request_approval_details(): void
    {
        $employeeUser = $this->makeUser('Jane Staff');
        $employee = Employee::create([
            'code' => 'EMP-0099',
            'name' => 'Jane Staff',
            'user_id' => $employeeUser->id,
            'status' => 'confirmed',
            'joined_on' => '2024-01-01',
        ]);

        $leaveType = LeaveType::query()->first() ?? LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANNUAL-NEW', 'days_per_year' => 20]);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-10',
            'days' => 8,
            'reason' => 'Annual family vacation',
            'status' => 'submitted',
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_LEAVE,
            $leave,
            $employeeUser,
            8,
        );

        $hrManager = $this->makeUser('HR Manager User');
        $this->assignRole($hrManager, 'HR Manager');

        $response = $this->actingAs($hrManager)->get(route('approvals.show', $instance));

        $response->assertOk();
        $response->assertSee('Jane Staff');
        $response->assertSee('8 Days Requested');
        $response->assertSee('Annual family vacation');
    }

    public function test_approver_can_view_payroll_run_approval_details(): void
    {
        $creator = $this->makeUser('HR Officer');
        $this->assignRole($creator, 'HR Manager');

        $payroll = \App\Models\PayrollRun::create([
            'period_month' => 8,
            'period_year' => 2026,
            'employee_count' => 12,
            'gross_total_minor' => 120000000,
            'deductions_total_minor' => 24000000,
            'net_total_minor' => 96000000,
            'status' => 'submitted',
            'run_by_user_id' => $creator->id,
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_PAYROLL_RUN,
            $payroll,
            $creator,
            96000000,
        );

        $accounts = $this->makeUser('Accounts Reviewer');
        $this->assignRole($accounts, 'Accounts');

        $response = $this->actingAs($accounts)->get(route('approvals.show', $instance));

        $response->assertOk();
        $response->assertSee('August 2026');
        $response->assertSee('960,000.00');
        $response->assertSee('Employees');
    }

    public function test_approver_can_view_farmer_payment_run_approval_details(): void
    {
        $world = $this->makeMilkWorld();
        $preparer = $this->makeUser('Accounts Preparer');
        $this->assignRole($preparer, 'Accounts');

        $run = \App\Models\PaymentRun::create([
            'reference' => 'RUN-202608-TEST',
            'scope_type' => 'collection_center',
            'scope_id' => $world['centerA']->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'farmer_count' => 50,
            'gross_total_minor' => 50000000,
            'deductions_total_minor' => 5000000,
            'held_net_minor' => 0,
            'held_count' => 0,
            'cash_required_minor' => 45000000,
            'status' => \App\Models\PaymentRun::STATUS_DRAFT,
            'run_by_user_id' => $preparer->id,
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_FARMER_PAYMENT_RUN,
            $run,
            $preparer,
            45000000,
        );

        $approver = $this->makeUser('Accounts Approver');
        $this->assignRole($approver, 'Accounts');

        $response = $this->actingAs($approver)->get(route('approvals.show', $instance));

        $response->assertOk();
        $response->assertSee('Farmer Milk Payment Run');
        $response->assertSee('450,000.00');
        $response->assertSee('Gross Milk Value');
    }

    public function test_non_approver_views_status_without_action_card(): void
    {
        $requester = $this->makeUser('Requisition Requester');
        $this->assignRole($requester, 'Inventory Officer');

        $requisition = Requisition::create([
            'reference' => 'REQ-TEST-004',
            'title' => 'Milk Testing Kit',
            'requester_user_id' => $requester->id,
            'justification' => 'Testing supplies.',
            'category' => 'laboratory',
            'total_minor' => 3000000,
            'status' => Requisition::STATUS_DRAFT,
        ]);

        $instance = app(WorkflowEngine::class)->start(
            Workflow::APPLIES_REQUISITION,
            $requisition,
            $requester,
            3000000,
        );

        // An auditor who is not in Stage 2 (Department Head)
        $auditor = $this->makeUser('Internal Auditor User');
        $this->assignRole($auditor, 'Internal Audit');

        $response = $this->actingAs($auditor)->get(route('approvals.show', $instance));

        $response->assertOk();
        $response->assertSee('REQ-TEST-004');
        $response->assertSee('Status Overview');
        $response->assertDontSee('Approve Request');
    }

    public function test_approvals_index_queue_renders_all_approval_types_without_errors(): void
    {
        $creator = $this->makeUser('Accounts Admin');
        $this->assignRole($creator, 'Accounts');
        $this->assignRole($creator, 'Department Head');
        $this->assignRole($creator, 'HR Manager');

        // 1. Requisition
        $requisition = Requisition::create([
            'reference' => 'REQ-INDEX-001',
            'title' => 'Sample Requisition',
            'requester_user_id' => $creator->id,
            'total_minor' => 1000000,
            'status' => Requisition::STATUS_DRAFT,
        ]);
        $requester2 = $this->makeUser('Requester 2');
        $this->assignRole($requester2, 'Inventory Officer');
        app(WorkflowEngine::class)->start(Workflow::APPLIES_REQUISITION, $requisition, $requester2, 1000000);

        // 2. Payroll Run
        $payroll = \App\Models\PayrollRun::create([
            'period_month' => 8,
            'period_year' => 2026,
            'employee_count' => 5,
            'gross_total_minor' => 50000000,
            'deductions_total_minor' => 5000000,
            'net_total_minor' => 45000000,
            'status' => 'submitted',
            'run_by_user_id' => $requester2->id,
        ]);
        app(WorkflowEngine::class)->start(Workflow::APPLIES_PAYROLL_RUN, $payroll, $requester2, 45000000);

        // 3. Leave Request
        $leaveType = LeaveType::query()->first() ?? LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANNUAL-QUEUE', 'days_per_year' => 20]);
        $employee = Employee::create([
            'code' => 'EMP-0055',
            'name' => 'Mark Staff',
            'user_id' => $requester2->id,
            'status' => 'confirmed',
            'joined_on' => '2024-01-01',
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-05',
            'days' => 4,
            'status' => 'submitted',
        ]);
        app(WorkflowEngine::class)->start(Workflow::APPLIES_LEAVE, $leave, $requester2, 4);

        $response = $this->actingAs($creator)->get(route('approvals.index'));

        $response->assertOk();
        $response->assertSee('REQ-INDEX-001');
        $response->assertSee('Payroll #');
        $response->assertSee('August 2026');
        $response->assertSee('Review &amp; Act', false);
    }
}
