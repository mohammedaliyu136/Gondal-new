<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SaleResource;
use App\Models\AdjustmentReason;
use App\Models\CooperativeEntry;
use App\Models\PendingFarmerDeduction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Community\CooperativeLedgerService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\GondalTestCase;

/** §7.5 — the One-Stop Shop. */
class ShopRulesTest extends GondalTestCase
{
    /**
     * BR-25 — "Product categories are created and retired by users holding
     * shop.categories.create. Retiring a category hides it from new sales but
     * preserves all history. Categories are never deleted."
     *
     * This is also the §14 Phase 6 acceptance criterion: "the shop manager creates
     * a category and it is immediately sellable".
     */
    public function test_br25_a_new_category_is_immediately_sellable(): void
    {
        $manager = $this->makeUser('Amina Kabir');
        $this->assignRole($manager, 'One-Stop Shop Manager');
        $this->actingAs($manager);

        $this->post(route('shop.categories.store'), [
            'code' => 'SEED',
            'name' => 'Seeds & forage',
            'default_unit' => 'kg',
            'default_reorder_level' => 25,
        ])->assertRedirect();

        $category = ProductCategory::query()->where('code', 'SEED')->firstOrFail();

        $this->assertSame('active', $category->status);
        $this->assertTrue(
            ProductCategory::query()->sellable()->whereKey($category->id)->exists(),
            'A category is sellable the moment it is created — no deployment, no cache warm-up.',
        );

        // And a product created against it inherits the category's defaults.
        $this->post(route('shop.products.store'), [
            'sku' => 'SEED-NAP',
            'name' => 'Napier grass cuttings',
            'product_category_id' => $category->id,
            'selling_price' => '1500.00',
        ])->assertRedirect();

        $product = Product::query()->where('sku', 'SEED-NAP')->firstOrFail();

        $this->assertSame('kg', $product->unit);
        $this->assertSame(25, (int) $product->reorder_level);
    }

    /** BR-25 — retiring preserves history; the category is never deleted. */
    public function test_br25_retiring_a_category_preserves_history(): void
    {
        [$manager, $product] = $this->shopWorld();
        $category = $product->category;

        $this->actingAs($manager);
        $this->post(route('shop.categories.retire', $category))->assertRedirect();

        $category->refresh();

        $this->assertSame('retired', $category->status);
        $this->assertNotNull($category->retired_at);
        $this->assertFalse(ProductCategory::query()->sellable()->whereKey($category->id)->exists());

        // Nothing was deleted: the row and its products are still there.
        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        // A new product cannot be filed under it.
        $this->post(route('shop.products.store'), [
            'sku' => 'NEW-001',
            'name' => 'Something new',
            'product_category_id' => $category->id,
            'selling_price' => '100.00',
        ])->assertSessionHasErrors('product_category_id');
    }

