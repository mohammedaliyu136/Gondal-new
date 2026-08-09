<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One act of judgement by M&E: this set of farmers, for this reason, by this
 * date — and whether what comes back needs their eyes on it.
 */
class ValidationRound extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'name', 'criteria', 'validation_reason_id', 'opens_on', 'due_on',
        'auto_approve', 'status', 'closed_at', 'opened_by_user_id', 'is_test',
    ];

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'due_on' => 'date',
            'auto_approve' => 'boolean',
            'closed_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function validations(): HasMany
    {
        return $this->hasMany(FarmerValidation::class, 'validation_round_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ValidationReason::class, 'validation_reason_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * The default M&E gets offered when they open a round. A Settings row, not
     * a constant: whether a submitted revalidation stands on its own is a
     * policy the organisation may tighten after a bad month, and tightening it
     * should not need a release.
     */
    public static function defaultAutoApprove(): bool
    {
        return Settings::boolean('community.validation_auto_approve', true);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
