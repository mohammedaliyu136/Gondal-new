<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A sequence that resets on a period must carry that period in its reference.
 *
 * THE DEFECT. `deliveries` (DEL) and `sales` (RCP) were defined with
 * `reset_period = daily` and `reference_format = '{prefix}-{number}'`. The
 * counter went back to 1 each morning, but the reference carried nothing to say
 * WHICH morning — so the second day's DEL-0001 was the same string as the first
 * day's, and `deliveries.reference` is unique.
 *
 * The effect was not subtle. Recording the first milk delivery of day two threw
 * a 500. So did the first shop sale. Those are the two highest-volume operations
 * in the system, and they were broken on every day except the day the demo data
 * was generated — which is exactly why the test suite never saw it: every test
 * runs against a freshly seeded database, on day one.
 *
 * THE FIX. Put the date in the reference: `DEL-20260803-0001`. This keeps what
 * the daily reset was FOR — §17 reads DEL-0009 as "the ninth delivery of the
 * day", and that still reads the same way — while making the string unique.
 * `payslips` (monthly, `{year}{month}`) and `requisitions` (yearly, `{year}`)
 * were already written this way; these two were the exceptions.
 *
 * Existing references are left exactly as they are. They were generated under
 * the old format, they are printed on paper and quoted in conversations, and
 * rewriting them would break every reference anybody already holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliveries', 'sales'] as $key) {
            DB::table('sequences')
                ->where('key', $key)
                ->where('reset_period', 'daily')
                ->where('reference_format', '{prefix}-{number}')
                ->update([
                    'reference_format' => '{prefix}-{year}{month}{day}-{number}',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (['deliveries', 'sales'] as $key) {
            DB::table('sequences')
                ->where('key', $key)
                ->update([
                    'reference_format' => '{prefix}-{number}',
                    'updated_at' => now(),
                ]);
        }
    }
};
