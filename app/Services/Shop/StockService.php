<?php

namespace App\Services\Shop;

use App\Exceptions\RuleViolationException;
use App\Models\AdjustmentReason;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §6.7 stock.
 *
 * BR-26 — "A sale decrements stock atomically within a transaction. A sale that
 *   would drive stock negative is rejected." The decrement lives here so the
 *   sale path and any future path share one implementation.
 * BR-28 — "Stock adjustments require a reason and an explanation, and are visible
 *   in the audit log."
 */
class StockService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Receive stock into a batch. `track_expiry` on the category decides whether
     * an expiry date is required — behaviour from data, not from code (BR-25).
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(Product $product, array $data, User $actor): ProductBatch
    {
        $quantity = (float) $data['quantity_received'];

        if ($quantity <= 0) {
            throw RuleViolationException::make(
                'BR-26',
                'Received quantity must be greater than zero.',
                [],
                'quantity_received',
            );
        }

        $category = $product->category;

        if ($category !== null && $category->track_expiry && empty($data['expiry_on'])) {
            throw RuleViolationException::make(
                'BR-25',
                "The {$category->name} category tracks expiry, so an expiry date is required.",
                ['category' => $category->code],
                'expiry_on',
            );
        }

        return DB::transaction(function () use ($product, $data, $quantity, $actor): ProductBatch {
            $batch = ProductBatch::query()->create([
                'product_id' => $product->getKey(),
                'batch_no' => $data['batch_no'],
                'supplier' => $data['supplier'] ?? null,
                'received_on' => $data['received_on'] ?? Wat::today()->toDateString(),
                'expiry_on' => $data['expiry_on'] ?? null,
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost_minor' => (int) ($data['unit_cost_minor'] ?? $product->cost_price_minor),
                'requisition_id' => $data['requisition_id'] ?? null,
                'status' => 'active',
            ]);

            /*
             * BR-26 — read the balance under a lock INSIDE the transaction, the
             * way decrementForSale, returnFromVoidedSale and adjust all do.
             *
             * This one did not, and it was not a race window but the whole HTTP
             * round trip: $product is the route-bound model, hydrated when the
             * receiving form was submitted from a page opened minutes earlier. The
             * officer opens a product showing 40 bags, the counter sells 12, the
             * officer submits "received 100" and the product is written to 140
             * instead of 128 — the 12 that were sold erased from the balance.
             *
             * The batch rows stay right while the product's does not, so the two
             * diverge silently; stock value (a shop.revenue figure) overstates, and
             * the negative-stock guard then passes sales on goods that are not
             * there.
             */
            $onHand = (float) Product::query()
                ->withoutGlobalScopes()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->value('quantity_on_hand');

            $balance = $onHand + $quantity;

            $product->forceFill(['quantity_on_hand' => $balance])->save();

            StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'product_batch_id' => $batch->getKey(),
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'reference' => $batch->batch_no,
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'balance_after' => $balance,
            ]);

            $this->audit->created(
                $batch,
                sprintf('%s: %s %s received into batch %s', $product->name, $quantity, (string) $product->unit, $batch->batch_no),
                'One-Stop Shop',
                ['balance_after' => $balance],
                $actor,
            );

            return $batch;
        });
    }

    /**
     * BR-26 — the atomic decrement. Called inside the sale's transaction.
     *
     * @return array<int, StockMovement>
     */
    public function decrementForSale(Product $product, float $quantity, ?int $saleId, string $reference, User $actor): array
    {
        if ($quantity <= 0) {
            throw RuleViolationException::make(
                'BR-26',
                'Quantity sold must be greater than zero.',
                [],
                'quantity',
            );
        }

        // BR-26 — refuse rather than go negative.
        $onHand = (float) Product::query()
            ->withoutGlobalScopes()
            ->whereKey($product->getKey())
            ->lockForUpdate()
            ->value('quantity_on_hand');

        if ($onHand < $quantity) {
            throw RuleViolationException::make(
                'BR-26',
                sprintf(
                    'Only %s %s of %s in stock — the sale would take it negative.',
                    rtrim(rtrim(number_format($onHand, 2), '0'), '.'),
                    (string) $product->unit,
                    $product->name,
                ),
                ['on_hand' => $onHand, 'requested' => $quantity, 'product' => $product->sku],
                'quantity',
            );
        }

        /*
         * Expired stock is on hand but not sellable. `quantity_on_hand` counts it,
         * so the negative-stock guard above can pass while every batch that could
         * satisfy the sale is out of date — which is exactly how expired feed used
         * to leave the counter. Refuse, and say which it is.
         */
        $expiredOnHand = (float) ProductBatch::query()
            ->where('product_id', $product->getKey())
            ->expired()
            ->sum('quantity_remaining');

        if ($expiredOnHand > 0 && ($onHand - $expiredOnHand) < $quantity) {
            throw RuleViolationException::make(
                'BR-26',
                sprintf(
                    'Only %s %s of %s is still within date — %s has expired and cannot be sold. Write it off with a stock adjustment.',
                    rtrim(rtrim(number_format($onHand - $expiredOnHand, 2), '0'), '.'),
                    (string) $product->unit,
                    $product->name,
                    rtrim(rtrim(number_format($expiredOnHand, 2), '0'), '.'),
                ),
                ['on_hand' => $onHand, 'expired' => $expiredOnHand, 'requested' => $quantity, 'product' => $product->sku],
                'quantity',
            );
        }

        $remaining = $quantity;
        $balance = $onHand;
        $movements = [];

        // Soonest expiry first among batches still WITHIN date, so stock rotates
        // without ever dispensing something out of date.
        $batches = ProductBatch::query()
            ->where('product_id', $product->getKey())
            ->sellable()
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $batch->quantity_remaining);
            $remaining -= $take;
            $balance -= $take;

            $batch->forceFill([
                'quantity_remaining' => (float) $batch->quantity_remaining - $take,
                'status' => ((float) $batch->quantity_remaining - $take) <= 0 ? 'depleted' : 'active',
            ])->save();

            $movements[] = StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'product_batch_id' => $batch->getKey(),
                'movement_type' => StockMovement::TYPE_SALE,
                'reference' => $reference,
                'quantity_in' => 0,
                'quantity_out' => $take,
                'balance_after' => $balance,
                'sale_id' => $saleId,
            ]);
        }

        /*
         * A product may carry stock without batch rows (an opening balance), and
         * that remainder is sold from the product's own quantity. Expired batch
         * stock is never reached here — the guard above refused the sale before
         * this point if within-date stock was short.
         */
        if ($remaining > 0) {
            $balance -= $remaining;

            $movements[] = StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_SALE,
                'reference' => $reference,
                'quantity_in' => 0,
                'quantity_out' => $remaining,
                'balance_after' => $balance,
                'sale_id' => $saleId,
            ]);
        }

        $product->forceFill(['quantity_on_hand' => $balance])->save();

        $this->warnIfLowStock($product->refresh(), $onHand);

        return $movements;
    }

    /**
     * Stock coming back from a voided sale.
     *
     * Deliberately NOT an adjustment: an adjustment means the count was wrong and
     * needs a reason from the configured list, whereas this is the reversal of a
     * movement the system itself made. Recorded as a return so the movement
     * history reads as what happened — sold, then unsold — rather than as a
     * mysterious stock gain.
     *
     * The stock returns to the product's own balance rather than to the batch it
     * came from: a batch may since have been depleted or expired, and putting
     * expired stock back into rotation is worse than losing the batch attribution.
     */
    public function returnFromVoidedSale(
        Product $product,
        float $quantity,
        int $saleId,
        string $reference,
        User $actor,
    ): StockMovement {
        return DB::transaction(function () use ($product, $quantity, $saleId, $reference, $actor): StockMovement {
            $onHand = (float) Product::query()
                ->withoutGlobalScopes()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->value('quantity_on_hand');

            $balance = $onHand + $quantity;

            $movement = StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_RETURN,
                'reference' => $reference.' (voided)',
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'balance_after' => $balance,
                'sale_id' => $saleId,
            ]);

            $product->forceFill(['quantity_on_hand' => $balance])->save();

            $this->audit->created(
                $movement,
                sprintf(
                    '%s %s of %s returned to stock — sale %s voided',
                    rtrim(rtrim(number_format($quantity, 2), '0'), '.'),
                    (string) $product->unit,
                    $product->name,
                    $reference,
                ),
                'One-Stop Shop',
                ['balance_after' => $balance, 'sale_id' => $saleId],
                $actor,
            );

            return $movement;
        });
    }

    /**
     * BR-28 — "Stock adjustments require a reason and an explanation, and are
     * visible in the audit log."
     */
    public function adjust(Product $product, float $delta, int $reasonId, string $explanation, User $actor): StockMovement
    {
        $explanation = trim($explanation);

        if ($explanation === '') {
            throw RuleViolationException::make(
                'BR-28',
                'A stock adjustment needs an explanation.',
                [],
                'explanation',
            );
        }

        if ($delta === 0.0) {
            throw RuleViolationException::make(
                'BR-28',
                'An adjustment of zero is not an adjustment.',
                [],
                'delta',
            );
        }

        $reason = AdjustmentReason::query()->find($reasonId);

        if ($reason === null || $reason->status !== 'active'
            || ! in_array($reason->applies_to, ['stock', 'any'], true)) {
            throw RuleViolationException::make(
                'BR-28',
                'Choose a stock adjustment reason from the configured list.',
                [],
                'reason_id',
            );
        }

        return DB::transaction(function () use ($product, $delta, $reason, $explanation, $actor): StockMovement {
            $onHand = (float) Product::query()
                ->withoutGlobalScopes()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->value('quantity_on_hand');

            $balance = $onHand + $delta;

            // BR-26's spirit: stock never goes negative, by any route.
            if ($balance < 0) {
                throw RuleViolationException::make(
                    'BR-26',
                    'That adjustment would take stock below zero.',
                    ['on_hand' => $onHand, 'delta' => $delta],
                    'delta',
                );
            }

            $product->forceFill(['quantity_on_hand' => $balance])->save();

            $movement = StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'reference' => $reason->code,
                'quantity_in' => $delta > 0 ? $delta : 0,
                'quantity_out' => $delta < 0 ? abs($delta) : 0,
                'balance_after' => $balance,
                'reason_id' => $reason->getKey(),
                'explanation' => $explanation,
            ]);

            $this->audit->created(
                $movement,
                sprintf(
                    '%s stock adjusted by %s%s — %s',
                    $product->name,
                    $delta > 0 ? '+' : '−',
                    rtrim(rtrim(number_format(abs($delta), 2), '0'), '.'),
                    $reason->name,
                ),
                'One-Stop Shop',
                [
                    'rule' => 'BR-28',
                    'explanation' => $explanation,
                    'balance_after' => $balance,
                ],
                $actor,
            );

            $this->warnIfLowStock($product->refresh(), $onHand);

            return $movement;
        });
    }

    /** NOTIF-3 — "low stock". */
    /**
     * Notify when stock CROSSES the reorder level, not every time it moves below
     * it.
     *
     * The old version fired on every sale while a product sat low, so one popular
     * item low on a busy Saturday buried the inventory officer's bell in identical
     * alerts — and an alert people scroll past is not an alert. The balance before
     * the movement is what makes it a crossing.
     */
    private function warnIfLowStock(Product $product, ?float $balanceBefore = null): void
    {
        if (! Settings::boolean('shop.low_stock_warning_enabled', true) || ! $product->isLowStock()) {
            return;
        }

        // Already low before this movement: nothing new to say.
        if ($balanceBefore !== null && $balanceBefore <= (float) $product->reorder_level) {
            return;
        }

        $this->notifications->send(
            eventCode: 'shop.low_stock',
            recipients: $this->notifications->usersWithPermission('shop.inventory.edit'),
            title: 'Low stock: '.$product->name,
            body: sprintf('%s %s remaining.', rtrim(rtrim((string) $product->quantity_on_hand, '0'), '.'), (string) $product->unit),
            actionUrl: route('shop.products.show', $product),
            subject: $product,
        );
    }
}
