<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\AdjustmentReason;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Audit\AuditLogger;
use App\Services\Shop\StockService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * one-stop-shop.html and product-detail.html.
 *
 * BR-29 — "Users holding shop.sales but not shop.revenue see their own
 *   transactions and no aggregate revenue, margin or stock-value figure — in API
 *   responses as well as UI." The `seesRevenue` flag decides what the view is
 *   even given; the figures are not computed and then hidden with CSS.
 *   The Inventory Officer persona is the concrete case: quantities only.
 */
class InventoryController extends Controller
{
    /**
     * ARCH-6 — the shape Money::fromMajor parses, minus the minus sign.
     *
     * A cost price is money too. `['nullable','string']` let '-3600' through to
     * the parser, which returns -360,000 kobo — a negative unit cost inverts the
     * margin and the stock-value tile, both shop.revenue figures (BR-29).
     */
    private const MONEY_FORMAT = 'regex:/^[0-9][0-9,]*(\.[0-9]{1,2})?$/';

    public function __construct(
        private readonly StockService $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $products = Product::query()
            ->with('category')
            ->when($request->filled('category'), fn ($query) => $query->where('product_category_id', $request->integer('category')))
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // BR-29 — the gate.
        $seesRevenue = $this->allows('shop.revenue.view');

        return view('shop.inventory.index', [
            'products' => $products,
            'categories' => ProductCategory::query()->sellable()->orderBy('position')->get(),
            'seesRevenue' => $seesRevenue,
            /*
             * Withheld entirely, not merely hidden.
             *
             * NFR-1 — and added up by the database. This hydrated every product row
             * to multiply two of its columns together; the query has no bound and
             * no filter, so it is the shape that fails first as the catalogue
             * grows. ROUND per row matches Product::stockValueMinor() exactly, so
             * the tile does not change value — only how it is arrived at.
             */
            'stockValueMinor' => $seesRevenue
                ? (int) Product::query()
                    ->selectRaw('coalesce(sum(round(quantity_on_hand * cost_price_minor)), 0) as stock_value_minor')
                    ->value('stock_value_minor')
                : null,
            'lowStockCount' => Product::query()->lowStock()->count(),
            'canCreate' => $this->allows('shop.inventory.create'),
            'canAdjust' => $this->allows('shop.inventory.edit'),
            'adjustmentReasons' => AdjustmentReason::query()->for('stock')->orderBy('position')->get(),
        ]);
    }

    public function show(Product $product): View
    {
        $this->authorizeAccess('shop.inventory.view', $product, 'Product → '.$product->name);

        $seesRevenue = $this->allows('shop.revenue.view');

        return view('shop.inventory.show', [
            'product' => $product->load('category'),
            'batches' => $product->batches()->latest('received_on')->get(),
            'movements' => $product->stockMovements()->with(['reason', 'batch', 'sale'])->limit(30)->get(),
            'seesRevenue' => $seesRevenue,
            'stockValueMinor' => $seesRevenue ? $product->stockValueMinor() : null,
            'marginMinor' => $seesRevenue ? $product->marginMinor() : null,
            'adjustmentReasons' => AdjustmentReason::query()->for('stock')->orderBy('position')->get(),
            'canAdjust' => $this->allows('shop.inventory.edit', $product),
            'canReceive' => $this->allows('shop.inventory.create'),
            // BR-25 — a live category to move the product into, plus its own,
            // so a product already in a retired category can still be edited
            // without being silently pulled out of it.
            'categories' => ProductCategory::query()->sellable()->orderBy('position')->get()
                ->push($product->category)->filter()->unique('id')->sortBy('position')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:32', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            // BR-25 — categories are rows; a new product must pick a live one.
            'product_category_id' => ['required', 'exists:product_categories,id'],
            'unit' => ['nullable', 'string', 'max:24'],
            'cost_price' => ['nullable', 'string', self::MONEY_FORMAT],
            'selling_price' => ['required', 'string', self::MONEY_FORMAT],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'preferred_supplier' => ['nullable', 'string', 'max:255'],
        ]);

        $category = ProductCategory::query()->findOrFail($validated['product_category_id']);

        if ($category->isRetired()) {
            return back()->withErrors([
                'product_category_id' => $category->name.' is retired and cannot take new products.',
            ])->withInput();
        }

        $product = Product::query()->create([
            'sku' => $validated['sku'],
            'name' => $validated['name'],
            'product_category_id' => $category->getKey(),
            // BR-25 — the category's default unit and reorder level apply unless
            // overridden, so category behaviour genuinely flows from data.
            'unit' => $validated['unit'] ?? $category->default_unit,
            'cost_price_minor' => Money::fromMajor($validated['cost_price'] ?? null) ?? 0,
            'selling_price_minor' => Money::fromMajor($validated['selling_price']) ?? 0,
            'reorder_level' => $validated['reorder_level'] ?? $category->default_reorder_level,
            'preferred_supplier' => $validated['preferred_supplier'] ?? null,
            'quantity_on_hand' => 0,
            'status' => 'active',
        ]);

        $this->audit->created(
            $product,
            sprintf('Product %s (%s) added to %s', $product->name, $product->sku, $category->name),
            'One-Stop Shop',
            ['selling_price_minor' => (int) $product->selling_price_minor],
            $this->currentUser(),
        );

        return redirect()->route('shop.products.show', $product)->with('success', $product->name.' added.');
    }

