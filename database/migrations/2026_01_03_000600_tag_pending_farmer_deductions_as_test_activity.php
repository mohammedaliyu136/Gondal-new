<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-35 / TEST-4 — "test accounts are excluded from all reports, aggregates and
 * payroll", and a test run must leave nothing behind that touches real money.
 *
 * `sales` carries `is_test` and Sale declares `$tagsTestActivity`, so a test
 * user's sale is correctly absent from every revenue figure. The money
 * consequence it creates was not: BR-30 writes a `pending_farmer_deductions` row
 * with no marker of any kind, against a REAL farmer (USER-1 — farmers are
 * records, not accounts, so there is no test farmer to point at instead).
 *
 * §15.1 makes that permanent rather than transient: Phase 7 will consume this
 * table as it stands, and with nothing on the row saying which entries were a
 * rehearsal it would deduct them from a farmer who never bought anything.
 *
 * Indexed because BR-35's exclusion is a predicate on every payment-run query,
 * which is the same reason `sales.is_test` is indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_farmer_deductions', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('status');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('pending_farmer_deductions', function (Blueprint $table): void {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
