<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.7 product batches.
 *
 * §15.5 — this table references a goods-received-note concept that has no screen
 * in v1. The requisition link is the only provenance available until a GRN is
 * specified; do not invent one here.
 */
class ProductBatch extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'product_id', 'batch_no', 'supplier', 'received_on', 'expiry_on',
        'quantity_received', 'quantity_remaining', 'unit_cost_minor',
        'requisition_id', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'expiry_on' => 'date',
            'quantity_received' => 'decimal:2',
            'quantity_remaining' => 'decimal:2',
            'unit_cost_minor' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_on !== null && $this->expiry_on->lessThan(Wat::today());
    }

    public function daysToExpiry(): ?int
    {
        return $this->expiry_on === null
            ? null
            : (int) Wat::today()->diffInDays($this->expiry_on, false);
    }

    /** Oldest usable stock first, so `track_expiry` categories rotate properly. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('case when expiry_on is null then 1 else 0 end')
            ->orderBy('expiry_on')
            ->orderBy('id');
    }

    /**
     * Available AND not past its expiry date.
     *
     * `available()` sorts soonest-expiry-first, which is the right rotation — but
     * on its own it also sorts EXPIRED stock to the very front, so an expired
     * batch was the first thing dispensed at the counter, silently. Selling is
     * the one operation that must exclude them; a stock count or an adjustment
     * still needs to see them, which is why this is a separate scope rather than
     * a change to available().
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->available()->where(function (Builder $inner): void {
            $inner->whereNull('expiry_on')
                ->orWhereDate('expiry_on', '>=', Wat::today()->toDateString());
        });
    }

    /** Stock that will expire within $days — the restock warning list. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->available()
            ->whereNotNull('expiry_on')
            ->whereDate('expiry_on', '>=', Wat::today()->toDateString())
            ->whereDate('expiry_on', '<=', Wat::today()->addDays($days)->toDateString());
    }

    /** Available stock already past its date — unsellable, still countable. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_on')
            ->whereDate('expiry_on', '<', Wat::today()->toDateString());
    }

    public function hasExpired(): bool
    {
        return $this->expiry_on !== null
            && $this->expiry_on->lt(Wat::today());
    }
}
