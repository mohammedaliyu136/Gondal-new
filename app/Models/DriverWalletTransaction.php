<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit ledger for all rider/driver wallet credits, debits, and adjustments.
 */
class DriverWalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'driver_wallet_id',
        'driver_id',
        'reference',
        'type',
        'source_type',
        'source_id',
        'amount_minor',
        'balance_before_minor',
        'balance_after_minor',
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
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(DriverWallet::class, 'driver_wallet_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
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
}
