<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Driver & Rider electronic balance / earnings wallet.
 *
 * Tracks available balance and lifetime credited/debited amounts for transport trips,
 * route tariffs, trip adjustments, and payout disbursements.
 */
class DriverWallet extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'driver_id',
        'balance_minor',
        'total_credited_minor',
        'total_debited_minor',
        'status',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'total_credited_minor' => 'integer',
            'total_debited_minor' => 'integer',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DriverWalletTransaction::class)->latest('id');
    }

    public function formattedBalance(): string
    {
        return Money::format($this->balance_minor);
    }

    public function formattedTotalCredited(): string
    {
        return Money::format($this->total_credited_minor);
    }

    public function formattedTotalDebited(): string
    {
        return Money::format($this->total_debited_minor);
    }
}
