<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BR-19 — a negative requisition line is not a discount, it is a cheaper
 * approval band.
 *
 * The band is chosen once from the total at WorkflowEngine::start() and never
 * recomputed, so a ₦2,000,000 purchase filed as a ₦2,000,000 line plus a
 * −₦1,600,000 line bands at ₦400,000 and routes past the Executive Director and
 * the General Manager. RequisitionService::replaceItems now refuses it, and this
 * is the same rule stated where it cannot be argued with.
 *
 * `unit_price_minor` and `amount_minor` were declared `unsignedBigInteger`,
 * which looks like this guard and is not one: PostgreSQL — the database ARCH-1
 * actually names — has no unsigned integers, and Laravel maps the column to a
 * plain `bigint` there.
 *
 * SQLite takes no ALTER for a CHECK, and rebuilding the table to add one would
 * change the schema the test suite runs against for a rule the service already
 * enforces on every write path. Production is PostgreSQL; that is where this has
 * to hold, and the BR-19 regression test proves the service half everywhere.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), self::DRIVERS, true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE requisition_items ADD CONSTRAINT requisition_items_non_negative_check
             CHECK (unit_price_minor >= 0 AND amount_minor >= 0)'
        );
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), self::DRIVERS, true)) {
            return;
        }

        DB::statement('ALTER TABLE requisition_items DROP CONSTRAINT requisition_items_non_negative_check');
    }
};
