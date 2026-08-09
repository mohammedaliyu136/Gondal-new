<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-15 — the cooperative deduction percentages, effective-dated.
 *
 * §6.6 put savings_deduction_pct, levy_pct and social_contribution_minor on
 * `cooperatives` as plain columns, updated in place. BR-13's argument for
 * grade_rates applies to them word for word — "changing a rate inserts a row; it
 * never updates one, so no historical figure can move" — and was not applied
 * here. A cooperative that moves its levy from 2% to 3% in September destroyed
 * the evidence of what August's members were owed, and BR-15's snapshot had
 * nowhere in the schema to live.
 *
 * This is the history, insert-only. The columns on `cooperatives` stay as the
 * live convenience copy so every existing read keeps working.
 *
 * STILL BLOCKED ON §15.1 — this makes BR-15 satisfiable; it does not satisfy it.
 * There is no payable-amount calculation anywhere in the system to snapshot AT,
 * because the payment module's home is an open decision. Whoever builds Phase 7
 * must copy the percentages in force onto the payment line at the moment the
 * payable is computed (savings_deduction_pct_snapshot / levy_pct_snapshot); this
 * table is what makes "in force on that date" answerable rather than lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperative_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained('cooperatives')->cascadeOnDelete();

            $table->decimal('savings_deduction_pct', 5, 2)->default(0);
            $table->decimal('levy_pct', 5, 2)->default(0);
            $table->unsignedBigInteger('social_contribution_minor')->default(0);   // ARCH-6

            $table->date('effective_from');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            // Exactly as grade_rates: one set of percentages per cooperative per
            // date, so a same-day correction replaces the row it corrects rather
            // than leaving two candidates for "the rate in force".
            $table->unique(['cooperative_id', 'effective_from']);
            $table->index(['cooperative_id', 'effective_from']);
            // NFR-3 — every foreign key leads an index.
            $table->index('created_by_user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        /*
         * Backfill the opening row from what each cooperative currently holds.
         * A history that begins the day this migration ran would say the
         * percentages were undefined before it, which is worse than saying they
         * were what the record still shows — registration is the date they
         * actually took effect.
         */
        foreach (DB::table('cooperatives')->whereNull('deleted_at')->get() as $cooperative) {
            DB::table('cooperative_rates')->insert([
                'cooperative_id' => $cooperative->id,
                'savings_deduction_pct' => $cooperative->savings_deduction_pct,
                'levy_pct' => $cooperative->levy_pct,
                'social_contribution_minor' => $cooperative->social_contribution_minor,
                'effective_from' => $cooperative->registered_on
                    ?? substr((string) $cooperative->created_at, 0, 10),
                'created_by_user_id' => $cooperative->created_by_user_id,
                'created_at' => $cooperative->created_at,
                'updated_at' => $cooperative->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_rates');
    }
};
