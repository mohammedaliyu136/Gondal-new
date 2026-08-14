<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Phase 7 — the cash book. The second leg of every payout.
 *
 * `farmer_payment_disbursements` records what a FARMER received. Nothing
 * anywhere recorded what the officer DREW, carried to the point, and brought
 * back. That is one leg of a two-leg movement, and the missing leg is the fraud
 * surface docs/PLAN-FARMER-PAYMENTS.md §7 calls the largest in the ERP:
 *
 *     "The officer took ₦500,000 to Girei — what came back?"
 *
 * had no answer. An officer could draw half a million, hand over four hundred
 * thousand, record all of it correctly, and keep the rest, and every screen in
 * the system would agree that every farmer had been paid.
 *
 * WHAT THIS DOES AND DOES NOT DO. It cannot stop that. It makes the difference
 * VISIBLE and ATTRIBUTABLE: drawn less disbursed less returned is a number with
 * somebody's name on it, computed by the system rather than by the person
 * holding the money. A variance is not an accusation — a rider paid in the field
 * from the same bag, a note that would not change, a farmer who did not come —
 * and the point is that it has to be explained rather than absorbed.
 *
 * TWO PEOPLE, ALWAYS. `issued_by_user_id` and `drawn_by_user_id` must differ,
 * and so must `drawn_by` and `received_back_by`. A float somebody issues to
 * themselves and signs back in themselves is a spreadsheet, not a control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_floats', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            /*
             * What the money is FOR. Polymorphic because a float is drawn
             * against a farmer payment run or a transport payment run, and both
             * are paid at the same centre on the same morning out of the same
             * bag. Nullable because a general operating float is a real thing
             * and refusing to record one would push it back off the books.
             */
            $table->nullableMorphs('purpose');

            $table->foreignId('collection_center_id')->nullable()
                ->constrained('collection_centers')->nullOnDelete();

            $table->integer('amount_drawn_minor');
            $table->foreignId('drawn_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');

            $table->integer('amount_returned_minor')->nullable();
            $table->foreignId('received_back_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();

            /*
             * Stamped at reconciliation, not recomputed on read.
             *
             * Same reasoning as `cooperative_entries.balance_after_minor`: a
             * disbursement corrected next month must not silently restate a
             * variance somebody was already asked to explain.
             */
            $table->integer('disbursed_minor')->nullable();
            $table->integer('variance_minor')->nullable();
            $table->text('variance_explanation')->nullable();

            $table->string('status', 16)->default('open');   // open | reconciled
            $table->text('notes')->nullable();

            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // NFR-3 — every FK leads an index. `status` and `drawn_by` together
            // are the query the whole screen exists for: who is still holding
            // money.
            $table->index(['status', 'drawn_by_user_id']);
            $table->index('collection_center_id');
            $table->index('drawn_by_user_id');
            $table->index('issued_by_user_id');
            $table->index('received_back_by_user_id');
            $table->index('created_by_user_id');
            $table->index('opened_at');
            $table->index('is_test');
        });

        if (! DB::table('sequences')->where('key', 'cash_floats')->exists()) {
            DB::table('sequences')->insert([
                'key' => 'cash_floats',
                'label' => 'Cash float',
                'prefix' => 'CASH',
                'digits' => 4,
                'reset_period' => 'yearly',
                'reference_format' => '{prefix}-{year}-{number}',
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_floats');

        DB::table('sequences')->where('key', 'cash_floats')->delete();
    }
};
