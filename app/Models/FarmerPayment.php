<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What one farmer is owed for one run, and how that figure was reached.
 *
 * BR-15 — the cooperative percentages in force are snapshotted onto this row at
 * generation. Changing the levy next year must not rewrite what was paid last
 * year, for the same reason BR-13/BR-14 snapshot the grade rate.
 */
class FarmerPayment extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_PAYABLE = 'payable';

    /** BR-36 — computed and owed, but not payable until the farmer is revalidated. */
    public const STATUS_HELD = 'held';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    public const HOLD_UNVALIDATED = 'unvalidated';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'payment_run_id', 'farmer_id', 'litres_paid', 'gross_minor',
        'savings_minor', 'levy_minor', 'social_minor', 'shop_deduction_minor', 'net_minor',
        'savings_pct_snapshot', 'levy_pct_snapshot', 'social_minor_snapshot',
        'status', 'hold_reason', 'breakdown', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'is_test' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PaymentRun::class, 'payment_run_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    /** The deliveries this payment claims. One row per delivery, forever. */
    public function lines(): HasMany
    {
        return $this->hasMany(FarmerPaymentDelivery::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(FarmerPaymentDisbursement::class);
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /** What is left to hand over: net less whatever has already been disbursed. */
    public function outstandingMinor(): int
    {
        if ($this->isHeld() || $this->status === self::STATUS_REVERSED) {
            return 0;
        }

        return max(0, (int) $this->net_minor - (int) $this->disbursements()->sum('amount_minor'));
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAYABLE);
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    /**
     * BR-35 — a payment made during a training run is not part of a farmer's
     * money history and must never appear on the statement handed to them.
     */
    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('farmer_payments.is_test', false);
    }
}
