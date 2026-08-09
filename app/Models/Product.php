<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.7 products.
 *
 * BR-29 — cost_price_minor is a shop.revenue figure. The resource layer strips
 * it, and any margin or stock-value aggregate, from users without that grant.
 *
 * SCOPE-2 note: this model is deliberately NOT Scopeable. The shop is a single
 * physical location, so a product has no owner, no center and no community — there
 * is no record-level question for a scope to answer. Declaring it scopeable and
 * then supplying only an `own` constraint would be worse than not declaring it at
 * all: the Sales Officer persona is assigned at scope `own` (because their SALES
 * are their own, BR-29), and an `own` constraint on the catalogue would leave them
 * unable to see the very products they exist to sell. Access to inventory is
 * therefore a pure permission question — shop.inventory.view — and the money
 * figures on it are gated separately by shop.revenue (see ProductResource).
 */
class Product extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'product_category_id', 'unit', 'cost_price_minor',
        'selling_price_minor', 'reorder_level', 'preferred_supplier',
        'quantity_on_hand', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cost_price_minor' => 'integer',
            'selling_price_minor' => 'integer',
            'reorder_level' => 'integer',
            'quantity_on_hand' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function isLowStock(): bool
    {
        $level = $this->reorder_level ?? $this->category?->default_reorder_level;

        return $level !== null && (float) $this->quantity_on_hand <= (float) $level;
    }

    /** BR-29 — a shop.revenue figure. */
    public function stockValueMinor(): int
    {
        return (int) round((float) $this->quantity_on_hand * $this->cost_price_minor);
    }

    /** BR-29 — a shop.revenue figure. */
    public function marginMinor(): int
    {
        return (int) $this->selling_price_minor - (int) $this->cost_price_minor;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * BR-25 — "Retiring a category hides it from new sales but preserves all
     * history."
     *
     * Sellable is two conditions, not one. The POS picker and the mobile catalogue
     * both filtered on `active()`, which asks about the PRODUCT's status — so
     * retiring a category left every product under it on the sale screen, priced,
     * and selling. Retirement stopped nothing at all.
     *
     * `whereHas` rather than a join so the caller can keep chaining `with`,
     * `orderBy` and pagination without ambiguous columns; the category's own
     * soft-delete is honoured by the relation.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query
            ->where('products.status', 'active')
            ->whereHas('category', fn (Builder $category) => $category->where('product_categories.status', 'active'));
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('reorder_level')->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }
}
