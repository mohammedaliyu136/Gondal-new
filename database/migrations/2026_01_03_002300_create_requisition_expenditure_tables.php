<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Phase 7 — a requisition that is approved and then forgotten.
 *
 * `requisitions.approved_total_minor` is stamped when the workflow clears, and
 * from that moment nothing in the system ever refers to it again. There is no
 * record that the money was spent, no vendor, no invoice, and no way to answer
 * "what did Logistics actually cost us last quarter" — `departments.cost_centre`
 * is a varchar that nothing reads.
 *
 * The gap is not academic. An approval is a permission to spend, not a spend;
 * without the second half, a requisition approved at ₦400,000 and settled at
 * ₦520,000 looks identical to one settled at ₦380,000, and both look identical
 * to one nobody ever bought anything against.
 *
 * A BUDGET IS ADVISORY, NOT A BLOCK. `departments.budget_minor` is nullable and
 * nothing refuses a payment because of it. Blocking spend on a budget nobody has
 * set up would break the purchase flow the day this ships, and a budget that
 * silently stops a feed delivery in the rainy season is worse than one that goes
 * over and says so. The screen shows the overrun; the decision stays human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_expenditures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();

            /*
             * The department is SNAPSHOTTED rather than read through the
             * requisition, for the same reason BR-15 snapshots a cooperative's
             * percentages: moving a requester between departments next year must
             * not silently restate what a department spent last year.
             */
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('cost_centre')->nullable();

            $table->integer('amount_minor');
            $table->string('vendor')->nullable();
            $table->string('invoice_reference')->nullable();
            $table->string('method', 24)->default('bank');   // bank | cash | cheque | transfer
            $table->date('spent_on');

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // NFR-3 — every FK leads an index. `department_id, spent_on` is the
            // whole per-department report in one index.
            $table->index('requisition_id');
            $table->index(['department_id', 'spent_on']);
            $table->index('cost_centre');
            $table->index('recorded_by_user_id');
            $table->index('created_by_user_id');
            $table->index('spent_on');
            $table->index('is_test');
        });

        Schema::table('departments', function (Blueprint $table): void {
            // Nullable and advisory. See the note in the class docblock.
            $table->integer('budget_minor')->nullable()->after('cost_centre');
            $table->string('budget_period', 16)->nullable()->after('budget_minor'); // monthly|quarterly|yearly
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_expenditures');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['budget_minor', 'budget_period']);
        });
    }
};
