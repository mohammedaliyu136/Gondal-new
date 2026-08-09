<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-4 — the re-grade control break.
 *
 * A clerk keeps `milk.grade.create`, because a grader unavailable at 06:00 blocks
 * the whole morning. The break is on RE-grading, which moves money after the fact
 * for milk already accepted: `milk.grade.edit`, a mandatory reason, and a weekly
 * exceptions list somebody reads.
 *
 * These columns are what that list is built from. The audit log already records
 * the change — DM-3 makes it append-only — but a list of exceptions has to be a
 * query over consignments, not a scan of every audit entry ever written, or it is
 * too slow to be read weekly and so it is not read at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            $table->timestamp('regraded_at')->nullable()->after('rate_per_litre_minor');
            $table->unsignedBigInteger('regraded_by_user_id')->nullable()->after('regraded_at');
            $table->string('regrade_reason')->nullable()->after('regraded_by_user_id');

            $table->foreign('regraded_by_user_id')->references('id')->on('users')->nullOnDelete();

            // NFR-3 — every foreign key leads an index. A foreign key implies one
            // on MySQL only; this project runs PostgreSQL, where it does not.
            $table->index('regraded_by_user_id');

            // The exceptions list is "re-grades, most recent first".
            $table->index('regraded_at');
        });
    }

    public function down(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            $table->dropForeign(['regraded_by_user_id']);
            $table->dropIndex(['regraded_by_user_id']);
            $table->dropIndex(['regraded_at']);
            $table->dropColumn(['regraded_at', 'regraded_by_user_id', 'regrade_reason']);
        });
    }
};
