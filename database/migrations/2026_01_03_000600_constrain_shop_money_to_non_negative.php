<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ARCH-6 / NFR-5 — money is kobo, and kobo the shop is owed is never negative.
 *
 * `sales.total_minor`, `sale_items.amount_minor` and `sale_items.unit_price_minor`
 * were declared `unsignedBigInteger` in 2026_01_01_001400, which reads like a
 * backstop and is not one. ARCH-1 names PostgreSQL and `.env.example` targets it;
 * Laravel's Postgres grammar emits a plain `bigint` and drops the `unsigned`
 * silently. A unit price of '-500' therefore reached the column, offset the other
 * lines on the same receipt, understated the day's revenue while the goods still
 * left the counter — and on a milk_deduction sale (BR-30) wrote a NEGATIVE pending
 * deduction, which is a standing credit against a farmer who bought something.
 *
 * The other half is the debtor. A `credit` sale with no cooperative and a
 * `milk_deduction` sale with no farmer both book revenue against nobody: the
 * customer holds the goods and a receipt, and no account anywhere can ever be
 * settled against it.
 *
 * SaleService refuses all of this first (BR-26, BR-25, BR-30). These constraints
 * are the layer underneath, for every path that does not go through it — a direct
 * UPDATE, an importer, whatever Phase 7 turns out to be.
 *
 * DM-1's precedent decides the shape: SQLite cannot ADD a CHECK to an existing
 * table, so these exist on the production drivers only, exactly as the deliveries
 * check does. The service refusal is what the rule tests prove, because that is
 * the layer every caller passes through.
 *
 * On an existing database with sales already recorded, reconcile any credit sale
 * with no cooperative before migrating — PostgreSQL validates a new CHECK against
 * the rows already there and will refuse rather than truncate the problem away.
 */
return new class extends Migration
{
    /**
     * Constraint name => predicate that must hold.
     *
     * @return array<string, array{0: string, 1: string}> name => [table, predicate]
     */
    private function checks(): array
    {
        return [
            'sales_total_minor_non_negative' => ['sales', 'total_minor >= 0'],
            'sales_amount_received_non_negative' => ['sales', 'amount_received_minor >= 0'],
            'sale_items_unit_price_non_negative' => ['sale_items', 'unit_price_minor >= 0'],
            'sale_items_amount_non_negative' => ['sale_items', 'amount_minor >= 0'],

            // BR-30 — a deduction of zero is not a deduction, and a negative one
            // is a payment the shop never made.
            'pending_deductions_amount_positive' => ['pending_farmer_deductions', 'amount_minor > 0'],

            // Credit and milk deduction are debts. A debt names who owes it.
            'sales_credit_names_a_cooperative' => [
                'sales',
                "payment_method <> 'credit' OR cooperative_id IS NOT NULL",
            ],
            'sales_deduction_names_a_farmer' => [
                'sales',
                "payment_method <> 'milk_deduction' OR farmer_id IS NOT NULL",
            ],
        ];
    }

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        foreach ($this->checks() as $name => [$table, $predicate]) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                $table,
                $name,
                $predicate,
            ));
        }
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }

        foreach ($this->checks() as $name => [$table, $predicate]) {
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
        }
    }

    private function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }
};
