<?php

namespace App\Models;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 deliveries — one farmer handing milk to one collection point.
 *
 * DM-1 / BR-6 — litres_accepted is STORED and equals presented − rejected. The
 *   database enforces it; nothing here recomputes it on read.
 * DM-2 — consignment_id stays null until the agent dispatches, and a fully
 *   rejected delivery never receives one.
 * BR-12 — litres_payable is litres_accepted plus the signed total of this
 *   delivery's adjustments, and it is the figure the farmer is paid on. Stored
 *   for the same reason litres_accepted is: a number money is computed from
 *   should not depend on every caller remembering the formula. AdjustmentService
 *   is its only writer.
 */
class Delivery extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_REJECTED = 'rejected';

    /** BR-35 — inherit the recording user's test flag. */
    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'collection_point_id', 'farmer_id', 'recorded_by_user_id',
        'delivered_at', 'litres_presented', 'litres_rejected', 'litres_accepted',
        'litres_adjusted', 'litres_payable',
        'rejection_reason_id', 'containers', 'consignment_id', 'notes', 'status',
        'was_after_cutoff', 'cutoff_applied', 'cutoff_override_by_user_id',
        'cutoff_override_reason', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'litres_presented' => 'decimal:2',
            'litres_rejected' => 'decimal:2',
            'litres_accepted' => 'decimal:2',
            'litres_adjusted' => 'decimal:2',
            'litres_payable' => 'decimal:2',
            'containers' => 'integer',
            'was_after_cutoff' => 'boolean',
            'is_test' => 'boolean',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'milk.deliveries';
    }

    /**
     * SCOPE-1 — a delivery belongs to a point, so `center`, `lga` and
     * `communities` all reach it through that point. This is exactly the case
     * SCOPE-2 warns about: without the join, a center-scoped officer's list
     * would leak every point in the network.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('deliveries.collection_point_id', $ids),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.collection_center_id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.lga_id', $ids),
            ),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.community_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('deliveries.recorded_by_user_id', $ids),
        ];
    }

    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class);
    }

    /**
     * Deliberately unscoped. A delivery the viewer is entitled to see must carry the
     * identity of its farmer, whatever the viewer's own FARMER scope says — that
     * scope governs browsing the farmer register, not knowing who this record
     * belongs to. Left scoped, the relation resolved to null whenever the
     * farmer's default point fell outside the viewer's assignment: names went
     * blank on the day sheet, and the detail page crashed building a link from a
     * null farmer. Opening the farmer's own record is still guarded by the
     * farmers.show route and policy.
     */
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class)
            ->withoutGlobalScope(DataScope::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class);
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function cutoffOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cutoff_override_by_user_id');
    }

    /** BR-12 — adjustments against a delivery are themselves audited records. */
    public function adjustments(): MorphMany
    {
        return $this->morphMany(Adjustment::class, 'adjustable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /* ------------------------------------------------------------------ */

    /** BR-12 — has an adjustment moved this delivery off its accepted volume? */
    public function isAdjusted(): bool
    {
        return Volume::toCentilitres($this->litres_adjusted) !== 0;
    }

    /** DM-2 — only accepted volume can travel. */
    public function isDispatchable(): bool
    {
        return $this->consignment_id === null
            && $this->status !== self::STATUS_REJECTED
            && Volume::toCentilitres($this->litres_accepted) > 0;
    }

    /** §8 — the status derived from the arithmetic, never hand-set. */
    public static function deriveStatus(string $presented, string $rejected): string
    {
        $rejectedCl = Volume::toCentilitres($rejected);
        $presentedCl = Volume::toCentilitres($presented);

        return match (true) {
            $rejectedCl <= 0 => self::STATUS_ACCEPTED,
            $rejectedCl >= $presentedCl => self::STATUS_REJECTED,
            default => self::STATUS_PARTIAL,
        };
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    public function scopeAwaitingDispatch(Builder $query): Builder
    {
        return $query->whereNull('consignment_id')->where('status', '!=', self::STATUS_REJECTED);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('delivered_at', $date);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PARTIAL, self::STATUS_REJECTED]);
    }
}
