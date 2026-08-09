<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.7 stock movements — the audit trail of every quantity change.
 *
 * BR-26 — a sale writes one of these inside the same transaction that decrements
 *   the product, so the ledger and the balance can never disagree.
 * BR-28 — an adjustment requires a reason AND an explanation, and shows up in
 *   the audit log.
 */
class StockMovement extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const TYPE_STOCK_IN = 'stock_in';

    public const TYPE_SALE = 'sale';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_RETURN = 'return';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'product_id', 'product_batch_id', 'movement_type', 'reference',
        'quantity_in', 'quantity_out', 'balance_after', 'reason_id',
        'explanation', 'sale_id', 'workflow_instance_id', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_in' => 'decimal:2',
            'quantity_out' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'is_test' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(AdjustmentReason::class, 'reason_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('movement_type', $type);
    }
}
