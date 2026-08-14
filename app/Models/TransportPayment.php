<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What one rider or driver is owed for one run, and which legs made that figure.
 *
 * No deductions, unlike FarmerPayment. A rider is paid their fee. Fuel advances
 * and damage recoveries are real in this business and are deliberately NOT
 * modelled — nothing in the system records one, and a column with no rule behind
 * it invites somebody to start using it as a free-text way to pay people less.
 */
class TransportPayment extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_PAYABLE = 'payable';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'transport_payment_run_id', 'driver_id', 'trip_count', 'litres_carried',
        'amount_minor', 'status', 'breakdown', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'litres_carried' => 'decimal:2',
            'is_test' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TransportPaymentRun::class, 'transport_payment_run_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** The trips this payment claims. One row per trip, forever. */
    public function lines(): HasMany
    {
        return $this->hasMany(TransportPaymentTrip::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(TransportPaymentDisbursement::class);
    }

    /** What is left to hand over: the fee less whatever has been paid already. */
    public function outstandingMinor(): int
    {
        if ($this->status === self::STATUS_REVERSED) {
            return 0;
        }

        return max(0, (int) $this->amount_minor - (int) $this->disbursements()->sum('amount_minor'));
    }

    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('transport_payments.is_test', false);
    }
}
