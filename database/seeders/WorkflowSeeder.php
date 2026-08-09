<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowBand;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;

/**
 * §9 / settings-workflows.html — "5 active, 1 disabled".
 *
 * BR-19 — "Seeded requisition bands: up to ₦500,000 → User, Dept Head, Internal
 *   Audit, Accounts. Above ₦500,000 → all six stages."
 * BR-23 — every stage names a ROLE, never a user.
 *
 * A note on `amount_minor`: for a requisition it is money in kobo. For a leave
 * request it is the number of DAYS, and for a batch discrepancy it is the
 * variance in centilitres. The engine only ever compares it against a stage's
 * condition_value or a band's range, so one column serves all three — which is
 * why the leave workflow uses a stage condition rather than a money band.
 */
class WorkflowSeeder extends Seeder
{
    /** ₦500,000 in kobo (ARCH-6). */
    private const REQUISITION_BAND_BREAK = 50_000_000;

    /** ₦50,000 in kobo. */
    private const STOCK_ADJUSTMENT_ACCOUNTS_THRESHOLD = 5_000_000;

    public function run(): void
    {
        $this->seedRequisitionWorkflow();
        $this->seedLeaveWorkflow();
        $this->seedStockAdjustmentWorkflow();
        $this->seedPayrollWorkflow();
        $this->seedBatchDiscrepancyWorkflow();
        $this->seedDisabledProjectWorkflow();
    }

