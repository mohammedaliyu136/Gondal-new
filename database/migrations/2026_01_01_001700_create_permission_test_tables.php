<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5.4 the permission testing protocol, as specified by permission-test.html.
 *
 * TEST-2 — a run records role under test, test user, simulated scope,
 *          environment, and expected-versus-actual checks with pass/fail.
 * TEST-3 — PRODUCTION MUST NOT BE OFFERABLE. The allowed values come from
 *          config('gondal.permission_test_environments') and are validated on
 *          write; the column is deliberately not an enum that could grow a
 *          'production' member by accident.
 * TEST-5 — saving a role change that affects live users prompts for a passing
 *          run first. It is a warning, not a block, and the override is logged —
 *          hence `roles.last_passing_test_run_id` plus an audit entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_test_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();          // TEST-0014

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('test_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // TEST-2 — the simulated scope.
            $table->string('scope_type', 16)->nullable();
            $table->unsignedBigInteger('scope_target_id')->nullable();

            // TEST-3 — development|staging only.
            $table->string('environment', 24);

            $table->string('signin_result', 16)->nullable();    // succeeded|failed|not_attempted

            // in_progress|passed|failed|approved_for_live|rejected|abandoned
            $table->string('status', 24)->default('in_progress');

            $table->unsignedInteger('passed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role_id', 'status']);
            $table->index('started_at');
        });

        Schema::create('permission_test_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_test_run_id')->constrained('permission_test_runs')->cascadeOnDelete();

            $table->string('module', 48)->nullable();           // the group-row heading
            $table->string('area');                             // "Confirm consignment at own center"
            $table->string('permission_key', 80)->nullable();
            $table->string('route')->nullable();

            // The scope dimension: an in-scope check and an out-of-scope check
            // of the same permission are different rows (SCOPE-3).
            $table->unsignedBigInteger('scope_target_id')->nullable();
            $table->boolean('is_scope_probe')->default(false);

            $table->string('expected', 16);                     // allow|deny
            $table->string('actual', 16)->nullable();           // allow|deny
            $table->string('actual_reason', 16)->nullable();    // permission|scope
            $table->boolean('passed')->nullable();
            $table->string('note')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['permission_test_run_id', 'position']);
            $table->index('passed');
        });

        // TEST-5
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('last_passing_test_run_id')->nullable()->after('status');
            $table->timestamp('permissions_changed_at')->nullable()->after('last_passing_test_run_id');

            $table->foreign('last_passing_test_run_id')
                ->references('id')->on('permission_test_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['last_passing_test_run_id']);
            $table->dropColumn(['last_passing_test_run_id', 'permissions_changed_at']);
        });

        Schema::dropIfExists('permission_test_checks');
        Schema::dropIfExists('permission_test_runs');
    }
};
