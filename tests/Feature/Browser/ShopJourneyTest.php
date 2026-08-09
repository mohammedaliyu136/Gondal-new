<?php

namespace Tests\Feature\Browser;

use App\Models\AppNotification;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\CooperativeEntry;
use App\Models\PendingFarmerDeduction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Wat;
use Tests\GondalTestCase;

/** Phase A shop repairs: the ledger, expiry, the void path and the alert storm. */
class ShopJourneyTest extends GondalTestCase
{
    /** A credit sale draws down the cooperative's account. */
    public function test_a_credit_sale_posts_to_the_cooperative_ledger(): void
    {
        $world = $this->makeMilkWorld();
        $cooperative = $world['cooperative'];

        $account = $this->asSystem(fn () => $cooperative->generalAccount()
            ?? CooperativeAccount::query()->create([
                'cooperative_id' => $cooperative->id,
                'kind' => Cooperative::ACCOUNT_GENERAL,
                'balance_minor' => 0,
            ]));

        $opening = (int) $account->balance_minor;

        $manager = $this->makeUser('Credit Manager');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager->fresh());

        $product = $this->stockedProduct('LEDGER-1', 20, sellingMinor: 12_000_00);

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'cooperative',
            'cooperative_id' => (string) $cooperative->id,
            'payment_method' => 'credit',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '3', 'unit_price' => '']],
        ])->assertSessionHasNoErrors();

        $entry = $this->asSystem(fn () => CooperativeEntry::query()->latest('id')->firstOrFail());

        $this->assertSame(CooperativeEntry::DIRECTION_OUT, $entry->direction);
        $this->assertSame(36_000_00, (int) $entry->amount_minor);
        $this->assertSame($opening - 36_000_00, (int) $entry->balance_after_minor);
        // The account itself agrees with its last entry.
        $this->assertSame($opening - 36_000_00, (int) $account->refresh()->balance_minor);
    }

    /** Expired stock is never dispensed, and the refusal says why. */
    public function test_expired_stock_is_not_sold(): void
    {
        $officer = $this->makeUser('Expiry Officer');
        $this->assignRole($officer, 'One-Stop Shop Manager');
        $this->actingAs($officer->fresh());

        $product = $this->stockedProduct('VET-EXP', 0, tracksExpiry: true);

        // 10 units expired last week, 4 units good for another month.
        $this->asSystem(function () use ($product): void {
            ProductBatch::query()->create([
                'product_id' => $product->id, 'batch_no' => 'OLD',
                'quantity_received' => 10, 'quantity_remaining' => 10,
                'received_on' => Wat::today()->subMonths(6)->toDateString(),
                'expiry_on' => Wat::today()->subWeek()->toDateString(),
                'status' => 'active',
            ]);
            ProductBatch::query()->create([
                'product_id' => $product->id, 'batch_no' => 'NEW',
                'quantity_received' => 4, 'quantity_remaining' => 4,
                'received_on' => Wat::today()->subDays(3)->toDateString(),
                'expiry_on' => Wat::today()->addMonth()->toDateString(),
                'status' => 'active',
            ]);
            $product->forceFill(['quantity_on_hand' => 14])->save();
        });

        // Asking for 6 cannot be met from within-date stock, even though 14 are
        // "on hand" — this used to quietly serve the expired batch first.
        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin',
            'customer_name' => 'Walk-in',
            'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '6', 'unit_price' => '']],
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->asSystem(fn () => Sale::query()->count()));

        // Asking for 4 succeeds, and comes out of the IN-DATE batch.
        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin',
            'customer_name' => 'Walk-in',
            'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '4', 'unit_price' => '']],
        ])->assertSessionHasNoErrors();

        $this->asSystem(function () use ($product): void {
            $expired = ProductBatch::query()->where('product_id', $product->id)->where('batch_no', 'OLD')->firstOrFail();
            $fresh = ProductBatch::query()->where('product_id', $product->id)->where('batch_no', 'NEW')->firstOrFail();

            $this->assertSame(10.0, (float) $expired->quantity_remaining, 'The expired batch must be untouched.');
            $this->assertSame(0.0, (float) $fresh->quantity_remaining, 'The in-date batch is what was sold.');
        });
    }

    /** Voiding unwinds stock, the deduction and the ledger together. */
    public function test_voiding_a_sale_unwinds_everything_it_did(): void
    {
        $world = $this->makeMilkWorld();

        $manager = $this->makeUser('Voiding Manager');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager->fresh());

        $product = $this->stockedProduct('VOID-1', 20);

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'farmer',
            'farmer_id' => (string) $world['farmer']->id,
            'payment_method' => 'milk_deduction',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '5', 'unit_price' => '']],
        ])->assertSessionHasNoErrors();

        $sale = $this->asSystem(fn () => Sale::query()->latest('id')->firstOrFail());

        $this->assertSame(15.0, (float) $product->refresh()->quantity_on_hand);
        $this->assertSame(1, $this->asSystem(fn () => PendingFarmerDeduction::query()
            ->where('sale_id', $sale->id)->where('status', PendingFarmerDeduction::STATUS_PENDING)->count()));

        // A void without a reason is refused.
        $this->post(route('shop.sales.void', $sale), ['void_reason' => ''])->assertSessionHasErrors();

        $this->post(route('shop.sales.void', $sale), [
            'void_reason' => 'Wrong product rung up.',
        ])->assertSessionHasNoErrors();

        $sale->refresh();

        $this->assertTrue($sale->isVoided());
        $this->assertSame($manager->id, $sale->voided_by_user_id);
        // Stock came back...
        $this->assertSame(20.0, (float) $product->refresh()->quantity_on_hand);
        // ...as a return movement, not a mystery adjustment.
        $this->assertSame(1, $this->asSystem(fn () => StockMovement::query()
            ->where('sale_id', $sale->id)->where('movement_type', StockMovement::TYPE_RETURN)->count()));
        // ...and the farmer no longer owes for it.
        $this->assertSame(PendingFarmerDeduction::STATUS_CANCELLED, $this->asSystem(fn () => PendingFarmerDeduction::query()
            ->where('sale_id', $sale->id)->value('status')));

        // Voiding twice is refused.
        $this->post(route('shop.sales.void', $sale), ['void_reason' => 'Again'])->assertSessionHasErrors();
    }

    /** A voided sale stops counting as revenue. */
    public function test_a_voided_sale_leaves_the_revenue_figures(): void
    {
        $manager = $this->makeUser('Revenue Manager');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager->fresh());

        $product = $this->stockedProduct('VOID-2', 20, sellingMinor: 10_000_00);

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin', 'customer_name' => 'Walk-in', 'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '2', 'unit_price' => '']],
        ])->assertSessionHasNoErrors();

        $sale = $this->asSystem(fn () => Sale::query()->latest('id')->firstOrFail());

        // The revenue tile uses the compact form.
        $this->get(route('shop.sales.index'))->assertOk()->assertSee('₦20k');

        $this->post(route('shop.sales.void', $sale), ['void_reason' => 'Customer changed their mind.']);

        $this->get(route('shop.sales.index'))->assertOk()->assertDontSee('₦20k');
    }

    /** The sale detail page exists and is reachable from the receipt number. */
    public function test_a_sale_can_be_opened_from_its_receipt_number(): void
    {
        $officer = $this->makeUser('Lookup Officer');
        $this->assignRole($officer, 'One-Stop Shop Manager');
        $this->actingAs($officer->fresh());

        $product = $this->stockedProduct('LOOKUP-1', 10);

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin', 'customer_name' => 'Walk-in', 'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '1', 'unit_price' => '']],
        ]);

        $sale = $this->asSystem(fn () => Sale::query()->latest('id')->firstOrFail());

        $this->get(route('shop.sales.index'))
            ->assertOk()
            ->assertSee(route('shop.sales.show', $sale), false);

        $this->get(route('shop.sales.show', $sale))
            ->assertOk()
            ->assertSee($sale->receipt_no)
            ->assertSee($product->name);
    }

    /** Low stock alerts on the crossing, not on every sale while low. */
    public function test_the_low_stock_alert_fires_once_on_crossing(): void
    {
        $manager = $this->makeUser('Alerted Manager');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager->fresh());

        $product = $this->stockedProduct('LOW-1', 10, reorderLevel: 5);

        $countAlerts = fn () => $this->asSystem(fn () => AppNotification::query()
            ->where('type', 'shop.low_stock')->count());

        // 10 -> 6: still above the level, no alert.
        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin', 'customer_name' => 'W', 'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '4', 'unit_price' => '']],
        ]);
        $this->assertSame(0, $countAlerts());

        // 6 -> 4: crosses the level. One alert.
        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin', 'customer_name' => 'W', 'payment_method' => 'cash',
            'items' => [['product_id' => (string) $product->id, 'quantity' => '2', 'unit_price' => '']],
        ]);
        $afterCrossing = $countAlerts();
        $this->assertGreaterThanOrEqual(1, $afterCrossing);

        // 4 -> 3 -> 2: already low. No new alerts.
        foreach ([1, 1] as $quantity) {
            $this->post(route('shop.sales.store'), [
                'customer_type' => 'walkin', 'customer_name' => 'W', 'payment_method' => 'cash',
                'items' => [['product_id' => (string) $product->id, 'quantity' => (string) $quantity, 'unit_price' => '']],
            ]);
        }

        $this->assertSame($afterCrossing, $countAlerts(), 'Staying low must not re-alert on every sale.');
    }

    /* ------------------------------------------------------------------ */

    private function stockedProduct(
        string $sku,
        float $quantity = 20,
        bool $tracksExpiry = false,
        int $sellingMinor = 11_000_00,
        float $reorderLevel = 2,
    ): Product {
        return $this->asSystem(function () use ($sku, $quantity, $tracksExpiry, $sellingMinor, $reorderLevel): Product {
            $category = ProductCategory::query()->create([
                'code' => 'SHOPJ-'.random_int(1000, 9999),
                'name' => 'Journey category',
                'default_unit' => 'bag',
                'default_reorder_level' => $reorderLevel,
                'requires_prescription' => false,
                'track_expiry' => $tracksExpiry,
                'allow_credit' => true,
                'requires_manager_approval' => false,
                'status' => 'active',
            ]);

            $product = Product::query()->create([
                'sku' => $sku,
                'name' => 'Product '.$sku,
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 8_000_00,
                'selling_price_minor' => $sellingMinor,
                'reorder_level' => $reorderLevel,
                'quantity_on_hand' => $quantity,
                'status' => 'active',
            ]);

            if ($quantity > 0) {
                StockMovement::query()->create([
                    'product_id' => $product->getKey(),
                    'movement_type' => StockMovement::TYPE_STOCK_IN,
                    'reference' => 'opening',
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'balance_after' => $quantity,
                ]);
            }

            return $product;
        });
    }
}
