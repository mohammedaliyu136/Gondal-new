<?php

namespace Tests\Feature;

use App\Contracts\WorkflowSubjectInterface;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Workflow\SubjectSynchroniser;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class DynamicWorkflowTest extends TestCase
{
    public function test_can_create_dynamic_workflow_and_stages(): void
    {
        $role = Role::query()->first();
        if (!$role) {
            $role = Role::create([
                'name' => 'Finance Reviewer',
                'status' => 'active',
            ]);
        }

        $code = 'WF-TEST-' . substr(uniqid(), 0, 6);
        $workflow = Workflow::create([
            'code' => $code,
            'name' => 'Milk Batch Payment Approval',
            'applies_to' => 'milk_batch_payment',
            'status' => 'active',
            'options' => ['strict_sequence' => true],
        ]);

        $stage1 = $workflow->stages()->create([
            'position' => 1,
            'name' => 'Preparation',
            'is_submission' => true,
            'condition_type' => 'always',
        ]);

        $stage2 = $workflow->stages()->create([
            'position' => 2,
            'name' => 'Finance Verification',
            'approving_role_id' => $role->id,
            'is_submission' => false,
            'condition_type' => 'always',
            'can_reject' => true,
            'sla_hours' => 24,
        ]);

        $this->assertDatabaseHas('workflows', ['code' => $code]);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflow->id, 'name' => 'Finance Verification']);
        $this->assertSame(2, $workflow->stages()->count());
    }

    public function test_workflow_subject_interface_sync(): void
    {
        $mockSubject = new class extends Model implements WorkflowSubjectInterface {
            protected $table = 'users'; // temporary table for morph test
            public bool $approvedCalled = false;
            public bool $rejectedCalled = false;

            public function getApprovalTitle(): string { return 'Test Item'; }
            public function getApprovalReference(): string { return 'REF-TEST-001'; }
            public function getApprovalAmountMinor(): ?int { return 100000; }
            public function getApprovalUrl(): ?string { return '/test/url'; }
            public function onWorkflowApproved(WorkflowInstance $instance): void { $this->approvedCalled = true; }
            public function onWorkflowRejected(WorkflowInstance $instance, string $reason): void { $this->rejectedCalled = true; }
        };

        $instance = new WorkflowInstance(['status' => WorkflowInstance::STATUS_APPROVED]);
        $syncer = app(SubjectSynchroniser::class);
        $syncer->sync($mockSubject, $instance);

        $this->assertTrue($mockSubject->approvedCalled);
    }
}
