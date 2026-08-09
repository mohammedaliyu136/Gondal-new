<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * product-categories.html.
 *
 * G-5 / BR-25 — "Product categories are created and retired by users holding
 * shop.categories.create. Retiring a category hides it from new sales but
 * preserves all history. Categories are never deleted."
 *
 * The Phase 6 acceptance criterion is that a category the manager creates is
 * IMMEDIATELY sellable — no deployment, no cache warm-up. That works because
 * nothing anywhere enumerates categories: products point at rows, and the
 * behaviour flags are columns.
 */
class ProductCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('shop.categories.index', [
            'categories' => ProductCategory::query()
                ->withCount('products')
                ->orderBy('position')
                ->orderBy('name')
                ->paginate($this->perPage($request->integer('per_page') ?: null)),
            'canCreate' => $this->allows('shop.categories.create'),
            'canEdit' => $this->allows('shop.categories.edit'),
            'canRetire' => $this->allows('shop.categories.delete'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:product_categories,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_unit' => ['nullable', 'string', 'max:24'],
            'default_reorder_level' => ['nullable', 'integer', 'min:0'],
            // Behaviour as data (BR-25 / BR-27).
            'requires_prescription' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'allow_credit' => ['nullable', 'boolean'],
            'requires_manager_approval' => ['nullable', 'boolean'],
        ]);

        $category = ProductCategory::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'default_unit' => $validated['default_unit'] ?? null,
            'default_reorder_level' => $validated['default_reorder_level'] ?? null,
            'requires_prescription' => $request->boolean('requires_prescription'),
            'track_expiry' => $request->boolean('track_expiry'),
            'allow_credit' => $request->boolean('allow_credit'),
            'requires_manager_approval' => $request->boolean('requires_manager_approval'),
            // Phase 6 acceptance — immediately sellable.
            'status' => 'active',
            'position' => (int) ProductCategory::query()->max('position') + 1,
        ]);

        $this->audit->created(
            $category,
            sprintf('Product category "%s" (%s) created and immediately sellable', $category->name, $category->code),
            'One-Stop Shop',
            [
                'rule' => 'BR-25',
                'requires_prescription' => $category->requires_prescription,
                'track_expiry' => $category->track_expiry,
                'allow_credit' => $category->allow_credit,
            ],
            $this->currentUser(),
        );

        return back()->with('success', $category->name.' created — it is sellable now.');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorizeAccess('shop.categories.edit', $category, 'Category → '.$category->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_unit' => ['nullable', 'string', 'max:24'],
            'default_reorder_level' => ['nullable', 'integer', 'min:0'],
            'requires_prescription' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'allow_credit' => ['nullable', 'boolean'],
            'requires_manager_approval' => ['nullable', 'boolean'],
        ]);

        $before = $category->only([
            'name', 'description', 'default_unit', 'default_reorder_level',
            'requires_prescription', 'track_expiry', 'allow_credit', 'requires_manager_approval',
        ]);

        $category->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'default_unit' => $validated['default_unit'] ?? null,
            'default_reorder_level' => $validated['default_reorder_level'] ?? null,
            'requires_prescription' => $request->boolean('requires_prescription'),
            'track_expiry' => $request->boolean('track_expiry'),
            'allow_credit' => $request->boolean('allow_credit'),
            'requires_manager_approval' => $request->boolean('requires_manager_approval'),
        ])->save();

        $this->audit->edited(
            $category,
            $category->name.' category updated',
            'One-Stop Shop',
            $before,
            $category->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $category->name.' updated.');
    }

    /** BR-25 — retire, never delete. */
    public function retire(ProductCategory $category): RedirectResponse
    {
        $this->authorizeAccess('shop.categories.delete', $category, 'Retire → '.$category->name);

        if ($category->isRetired()) {
            $category->reinstate();

            $this->audit->edited(
                $category,
                $category->name.' category reinstated',
                'One-Stop Shop',
                ['status' => 'retired'],
                ['status' => 'active'],
                $this->currentUser(),
            );

            return back()->with('success', $category->name.' is sellable again.');
        }

        $category->retire();

        $this->audit->edited(
            $category,
            $category->name.' category retired — hidden from new sales, history preserved',
            'One-Stop Shop',
            ['status' => 'active'],
            ['status' => 'retired', 'rule' => 'BR-25', 'history' => 'preserved'],
            $this->currentUser(),
        );

        return back()->with('success', $category->name.' retired. Its sales history is untouched.');
    }
}
