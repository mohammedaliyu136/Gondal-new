<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.5 the workflow engine — "who approves what, in what order: configured,
 * not coded".
 *
 * BR-23 — stages reference ROLES, not users, so reassigning staff never breaks
 *         a workflow.
 * BR-19 — which stages apply comes from the matching amount band.
 * BR-17 — approval is strictly sequential.
 * §9    — the six seeded workflows (five active, one disabled) are rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();                 // WF-001 …
            $table->string('name');
            $table->text('description')->nullable();

            // requisition|leave|stock_adjustment|payroll_run|batch_discrepancy
            $table->string('applies_to', 32);

            $table->string('status', 16)->default('active');      // active|disabled

            // The settings-workflows.html "Workflow Options" panel, as data.
            // Keys: strict_sequence, rejection_returns_to_requester,
            // approver_may_reduce_amount, allow_request_info, allow_delegation,
            // auto_escalate_on_sla, requester_may_not_approve_own,
            // overdue_reminder (daily|twelve_hourly|once|never)
            $table->json('options')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['applies_to', 'status']);
        });

        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');

            // BR-23 — the stage's role. Null only for the "raised by user"
            // stage, which is satisfied by submission rather than by approval.
            $table->foreignId('approving_role_id')->nullable()->constrained('roles')->restrictOnDelete();

            // A stage may additionally demand a specific approval permission,
            // which is how purchase.approve.audit and friends are enforced
            // without hardcoding stage numbers.
            $table->string('required_permission', 80)->nullable();

            $table->string('condition_type', 24)->default('always');   // always|amount_above|department|category
            $table->string('condition_value')->nullable();

            $table->unsignedInteger('sla_hours')->nullable();
            $table->boolean('can_reject')->default(true);

            // The stage the requester's own submission satisfies (stage 1).
            $table->boolean('is_submission')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workflow_id', 'position']);
            $table->index('approving_role_id');
        });

        // BR-19 — the band decides which stages apply.
        Schema::create('workflow_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('amount_from_minor')->default(0);
            $table->unsignedBigInteger('amount_to_minor')->nullable();   // null = no upper bound
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workflow_id', 'amount_from_minor']);
        });

        Schema::create('workflow_band_stage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_band_id')->constrained('workflow_bands')->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workflow_band_id', 'workflow_stage_id'], 'workflow_band_stage_unique');
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->restrictOnDelete();
            $table->foreignId('workflow_band_id')->nullable()->constrained('workflow_bands')->nullOnDelete();

            // Polymorphic subject: requisition, leave request, payroll run,
            // stock adjustment, batch discrepancy.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();

            $table->string('status', 16)->default('in_progress');   // in_progress|approved|rejected|cancelled

            // BR-18 — captured so "may never approve their own submission" is
            // enforceable without walking back to the subject each time.
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();

            // BR-19 / BR-22 — the amount the band was chosen from, and the
            // running approved amount which may only ever be reduced.
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedBigInteger('approved_amount_minor')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('current_stage_due_at')->nullable();   // NOTIF-4

            $table->boolean('is_test')->default(false);              // BR-35
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'current_stage_id']);
            $table->index('current_stage_due_at');
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // BR-24 — a delegated action records BOTH users.
            $table->foreignId('on_behalf_of_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegation_id')->nullable();

            // submit|approve|reject|request_info|delegate|cancel
            $table->string('action', 16);

            // BR-22 — an approver may reduce this, never raise it.
            $table->unsignedBigInteger('amount_minor')->nullable();

            $table->unsignedBigInteger('reason_id')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['workflow_instance_id', 'acted_at']);
            $table->index(['actor_user_id', 'acted_at']);
        });

        // BR-24 — an active delegation routes the delegator's queue to the delegate.
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['to_user_id', 'starts_on', 'ends_on']);
            $table->index(['from_user_id', 'role_id']);
        });

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->foreign('delegation_id')->references('id')->on('delegations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_band_stage');
        Schema::dropIfExists('workflow_bands');
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflows');
    }
};
