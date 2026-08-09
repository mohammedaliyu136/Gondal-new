<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Farmer revalidation — M&E decides WHO needs checking and WHO checks them;
 * field staff do the checking.
 *
 * The shape is deliberately the one `quality_followups` already established: a
 * subject, a reason drawn from reference data, an open/closed lifecycle, and a
 * closure attributable to the person who did the work. A second, different
 * shape for the same idea would be a second thing to learn and a second thing
 * to get wrong.
 *
 * What is new here is the ASSIGNMENT. A follow-up is raised by the system and
 * closed by whoever gets to it; a revalidation is directed — one person is
 * asked, by a named person, by a date. That is the whole point of the feature,
 * so `assigned_to_user_id`, `assigned_by_user_id` and `due_on` are columns
 * rather than a convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * §9 — why a farmer was picked. Reference data, so M&E can add
         * "post-flood verification" in October without a release.
         */
        Schema::create('validation_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('help_text')->nullable();

            // Some reasons are raised by the system (a farmer is overdue), some
            // only by hand. The flag is what lets a future scheduler pick the
            // right one without hardcoding its code.
            $table->boolean('is_automatic')->default(false);

            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('created_by_user_id');   // NFR-3
        });

        /*
         * The bulk decision. "Everyone in Kumbotso who has not been seen since
         * March" is one act of judgement by M&E, and it should be recorded once
         * — with the criteria they used — rather than inferred later from a
         * hundred identical assignments.
         *
         * A round is optional: an assignment may stand alone.
         */
        Schema::create('validation_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('name');

            // In M&E's own words. Not a machine-readable filter: the query that
            // produced the list is worth less six months later than the sentence
            // explaining why it was the right list.
            $table->text('criteria')->nullable();

            $table->foreignId('validation_reason_id')->nullable()
                ->constrained('validation_reasons')->nullOnDelete();

            $table->date('opens_on')->nullable();
            $table->date('due_on')->nullable();

            /*
             * M&E's call, per round: does a submitted revalidation stand on its
             * own, or does it wait for review?
             *
             * Both are legitimate. A periodic sweep of phone numbers does not
             * need a second pair of eyes; a round triggered by a rejection
             * pattern does. Making it a round-level decision means M&E chooses
             * per campaign instead of the system choosing for them, and the
             * default comes from Settings rather than from code.
             */
            $table->boolean('auto_approve')->default(true);

            $table->string('status', 16)->default('open');   // open|closed|cancelled
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('opened_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->boolean('is_test')->default(false);      // TEST-1
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_on']);
            // NFR-3 — every foreign key leads an index.
            $table->index('opened_by_user_id');
            $table->index('validation_reason_id');
        });

        // The assignment, and its outcome.
        Schema::create('farmer_validations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();

            $table->foreignId('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignId('validation_round_id')->nullable()
                ->constrained('validation_rounds')->nullOnDelete();
            $table->foreignId('validation_reason_id')->nullable()
                ->constrained('validation_reasons')->nullOnDelete();

            /*
             * Null means "whoever covers this farmer" — a pool assignment.
             *
             * Supported, but not the default. A pool assignment is nobody's
             * until somebody takes it, and the ones nobody takes are exactly the
             * farmers hardest to reach. Naming a person makes the gap visible on
             * that person's list instead of invisible in a shared one.
             */
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('assigned_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->date('due_on')->nullable();

            // pending|submitted|accepted|returned|cancelled
            $table->string('status', 16)->default('pending');

            // confirmed|corrected|not_found|refused
            $table->string('outcome', 16)->nullable();

            /*
             * What actually changed, both sides. "Corrected" is worthless
             * without it — a reviewer needs to see that a phone number moved,
             * not merely that somebody pressed a button.
             */
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('findings')->nullable();

            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();

            // ARCH-2 / AUDIT-4 — web or the field app.
            $table->string('source', 16)->default('web');

            $table->boolean('is_test')->default(false);      // TEST-1
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // "My assignments" on a phone, and "what is still open" for M&E.
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['status', 'due_on']);
            $table->index(['farmer_id', 'status']);
            $table->index('validation_round_id');
            $table->index('validation_reason_id');
            // NFR-3 — the attribution columns are foreign keys too.
            $table->index('assigned_by_user_id');
            $table->index('submitted_by_user_id');
            $table->index('reviewed_by_user_id');
            $table->index('created_by_user_id');
        });

        Schema::table('farmers', function (Blueprint $table) {
            /*
             * Denormalised so "who is overdue" is an indexed comparison rather
             * than a correlated scan of every farmer's validation history — the
             * question M&E asks most, over 1,842 rows and growing.
             *
             * Written ONLY by an accepted validation whose outcome was confirmed
             * or corrected. A farmer nobody could find has not been validated,
             * and must not look as though they have.
             */
            $table->date('last_validated_on')->nullable()->after('enrolled_on');

            $table->index('last_validated_on');
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->dropIndex(['last_validated_on']);
            $table->dropColumn('last_validated_on');
        });

        Schema::dropIfExists('farmer_validations');
        Schema::dropIfExists('validation_rounds');
        Schema::dropIfExists('validation_reasons');
    }
};
