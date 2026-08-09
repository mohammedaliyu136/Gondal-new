<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCOPE-1 + TEST-2 — a validation run must be able to simulate the scope it is
 * validating, and a scope may name several targets.
 *
 * `scope_target_id` holds one. A supervisor who covers two centres therefore had
 * no run that could reproduce their access: the run silently tested one centre
 * and reported a clean scope, which is exactly the false green the protocol
 * exists to prevent.
 *
 * The single column stays and keeps its meaning — every run recorded so far is
 * unchanged and still reads correctly. This column carries the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_test_runs', function (Blueprint $table) {
            $table->json('scope_target_ids')->nullable()->after('scope_target_id');
        });
    }

    public function down(): void
    {
        Schema::table('permission_test_runs', function (Blueprint $table) {
            $table->dropColumn('scope_target_ids');
        });
    }
};
