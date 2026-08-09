<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\OptimisticLocking;
use App\Models\Concerns\RecordsActor;
use App\Support\Settings;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 batches — a center's confirmed milk travelling to the factory.
 *
 * BR-9  litres_dispatched = Σ litres_confirmed of its consignments
 * BR-10 discrepancy_litres = received − dispatched (negative for a shortfall)
 * BR-11 beyond the configured tolerance, supervisor_notes is REQUIRED before
 *       release, and the tolerance itself is a setting, not a constant
 * NG-5  scope ends at factory intake reconciliation; there is no processing here
 */
class Batch extends Model implements Scopeable
{
    use AppliesDataScope;
    use OptimisticLocking;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_DISCREPANCY = 'discrepancy';

    public const STATUS_RELEASED = 'released';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'collection_center_id', 'dispatched_by_user_id', 'dispatched_at',
        'litres_dispatched', 'containers', 'trip_id',
        'reconciled_by_user_id', 'reconciled_at', 'litres_received', 'containers_received',
        'discrepancy_litres', 'discrepancy_cause_id',
        'litres_rejected_at_factory', 'rejection_reason_id',
        'supervisor_notes', 'released_at', 'released_by_user_id', 'status',
        'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'released_at' => 'datetime',
            'litres_dispatched' => 'decimal:2',
            'litres_received' => 'decimal:2',
            'discrepancy_litres' => 'decimal:2',
            'litres_rejected_at_factory' => 'decimal:2',
            'containers' => 'integer',
            'containers_received' => 'integer',
            'lock_version' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'milk.batch.dispatch';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereIn('batches.collection_center_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereHas(
                'consignments',
                fn (Builder $inner) => $inner->whereIn('consignments.collection_point_id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionCenter',
                fn (Builder $inner) => $inner->whereIn('collection_centers.lga_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q
                ->whereIn('batches.dispatched_by_user_id', $ids)
                ->orWhereIn('batches.reconciled_by_user_id', $ids),
        ];
    }

    public function collectionCenter(): BelongsTo
    {
        return $this->belongsTo(CollectionCenter::class);
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function discrepancyCause(): BelongsTo
    {
        return $this->belongsTo(DiscrepancyCause::class);
    }

    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class);
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

    /** BR-11 — |discrepancy| / dispatched, as an exact decimal string. */
    public function discrepancyPercentage(): ?string
    {
        if ($this->discrepancy_litres === null) {
            return null;
        }

        return Volume::percentageOf($this->discrepancy_litres, $this->litres_dispatched);
    }

    /** BR-11 — the tolerance is a SETTING (§9), never a constant. */
    public function tolerancePercentage(): string
    {
        return Settings::decimalString('milk.batch_discrepancy_tolerance_pct', '1.0');
    }

    /**
     * BR-11 — compared on the exact ratio rather than on the rounded percentage the
     * screen shows, so a variance just over the line is still over it.
     */
    public function exceedsTolerance(): bool
    {
        if ($this->discrepancy_litres === null) {
            return false;
        }

        /*
         * BR-11 — a variance against nothing dispatched is not "within
         * tolerance", it is unexplainable. Volume::exceedsPercentage() answers
         * false when the denominator is zero, which is right for a ratio and
         * wrong for this rule: a legacy 0 L batch receiving litres at the
         * factory would otherwise release with no cause and no note. New
         * batches cannot reach 0 L — BatchService::dispatch refuses them under
         * BR-9 — so this covers the rows that predate that guard.
         */
        if (Volume::toCentilitres($this->litres_dispatched) === 0) {
            return Volume::toCentilitres($this->discrepancy_litres) !== 0;
        }

        return Volume::exceedsPercentage(
            $this->discrepancy_litres,
            $this->litres_dispatched,
            $this->tolerancePercentage(),
        );
    }

    /** §8 — reconciled|discrepancy → released. */
    public function isReleasable(): bool
    {
        return in_array($this->status, [self::STATUS_RECONCILED, self::STATUS_DISCREPANCY], true)
            && $this->released_at === null;
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_TRANSIT);
    }

    public function scopeAwaitingRelease(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RECONCILED, self::STATUS_DISCREPANCY])
            ->whereNull('released_at');
    }
}
