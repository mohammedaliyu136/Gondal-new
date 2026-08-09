<?php

use App\Models\Delivery;
use App\Support\Volume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-12 — "Adjustments are never silent." A delivery adjustment was.
 *
 * THE PROBLEM. `AdjustmentService::record()` wrote an `adjustments` row and
 * stopped there. For a consignment that is right: BR-8 folds the total into
 * `litres_confirmed` at confirmation. For a DELIVERY nothing folded it into
 * anything. `deliveries.litres_accepted` is pinned by DM-1's CHECK to
 * `litres_presented − litres_rejected`, so it cannot absorb a correction, and no
 * column, accessor or query anywhere read `Delivery->adjustments()`
 * arithmetically. §17's own worked example — DEL-0009, 28 L accepted, −1 L
 * adjustment, 27 L payable, ₦6,615 net — was reproducible only by an inline
 * expression in the demo seeder's console report. Meanwhile the delivery screen
 * told the operator "Litres cannot be edited here. Use Record adjustment to
 * change a volume." It changed no volume anyone could query.
 *
 * The delivery is the FARMER's payment unit, so the figure that has to move is
 * the farmer's payable volume. Two columns rather than one:
 *
 *   litres_adjusted  the signed running total of the delivery's adjustments,
 *                    kept so a reader can see the correction as well as its
 *                    result without re-summing a polymorphic table
 *   litres_payable   litres_accepted + litres_adjusted — the number a payment
 *                    run reads, stored rather than derived for the same reason
 *                    litres_accepted is (DM-1): a figure money is computed from
 *                    should not depend on a formula being remembered at every
 *                    call site
 *
 * DM-1's CHECK is deliberately left exactly as it was. It constrains
 * litres_accepted against presented and rejected, which is still true and still
 * worth enforcing; the adjustment lives beside it rather than inside it, so the
 * arithmetic the point recorded and the correction made afterwards stay
 * separately visible. Both are written in one transaction by AdjustmentService,
 * which is the only writer.
 *
 * NOT decided here (see the note in AdjustmentService::applyToDelivery): whether
 * BR-7's `litres_dispatched` should sum `litres_payable` instead of
 * `litres_accepted`. That is the open business question — in §17's scenario the
 * litre lost decanting into the centre can is deducted from the farmer while
 * still counted in the 28 L the consignment carried — and it is not this
 * migration's to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->decimal('litres_adjusted', 10, 2)->default(0)->after('litres_accepted');
            $table->decimal('litres_payable', 10, 2)->default(0)->after('litres_adjusted');
        });

        /*
         * Backfill from the adjustments that already exist, so the demo dataset's
         * −1 L on §17's DEL-0009 becomes readable by the application the moment
         * this lands rather than only for deliveries adjusted from now on.
         *
         * NFR-5 — the arithmetic goes through Volume in integer centilitres and
         * the result is handed to the column as a decimal string. Summing in SQL
         * and writing the sum back would put a float in the middle of a figure a
         * farmer is paid from, on the one connection (SQLite) where DECIMAL is
         * not exact.
         */
        DB::table('deliveries')->update([
            'litres_adjusted' => '0.00',
            'litres_payable' => DB::raw('litres_accepted'),
        ]);

        $deltas = DB::table('adjustments')
            ->where('adjustable_type', Delivery::class)
            ->whereNull('deleted_at')
            ->get(['adjustable_id', 'litres_delta'])
            ->groupBy('adjustable_id');

        foreach ($deltas as $deliveryId => $rows) {
            $adjusted = Volume::sum($rows->pluck('litres_delta')->all());
            $accepted = DB::table('deliveries')->where('id', $deliveryId)->value('litres_accepted');

            DB::table('deliveries')->where('id', $deliveryId)->update([
                'litres_adjusted' => $adjusted,
                'litres_payable' => Volume::add($accepted, $adjusted),
            ]);
        }

        Schema::table('deliveries', function (Blueprint $table): void {
            // NFR-3 — a payment run reads a day's payable volume per point.
            $table->index(['delivered_at', 'litres_payable'], 'deliveries_delivered_at_payable_index');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex('deliveries_delivered_at_payable_index');
            $table->dropColumn(['litres_adjusted', 'litres_payable']);
        });
    }
};
