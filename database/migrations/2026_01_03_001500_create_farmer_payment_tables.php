<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Phase 7 — paying a farmer for their milk.
 *
 * Until now the system captured everything a payment needs and computed none of
 * it: `test_phase7_payments_is_blocked_but_its_data_is_captured` asserted the
 * absence, and five screens said so to the user. This is the schema half of
 * closing that.
 *
 * THE DESIGN IS DELIVERY-ANCHORED, and the whole thing turns on one constraint.
 *
 * A consignment pools many farmers and carries ONE grade and ONE rate. The
 * obvious shape — a monthly run keyed on (period, cooperative) — makes "paid
 * twice" a question about periods, which is exactly the question that goes
 * wrong when a consignment is confirmed three days after its month closed. So
 * the claim is recorded per DELIVERY, and
 *
 *     farmer_payment_deliveries.delivery_id UNIQUE
 *
 * makes "a litre is paid exactly once, ever" a fact the database enforces
 * rather than a guard a service can be refactored around. Ragged runs,
 * catch-up runs and late confirmations all become safe for free, and the period
 * on a run demotes to a label.
 *
 * BR-15 — the cooperative percentages in force are SNAPSHOTTED onto each
 * farmer_payments row at generation, for the same reason BR-13/BR-14 snapshot
 * the grade rate: changing the levy next year must not silently rewrite what a
 * farmer was paid last year.
 *
 * BR-35 — test data is excluded through `deliveries.is_test`, NOT through the
 * farmer. `farmers` has no is_test column at all, so a population built from
 * farmers could not honour BR-35; building it from deliveries is what makes the
 * generic excludingTestData() work here.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT DECIDE. Two business questions are
 * open (docs/PLAN-FARMER-PAYMENTS.md §1.1, §1.2) and both are encoded as data
 * rather than structure, so answering them differently later is a settings
 * change and a backfill, not a rewrite:
 *   - apportionment: each line stores the consignment and grade it was priced
 *     at, so pooled pricing is visible and auditable rather than assumed;
 *   - disbursement channel: `method` is a column, so bank and mobile money are
 *     additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();      // PRUN-2026-0001

            /*
             * Scope is polymorphic because the answer to "who is a run for?"
             * depends on an unanswered business question: cooperative if
             * members are the unit, collection centre if unaffiliated farmers
             * are common. Storing the type means the answer can change without
             * a migration. See the plan's "a number to go and get".
             */
            $table->string('scope_type', 32);           // collection_center | cooperative
            $table->unsignedBigInteger('scope_id');

            // A LABEL, not the double-payment guard. The guard is the UNIQUE on
            // farmer_payment_deliveries.delivery_id.
            $table->date('period_start');
            $table->date('period_end');

            $table->string('status', 16)->default('draft');   // draft|processing|approved|paid|cancelled

            $table->integer('gross_total_minor')->default(0);
            $table->integer('deductions_total_minor')->default(0);
            $table->integer('net_total_minor')->default(0);

            /*
             * BR-36 money: owed, computed, and not payable until the farmer is
             * revalidated. It sits INSIDE net_total_minor — the debt is real —
             * which is why cash_required_minor exists separately. Reading the
             * headline total as "cash to send to the points" would send a
             * surplus that then sits in a village untracked.
             */
            $table->integer('held_net_minor')->default(0);
            $table->integer('cash_required_minor')->default(0);

            $table->unsignedInteger('farmer_count')->default(0);
            $table->unsignedInteger('held_count')->default(0);

            $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_type', 'scope_id']);
            // NFR-3 — every foreign key leads an index, and a test asserts it
            // for the whole schema.
            $table->index('workflow_instance_id');
            $table->index('run_by_user_id');
            $table->index('approved_by_user_id');
            $table->index('created_by_user_id');
            $table->index(['status', 'period_end']);
        });

        Schema::create('farmer_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_run_id')->constrained('payment_runs')->cascadeOnDelete();
            $table->foreignId('farmer_id')->constrained('farmers')->restrictOnDelete();

            $table->decimal('litres_paid', 10, 2)->default(0);
            $table->integer('gross_minor')->default(0);

            $table->integer('savings_minor')->default(0);
            $table->integer('levy_minor')->default(0);
            $table->integer('social_minor')->default(0);
            $table->integer('shop_deduction_minor')->default(0);
            $table->integer('net_minor')->default(0);

            /*
             * BR-15. Nullable because a farmer with no cooperative has no
             * percentages to snapshot — and that is a real case, not an error.
             */
            $table->decimal('savings_pct_snapshot', 5, 2)->nullable();
            $table->decimal('levy_pct_snapshot', 5, 2)->nullable();
            $table->integer('social_minor_snapshot')->nullable();

            $table->string('status', 16)->default('payable');   // payable|held|paid|reversed
            $table->string('hold_reason', 32)->nullable();      // 'unvalidated' (BR-36)

            // How net was reached, step by step, so a figure can be argued with
            // rather than merely asserted at a collection point.
            $table->json('breakdown')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['payment_run_id', 'farmer_id']);
            $table->index('created_by_user_id');
            $table->index(['farmer_id', 'status']);
        });

        Schema::create('farmer_payment_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farmer_payment_id')->constrained('farmer_payments')->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();

            // Snapshotted, not joined. The rate on the consignment can be
            // re-graded later (BR-4's control break); what this farmer was paid
            // must not move when it is.
            $table->decimal('litres_payable', 10, 2);
            $table->integer('rate_per_litre_minor');
            $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();
            $table->foreignId('consignment_id')->nullable()->constrained('consignments')->nullOnDelete();
            $table->integer('line_gross_minor');

            $table->timestamps();

            // THE constraint. A litre is paid exactly once, ever.
            $table->unique('delivery_id');
            $table->index('farmer_payment_id');
            $table->index('grade_id');
            $table->index('consignment_id');
        });

        Schema::create('farmer_payment_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farmer_payment_id')->constrained('farmer_payments')->cascadeOnDelete();

            $table->string('method', 24);               // cash|bank|mobile_money|via_cooperative
            $table->integer('amount_minor');
            $table->timestamp('disbursed_at');

            // Who handed it over. Not the same person as who recorded the milk,
            // if the cooperative separates the duties (plan §1.3).
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('received_by')->nullable();
            $table->string('received_by_relation', 32)->nullable();   // self|son|wife|...
            $table->string('proxy_authority_ref')->nullable();

            $table->string('external_reference')->nullable();          // bank / MoMo ref
            $table->foreignId('signature_evidence_id')->nullable()->constrained('attachments')->nullOnDelete();

            // Same columns and meaning as the field-capture work: evidence for a
            // reviewer, never a gate. A payout with no fix is still recordable.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('location_accuracy_m')->nullable();
            $table->timestamp('located_at')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['method', 'disbursed_at']);
            $table->index('farmer_payment_id');
            $table->index('paid_by_user_id');
            $table->index('signature_evidence_id');
            $table->index('created_by_user_id');
        });

        /*
         * Payout details. Nullable throughout because most smallholders in the
         * dairy belt have none — the plan's recommended v1 channel is cash at
         * the collection point precisely because of that.
         */
        Schema::table('farmers', function (Blueprint $table): void {
            $table->string('payout_method', 24)->nullable()->after('status');
            $table->string('bank_name')->nullable()->after('payout_method');
            $table->string('bank_account_masked', 32)->nullable()->after('bank_name');
            $table->string('mobile_money_number', 32)->nullable()->after('bank_account_masked');
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table): void {
            $table->dropColumn(['payout_method', 'bank_name', 'bank_account_masked', 'mobile_money_number']);
        });

        Schema::dropIfExists('farmer_payment_disbursements');
        Schema::dropIfExists('farmer_payment_deliveries');
        Schema::dropIfExists('farmer_payments');
        Schema::dropIfExists('payment_runs');
    }
};
