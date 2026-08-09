<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §6.2 the milk flow: batch ← consignment ← delivery.
 *
 * Created in reverse chain order because the child rows carry the parent key
 * (deliveries.consignment_id, consignments.batch_id), which is DM-2's design:
 * a delivery has no consignment until the agent dispatches.
 *
 * ARCH-6 — every volume is decimal(10,2) litres, every amount integer kobo.
 * NFR-4  — consignments and batches carry `lock_version` for optimistic locking.
 * BR-14  — the applicable rate is SNAPSHOTTED onto the consignment, both the
 *          grade_rate_id and the numeric rate, so payment never joins live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('collection_center_id')->constrained('collection_centers')->restrictOnDelete();

            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();

            // BR-9 — Σ litres_confirmed of its consignments.
            $table->decimal('litres_dispatched', 10, 2)->default(0);
            $table->unsignedInteger('containers')->nullable();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();

            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->decimal('litres_received', 10, 2)->nullable();
            $table->unsignedInteger('containers_received')->nullable();

            // BR-10 — received − dispatched. Negative for a shortfall, so signed.
            $table->decimal('discrepancy_litres', 10, 2)->nullable();
            $table->foreignId('discrepancy_cause_id')->nullable()->constrained('discrepancy_causes')->nullOnDelete();

            $table->decimal('litres_rejected_at_factory', 10, 2)->default(0);
            $table->foreignId('rejection_reason_id')->nullable()->constrained('rejection_reasons')->nullOnDelete();

            // BR-11 — required before release when tolerance is exceeded.
            $table->text('supervisor_notes')->nullable();

            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // §8 — in_transit|reconciled|discrepancy|released
            $table->string('status', 24)->default('in_transit');

            $table->unsignedBigInteger('lock_version')->default(0);   // NFR-4
            $table->boolean('is_test')->default(false);               // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();                                     // ARCH-8

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'collection_center_id']);
            $table->index('dispatched_at');
            $table->index('is_test');
        });

        Schema::create('consignments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('collection_point_id')->constrained('collection_points')->restrictOnDelete();
            $table->foreignId('collection_center_id')->constrained('collection_centers')->restrictOnDelete();

            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();

            // BR-7 — Σ litres_accepted of its deliveries.
            $table->decimal('litres_dispatched', 10, 2)->default(0);
            $table->unsignedInteger('containers')->nullable();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();

            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // BR-8 — dispatched + Σ adjustments − rejected at center.
            $table->decimal('litres_confirmed', 10, 2)->nullable();

            $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();

            // BR-14 — the snapshot. Downstream payment reads these two columns,
            // never grade_rates through a live join.
            $table->foreignId('grade_rate_id')->nullable()->constrained('grade_rates')->nullOnDelete();
            $table->unsignedBigInteger('rate_per_litre_minor')->nullable();

            $table->decimal('litres_rejected_at_center', 10, 2)->default(0);
            $table->foreignId('rejection_reason_id')->nullable()->constrained('rejection_reasons')->nullOnDelete();

            $table->decimal('intake_temperature_c', 5, 2)->nullable();

            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->text('officer_notes')->nullable();

            // §8 — awaiting_confirmation|confirmed|adjusted|partly_rejected
            $table->string('status', 24)->default('awaiting_confirmation');

            $table->unsignedBigInteger('lock_version')->default(0);   // NFR-4
            $table->boolean('is_test')->default(false);               // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // NFR-3 — named explicitly in the requirement.
            $table->index(['status', 'collection_center_id']);
            $table->index(['collection_point_id', 'dispatched_at']);
            $table->index('batch_id');
            $table->index('is_test');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('collection_point_id')->constrained('collection_points')->restrictOnDelete();
            $table->foreignId('farmer_id')->constrained('farmers')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at');

            $table->decimal('litres_presented', 10, 2);
            $table->decimal('litres_rejected', 10, 2)->default(0);

            /*
             * DM-1 — stored, not computed on read, and guaranteed by a check
             * constraint.
             *
             * SQLite cannot ALTER a constraint in, so there it is declared
             * inline (MySQL and PostgreSQL take the ALTER below). It is also
             * expressed with round(..., 2) on SQLite for a reason worth stating:
             * SQLite gives a DECIMAL column NUMERIC affinity and stores
             * non-integers as binary floats, so `29.16 = 33.16 - 4.00` is false
             * there by a few femtolitres. Rounding to the column's own declared
             * precision keeps the guard exact at the precision the schema
             * promises. On MySQL and PostgreSQL DECIMAL is exact and no rounding
             * is needed — which is why ARCH-1 names those two, and why NFR-5
             * insists the application itself never uses floats (see Volume,
             * which does all arithmetic in integer centilitres).
             */
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->rawColumn(
                    'litres_accepted',
                    'decimal(10,2) check (round("litres_accepted" - ("litres_presented" - "litres_rejected"), 2) = 0)'
                );
            } else {
                $table->decimal('litres_accepted', 10, 2);
            }

            $table->foreignId('rejection_reason_id')->nullable()->constrained('rejection_reasons')->nullOnDelete();
            $table->unsignedInteger('containers')->nullable();

            // DM-2 — null until the agent dispatches. A fully rejected delivery
            // never receives one.
            $table->foreignId('consignment_id')->nullable()->constrained('consignments')->nullOnDelete();

            $table->text('notes')->nullable();

            // §8 — accepted|partial|rejected
            $table->string('status', 16);

            // BR-3 — a delivery after the cut-off is either rejected with the
            // cut-off reason, or accepted under a logged supervisor override.
            $table->boolean('was_after_cutoff')->default(false);
            $table->time('cutoff_applied')->nullable();
            $table->foreignId('cutoff_override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cutoff_override_reason')->nullable();

            $table->boolean('is_test')->default(false);               // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // NFR-3 — named explicitly in the requirement.
            $table->index(['delivered_at', 'collection_point_id']);
            $table->index(['farmer_id', 'delivered_at']);
            $table->index(['rejection_reason_id', 'delivered_at']);   // BR-5 threshold lookups
            $table->index('consignment_id');
            $table->index('status');
            $table->index('is_test');
        });

        // BR-12 — every adjustment carries a reason and an explanation.
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustable_type');                  // delivery|consignment|batch model class
            $table->unsignedBigInteger('adjustable_id');
            $table->foreignId('adjustment_reason_id')->constrained('adjustment_reasons')->restrictOnDelete();

            // Signed: −1.00 L is a deduction, +1.00 L a top-up.
            $table->decimal('litres_delta', 10, 2);

            $table->text('explanation');                        // BR-12 — never silent
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['adjustable_type', 'adjustable_id']);
        });

        // BR-4 — grading is blocked until every required test is recorded.
        Schema::create('quality_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_id')->constrained('consignments')->cascadeOnDelete();
            $table->foreignId('quality_test_definition_id')->nullable()
                ->constrained('quality_test_definitions')->nullOnDelete();

            // Snapshots of the definition at the time of testing, so retiring a
            // test definition never rewrites a recorded result.
            $table->string('test_type', 32);
            $table->string('reading', 32)->nullable();
            $table->string('acceptable_range', 64)->nullable();

            $table->boolean('passed');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['consignment_id', 'test_type']);
        });

        $this->addDeliveryCheckConstraint();
    }

    /**
     * DM-1 — "Enforce with a database check constraint."
     *
     * SQLite already has it inline (see the column definition above); MySQL 8
     * and PostgreSQL both accept a table-level CHECK by ALTER.
     */
    private function addDeliveryCheckConstraint(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE deliveries ADD CONSTRAINT deliveries_litres_accepted_check
             CHECK (litres_accepted = litres_presented - litres_rejected)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_tests');
        Schema::dropIfExists('adjustments');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('consignments');
        Schema::dropIfExists('batches');
    }
};
