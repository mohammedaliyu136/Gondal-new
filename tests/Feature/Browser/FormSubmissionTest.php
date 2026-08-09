<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\Consignment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\QualityTestDefinition;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Wat;
use Illuminate\Support\Facades\File;
use Tests\GondalTestCase;

/**
 * Regression tests that post what a BROWSER posts.
 *
 * The rule suite covers the business rules thoroughly, but it reaches them by
 * calling services directly. That left a gap wide enough for two shipped defects
 * to sit in for the whole build while 163 tests stayed green:
 *
 *   - the quality-test control was inside a nested <form>, which browsers discard,
 *     so no quality test could ever be recorded through the interface;
 *   - the sale form posted four line rows and validation required every one, so a
 *     sale was impossible unless the customer bought exactly four products.
 *
 * Neither is reachable from a service-level test, because neither is a rule — both
 * are the shape of the request the form actually produces. These tests assert on
 * that shape: the field NAMES the markup emits, and the blank rows it sends.
 */
class FormSubmissionTest extends GondalTestCase
{
    /**
     * The sale form renders a fixed set of line rows; unused rows post empty
     * strings. A one-item sale must work.
     */
    public function test_a_single_item_sale_can_be_recorded_from_the_form(): void
    {
        $officer = $this->makeUser('POS Officer');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);
        $this->actingAs($officer->fresh());

        $product = $this->stockedProduct('FEED-A');

        // Exactly what the browser sends: row 0 filled, rows 1-3 blank.
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $items[$i] = [
                'product_id' => $i === 0 ? (string) $product->getKey() : '',
                'quantity' => $i === 0 ? '2' : '',
                'unit_price' => '',
            ];
        }

        $response = $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin',
            'customer_name' => 'Walk-in customer',
            'payment_method' => 'cash',
            'amount_received' => '',
            'items' => $items,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $sale = $this->asSystem(fn () => Sale::query()->latest('id')->with('items')->firstOrFail());

        $this->assertCount(1, $sale->items, 'The three blank rows must not become line items.');
        $this->assertSame($product->getKey(), $sale->items->first()->product_id);
    }

    /** And a sale with several lines still records every one of them. */
    public function test_a_multi_line_sale_still_records_every_line(): void
    {
        $officer = $this->makeUser('POS Officer Multi');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);
        $this->actingAs($officer->fresh());

        $first = $this->stockedProduct('FEED-B');
        $second = $this->stockedProduct('FEED-C');

        $items = [
            ['product_id' => (string) $first->getKey(), 'quantity' => '1', 'unit_price' => ''],
            ['product_id' => (string) $second->getKey(), 'quantity' => '3', 'unit_price' => ''],
            ['product_id' => '', 'quantity' => '', 'unit_price' => ''],
            ['product_id' => '', 'quantity' => '', 'unit_price' => ''],
        ];

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin',
            'customer_name' => 'Walk-in customer',
            'payment_method' => 'cash',
            'items' => $items,
        ])->assertSessionHasNoErrors();

        $sale = $this->asSystem(fn () => Sale::query()->latest('id')->with('items')->firstOrFail());

        $this->assertCount(2, $sale->items);
        $this->assertSame(['1.00', '3.00'], $sale->items->pluck('quantity')->map(fn ($q) => number_format((float) $q, 2, '.', ''))->all());
    }

    /** A sale with no lines at all is still refused. */
    public function test_a_sale_with_no_lines_is_refused(): void
    {
        $officer = $this->makeUser('POS Officer Empty');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);
        $this->actingAs($officer->fresh());

        $this->post(route('shop.sales.store'), [
            'customer_type' => 'walkin',
            'customer_name' => 'Walk-in customer',
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => '', 'quantity' => '', 'unit_price' => ''],
                ['product_id' => '', 'quantity' => '', 'unit_price' => ''],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, $this->asSystem(fn () => Sale::query()->count()));
    }

    /**
     * The confirmation screen posts a quality test with the reading keyed by test
     * id and the test identified by the submit button that was clicked, because it
     * cannot open a form of its own inside the confirmation form.
     */
    public function test_a_quality_test_can_be_recorded_from_the_confirmation_screen(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Confirming Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        $consignment = $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-9001',
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => '100.00',
            'status' => Consignment::STATUS_AWAITING,
        ]));

        $definitions = $this->asSystem(fn () => QualityTestDefinition::query()->active()->orderBy('position')->get());
        $target = $definitions->first();

        // The browser posts EVERY row's reading, keyed by definition id, plus the
        // clicked button's value naming which row was submitted.
        $readings = [];
        foreach ($definitions as $definition) {
            $readings[$definition->getKey()] = $definition->kind === 'boolean' ? '1' : '1.030';
        }

        $this->post(route('consignments.quality-test', $consignment), [
            'quality_test_definition_id' => (string) $target->getKey(),
            'readings' => $readings,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Exactly one test recorded — the one whose button was clicked, not all of them.
        $this->assertSame(1, $this->asSystem(fn () => $consignment->qualityTests()->count()));
        $this->assertSame(
            $target->getKey(),
            $this->asSystem(fn () => $consignment->qualityTests()->first()->quality_test_definition_id),
        );
    }

    /** The flat shape the API and the rule tests use keeps working unchanged. */
    public function test_the_flat_quality_test_payload_still_works(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Flat Payload Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        $consignment = $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-9002',
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => '80.00',
            'status' => Consignment::STATUS_AWAITING,
        ]));

        $definition = $this->asSystem(fn () => QualityTestDefinition::query()->active()->orderBy('position')->firstOrFail());

        $this->post(route('consignments.quality-test', $consignment), [
            'quality_test_definition_id' => (string) $definition->getKey(),
            'reading' => $definition->kind === 'boolean' ? '1' : '1.030',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asSystem(fn () => $consignment->qualityTests()->count()));
    }

    /**
     * No view may open a <form> inside another <form>. The browser silently drops
     * the inner one, which turns its submit button into a submit for the outer
     * form — a failure that renders and looks fine, and that no controller test
     * can see.
     */
    public function test_no_view_nests_a_form_inside_another_form(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $markup = $file->getContents();

            // Strip Blade comments so a documented example does not trip this.
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $markup);

            $depth = 0;
            foreach (preg_split('/(?=<form\b)|(?<=<\/form>)/i', $markup) as $chunk) {
                if (preg_match('/^<form\b/i', $chunk)) {
                    $depth++;

                    if ($depth > 1) {
                        $offenders[] = $file->getRelativePathname();
                    }
                }

                if (preg_match('/<\/form>$/i', $chunk)) {
                    $depth = max(0, $depth - 1);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)));
    }

    /* ------------------------------------------------------------------ */

    /**
     * A product with stock on hand. The rule tests build one of these too, but
     * privately; these tests need it for a different reason — to have something a
     * form can post — so it is rebuilt here rather than coupling the two suites.
     */
    private function stockedProduct(string $sku, int $quantity = 50): Product
    {
        return $this->asSystem(function () use ($sku, $quantity): Product {
            $category = ProductCategory::query()->firstOrCreate(
                ['code' => 'BROWSER-CAT'],
                [
                    'name' => 'Animal feed',
                    'default_unit' => 'bag',
                    'default_reorder_level' => 5,
                    'requires_prescription' => false,
                    'track_expiry' => false,
                    'allow_credit' => true,
                    'requires_manager_approval' => false,
                    'status' => 'active',
                ],
            );

            $product = Product::query()->create([
                'sku' => $sku,
                'name' => 'Concentrate feed '.$sku,
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 9_800_00,
                'selling_price_minor' => 12_500_00,
                'reorder_level' => 5,
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
    }
}