    /**
     * The catalogue record: price, cost, reorder level, supplier.
     *
     * A price change is the most routine event in a shop and there was no route
     * for it anywhere, so the only ways to make one were a database edit — which
     * bypasses this audit entry — or a duplicate SKU, which splits the stock and
     * the sales history of what is really one product.
     *
     * Deliberately NOT the SKU or the quantity. The SKU is what stock movements,
     * sale lines and batches are reconciled by, and renaming it silently
     * re-labels history; the quantity has its own audited paths (receive and
     * adjust) and letting it be typed here would be a stock correction with no
     * reason attached.
     *
     * The audit entry carries both prices because a sale is priced from
     * `selling_price_minor` at the moment it is rung up: "why was this bag
     * ₦12,500 in March and ₦13,400 in April" has no other answer.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeAccess('shop.inventory.edit', $product, 'Edit product '.$product->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['required', 'exists:product_categories,id'],
            'unit' => ['nullable', 'string', 'max:24'],
            'cost_price' => ['nullable', 'string', self::MONEY_FORMAT],
            'selling_price' => ['required', 'string', self::MONEY_FORMAT],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'preferred_supplier' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $category = ProductCategory::query()->findOrFail($validated['product_category_id']);

        // BR-25 — the same guard as creation. Moving a product into a retired
        // category would make it unsellable by a side door rather than by a
        // decision anybody recorded.
        if ($category->isRetired() && (int) $category->getKey() !== (int) $product->product_category_id) {
            return back()->withErrors([
                'product_category_id' => $category->name.' is retired and cannot take products.',
            ])->withInput();
        }

        $before = $product->only([
            'name', 'product_category_id', 'unit', 'cost_price_minor',
            'selling_price_minor', 'reorder_level', 'preferred_supplier', 'status',
        ]);

        $product->forceFill([
            'name' => $validated['name'],
            'product_category_id' => $category->getKey(),
            'unit' => $validated['unit'] ?? $product->unit,
            'cost_price_minor' => Money::fromMajor($validated['cost_price'] ?? null) ?? 0,
            'selling_price_minor' => Money::fromMajor($validated['selling_price']) ?? 0,
            'reorder_level' => $validated['reorder_level'] ?? $product->reorder_level,
            'preferred_supplier' => $validated['preferred_supplier'] ?? null,
            'status' => $validated['status'],
        ])->save();

        $this->audit->edited(
            $product,
            sprintf('Product %s (%s) updated', $product->name, $product->sku),
            'One-Stop Shop',
            $before,
            $product->only([
                'name', 'product_category_id', 'unit', 'cost_price_minor',
                'selling_price_minor', 'reorder_level', 'preferred_supplier', 'status',
            ]),
            $this->currentUser(),
        );

        return back()->with('success', $product->name.' updated.');
    }

    public function receiveStock(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeAccess('shop.inventory.create', $product, 'Receive stock for '.$product->name);

        $validated = $request->validate([
            'batch_no' => ['required', 'string', 'max:48'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'received_on' => ['nullable', 'date'],
            // BR-25 — required when the category tracks expiry; the service
            // enforces it from the category flag.
            'expiry_on' => ['nullable', 'date'],
            'quantity_received' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'string', self::MONEY_FORMAT],
            'requisition_id' => ['nullable', 'exists:requisitions,id'],
        ]);

        $batch = $this->stock->receive($product, array_merge($validated, [
            'unit_cost_minor' => Money::fromMajor($validated['unit_cost'] ?? null) ?? $product->cost_price_minor,
        ]), $this->currentUser());

        return back()->with('success', 'Batch '.$batch->batch_no.' received.');
    }

    /** BR-28 */
    public function adjustStock(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeAccess('shop.inventory.edit', $product, 'Adjust stock for '.$product->name);

        $validated = $request->validate([
            'delta' => ['required', 'numeric'],
            'reason_id' => ['required', 'exists:adjustment_reasons,id'],
            'explanation' => ['required', 'string', 'max:2000'],
        ]);

        $movement = $this->stock->adjust(
            $product,
            (float) $validated['delta'],
            (int) $validated['reason_id'],
            $validated['explanation'],
            $this->currentUser(),
        );

        return back()->with('success', sprintf(
            'Stock adjusted. %s now holds %s.',
            $product->name,
            rtrim(rtrim((string) $movement->balance_after, '0'), '.'),
        ));
    }
}
