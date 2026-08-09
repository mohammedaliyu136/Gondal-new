<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.7 sale lines. BR-29 — unit_cost_minor_snapshot is the shop.revenue figure
 * and is stripped from responses for anyone without that grant.
 */
class SaleItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id', 'product_id', 'product_batch_id', 'quantity',
        'unit_price_minor', 'amount_minor', 'unit_cost_minor_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
            'unit_cost_minor_snapshot' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
