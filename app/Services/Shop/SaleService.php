<?php

namespace App\Services\Shop;

use App\Exceptions\RuleViolationException;
use App\Models\Cooperative;
use App\Models\CooperativeEntry;
use App\Models\Farmer;
use App\Models\PendingFarmerDeduction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Community\CooperativeLedgerService;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §6.7 sales.
 *
 * BR-25 — a retired category is hidden from NEW sales, and credit exposure is a
 *   category flag. History is untouched either way.
 * BR-26 — the whole sale is one transaction; a line that would take stock
 *   negative aborts the lot. Nor may a line carry a price at or below zero.
 * BR-27 — a sale from a category with requires_prescription must carry a
 *   prescription_reference. The flag is a COLUMN, so which categories those are
 *   is the administrator's call (BR-25).
 * BR-30 — a milk_deduction sale creates a pending deduction against the farmer's
 *   next payment.
 *
 * Everything the service refuses, it refuses BEFORE the transaction opens where
 * it can — a guard that fires halfway through is correct only because the
 * rollback saves it, and the rollback is not the thing that should be load
 * bearing.
 */
class SaleService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly AuditLogger $audit,
        private readonly CooperativeLedgerService $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{product_id: int, quantity: float, unit_price_minor?: int|null}>  $lines
     */
    public function record(array $data, array $lines, User $actor): Sale
    {
        if ($lines === []) {
            throw RuleViolationException::make(
                'BR-26',
                'A sale needs at least one line.',
                [],
                'items',
            );
        }

        $requested = array_values(array_unique(array_column($lines, 'product_id')));

        $products = Product::query()
            ->whereIn('id', $requested)
            ->with('category')
            ->get()
            ->keyBy('id');

        // A line naming a product that does not exist (or has been soft-deleted)
        // is a clear refusal rather than a stray array-key error.
        $missing = array_values(array_diff($requested, $products->keys()->all()));

        if ($missing !== []) {
            throw RuleViolationException::make(
                'BR-26',
                'One of the products on this sale no longer exists.',
                ['product_ids' => $missing],
                'items',
            );
        }

        // BR-25 / BR-27 — checked BEFORE anything is written.
        $this->guardCategoryRetired($products);
        $this->guardPrescription($products, $data);
        $this->guardLinePrices($lines, $products);

        $paymentMethod = $this->allowedValue(
            $data['payment_method'] ?? null,
            Sale::PAYMENT_METHODS,
            'payment_method',
            'payment method',
        );

        $customerType = $this->allowedValue(
            $data['customer_type'] ?? null,
            Sale::CUSTOMER_TYPES,
            'customer_type',
            'customer type',
        );

        $this->guardCredit($products, $paymentMethod);

        // Identity lookup for the transaction, not a browse — the officer's own
        // farmer scope ("enrolled by me") must not decide who may buy feed.
        $farmer = ($data['farmer_id'] ?? null) === null
            ? null
            : Farmer::withoutDataScope()->find($data['farmer_id']);

        $cooperative = ($data['cooperative_id'] ?? null) === null
            ? null
            : Cooperative::withoutDataScope()->find($data['cooperative_id']);

        if ($paymentMethod === Sale::PAYMENT_MILK_DEDUCTION && $farmer === null) {
            throw RuleViolationException::make(
                'BR-30',
                'A milk-deduction sale must name the farmer whose payment it comes from.',
                [],
                'farmer_id',
            );
        }

        $this->guardCreditNamesADebtor($paymentMethod, $customerType, $cooperative);

        $sale = DB::transaction(function () use ($data, $lines, $products, $actor, $paymentMethod, $customerType, $farmer, $cooperative): Sale {
            $sale = Sale::query()->create([
                'receipt_no' => Sequences::next('sales'),
                'customer_type' => $customerType,
                'farmer_id' => $farmer?->getKey(),
                'cooperative_id' => $cooperative?->getKey(),
                'customer_name' => $data['customer_name'] ?? null,
                'payment_method' => $paymentMethod,
                'total_minor' => 0,
                'amount_received_minor' => (int) ($data['amount_received_minor'] ?? 0),
                'prescription_reference' => $data['prescription_reference'] ?? null,
                'sales_officer_user_id' => $actor->getKey(),
                'notes' => $data['notes'] ?? null,
                'sold_at' => Wat::instant($data['sold_at'] ?? null) ?? Wat::now(),
            ]);

            $total = 0;

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $products[$line['product_id']];
                $quantity = (float) $line['quantity'];

                $unitPrice = (int) ($line['unit_price_minor'] ?? $product->selling_price_minor);
                $amount = (int) round($quantity * $unitPrice);

                /*
                 * BR-26 — the last line of defence, held even though
                 * guardLinePrices() has already refused every price that could
                 * produce this. Quantity is operator input too, and a rounding
                 * path that ever produced a negative amount would offset the rest
                 * of the receipt in silence rather than fail.
                 */
                if ($amount < 0) {
                    throw RuleViolationException::make(
                        'BR-26',
                        sprintf('The line for %s comes to a negative amount.', $product->name),
                        ['product' => $product->sku, 'amount_minor' => $amount],
                        'items',
                    );
                }

                $total += $amount;

                SaleItem::query()->create([
                    'sale_id' => $sale->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity' => $quantity,
                    'unit_price_minor' => $unitPrice,
                    'amount_minor' => $amount,
                    // BR-29 — snapshotted so margin never needs a live join, and
                    // can be stripped for users without shop.revenue.
                    'unit_cost_minor_snapshot' => $product->cost_price_minor,
                ]);

                // BR-26 — atomic, and it throws rather than going negative.
                $this->stock->decrementForSale($product, $quantity, $sale->getKey(), $sale->receipt_no, $actor);
            }

            $sale->forceFill(['total_minor' => $total])->save();

            /*
             * A credit sale draws down the cooperative's account. Posted inside
             * the sale's own transaction so a sale can never exist without the
             * exposure it created.
             *
             * Unconditional now, and that is the point: the previous version was
             * `if (... && $sale->cooperative_id !== null)`, which quietly covered
             * one of four customer types. A credit sale to a walk-in, a farmer or
             * an internal department posted nothing anywhere, so the goods left
             * against a free-text name with no account, no balance and nothing
             * that could ever be settled. guardCreditNamesADebtor() refuses those
             * before this line is reached, so $cooperative is never null here.
             */
            if ($paymentMethod === Sale::PAYMENT_CREDIT) {
                $this->ledger->recordCreditSale($cooperative, $sale, $total, $actor);
            }

            // BR-30
            if ($paymentMethod === Sale::PAYMENT_MILK_DEDUCTION && $sale->farmer_id !== null) {
                PendingFarmerDeduction::query()->create([
                    'farmer_id' => $sale->farmer_id,
                    'sale_id' => $sale->getKey(),
                    'amount_minor' => $total,
                    'description' => 'One-Stop Shop purchase '.$sale->receipt_no,
                    'status' => PendingFarmerDeduction::STATUS_PENDING,
                ]);
            }

            return $sale;
        });

        $this->audit->created(
            $sale,
            sprintf(
                'Sale %s to %s — %s (%s)',
                $sale->receipt_no,
                $sale->customerLabel(),
                Money::format((int) $sale->total_minor),
                str_replace('_', ' ', $paymentMethod),
            ),
            'One-Stop Shop',
            [
                'lines' => count($lines),
                'payment_method' => $paymentMethod,
                'rules' => ['BR-26', 'BR-27', 'BR-30'],
            ],
            $actor,
        );

        return $sale->load('items.product');
    }

    /**
     * Void a sale.
     *
     * A sale rung up wrong had no remedy at all: no void, no return, and the
     * receipt number lived only in a flash message. The correction has to unwind
     * three things together or it makes the books worse than the mistake did —
     * the stock that left, the deduction standing against a farmer's next
     * payment, and the credit drawn on a cooperative's account.
     *
     * The sale row survives, marked. The customer is holding the receipt, so the
     * record has to remain findable with its correction visible on it.
     */
    public function void(Sale $sale, string $reason, User $actor): Sale
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw RuleViolationException::make(
                'BR-28',
                'A void needs a reason. Corrections are never silent.',
                [],
                'void_reason',
            );
        }

        if ($sale->isVoided()) {
            throw RuleViolationException::make(
                'BR-28',
                sprintf('%s was already voided by %s.', $sale->receipt_no, $sale->voidedBy?->name ?? 'someone else'),
                ['sale' => $sale->receipt_no],
            );
        }

        DB::transaction(function () use ($sale, $reason, $actor): void {
            // 1. The stock comes back.
            foreach ($sale->items as $item) {
                $product = Product::query()->find($item->product_id);

                if ($product !== null) {
                    $this->stock->returnFromVoidedSale(
                        $product,
                        (float) $item->quantity,
                        $sale->getKey(),
                        $sale->receipt_no,
                        $actor,
                    );
                }
            }

            // 2. A pending deduction against the farmer is cancelled. One already
            //    settled is left alone — that money has moved, and reversing it is
            //    a payment-module act that does not exist yet.
            PendingFarmerDeduction::query()
                ->where('sale_id', $sale->getKey())
                ->where('status', PendingFarmerDeduction::STATUS_PENDING)
                ->update([
                    'status' => PendingFarmerDeduction::STATUS_CANCELLED,
                    'description' => 'Cancelled — sale '.$sale->receipt_no.' voided',
                ]);

            // 3. Credit drawn on a cooperative is given back, as a new entry
            //    rather than by deleting the old one.
            $entry = CooperativeEntry::query()
                ->where('source_type', $sale->getMorphClass())
                ->where('source_id', $sale->getKey())
                ->latest('id')
                ->first();

            if ($entry !== null) {
                $this->ledger->reverse($entry, 'sale '.$sale->receipt_no.' voided', $actor);
            }

            $sale->forceFill([
                'voided_at' => Wat::now(),
                'voided_by_user_id' => $actor->getKey(),
                'void_reason' => $reason,
            ])->save();
        });

        $this->audit->edited(
            $sale,
            sprintf('Sale %s voided — %s', $sale->receipt_no, $reason),
            'One-Stop Shop',
            ['voided' => false],
            [
                'voided' => true,
                'reason' => $reason,
                'total_reversed_minor' => (int) $sale->total_minor,
                'lines_returned' => $sale->items->count(),
            ],
            $actor,
        );

        return $sale->refresh();
    }

    /**
     * BR-27 — "A sale from a category with requires_prescription must carry a
     * prescription_reference."
     *
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $data
     */
    private function guardPrescription($products, array $data): void
    {
        $needsPrescription = $products->filter(
            fn (Product $product) => (bool) $product->category?->requires_prescription,
        );

        if ($needsPrescription->isEmpty()) {
            return;
        }

        if (trim((string) ($data['prescription_reference'] ?? '')) === '') {
            throw RuleViolationException::make(
                'BR-27',
                sprintf(
                    '%s requires a prescription reference (category: %s).',
                    $needsPrescription->first()->name,
                    $needsPrescription->first()->category?->name ?? 'restricted',
                ),
                ['products' => $needsPrescription->pluck('sku')->all()],
                'prescription_reference',
            );
        }
    }

    /**
     * BR-25 — "Retiring a category hides it from new sales but preserves all
     * history."
     *
     * Retirement was implemented everywhere except on the path it exists to stop.
     * `ProductCategory::retire()`, the `sellable()` scope and the retire action all
     * worked; the sale path never asked. A shop manager could retire a category,
     * watch its status flip on the screen, and the counter would go on selling its
     * products all afternoon.
     *
     * A product whose category row has been soft-deleted arrives here with a null
     * category and is refused for the same reason: nothing live says what selling
     * it means — whether it needs a prescription, whether it may go on credit.
     *
     * @param  Collection<int, Product>  $products
     */
    private function guardCategoryRetired($products): void
    {
        $retired = $products->filter(
            fn (Product $product) => $product->category === null || $product->category->isRetired(),
        );

        if ($retired->isEmpty()) {
            return;
        }

        throw RuleViolationException::make(
            'BR-25',
            sprintf(
                'The %s category has been retired, so %s can no longer be sold. Its history is unaffected.',
                $retired->first()->category?->name ?? 'selected',
                $retired->first()->name,
            ),
            ['products' => $retired->pluck('sku')->all()],
            'items',
        );
    }

    /**
     * BR-26 — a sale line's price is operator input, and it was trusted.
     *
     * `items.*.unit_price` was validated as a string and nothing else, so '-500'
     * became -50,000 kobo and did four separate kinds of damage: it offset the
     * positive lines so the receipt and the day's revenue understated while
     * StockService still sent the goods out of the door; it wrote a NEGATIVE
     * `pending_farmer_deductions` row on a milk_deduction sale, which is a standing
     * credit against the farmer's next payment (BR-30); and it persisted, because
     * the `unsignedBigInteger` columns are plain `bigint` on PostgreSQL. Only the
     * cooperative-credit case failed loudly, and only because the ledger refuses a
     * non-positive posting.
     *
     * Zero is refused with it. A line at ₦0.00 hands goods over free and audits as
     * a sale of nothing — if giving stock away is a real case it wants an
     * authorised route (a write-off already has one, BR-28), not an empty box on
     * the sale form.
     *
     * OPEN DECISION (§15.4): whether a price override should exist at all, and if
     * it should, PERM-1 says it is its own permission row rather than something
     * every holder of shop.sales.create gets for free. Not invented here — see the
     * change requested against PermissionSeeder.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     */
    private function guardLinePrices(array $lines, $products): void
    {
        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $products[$line['product_id']];

            $override = $line['unit_price_minor'] ?? null;
            $unitPrice = (int) ($override ?? $product->selling_price_minor);

            if ($unitPrice > 0) {
                continue;
            }

            throw RuleViolationException::make(
                'BR-26',
                $override === null
                    ? sprintf('%s has no selling price set, so it cannot be sold until one is.', $product->name)
                    : sprintf('The price for %s must be more than zero.', $product->name),
                ['product' => $product->sku, 'unit_price_minor' => $unitPrice],
                $override === null ? 'items' : 'items.unit_price',
            );
        }
    }

    /**
     * ARCH-2 — "the API enforces the same rules as the web UI."
     *
     * The web controller pins both columns with an `in:` rule; the mobile sync path
     * passed the phone's own string through untouched into a bare string(16). One
     * list on the model, checked here, is what makes the two agree — and makes a
     * wrong value a refusal the field app can show rather than a row that reads
     * correctly on screen and is wrong in every breakdown.
     *
     * @param  array<int, string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $field, string $noun): string
    {
        $value = (string) $value;

        if (! in_array($value, $allowed, true)) {
            throw RuleViolationException::make(
                'BR-26',
                sprintf('"%s" is not a %s this shop records.', $value, $noun),
                [$field => $value, 'allowed' => $allowed],
                $field,
            );
        }

        return $value;
    }

    /**
     * A credit sale is a debt, and a debt names who owes it.
     *
     * `guardCredit()` below checks that the CATEGORY allows credit and stops there,
     * so `customer_type=walkin` with `payment_method=credit` passed cleanly: the
     * goods left, `amount_received_minor` was 0, and no cooperative entry and no
     * pending deduction were written. The money owed existed on the customer's
     * receipt and nowhere in the database, while `total_minor` still counted it as
     * revenue.
     *
     * The refusal for a cooperative with no general account is BR-28, deliberately
     * the same rule and the same shape CooperativeLedgerService::recordCreditSale
     * already throws — the sale path should not invent a second answer to a
     * question the ledger has already answered. Raising it here rather than inside
     * the transaction means the operator is stopped before a receipt number is
     * consumed.
     *
     * BLOCKED ON A BUSINESS DECISION (§15.4): whether a farmer may buy on credit
     * against their next milk payment — as distinct from `milk_deduction`, which
     * already does exactly that and has a settlement path waiting in Phase 7 — and
     * whether a walk-in debtor is a real case. Neither has a debtor record or a way
     * to settle, so both are refused until somebody decides. Refusing is
     * recoverable; a receivable that exists nowhere is not.
     */
    private function guardCreditNamesADebtor(
        string $paymentMethod,
        string $customerType,
        ?Cooperative $cooperative,
    ): void {
        if ($paymentMethod !== Sale::PAYMENT_CREDIT) {
            return;
        }

        if ($customerType !== Sale::CUSTOMER_COOPERATIVE || $cooperative === null) {
            throw RuleViolationException::make(
                'BR-25',
                'Credit is only extended to a cooperative, because a cooperative is the only customer with an account the debt can sit on. Take payment another way.',
                ['customer_type' => $customerType],
                'payment_method',
            );
        }

        if ($cooperative->generalAccount() === null) {
            throw RuleViolationException::make(
                'BR-28',
                sprintf('%s has no general account, so a credit sale cannot be recorded against it.', $cooperative->name),
                ['cooperative' => $cooperative->name],
                'cooperative_id',
            );
        }
    }

    /**
     * BR-25 — `allow_credit` is a category flag, so credit exposure is
     * configured rather than coded.
     *
     * @param  Collection<int, Product>  $products
     */
    private function guardCredit($products, string $paymentMethod): void
    {
        if ($paymentMethod !== Sale::PAYMENT_CREDIT) {
            return;
        }

        $notOnCredit = $products->reject(fn (Product $product) => (bool) $product->category?->allow_credit);

        if ($notOnCredit->isNotEmpty()) {
            throw RuleViolationException::make(
                'BR-25',
                sprintf(
                    'The %s category is not configured for credit sales.',
                    $notOnCredit->first()->category?->name ?? 'selected',
                ),
                ['products' => $notOnCredit->pluck('sku')->all()],
                'payment_method',
            );
        }
    }
}
