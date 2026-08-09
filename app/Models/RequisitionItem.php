<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** §6.4 requisition line items. ARCH-6 — prices in kobo. */
class RequisitionItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'requisition_id', 'item', 'purpose', 'quantity', 'unit',
        'unit_price_minor', 'amount_minor', 'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
