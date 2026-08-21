<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit ledger for all farmer wallet credits, debits, and adjustments.
 */
class FarmerWalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'farmer_wallet_id',
        'farmer_id',
        'reference',
        'type',
        'source_type',
        'source_id',
        'amount_minor',
        'balance_before_minor',
        'balance_after_minor',
        'litres',
        'rate_per_litre_minor',
        'description',
        'meta',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_before_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'rate_per_litre_minor' => 'integer',
            'litres' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(FarmerWallet::class, 'farmer_wallet_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount_minor);
    }

    public function formattedBalanceAfter(): string
    {
        return Money::format($this->balance_after_minor);
    }

    public function formattedRate(): ?string
    {
        return $this->rate_per_litre_minor !== null
            ? Money::format($this->rate_per_litre_minor)
            : null;
    }

    public function formattedLitres(): ?string
    {
        return $this->litres !== null
            ? Volume::format($this->litres)
            : null;
    }
}
