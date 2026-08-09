<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-30 — "milk_deduction sales create a pending deduction against the farmer's
 * next payment."
 *
 * §15.1 — where the payment module lives is an OPEN DECISION and Phase 7 is
 * blocked. Capturing the deduction now is exactly what BR-13..BR-16 require: the
 * data is correct wherever the module ends up. Nothing here settles a payment.
 */
class PendingFarmerDeduction extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * BR-35 / TEST-4 — inherited from the acting user by RecordsActor, exactly as
     * it is on Sale.
     *
     * The sale a rehearsal creates is already excluded from every revenue figure.
     * The debt it stands up against a farmer's next payment was not marked at all,
     * so a permission-test run under TEST-2 left a real pending deduction against
     * a real person (USER-1 — there is no test farmer to point at instead). §15.1
     * makes that permanent: Phase 7 consumes this table as it stands.
     */
    public bool $tagsTestActivity = true;

    protected $fillable = [
        'farmer_id', 'sale_id', 'amount_minor', 'description', 'status',
        'settled_at', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'settled_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * BR-35 — what a payment run must ask for.
     *
     * Not a global scope: TEST-2 requires a test user to still see their own work
     * while a run is in progress, so the exclusion is opted into by the aggregate
     * rather than hidden from everyone. Same reasoning as AppliesDataScope's.
     */
    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('is_test', false);
    }
}