    /** WF-001 — the six-stage route agreed at the review meeting. */
    private function seedRequisitionWorkflow(): void
    {
        $workflow = $this->workflow('WF-001', 'Purchase Requisition', Workflow::APPLIES_REQUISITION, 'active', [
            'strict_sequence' => true,                  // BR-17
            'rejection_returns_to_requester' => true,   // BR-20
            'approver_may_reduce_amount' => true,       // BR-22
            'allow_request_info' => true,               // BR-21
            'allow_delegation' => true,                 // BR-24
            'auto_escalate_on_sla' => false,
            'requester_may_not_approve_own' => true,    // BR-18
            'overdue_reminder' => 'daily',              // NOTIF-4
        ], 'All requisitions, routed by amount band');

        $stages = [
            ['position' => 1, 'name' => 'Raised by user', 'role' => null, 'permission' => 'purchase.requisitions.create', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Department Head', 'role' => 'Department Head', 'permission' => 'purchase.approve.depthead.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
            ['position' => 3, 'name' => 'Internal Audit', 'role' => 'Internal Audit', 'permission' => 'purchase.approve.audit.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 48, 'reject' => true, 'submission' => false],
            ['position' => 4, 'name' => 'Executive Director', 'role' => 'Executive Director', 'permission' => 'purchase.approve.ed.approve', 'condition' => WorkflowStage::CONDITION_AMOUNT_ABOVE, 'value' => (string) self::REQUISITION_BAND_BREAK, 'sla' => 48, 'reject' => true, 'submission' => false],
            ['position' => 5, 'name' => 'Accounts', 'role' => 'Accounts', 'permission' => 'purchase.approve.accounts.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
            ['position' => 6, 'name' => 'General Manager', 'role' => 'General Manager', 'permission' => 'purchase.approve.gm.approve', 'condition' => WorkflowStage::CONDITION_AMOUNT_ABOVE, 'value' => (string) self::REQUISITION_BAND_BREAK, 'sla' => 24, 'reject' => true, 'submission' => false],
        ];

        $created = $this->stages($workflow, $stages);

        // BR-19 — the bands. "Standard" deliberately omits ED and GM.
        $standard = WorkflowBand::query()->updateOrCreate(
            ['workflow_id' => $workflow->getKey(), 'name' => 'Standard'],
            ['amount_from_minor' => 0, 'amount_to_minor' => self::REQUISITION_BAND_BREAK, 'position' => 1],
        );
        $standard->stages()->sync([
            $created[1]->getKey(), $created[2]->getKey(), $created[3]->getKey(), $created[5]->getKey(),
        ]);

        $major = WorkflowBand::query()->updateOrCreate(
            ['workflow_id' => $workflow->getKey(), 'name' => 'Major'],
            ['amount_from_minor' => self::REQUISITION_BAND_BREAK + 1, 'amount_to_minor' => null, 'position' => 2],
        );
        $major->stages()->sync(collect($created)->map->getKey()->values()->all());
    }

    /** WF-002 — "Over 5 days adds HR". `amount_minor` carries days here. */
    private function seedLeaveWorkflow(): void
    {
        $workflow = $this->workflow('WF-002', 'Leave Request', Workflow::APPLIES_LEAVE, 'active', [
            'strict_sequence' => true,
            'rejection_returns_to_requester' => true,
            'approver_may_reduce_amount' => false,
            'allow_request_info' => true,
            'allow_delegation' => true,
            'auto_escalate_on_sla' => false,
            'requester_may_not_approve_own' => true,
            'overdue_reminder' => 'daily',
        ], 'All staff leave. Requests over 5 days additionally need HR.');

        $this->stages($workflow, [
            ['position' => 1, 'name' => 'Raised by employee', 'role' => null, 'permission' => 'hr.leave.own.create', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Department Head', 'role' => 'Department Head', 'permission' => 'hr.leave.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
            ['position' => 3, 'name' => 'HR Manager', 'role' => 'HR Manager', 'permission' => 'hr.leave.approve', 'condition' => WorkflowStage::CONDITION_AMOUNT_ABOVE, 'value' => '5', 'sla' => 48, 'reject' => true, 'submission' => false],
        ]);
    }

    /** WF-003 — "Over ₦50,000 adds Accounts". BR-28. */
    private function seedStockAdjustmentWorkflow(): void
    {
        $workflow = $this->workflow('WF-003', 'Stock Adjustment', Workflow::APPLIES_STOCK_ADJUSTMENT, 'active', [
            'strict_sequence' => true,
            'rejection_returns_to_requester' => true,
            'approver_may_reduce_amount' => false,
            'allow_request_info' => true,
            'allow_delegation' => false,
            'auto_escalate_on_sla' => false,
            'requester_may_not_approve_own' => true,
            'overdue_reminder' => 'once',
        ], 'One-Stop Shop write-offs. Adjustments over ₦50,000 additionally need Accounts.');

        $this->stages($workflow, [
            ['position' => 1, 'name' => 'Raised by officer', 'role' => null, 'permission' => 'shop.inventory.edit', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Shop Manager', 'role' => 'One-Stop Shop Manager', 'permission' => 'shop.inventory.edit', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
            ['position' => 3, 'name' => 'Accounts', 'role' => 'Accounts', 'permission' => 'purchase.approve.accounts.approve', 'condition' => WorkflowStage::CONDITION_AMOUNT_ABOVE, 'value' => (string) self::STOCK_ADJUSTMENT_ACCOUNTS_THRESHOLD, 'sla' => 48, 'reject' => true, 'submission' => false],
        ]);
    }

    /** WF-004 — monthly payroll, three stages, no conditions. */
    private function seedPayrollWorkflow(): void
    {
        $workflow = $this->workflow('WF-004', 'Payroll Run', Workflow::APPLIES_PAYROLL_RUN, 'active', [
            'strict_sequence' => true,
            'rejection_returns_to_requester' => true,
            'approver_may_reduce_amount' => false,
            'allow_request_info' => true,
            'allow_delegation' => false,
            'auto_escalate_on_sla' => false,
            'requester_may_not_approve_own' => true,
            'overdue_reminder' => 'daily',
        ], 'Monthly payroll');

        $this->stages($workflow, [
            ['position' => 1, 'name' => 'Prepared by HR', 'role' => null, 'permission' => 'hr.payroll.create', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Accounts', 'role' => 'Accounts', 'permission' => 'hr.payroll.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 48, 'reject' => true, 'submission' => false],
            ['position' => 3, 'name' => 'General Manager', 'role' => 'General Manager', 'permission' => 'hr.payroll.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
        ]);
    }

    /** WF-005 — BR-11: "Always requires a note". */
    private function seedBatchDiscrepancyWorkflow(): void
    {
        $workflow = $this->workflow('WF-005', 'Batch Discrepancy', Workflow::APPLIES_BATCH_DISCREPANCY, 'active', [
            'strict_sequence' => true,
            'rejection_returns_to_requester' => false,
            'approver_may_reduce_amount' => false,
            'allow_request_info' => true,
            'allow_delegation' => false,
            'auto_escalate_on_sla' => false,
            'requester_may_not_approve_own' => true,
            'overdue_reminder' => 'daily',
            // BR-11 — the note is required by the rule itself, not by the option;
            // the option records that this workflow expects one so the screen can
            // say so.
            'requires_explanatory_note' => true,
        ], 'Factory variance beyond the configured tolerance');

        $this->stages($workflow, [
            ['position' => 1, 'name' => 'Raised at reconciliation', 'role' => null, 'permission' => 'milk.reconciliation.create', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Milk Collection Supervisor', 'role' => 'Milk Collection Supervisor', 'permission' => 'milk.reconciliation.approve', 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
        ]);
    }

    /**
     * WF-006 — NG-2: the Project module was disabled. The workflow is kept
     * disabled rather than deleted, exactly as its 11 permissions were retired
     * rather than deleted (PERM-3).
     */
    private function seedDisabledProjectWorkflow(): void
    {
        $workflow = $this->workflow('WF-006', 'Project Approval', Workflow::APPLIES_REQUISITION, 'disabled', [
            'strict_sequence' => true,
            'rejection_returns_to_requester' => true,
        ], 'Project module — disabled 12 Jul 2026');

        $this->stages($workflow, [
            ['position' => 1, 'name' => 'Raised by project lead', 'role' => null, 'permission' => null, 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => null, 'reject' => false, 'submission' => true],
            ['position' => 2, 'name' => 'Department Head', 'role' => 'Department Head', 'permission' => null, 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 24, 'reject' => true, 'submission' => false],
            ['position' => 3, 'name' => 'Internal Audit', 'role' => 'Internal Audit', 'permission' => null, 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 48, 'reject' => true, 'submission' => false],
            ['position' => 4, 'name' => 'Executive Director', 'role' => 'Executive Director', 'permission' => null, 'condition' => WorkflowStage::CONDITION_ALWAYS, 'value' => null, 'sla' => 48, 'reject' => true, 'submission' => false],
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $options
     */
    private function workflow(string $code, string $name, string $appliesTo, string $status, array $options, string $description): Workflow
    {
        return Workflow::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'applies_to' => $appliesTo,
                'status' => $status,
                'options' => $options,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, WorkflowStage> keyed by position
     */
    private function stages(Workflow $workflow, array $definitions): array
    {
        $created = [];

        foreach ($definitions as $definition) {
            $roleId = $definition['role'] === null
                ? null
                : Role::query()->where('name', $definition['role'])->value('id');

            $created[$definition['position']] = WorkflowStage::query()->updateOrCreate(
                ['workflow_id' => $workflow->getKey(), 'position' => $definition['position']],
                [
                    'name' => $definition['name'],
                    'approving_role_id' => $roleId,
                    'required_permission' => $definition['permission'],
                    'condition_type' => $definition['condition'],
                    'condition_value' => $definition['value'],
                    'sla_hours' => $definition['sla'],
                    'can_reject' => $definition['reject'],
                    'is_submission' => $definition['submission'],
                ],
            );
        }

        return $created;
    }
}
