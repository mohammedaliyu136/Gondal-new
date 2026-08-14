<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money out of the safe and into somebody's hands, and what came back.
 *
 * The second leg of every payout. See the migration for why it exists at all;
 * the short version is that "the officer took ₦500,000 to Girei — what came
 * back?" had no answer before this row.
 */
class CashFloat extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_RECONCILED = 'reconciled';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'purpose_type', 'purpose_id', 'collection_center_id',
        'amount_drawn_minor', 'drawn_by_user_id', 'issued_by_user_id', 'opened_at',
        'amount_returned_minor', 'received_back_by_user_id', 'returned_at',
        'disbursed_minor', 'variance_minor', 'variance_explanation',
        'status', 'notes', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'returned_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function purpose(): MorphTo
    {
        return $this->morphTo();
    }

    public function collectionCenter(): BelongsTo
    {
        return $this->belongsTo(CollectionCenter::class);
    }

    /** The person carrying the money. */
    public function drawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drawn_by_user_id');
    }

    /** The person who took it out of the safe. Never the same person. */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function receivedBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_back_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * What the officer is still answerable for, before reconciliation.
     *
     * Drawn less whatever the system has already seen them hand over. This is a
     * live figure while the float is open and is superseded by the stamped
     * `variance_minor` once it is signed back in.
     */
    public function unaccountedMinor(int $disbursedMinor): int
    {
        return (int) $this->amount_drawn_minor - $disbursedMinor - (int) ($this->amount_returned_minor ?? 0);
    }

    /* ---------------------------------------------------------------------
     | Scope — SCOPE-2
     * ------------------------------------------------------------------ */

    public function scopeResourceKey(): string
    {
        return 'finance.cash';
    }

    /**
     * `own` is present here, unlike on a payment run.
     *
     * An officer must be able to see their OWN outstanding float. Being unable
     * to check your own position before handing the bag back is how an honest
     * officer ends up unable to explain a shortfall.
     *
     * @return array<string, callable>
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Center->value => fn (Builder $query, array $ids) => $query
                ->whereIn('cash_floats.collection_center_id', $ids),
            // `own` carries the holder's own user ids, so this is "floats I am
            // carrying" — not "floats I issued".
            ScopeType::Own->value => fn (Builder $query, array $ids) => $query
                ->whereIn('cash_floats.drawn_by_user_id', $ids),
        ];
    }

    public function scopeOpenFloats(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('cash_floats.is_test', false);
    }
}