    /**
     * BR-26 — "A sale decrements stock atomically within a transaction. A sale
     * that would drive stock negative is rejected."
     */
    public function test_br26_stock_decrements_atomically(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 50);
        $this->actingAs($officer);

        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
            'customer_name' => 'Walk-in',
        ], [
            ['product_id' => $product->id, 'quantity' => 12.0],
        ], $officer);

        $this->assertSame('38.00', (string) $product->refresh()->quantity_on_hand);

        // The ledger and the balance agree, because both are written in the same
        // transaction.
        $movement = StockMovement::query()
            ->where('sale_id', $sale->id)
            ->where('movement_type', StockMovement::TYPE_SALE)
            ->firstOrFail();

        $this->assertSame('12.00', (string) $movement->quantity_out);
        $this->assertSame('38.00', (string) $movement->balance_after);
    }

    /** BR-26 — a sale that would go negative is refused, and nothing is written. */
    public function test_br26_a_sale_that_would_go_negative_is_rejected(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 5);
        $this->actingAs($officer);

        $salesBefore = Sale::query()->count();
        $movementsBefore = StockMovement::query()->count();

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_CASH,
            ], [
                ['product_id' => $product->id, 'quantity' => 9.0],
            ], $officer);

            $this->fail('A sale beyond available stock must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-26', $exception->ruleId);
        }

        // Atomic: the refusal rolled the whole sale back.
        $this->assertSame('5.00', (string) $product->refresh()->quantity_on_hand);
        $this->assertSame($salesBefore, Sale::query()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }

    /** BR-26 — a multi-line sale rolls back entirely if one line cannot be met. */
    public function test_br26_a_multi_line_sale_rolls_back_completely(): void
    {
        [$officer, $plenty] = $this->shopWorld(quantity: 100);

        $scarce = $this->asSystem(fn () => Product::query()->create([
            'sku' => 'SCARCE-1',
            'name' => 'Nearly out of stock',
            'product_category_id' => $plenty->product_category_id,
            'unit' => 'bag',
            'cost_price_minor' => 100_00,
            'selling_price_minor' => 150_00,
            'quantity_on_hand' => 1,
            'status' => 'active',
        ]));

        $this->actingAs($officer);

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_CASH,
            ], [
                ['product_id' => $plenty->id, 'quantity' => 10.0],
                ['product_id' => $scarce->id, 'quantity' => 5.0],
            ], $officer);

            $this->fail('One unmeetable line must abort the whole sale.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-26', $exception->ruleId);
        }

        $this->assertSame('100.00', (string) $plenty->refresh()->quantity_on_hand, 'The first line was rolled back.');
        $this->assertSame('1.00', (string) $scarce->refresh()->quantity_on_hand);
        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * BR-27 — "A sale from a category with requires_prescription must carry a
     * prescription_reference."
     */
    public function test_br27_prescription_category_requires_a_reference(): void
    {
        [$officer, $product] = $this->shopWorld(requiresPrescription: true, quantity: 20);
        $this->actingAs($officer);

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_CASH,
            ], [
                ['product_id' => $product->id, 'quantity' => 2.0],
            ], $officer);

            $this->fail('A prescription category needs a reference.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-27', $exception->ruleId);
        }

        // With one, the sale goes through.
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
            'prescription_reference' => 'RX-2026-0031',
        ], [
            ['product_id' => $product->id, 'quantity' => 2.0],
        ], $officer);

        $this->assertSame('RX-2026-0031', $sale->prescription_reference);
    }

    /** BR-25 — the prescription flag is DATA: clearing it changes the outcome. */
    public function test_br27_the_prescription_requirement_comes_from_the_category_row(): void
    {
        [$officer, $product] = $this->shopWorld(requiresPrescription: true, quantity: 20);
        $this->actingAs($officer);

        // The administrator decides this category no longer needs a prescription.
        $product->category->forceFill(['requires_prescription' => false])->save();

        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [
            ['product_id' => $product->fresh()->id, 'quantity' => 2.0],
        ], $officer);

        $this->assertNull($sale->prescription_reference);
    }

    /**
     * BR-28 — "Stock adjustments require a reason and an explanation, and are
     * visible in the audit log."
     */
    public function test_br28_stock_adjustment_requires_reason_and_explanation(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 40);
        $this->actingAs($officer);

        $reasonId = AdjustmentReason::query()->where('code', 'ADJ-DAMAGE')->value('id');

        try {
            app(StockService::class)->adjust($product, -5.0, $reasonId, '  ', $officer);
            $this->fail('An adjustment with a blank explanation must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-28', $exception->ruleId);
        }

        $movement = app(StockService::class)->adjust(
            $product,
            -5.0,
            $reasonId,
            'Five bags soaked in the store-room leak.',
            $officer,
        );

        $this->assertSame('35.00', (string) $product->refresh()->quantity_on_hand);

        // Visible in the audit log, as the rule requires.
        $this->assertDatabaseHas('audit_entries', [
            'subject_type' => StockMovement::class,
            'subject_id' => $movement->id,
            'module' => 'One-Stop Shop',
        ]);
    }

    /** BR-28 / BR-26 — an adjustment cannot take stock negative either. */
    public function test_br28_adjustment_cannot_take_stock_negative(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 3);
        $this->actingAs($officer);

        try {
            app(StockService::class)->adjust(
                $product,
                -10.0,
                AdjustmentReason::query()->where('code', 'ADJ-COUNT')->value('id'),
                'Deliberate over-deduction, to prove the guard.',
                $officer,
            );

            $this->fail('An adjustment below zero must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-26', $exception->ruleId);
        }

        $this->assertSame('3.00', (string) $product->refresh()->quantity_on_hand);
    }

    /**
     * BR-29 — "Users holding shop.sales but not shop.revenue see their own
     * transactions and no aggregate revenue, margin or stock-value figure — in API
     * responses as well as UI."
     */
    public function test_br29_sales_officer_sees_no_revenue_aggregates_in_the_ui(): void
    {
        [$manager, $product] = $this->shopWorld(quantity: 100);

        $officer = $this->makeUser('Hauwa Ibrahim');
        // The Sales Officer role deliberately excludes shop.revenue.
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);

        $this->assertTrue($officer->hasPermission('shop.sales.view'));
        $this->assertFalse($officer->hasPermission('shop.revenue.view'));

        // A sale by somebody else.
        $this->actingAs($manager);
        app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->id, 'quantity' => 4.0]], $manager);

        // A sale by the officer.
        $this->actingAs($officer);
        $own = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->fresh()->id, 'quantity' => 3.0]], $officer);

        $response = $this->get(route('shop.sales.index'));

        $response->assertOk();
        // Their own transaction is there — BR-29 grants them that much.
        $response->assertSee($own->receipt_no);
        // The aggregates are not computed at all, and the page says why.
        $response->assertSee('You see your own transactions, not revenue.', false);
        $response->assertSee('not shown to your role', false);
        // "Margin today" is the label the view uses ONLY when the figure is real.
        $response->assertDontSee('Margin today', false);

        // SCOPE-2 — the `own` scope narrows the list to their own sales, so the
        // manager's sale is not even in the result set.
        $this->assertSame(1, Sale::query()->count(), 'Only their own sale is visible.');

        /*
         * The same screen, for someone who does hold shop.revenue.
         *
         * The session is flushed first because BR-33's mechanism is real: the
         * `session.authenticate` middleware pins a session to the password hash of
         * the user who created it, so swapping users mid-session is treated as a
         * stale session and signed out. That is the behaviour we want in
         * production, so the test works with it rather than around it.
         */
        $this->flushSession();
        $this->actingAs($manager);
        $managerView = $this->get(route('shop.sales.index'));

        $managerView->assertOk();
        $managerView->assertSee('Margin today', false);
        $managerView->assertDontSee('not shown to your role', false);
    }

    /** BR-29 — and the same figures are absent from the API resource. */
    public function test_br29_revenue_figures_are_absent_from_api_responses(): void
    {
        [$manager, $product] = $this->shopWorld(quantity: 100);

        $officer = $this->makeUser('API Sales Officer');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);

        $this->actingAs($officer);
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->id, 'quantity' => 2.0]], $officer);

        // Resolved the way a real response resolves it, so the withheld keys are
        // genuinely absent from the JSON rather than present as a placeholder.
        $withoutRevenue = $this->resolve(
            SaleResource::make($sale->load('items.product')),
            $officer,
        );

        $this->assertArrayNotHasKey('margin', $withoutRevenue, 'Margin is omitted, not nulled.');
        $this->assertArrayHasKey('total', $withoutRevenue, 'Their own transaction total is still theirs to see.');
        $this->assertArrayNotHasKey('unit_cost', $withoutRevenue['items'][0], 'Nor the snapshotted unit cost.');

        // The manager, who holds shop.revenue, gets it.
        $withRevenue = $this->resolve(
            SaleResource::make($sale->load('items.product')),
            $manager,
        );

        $this->assertArrayHasKey('margin', $withRevenue);

        // Same story for a product's cost, stock value and margin.
        $productPayload = $this->resolve(
            ProductResource::make($product->load('category')),
            $officer,
        );

        $this->assertArrayNotHasKey('cost_price', $productPayload);
        $this->assertArrayNotHasKey('stock_value', $productPayload);
        $this->assertArrayNotHasKey('margin', $productPayload);
        $this->assertArrayHasKey('quantity_on_hand', $productPayload, 'Quantities are fine — it is values that are withheld.');
    }

    /**
     * BR-30 — "milk_deduction sales create a pending deduction against the
     * farmer's next payment."
     */
    public function test_br30_milk_deduction_creates_a_pending_deduction(): void
    {
        $world = $this->makeMilkWorld();
        [$officer, $product] = $this->shopWorld(quantity: 60);
        $this->actingAs($officer);

        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_FARMER,
            'farmer_id' => $world['farmer']->id,
            'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
        ], [['product_id' => $product->id, 'quantity' => 3.0]], $officer);

        $deduction = PendingFarmerDeduction::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame($world['farmer']->id, (int) $deduction->farmer_id);
        $this->assertSame((int) $sale->total_minor, (int) $deduction->amount_minor);
        $this->assertSame(PendingFarmerDeduction::STATUS_PENDING, $deduction->status);
        $this->assertStringContainsString($sale->receipt_no, $deduction->description);
    }

    /** BR-30 — a milk deduction must name the farmer it comes from. */
    public function test_br30_milk_deduction_requires_a_farmer(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 20);
        $this->actingAs($officer);

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
            ], [['product_id' => $product->id, 'quantity' => 1.0]], $officer);

            $this->fail('A milk deduction with no farmer must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-30', $exception->ruleId);
        }
    }

    /** BR-25 — credit is a category flag, so a non-credit category refuses it. */
    public function test_br25_credit_sales_need_a_category_that_allows_credit(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 30, allowCredit: false);
        $this->actingAs($officer);

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_COOPERATIVE,
                'cooperative_id' => $this->makeMilkWorld()['cooperative']->id,
                'payment_method' => Sale::PAYMENT_CREDIT,
            ], [['product_id' => $product->id, 'quantity' => 2.0]], $officer);

            $this->fail('A category that does not allow credit must refuse a credit sale.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-25', $exception->ruleId);
        }
    }

    /**
     * BR-26 — a sale line's price is money the shop is owed, so it is never
     * negative and never zero.
     *
     * `items.*.unit_price` was validated as a string and nothing else, so '-500'
     * reached Money::fromMajor as -50,000 kobo. The refusal is asserted at both
     * layers on purpose: the form gives the officer a field-level error, and the
     * service refuses regardless of who is calling it — the mobile sync path
     * (ARCH-2) does not pass through the form at all.
     */
    public function test_br26_a_sale_line_price_below_a_kobo_is_refused(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 20);
        $this->actingAs($officer);

        // Layer one: the form.
        $this->post(route('shop.sales.store'), [
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
            'items' => [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '-500']],
        ])->assertSessionHasErrors('items.0.unit_price');

        $this->assertSame(0, Sale::query()->count(), 'Nothing was written.');

        // Layer two: the service, called directly the way the sync batch calls it.
        foreach ([-50_000, 0] as $unitPriceMinor) {
            try {
                app(SaleService::class)->record([
                    'customer_type' => Sale::CUSTOMER_WALKIN,
                    'payment_method' => Sale::PAYMENT_CASH,
                ], [[
                    'product_id' => $product->id,
                    'quantity' => 2.0,
                    'unit_price_minor' => $unitPriceMinor,
                ]], $officer);

                $this->fail('A unit price of '.$unitPriceMinor.' kobo must be refused.');
            } catch (RuleViolationException $exception) {
                $this->assertSame('BR-26', $exception->ruleId);
            }
        }

        // And the goods stayed on the shelf.
        $this->assertSame('20.00', (string) $product->refresh()->quantity_on_hand);
        $this->assertSame(0, Sale::query()->count());
    }

    /**
     * BR-26 / BR-30 — the consequence the negative price was really buying.
     *
     * A negative line offsets the positive ones, so the receipt and the day's
     * revenue understate while the stock still leaves; on a milk_deduction sale it
     * wrote a NEGATIVE pending deduction, which is a standing credit against the
     * farmer's next payment for goods they took away.
     */
    public function test_br30_a_negative_line_can_never_reach_a_farmer_deduction(): void
    {
        $world = $this->makeMilkWorld();
        [$officer, $product] = $this->shopWorld(quantity: 60);
        $this->actingAs($officer);

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_FARMER,
                'farmer_id' => $world['farmer']->id,
                'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
            ], [
                ['product_id' => $product->id, 'quantity' => 1.0],
                ['product_id' => $product->id, 'quantity' => 4.0, 'unit_price_minor' => -100_000_00],
            ], $officer);

            $this->fail('A line priced below zero must abort the sale.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-26', $exception->ruleId);
        }

        $this->assertSame(0, PendingFarmerDeduction::query()->count(), 'No debt was stood up against the farmer.');
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('60.00', (string) $product->refresh()->quantity_on_hand);

        // The same sale priced honestly goes through, and the deduction is positive.
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_FARMER,
            'farmer_id' => $world['farmer']->id,
            'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
        ], [['product_id' => $product->fresh()->id, 'quantity' => 5.0]], $officer);

        $this->assertGreaterThan(
            0,
            (int) PendingFarmerDeduction::query()->where('sale_id', $sale->id)->value('amount_minor'),
        );
    }

    /**
     * BR-25 — "Retiring a category hides it from NEW sales but preserves all
     * history."
     *
     * The half of the rule that stops something was never implemented: retirement
     * flipped a status and the counter went on selling. This is the refusal, and
     * the picker that should not have offered it in the first place.
     */
    public function test_br25_a_retired_categorys_products_can_no_longer_be_sold(): void
    {
        [$manager, $product] = $this->shopWorld(quantity: 20);
        $this->actingAs($manager);

        // Sellable before, so the refusal below is retirement's doing and nothing else.
        $this->assertTrue(Product::query()->sellable()->whereKey($product->id)->exists());

        $this->post(route('shop.categories.retire', $product->category))->assertRedirect();

        try {
            app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_CASH,
            ], [['product_id' => $product->id, 'quantity' => 3.0]], $manager);

            $this->fail('A product under a retired category must not sell.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-25', $exception->ruleId);
        }

        $this->assertSame('20.00', (string) $product->refresh()->quantity_on_hand, 'Stock did not move.');
        $this->assertSame(0, Sale::query()->count());

        // And it is gone from the POS picker, so the officer never offers it.
        $this->assertFalse(Product::query()->sellable()->whereKey($product->id)->exists());

        $picker = $this->get(route('shop.sales.index'));

        $picker->assertOk();
        $this->assertFalse(
            collect($picker->viewData('products'))->contains(fn (Product $listed) => $listed->id === $product->id),
            'A retired category must not appear in the sale screen.',
        );
    }

    /**
     * BR-25 — a credit sale is a debt, and only a cooperative has an account to
     * carry one.
     *
     * `guardCredit()` checked the category flag and stopped there, so
     * customer_type=walkin with payment_method=credit booked the sale, decremented
     * the stock, recorded nothing received and created no receivable of any kind.
     * The money owed existed on the customer's receipt and nowhere in the database.
     */
    public function test_br25_a_credit_sale_to_anyone_but_a_cooperative_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        [$officer, $product] = $this->shopWorld(quantity: 40);
        $this->actingAs($officer);

        foreach ([
            [Sale::CUSTOMER_WALKIN, null],
            [Sale::CUSTOMER_INTERNAL, null],
            [Sale::CUSTOMER_FARMER, null],
        ] as [$customerType, $cooperativeId]) {
            try {
                app(SaleService::class)->record([
                    'customer_type' => $customerType,
                    'farmer_id' => $customerType === Sale::CUSTOMER_FARMER ? $world['farmer']->id : null,
                    'cooperative_id' => $cooperativeId,
                    'payment_method' => Sale::PAYMENT_CREDIT,
                ], [['product_id' => $product->id, 'quantity' => 2.0]], $officer);

                $this->fail('A '.$customerType.' credit sale has no debtor and must be refused.');
            } catch (RuleViolationException $exception) {
                $this->assertSame('BR-25', $exception->ruleId);
            }
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('40.00', (string) $product->refresh()->quantity_on_hand);

        // The cooperative case still works, and it lands on the ledger — which is
        // what makes the refusals above a closed door rather than a dead end.
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_COOPERATIVE,
            'cooperative_id' => $world['cooperative']->id,
            'payment_method' => Sale::PAYMENT_CREDIT,
        ], [['product_id' => $product->fresh()->id, 'quantity' => 2.0]], $officer);

        $this->assertDatabaseHas('cooperative_entries', [
            'source_type' => $sale->getMorphClass(),
            'source_id' => $sale->id,
            'direction' => 'out',
            'amount_minor' => (int) $sale->total_minor,
        ]);

        /*
         * And the screen's red tile is the debt, not a tally of everything ever
         * lent. It used to be the lifetime sum of credit issued with nothing
         * subtracted, so it only ever climbed and disagreed with the cooperative's
         * own balance the moment anyone paid.
         */
        $this->assertSame(
            (int) $sale->total_minor,
            $this->get(route('shop.sales.index'))->viewData('creditOutstandingMinor'),
        );

        app(CooperativeLedgerService::class)->post(
            $world['cooperative']->generalAccount(),
            CooperativeEntry::DIRECTION_IN,
            (int) $sale->total_minor,
            'The cooperative settles its shop account.',
            null,
            $officer,
        );

        $this->assertSame(
            0,
            $this->get(route('shop.sales.index'))->viewData('creditOutstandingMinor'),
            'A repayment must reduce a figure labelled "outstanding".',
        );
    }

    /**
     * ARCH-2 — "the API enforces the same rules as the web UI."
     *
     * The web form pinned customer_type with an `in:` rule and the mobile sync path
     * did not, so the phone's own literal 'walk_in' — which nothing else in the
     * system recognises — landed in a bare string(16). The sale then never appeared
     * under the Walk-in filter and was miscounted in every breakdown by customer
     * type, while customerLabel() fell through to its default branch and rendered
     * "Walk-in" anyway, so the screen never gave it away.
     */
    public function test_arch2_a_customer_type_the_shop_does_not_recognise_is_refused(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 20);
        $this->actingAs($officer);

        foreach ([
            ['customer_type' => 'walk_in', 'payment_method' => Sale::PAYMENT_CASH],
            ['customer_type' => Sale::CUSTOMER_WALKIN, 'payment_method' => 'pos'],
        ] as $data) {
            try {
                app(SaleService::class)->record(
                    $data,
                    [['product_id' => $product->id, 'quantity' => 1.0]],
                    $officer,
                );

                $this->fail('A value neither the form nor the model recognises must be refused.');
            } catch (RuleViolationException $exception) {
                $this->assertSame('BR-26', $exception->ruleId);
            }
        }

        $this->assertSame(0, Sale::query()->count());

        // The value the rest of the system actually uses goes through.
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->id, 'quantity' => 1.0]], $officer);

        $this->assertSame('walkin', $sale->customer_type);
    }

    /**
     * BR-35 / TEST-4 — "test accounts are excluded from all reports, aggregates
     * and payroll."
     *
     * The sale was already excluded. The debt it created was not: farmers are
     * records rather than accounts (USER-1), so a rehearsal left a live, unmarked
     * deduction pointing at a real person for Phase 7 to collect.
     */
    public function test_br35_a_test_users_milk_deduction_is_marked_as_test_activity(): void
    {
        $world = $this->makeMilkWorld();
        [, $product] = $this->shopWorld(quantity: 30);

        $tester = $this->makeUser('Test Shop Officer', ['is_test' => true]);
        $this->assignRole($tester, 'One-Stop Shop Manager');
        $this->actingAs($tester);

        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_FARMER,
            'farmer_id' => $world['farmer']->id,
            'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
        ], [['product_id' => $product->id, 'quantity' => 2.0]], $tester);

        $deduction = PendingFarmerDeduction::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertTrue((bool) $sale->is_test);
        $this->assertTrue((bool) $deduction->is_test, 'The deduction inherits the actor’s flag, exactly as the sale does.');

        // Which is the whole point: a payment run will not see it.
        $this->assertFalse(
            PendingFarmerDeduction::query()->excludingTestData()->pending()->whereKey($deduction->id)->exists(),
        );

        // A real officer's deduction is still collected.
        $this->flushSession();
        $real = $this->makeUser('Real Shop Officer');
        $this->assignRole($real, 'One-Stop Shop Manager');
        $this->actingAs($real);

        $realSale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_FARMER,
            'farmer_id' => $world['farmer']->id,
            'payment_method' => Sale::PAYMENT_MILK_DEDUCTION,
        ], [['product_id' => $product->fresh()->id, 'quantity' => 1.0]], $real);

        $this->assertTrue(
            PendingFarmerDeduction::query()->excludingTestData()->pending()
                ->where('sale_id', $realSale->id)->exists(),
        );
    }

    /**
     * BR-26 — receiving stock reads the balance under a lock, inside the
     * transaction.
     *
     * `receive()` derived the new balance from the route-bound model, hydrated when
     * the request arrived and never re-read — so it did not race a concurrent
     * writer, it overwrote everything that had happened since the receiving form
     * was opened. Every other writer in the class already re-reads under a lock;
     * this test interleaves a sale between hydration and receipt, which is the
     * ordinary sequence at a counter with one person selling and another receiving.
     */
    public function test_br26_receiving_stock_does_not_erase_sales_made_since_the_page_loaded(): void
    {
        [$officer, $product] = $this->shopWorld(quantity: 40);
        $this->actingAs($officer);

        // The inventory officer opens the receiving form: the model as it is now.
        $stale = Product::query()->findOrFail($product->id);

        // The counter sells 12 while that form is open.
        app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->id, 'quantity' => 12.0]], $officer);

        // The officer submits "received 100" against the model they were bound to.
        app(StockService::class)->receive($stale, [
            'batch_no' => 'BATCH-RECV-1',
            'quantity_received' => 100.0,
            'received_on' => Wat::today()->toDateString(),
        ], $officer);

        $this->assertSame(
            '128.00',
            (string) $product->refresh()->quantity_on_hand,
            '40 − 12 + 100. Reading the stale 40 gives 140 and erases the sale.',
        );

        // The movement ledger and the balance agree, which is what BR-26 is for.
        $this->assertSame('128.00', (string) StockMovement::query()
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_STOCK_IN)
            ->latest('id')
            ->value('balance_after'));
    }

    /**
     * NFR-1 / BR-29 — the revenue tiles are summed by the database now, and are
     * the same figures they were.
     *
     * Margin hydrated every sale of the day `with('items')` and stock value
     * hydrated the whole catalogue, both to add two columns together. This is the
     * equivalence check that refactor needs: the PHP that used to produce each
     * number is run beside the SQL that produces it now, including the voided sale
     * that must not count. It is a guard rather than a defect proof — the point of
     * the change was that nothing on screen moves.
     */
    public function test_nfr1_the_revenue_aggregates_are_summed_by_the_database(): void
    {
        [$manager, $product] = $this->shopWorld(quantity: 100);
        $this->actingAs($manager);

        $sales = [];

        foreach ([3.0, 5.5, 2.0] as $quantity) {
            $sales[] = app(SaleService::class)->record([
                'customer_type' => Sale::CUSTOMER_WALKIN,
                'payment_method' => Sale::PAYMENT_CASH,
            ], [['product_id' => $product->fresh()->id, 'quantity' => $quantity]], $manager);
        }

        // One of them is voided, so the SQL has to honour notVoided() as the PHP did.
        app(SaleService::class)->void(end($sales), 'Rung up against the wrong customer.', $manager);

        $expectedMargin = (int) collect($sales)
            ->reject(fn (Sale $sale) => $sale->refresh()->isVoided())
            ->sum(fn (Sale $sale) => $sale->load('items')->marginMinor());

        $screen = $this->get(route('shop.sales.index'));

        $screen->assertOk();
        $this->assertGreaterThan(0, $expectedMargin, 'A zero margin would make the comparison vacuous.');
        $this->assertSame($expectedMargin, $screen->viewData('marginTodayMinor'));

        $inventory = $this->get(route('shop.inventory'));

        $inventory->assertOk();
        $this->assertSame(
            (int) Product::query()->get()->sum(fn (Product $listed) => $listed->stockValueMinor()),
            $inventory->viewData('stockValueMinor'),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Resolve an API resource exactly as an HTTP response would, so `when()`
     * placeholders are stripped rather than left in the array.
     *
     * @return array<string, mixed>
     */
    private function resolve(JsonResource $resource, User $as): array
    {
        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $as);

        return json_decode($resource->toResponse($request)->getContent(), true)['data'];
    }

    /**
     * A shop manager plus one product.
     *
     * @return array{0: User, 1: Product}
     */
    private function shopWorld(
        int $quantity = 10,
        bool $requiresPrescription = false,
        bool $allowCredit = true,
    ): array {
        $manager = $this->makeUser('Shop Manager '.Str_random(), []);
        $this->assignRole($manager, 'One-Stop Shop Manager');

        $product = $this->asSystem(function () use ($quantity, $requiresPrescription, $allowCredit): Product {
            $category = ProductCategory::query()->create([
                'code' => 'CAT'.random_int(1000, 9999),
                'name' => 'Test category',
                'default_unit' => 'bag',
                'default_reorder_level' => 10,
                'requires_prescription' => $requiresPrescription,
                'track_expiry' => false,
                'allow_credit' => $allowCredit,
                'requires_manager_approval' => false,
                'status' => 'active',
            ]);

            $product = Product::query()->create([
                'sku' => 'SKU'.random_int(1000, 9999),
                'name' => 'Test product',
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 9_800_00,
                'selling_price_minor' => 12_500_00,
                'reorder_level' => 10,
                'quantity_on_hand' => $quantity,
                'status' => 'active',
            ]);

            StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'reference' => 'opening',
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'balance_after' => $quantity,
            ]);

            return $product;
        });

        return [$manager, $product];
    }
}

/** Keeps generated user e-mails unique across helper calls. */
function Str_random(): string
{
    static $counter = 0;

    return (string) ++$counter;
}
