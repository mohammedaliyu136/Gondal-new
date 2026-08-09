<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 adjustments.
 *
 * BR-12 — "Every adjustment requires a reason and an explanation. Adjustments
 * are never silent." Both columns are NOT NULL and the service layer refuses an
 * empty explanation, so there is no path to a silent correction.
 */
class Adjustment extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'adjustable_type', 'adjustable_id', 'adjustment_reason_id',
        'litres_delta', 'explanation', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['litres_delta' => 'decimal:2'];
    }

    public function adjustable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(AdjustmentReason::class, 'adjustment_reason_id');
    }

    public function isDeduction(): bool
    {
        return Volume::isNegative($this->litres_delta);
    }

    /** "−1.00 L" as the prototype writes it. */
    public function signedLitres(): string
    {
        $centilitres = Volume::toCentilitres($this->litres_delta);
        $sign = $centilitres < 0 ? '−' : '+';

        return $sign.Volume::format(Volume::fromCentilitres(abs($centilitres)));
    }
}
