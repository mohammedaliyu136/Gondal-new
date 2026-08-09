<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.9 + §9 — reference data. Every row here is editable through Settings.
 *
 * §18.7 — "No reference data from §9 appears as an enum, constant or config
 * value anywhere in the codebase." Two consequences show up as columns rather
 * than as code:
 *
 *  - BR-3 needs to know which rejection reason means "arrived after the
 *    cut-off". Instead of matching on the code REJ-LATE, the administrator
 *    marks the reason with `is_cutoff_breach`.
 *  - BR-16 needs to know which grade means "rejected". Instead of matching on
 *    GRD-R, the grade carries `is_rejection`.
 *
 * BR-4 needs a list of quality tests to insist upon, so the thresholds on
 * settings.html are rows in `quality_test_definitions`, not settings keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §6.9 settings — free-form key/value for the genuinely scalar knobs
        // (tolerances, cut-offs, cooperative defaults, disabled modules).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 48)->default('general');
            $table->string('label')->nullable();
            $table->text('help_text')->nullable();
            $table->string('value_type', 16)->default('string');   // string|integer|decimal|boolean|time|json
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('group');
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->text('criteria')->nullable();
            $table->string('status', 16)->default('active');       // active|retired|system

            // BR-16 — the grade that represents rejected milk. Marked by the
            // administrator so no rule ever matches on a literal code.
            $table->boolean('is_rejection')->default(false);

            // Seeded rows the administrator may not delete (the Rejected grade).
            $table->boolean('is_system')->default(false);

            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // BR-13 / REF-2 — rates are effective-dated. Changing a rate inserts a
        // row; it never updates one, so no historical figure can move.
        Schema::create('grade_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained('grades')->cascadeOnDelete();
            $table->unsignedBigInteger('rate_per_litre_minor');     // ARCH-6
            $table->date('effective_from');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['grade_id', 'effective_from']);
            $table->index(['grade_id', 'effective_from']);
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // BR-1 — the only reasons selectable anywhere. Free text is never accepted.
        Schema::create('rejection_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('help_text')->nullable();

            // BR-1 — enabled per stage.
            $table->boolean('available_at_point')->default(true);
            $table->boolean('available_at_center')->default(true);
            $table->boolean('available_at_factory')->default(true);

            // BR-5 — thresholds that open a quality follow-up automatically.
            $table->unsignedSmallInteger('followup_threshold')->nullable();
            $table->unsignedSmallInteger('followup_window_days')->nullable();

            // BR-2 / BR-16
            $table->boolean('excluded_from_payment')->default(true);

            // BR-3 — marks the reason that means "after the cut-off".
            $table->boolean('is_cutoff_breach')->default(false);

            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        // BR-12 — every adjustment needs a reason from this list.
        Schema::create('adjustment_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('help_text')->nullable();

            // delivery|consignment|batch|stock — which records may cite it.
            $table->string('applies_to', 24)->default('consignment');

            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // BR-10 / BR-11 — the cause an operator must select for a batch discrepancy.
        Schema::create('discrepancy_causes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('help_text')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // §6.9 activity_types — extension activity vocabulary.
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('help_text')->nullable();

            // BR-5 / Phase 5 acceptance — only some activity types can close a
            // quality follow-up, and which ones is the administrator's call.
            $table->boolean('closes_quality_followup')->default(false);

            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // BR-4 — "only after all configured quality tests are recorded". The
        // thresholds on settings.html are these rows.
        Schema::create('quality_test_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('kind', 16)->default('range');          // range|maximum|minimum|boolean
            $table->decimal('min_value', 12, 4)->nullable();
            $table->decimal('max_value', 12, 4)->nullable();
            $table->string('unit', 16)->nullable();
            $table->string('expected_boolean_label')->nullable();  // e.g. "no coagulation"
            $table->boolean('is_required')->default(true);         // BR-4
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // §6.9 sequences — reference numbering, editable per record type.
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();                   // deliveries|consignments|batches|trips|requisitions|field_activities|sales|payslips
            $table->string('label');
            $table->string('prefix', 16);
            $table->unsignedTinyInteger('digits')->default(4);
            $table->string('reset_period', 16)->default('never');   // daily|monthly|yearly|never

            // Kept as data so REQ-2026-0142 and DEL-0009 can differ in shape
            // without a code change. Placeholders: {prefix} {year} {month} {day} {number}
            $table->string('reference_format', 64)->default('{prefix}-{number}');

            $table->unsignedBigInteger('current_value')->default(0);
            $table->date('last_reset_on')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ARCH-7 — replays of a write return the original result.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 8);
            $table->string('path');
            $table->string('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['key', 'user_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('quality_test_definitions');
        Schema::dropIfExists('activity_types');
        Schema::dropIfExists('discrepancy_causes');
        Schema::dropIfExists('adjustment_reasons');
        Schema::dropIfExists('rejection_reasons');
        Schema::dropIfExists('grade_rates');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('settings');
    }
};
