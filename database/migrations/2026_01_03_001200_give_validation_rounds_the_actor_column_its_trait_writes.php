<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6 conventions — "Every table recording an action has created_by_user_id."
 *
 * THE PROBLEM. `ValidationRound` uses the `RecordsActor` trait, which sets
 * `created_by_user_id` on every create — and `validation_rounds` has no such
 * column. Any authenticated call to `FarmerValidationService::openRound()`
 * therefore died on:
 *
 *     SQLSTATE[HY000]: table validation_rounds has no column named
 *     created_by_user_id
 *
 * It was never noticed because it was never called. `openRound()` had no route,
 * no controller action, no UI and no test: M&E could schedule revalidations one
 * farmer at a time and the bulk path existed only as service code nobody had
 * reached. Wiring the round-opening screen is what ran it for the first time.
 *
 * WHY THE COLUMN RATHER THAN THE TRAIT. `opened_by_user_id` already records who
 * opened the round, so the obvious alternative is to exempt this model from
 * `RecordsActor`. That is the wrong way round. The trait is the convention every
 * other actor-recording table in the schema follows — this is the only model
 * carrying it without the column — and the trait also writes `is_test`
 * (TEST-4 / BR-35), which `validation_rounds` DOES have and does rely on.
 * Removing the trait to fix a missing column would quietly stop tagging test
 * activity on rounds, and the aggregates that exclude test data would start
 * counting them.
 *
 * The two columns are not redundant in the way they look. `opened_by_user_id`
 * is a domain fact M&E sets and could in principle re-attribute; the trait's
 * column is the system's own record of who was signed in when the row appeared.
 * Everywhere else in this schema both exist side by side for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('validation_rounds', 'created_by_user_id')) {
            return;
        }

        Schema::table('validation_rounds', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('opened_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            // NFR-3 — every foreign key leads an index, and there is a test that
            // says so for the whole schema.
            $table->index('created_by_user_id', 'validation_rounds_created_by_user_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('validation_rounds', 'created_by_user_id')) {
            return;
        }

        Schema::table('validation_rounds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
