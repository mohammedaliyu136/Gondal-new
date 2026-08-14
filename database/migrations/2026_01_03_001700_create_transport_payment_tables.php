<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Phase 7 — paying the riders and drivers who move the milk.
 *
 * `trips` has carried `fee_minor` and a `payment_status` with three states since
 * phase 3, and `TripService` has only ever written the first of them. Nothing in
 * the system could move a trip from `queued` to `approved` to `paid`, there was
 * no screen behind the `logistics.payments` permission, and `payment_run_id`
 * was a column pointing at a table that did not exist. Every leg run since the
 * network opened has accrued a fee nobody can pay.
 *
 * SHAPED LIKE THE FARMER RUN, ON PURPOSE. Same states, same workflow engine,
 * same claim-table guarantee, same disbursement-with-evidence. Accounts should
 * learn one shape for "a batch of money owed to many people", not three.
 *
 * THE CLAIM TABLE IS THE POINT. `transport_payment_trips.trip_id` is UNIQUE, so
 * "a trip is paid exactly once, ever" is a database fact rather than a service
 * guard. `trips.payment_status` and `trips.payment_run_id` are kept in step
 * because the logistics screen filters on them, but they are a convenience for
 * that screen — the constraint is here.
 *
 * WHAT IS DELIBERATELY ABSENT: deductions. A farmer payment carries savings, a
 * levy and shop credit; a rider is paid their fee. Fuel advances and damage
 * recoveries are real in this business and are NOT modelled, because nothing in
 * the system records one today and inventing the column would invite somebody to
 * start using it without a rule behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_payment_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            /*
             * NETWORK is a real scope here, unlike on the farmer run.
             *
             * `trips.collection_center_id` is nullable, so a centre-scoped run
             * can never reach a trip whose centre was not recorded. On the
             * farmer side that would be a gap; here it would be a rider working
             * every week and never appearing on a payment sheet. A network run
             * claims everything unclaimed and is the safety valve.
             */
            $table->string('scope_type', 32);            // collection_center | network
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft');  // draft|processing|approved|paid|cancelled

            $table->integer('total_minor')->default(0);
            $table->integer('trip_count')->default(0);
            $table->integer('driver_count')->default(0);

            $table->foreignId('workflow_instance_id')->nullable()
                ->constrained('workflow_instances')->nullOnDelete();
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // NFR-3 — every FK leads an index, and the list screen sorts on status.
            $table->index(['scope_type', 'scope_id']);
            $table->index('status');
            $table->index('workflow_instance_id');
            $table->index('run_by_user_id');
            $table->index('approved_by_user_id');
            $table->index('created_by_user_id');
            $table->index('is_test');
        });

        Schema::create('transport_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transport_payment_run_id')
                ->constrained('transport_payment_runs')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();

            $table->integer('trip_count')->default(0);
            $table->decimal('litres_carried', 12, 2)->default(0);
            $table->integer('amount_minor')->default(0);

            $table->string('status', 16)->default('payable');   // payable|paid|reversed

            // Every trip that made this figure, with its fee and its route, so a
            // rider disputing the total at a centre can be shown the legs.
            $table->json('breakdown')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One line per driver per run. A second line for the same driver is
            // a bug that would pay them twice off one sheet.
            $table->unique(['transport_payment_run_id', 'driver_id']);
            $table->index('driver_id');
            $table->index('status');
            $table->index('created_by_user_id');
            $table->index('is_test');
        });

        Schema::create('transport_payment_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transport_payment_id')
                ->constrained('transport_payments')->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained('trips')->restrictOnDelete();

            $table->integer('fee_minor');
            $table->decimal('litres_carried', 10, 2)->default(0);
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->timestamps();

            // THE constraint. A trip is paid exactly once, ever.
            $table->unique('trip_id');
            $table->index('transport_payment_id');
            $table->index('route_id');
        });

        Schema::create('transport_payment_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transport_payment_id')
                ->constrained('transport_payments')->cascadeOnDelete();

            $table->integer('amount_minor');
            $table->string('method', 24);                    // cash|bank|mobile_money
            $table->string('external_reference')->nullable();

            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('transport_payment_id');
            $table->index('paid_by_user_id');
            $table->index('created_by_user_id');
            $table->index('disbursed_at');
            $table->index('is_test');
        });

        /*
         * §9 — the run reference is a row an administrator can edit, not a
         * format baked into code. Yearly, matching the farmer run.
         */
        if (! DB::table('sequences')->where('key', 'transport_payment_runs')->exists()) {
            DB::table('sequences')->insert([
                'key' => 'transport_payment_runs',
                'label' => 'Transport payment run',
                'prefix' => 'TRUN',
                'digits' => 4,
                'reset_period' => 'yearly',
                'reference_format' => '{prefix}-{year}-{number}',
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_payment_disbursements');
        Schema::dropIfExists('transport_payment_trips');
        Schema::dropIfExists('transport_payments');
        Schema::dropIfExists('transport_payment_runs');

        DB::table('sequences')->where('key', 'transport_payment_runs')->delete();
    }
};
