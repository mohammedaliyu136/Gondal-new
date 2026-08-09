<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-3 / NFR-1 — index the instant columns the day queries actually filter.
 *
 * NFR-3 was honoured literally: every foreign key leads an index (migration
 * 2026_01_01_009000) and all three composites the requirement names by hand
 * exist. But the requirement's list was written before the screens were, and the
 * two columns the application filters hardest were not on it.
 *
 * `confirmed_at` is the single most-filtered column in the system — the dashboard
 * asks for a day of it seven times per render (DashboardMetrics) and the centre
 * index and detail ask twice more. `reconciled_at` carries the factory day.
 * Neither had an index of any kind, so every one of those queries was a
 * sequential scan over the whole table.
 *
 * This lands with the whereDate() repair and only works because of it: a
 * `whereDate` compiles to `"confirmed_at"::date = ?` on PostgreSQL, and a
 * function on the column means no b-tree index can be used however many are
 * declared. Now that the day is asked for as a half-open instant range through
 * Wat::dayRange(), these two make the difference between a scan and a search.
 *
 * The centre id rides along on the consignments index because SCOPE-4 narrows
 * every one of those aggregates to the viewer's centre, so the two predicates
 * always arrive together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignments', function (Blueprint $table): void {
            $table->index(['confirmed_at', 'collection_center_id'], 'consignments_confirmed_at_center_index');
        });

        Schema::table('batches', function (Blueprint $table): void {
            $table->index('reconciled_at', 'batches_reconciled_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('consignments', function (Blueprint $table): void {
            $table->dropIndex('consignments_confirmed_at_center_index');
        });

        Schema::table('batches', function (Blueprint $table): void {
            $table->dropIndex('batches_reconciled_at_index');
        });
    }
};
