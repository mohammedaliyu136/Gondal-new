<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-13 / BR-14 — separate the instant the rate was priced against from the
 * instant the operator says the consignment was confirmed.
 *
 * They used to be one column, and that column arrives from the request. BR-14
 * was satisfied to the letter throughout — the row and the number were both
 * snapshotted — which is exactly why nothing caught it: the snapshot was
 * faithful, it was the anchor that was forged. An officer holding nothing but
 * `milk.consignment.confirm.edit` posted a `confirmed_at` a week back and a
 * 100 L consignment snapshotted the pre-cut ₦250/L instead of ₦200/L. The
 * person keying the record chose what the farmer was paid, with no supervisor
 * in the path and no exception entry behind it.
 *
 * `rate_anchored_at` is stamped by the server inside confirm() and is the only
 * thing BR-13's "the rate in force on that day" is ever resolved against —
 * including by the later grade() and regrade() calls, which read it rather
 * than `confirmed_at` so that fixing the write point fixes all three.
 *
 * The backfill is `confirmed_at` because that IS what those rows were priced
 * at: BR-13 says changing a rule must never move a historical figure, and
 * leaving the column null on already-confirmed consignments would re-anchor
 * every later re-grade of them to today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignments', function (Blueprint $table): void {
            $table->timestamp('rate_anchored_at')->nullable()->after('rate_per_litre_minor');
        });

        DB::table('consignments')
            ->whereNotNull('confirmed_at')
            ->update(['rate_anchored_at' => DB::raw('confirmed_at')]);
    }

    public function down(): void
    {
        Schema::table('consignments', function (Blueprint $table): void {
            $table->dropColumn('rate_anchored_at');
        });
    }
};
