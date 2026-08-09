<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One farmer, asked of one field worker, by a date.
 *
 * M&E owns the assignment; the field worker owns the outcome. Nothing here lets
 * the person who scheduled the check also record what it found — that
 * separation is the whole reason an evaluation is worth anything.
 */
class FarmerValidation extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    /** Sent back to the field worker: something about the submission did not stand up. */
    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    /** Details were checked and were already right. */
    public const OUTCOME_CONFIRMED = 'confirmed';

    /** Details were checked and something was wrong; the record now reflects reality. */
    public const OUTCOME_CORRECTED = 'corrected';

    /** The farmer could not be located. */
    public const OUTCOME_NOT_FOUND = 'not_found';

    /** The farmer declined to be checked. */
    public const OUTCOME_REFUSED = 'refused';

    /**
     * The two outcomes that mean somebody actually verified this farmer.
     *
     * `not_found` and `refused` close the ASSIGNMENT — the field worker did
     * their job and reported honestly — but they do not validate the FARMER.
     * Conflating the two would let a farmer nobody can find quietly count as
     * verified, which is the exact failure the whole feature exists to prevent.
     */
    public const VERIFYING_OUTCOMES = [self::OUTCOME_CONFIRMED, self::OUTCOME_CORRECTED];

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'farmer_id', 'validation_round_id', 'validation_reason_id',
        'assigned_to_user_id', 'assigned_by_user_id', 'assigned_at', 'due_on',
        'status', 'outcome', 'before', 'after', 'findings',
        'submitted_by_user_id', 'submitted_at', 'reviewed_by_user_id', 'reviewed_at',
        'review_note', 'source', 'is_test', 'created_by_user_id',
        'latitude', 'longitude', 'location_accuracy_m', 'located_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'due_on' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'is_test' => 'boolean',
            // See FieldActivity::casts() on why these are strings.
            'latitude' => 'string',
            'longitude' => 'string',
            'location_accuracy_m' => 'integer',
            'located_at' => 'datetime',
        ];
    }

    /** Did the phone know where it was when this was submitted? */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /* ---------------------------------------------------------------------
     | Scope — SCOPE-2
     * ------------------------------------------------------------------ */

    public function scopeResourceKey(): string
    {
        return 'community.validation';
    }

    /**
     * An assignment is scoped by its FARMER, plus one addition: the person it
     * was given to can always see it.
     *
     * Both halves are needed. Scoping by the farmer is what stops an agent
     * browsing another community's queue. But a Collection Agent is scoped to a
     * point, and a farmer whose default point moved last week would drop off the
     * list of the very person who was asked to go and see them — so `own` reads
     * the assignment, not the enrolment.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q
                ->whereIn('farmer_validations.assigned_to_user_id', $ids)
                ->orWhereIn('farmer_validations.assigned_by_user_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer',
                fn (Builder $inner) => $inner->whereIn('farmers.community_id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer',
                fn (Builder $inner) => $inner->whereIn('farmers.lga_id', $ids),
            ),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer',
                fn (Builder $inner) => $inner->whereIn('farmers.default_collection_point_id', $ids),
            ),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer.defaultCollectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.collection_center_id', $ids),
            ),
        ];
    }

    /* ---------------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------------ */

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ValidationRound::class, 'validation_round_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ValidationReason::class, 'validation_reason_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /* ---------------------------------------------------------------------
     | State
     * ------------------------------------------------------------------ */

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RETURNED], true);
    }

    public function isAwaitingReview(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_on !== null
            && $this->due_on->isBefore(Wat::today());
    }

    /** Did this validation actually verify the farmer? */
    public function verified(): bool
    {
        return $this->status === self::STATUS_ACCEPTED
            && in_array((string) $this->outcome, self::VERIFYING_OUTCOMES, true);
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     * ------------------------------------------------------------------ */

    /** What a field worker still owes. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETURNED]);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_on')->whereDate('due_on', '<', Wat::today());
    }

    /**
     * The work in front of one person: what they were named for, plus the
     * unclaimed pool for farmers already inside their data scope.
     */
    public function scopeForFieldWorker(Builder $query, User $user): Builder
    {
        return $query->open()->where(fn (Builder $inner) => $inner
            ->where('farmer_validations.assigned_to_user_id', $user->getKey())
            ->orWhereNull('farmer_validations.assigned_to_user_id'));
    }
}
