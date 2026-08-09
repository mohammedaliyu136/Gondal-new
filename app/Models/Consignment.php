<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\OptimisticLocking;
use App\Models\Concerns\RecordsActor;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * §6.2 consignments — one point's accepted milk travelling to its center.
 *
 * BR-7  litres_dispatched = Σ litres_accepted of its deliveries
 * BR-8  litres_confirmed  = dispatched + Σ adjustments − rejected at center
 * BR-14 the grade rate is SNAPSHOTTED here (grade_rate_id AND the number), so
 *       payment never reads a live join and BR-13 holds forever
 * NFR-4 optimistic locking: confirming an already-confirmed consignment fails
 */
class Consignment extends Model implements Scopeable
{
    use AppliesDataScope;
    use OptimisticLocking;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_AWAITING = 'awaiting_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ADJUSTED = 'adjusted';

    public const STATUS_PARTLY_REJECTED = 'partly_rejected';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'collection_point_id', 'collection_center_id',
        'dispatched_by_user_id', 'dispatched_at', 'litres_dispatched', 'containers',
        'trip_id', 'confirmed_by_user_id', 'confirmed_at', 'litres_confirmed',
        'grade_id', 'grade_rate_id', 'rate_per_litre_minor', 'rate_anchored_at',
        'regraded_at', 'regraded_by_user_id', 'regrade_reason',
        'litres_rejected_at_center', 'rejection_reason_id', 'intake_temperature_c',
        'batch_id', 'officer_notes', 'status', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rate_anchored_at' => 'datetime',
            'regraded_at' => 'datetime',
            'litres_dispatched' => 'decimal:2',
            'litres_confirmed' => 'decimal:2',
            'litres_rejected_at_center' => 'decimal:2',
            'intake_temperature_c' => 'decimal:2',
            'rate_per_litre_minor' => 'integer',
            'containers' => 'integer',
            'lock_version' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    /** BR-4 — who changed an assigned grade, for the exceptions list. */
    public function regradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regraded_by_user_id');
    }

    public function wasRegraded(): bool
    {
        return $this->regraded_at !== null;
    }

    public function scopeResourceKey(): string
    {
        return 'milk.consignment.confirm';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereIn('consignments.collection_center_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('consignments.collection_point_id', $ids),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionCenter',
                fn (Builder $inner) => $inner->whereIn('collection_centers.lga_id', $ids),
            ),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.community_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q
                ->whereIn('consignments.dispatched_by_user_id', $ids)
                ->orWhereIn('consignments.confirmed_by_user_id', $ids),
        ];
    }

    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class);
    }

    public function collectionCenter(): BelongsTo
    {
        return $this->belongsTo(CollectionCenter::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /** BR-14 — the snapshotted rate row. */
    public function gradeRate(): BelongsTo
    {
        return $this->belongsTo(GradeRate::class);
    }

    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function qualityTests(): HasMany
    {
        return $this->hasMany(QualityTest::class);
    }

    public function adjustments(): MorphMany
    {
        return $this->morphMany(Adjustment::class, 'adjustable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /* ------------------------------------------------------------------ */

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * BR-13 / BR-14 — the instant this consignment's rate is priced against.
     *
     * Stamped by the server at confirmation and never taken from a request, so
     * that grading or re-grading three days later still asks "what was a litre
     * worth on the day this milk was accepted?" without letting the person
     * asking choose the day. `confirmed_at` is what the operator says happened
     * and is deliberately not this.
     *
     * The fallback covers a consignment confirmed before the column existed;
     * the migration backfills the rest, because re-anchoring a historical
     * figure to today is the exact thing BR-13 forbids.
     */
    public function rateAnchor(): ?Carbon
    {
        return $this->rate_anchored_at ?? $this->confirmed_at;
    }

    /** BR-9 — only a confirmed AND graded consignment may join a batch. */
    public function isBatchable(): bool
    {
        return $this->batch_id === null
            && $this->isConfirmed()
            && $this->grade_id !== null
            && in_array($this->status, [
                self::STATUS_CONFIRMED,
                self::STATUS_ADJUSTED,
                self::STATUS_PARTLY_REJECTED,
            ], true);
    }

    /**
     * BR-8 — the net adjustment applied at the center.
     *
     * ARCH-6/NFR-5: folded in integer centilitres through Volume, not through
     * `(int) round(100 * (float) …)`. The round() kept it correct at realistic
     * magnitudes, but this figure is an input to `litres_confirmed` — a stored
     * number BR-8 defines and a payment run will read — and it was the one
     * place inside the money path that went through a float.
     *
     * It was also an N+1: one SUM per call, called once per table row, again
     * inside every unconfirmed row's confirmation modal, and once per row in
     * the API. A list query that has already asked for the sum passes it in
     * `adjustments_sum_litres_delta` (Laravel's withSum alias) and this reads
     * that instead, so a 25-row page costs one query rather than fifty.
     */
    public function adjustmentTotal(): string
    {
        if (array_key_exists('adjustments_sum_litres_delta', $this->attributes)) {
            return Volume::sum([$this->attributes['adjustments_sum_litres_delta']]);
        }

        if ($this->relationLoaded('adjustments')) {
            return Volume::sum($this->adjustments->pluck('litres_delta')->all());
        }

        return Volume::sum($this->adjustments()->pluck('litres_delta')->all());
    }

    /**
     * BR-14 / BR-16 — the payable value of this consignment at the snapshotted
     * rate. Reads rate_per_litre_minor, never grade_rates.
     */
    public function payableValueMinor(): int
    {
        if ($this->rate_per_litre_minor === null || $this->litres_confirmed === null) {
            return 0;
        }

        return Money::valueVolume($this->litres_confirmed, (int) $this->rate_per_litre_minor);
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING);
    }

    /** BR-9 */
    public function scopeBatchable(Builder $query): Builder
    {
        return $query->whereNull('batch_id')
            ->whereNotNull('confirmed_at')
            ->whereNotNull('grade_id')
            ->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_ADJUSTED, self::STATUS_PARTLY_REJECTED]);
    }
}
