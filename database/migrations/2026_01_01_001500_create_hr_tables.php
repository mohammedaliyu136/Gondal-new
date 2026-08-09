<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.8 human resources.
 *
 * G-6 — payroll is a sensitive module. Salary figures live only on `employees`
 *   and `payslips`, both of which the resource layer gates on hr.payroll.view or
 *   hr.payslip.own.view (§5.1).
 * BR-35 / TEST-1 — test accounts are excluded from payroll, enforced when a run
 *   is generated rather than filtered afterwards.
 * §15.5 — attendance and recruitment applicants are known gaps, deliberately
 *   absent. `positions` is the vacancy register the prototype shows, nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('position')->nullable();
            $table->string('grade_level', 24)->nullable();
            $table->string('employment_type', 24)->nullable();
            $table->string('duty_station')->nullable();
            $table->foreignId('line_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joined_on')->nullable();
            $table->date('confirmed_on')->nullable();

            // G-6 — sensitive.
            $table->unsignedBigInteger('gross_monthly_minor')->default(0);
            $table->string('bank_name')->nullable();

            // NFR-9 — only the masked form is ever stored or displayed.
            $table->string('bank_account_masked', 32)->nullable();

            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();

            $table->string('status', 16)->default('probation');   // probation|confirmed|on_leave|exited
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['department_id', 'status']);
            $table->index('name');
        });

        // users.employee_id was created unconstrained so employees could be
        // defined after users. Close the loop now.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        // §9-style reference data for HR.
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('annual_entitlement_days')->default(0);
            $table->boolean('requires_document')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');
            $table->text('reason')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()
                ->constrained('workflow_instances')->nullOnDelete();
            $table->string('status', 16)->default('draft');   // draft|in_review|approved|rejected|cancelled
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['employee_id', 'status']);
            $table->index(['status', 'starts_on']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('grade_level', 24)->nullable();
            $table->unsignedSmallInteger('openings')->default(1);
            $table->date('posted_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->string('status', 16)->default('open');    // open|closed|filled
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'department_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->foreignId('workflow_instance_id')->nullable()
                ->constrained('workflow_instances')->nullOnDelete();
            $table->string('status', 16)->default('draft');   // draft|processing|approved|paid
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('gross_total_minor')->default(0);
            $table->unsignedBigInteger('deductions_total_minor')->default(0);
            $table->unsignedBigInteger('net_total_minor')->default(0);
            $table->unsignedInteger('employee_count')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['period_year', 'period_month']);
            $table->index('status');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->unsignedBigInteger('gross_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->json('breakdown')->nullable();
            $table->json('ytd')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('employees');
    }
};
