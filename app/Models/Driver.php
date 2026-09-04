<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.3 drivers and riders.
 *
 * USER-1 — a record, not an account. There is deliberately no user_id here.
 */
class Driver extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'licence_no',
        'type',
        'status',
        'image',
        'bank_name',
        'bank_code',
        'bank_account',
        'account_name',
        'created_by_user_id',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DriverWallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DriverWalletTransaction::class);
    }

    public function getOrCreateWallet(): DriverWallet
    {
        return $this->wallet()->firstOrCreate(
            ['driver_id' => $this->getKey()],
            [
                'balance_minor' => 0,
                'total_credited_minor' => 0,
                'total_debited_minor' => 0,
                'status' => DriverWallet::STATUS_ACTIVE,
                'currency' => 'NGN',
            ]
        );
    }

    public function formattedWalletBalance(): string
    {
        return $this->wallet?->formattedBalance() ?? \App\Support\Money::format(0);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) {
            return null;
        }

        if (str_starts_with($this->image, 'http://') || str_starts_with($this->image, 'https://')) {
            return $this->image;
        }

        return asset('storage/' . ltrim($this->image, '/'));
    }
}

