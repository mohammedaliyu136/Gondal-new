<?php

use App\Models\Cooperative;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — somewhere for a farmer's savings deduction to land.
 *
 * Until now the farmer payment run subtracted a savings percentage, a levy and a
 * social contribution from every farmer and credited **nothing**. The money came
 * off the farmer and stopped existing. `cooperative_accounts` held two accounts
 * per cooperative at zero and `cooperative_entries` was empty, while the
 * deductions on `farmer_payments` were real.
 *
 * WHY A THIRD KIND RATHER THAN THE GENERAL ACCOUNT. Savings is members' money
 * the cooperative holds. The general account is the cooperative's own trading
 * account and is drawn down by credit purchases from the shop. Posting savings
 * into it would make a member's savings indistinguishable from the cooperative's
 * working capital, and a shop debt would eat it — which is precisely the harm
 * docs/PLAN-FARMER-PAYMENTS.md warns about when it says a deduction into an
 * unaccountable pool is, from the household's side, a fee.
 *
 * STILL POOLED, NOT PER-FARMER. This does not build increment 7. A member can be
 * told what the cooperative's savings pool holds; they still cannot be told what
 * THEIR share of it is, and the plan is right that this is the deferral most
 * likely to be regretted. It is the difference between money that vanished and
 * money that is somewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cooperatives = DB::table('cooperatives')->whereNull('deleted_at')->pluck('id');

        foreach ($cooperatives as $cooperativeId) {
            $exists = DB::table('cooperative_accounts')
                ->where('cooperative_id', $cooperativeId)
                ->where('kind', Cooperative::ACCOUNT_SAVINGS)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('cooperative_accounts')->insert([
                'cooperative_id' => $cooperativeId,
                'kind' => Cooperative::ACCOUNT_SAVINGS,
                'balance_minor' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        /*
         * Only accounts that never moved. An account carrying entries is a
         * record of members' money and is not something a rollback may delete;
         * DM-2 applies to it exactly as it does to any other financial history.
         */
        $untouched = DB::table('cooperative_accounts')
            ->where('kind', Cooperative::ACCOUNT_SAVINGS)
            ->whereNotIn('id', DB::table('cooperative_entries')->select('cooperative_account_id'))
            ->pluck('id');

        DB::table('cooperative_accounts')->whereIn('id', $untouched)->delete();
    }
};
