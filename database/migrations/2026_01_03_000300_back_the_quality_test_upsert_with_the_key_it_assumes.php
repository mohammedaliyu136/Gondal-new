<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BR-4 — give `quality_tests` the uniqueness its writer already assumes.
 *
 * `ConsignmentService::recordQualityTest()` upserts on
 * (consignment_id, quality_test_definition_id). The table carried only a
 * non-unique index on (consignment_id, test_type), so nothing enforced the key
 * the upsert is keyed on: two submissions racing each other both miss the
 * SELECT and both INSERT, and the consignment ends up with two answers to "did
 * it pass the alcohol test?".
 *
 * That is not theoretical here. The confirmation screen posts one row per test
 * from a single form, identified by which submit button was clicked, so
 * repeated clicks on a slow connection are the expected interaction — and
 * BR-4's completeness check only asks whether each required definition has *a*
 * recorded test, so grading is not blocked and the contradiction never
 * surfaces. The screen renders whichever row the ordering happens to return.
 *
 * Partial on the live rows only, following role_user's migration: the table
 * soft-deletes, and a plain unique would make re-recording a test after a
 * deletion collide with a row nobody can see.
 */
return new class extends Migration
{
    private const INDEX = 'quality_tests_consignment_definition_unique';

    public function up(): void
    {
        // Deduplicate first, keeping the most recent reading — the one the
        // screen was already showing.
        $duplicates = DB::table('quality_tests')
            ->selectRaw('consignment_id, quality_test_definition_id, max(id) as keep_id, count(*) as total')
            ->whereNull('deleted_at')
            ->whereNotNull('quality_test_definition_id')
            ->groupBy('consignment_id', 'quality_test_definition_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('quality_tests')
                ->where('consignment_id', $duplicate->consignment_id)
                ->where('quality_test_definition_id', $duplicate->quality_test_definition_id)
                ->whereNull('deleted_at')
                ->where('id', '!=', $duplicate->keep_id)
                ->update(['deleted_at' => now()]);
        }

        if ($this->supportsPartialIndexes()) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON quality_tests (consignment_id, quality_test_definition_id) WHERE deleted_at IS NULL',
                self::INDEX,
            ));
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX '.self::INDEX);
        }
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
