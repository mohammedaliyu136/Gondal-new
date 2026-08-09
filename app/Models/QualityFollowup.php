<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 quality follow-ups.
 *
 * BR-5 — opened AUTOMATICALLY when a farmer accumulates a reason's
 *   followup_threshold rejections inside followup_window_days. The threshold and
 *   window in force are copied onto the row so the reason can be re-tuned later
 *   without making an existing follow-up look wrong.
 *
 * Phase 5 acceptance — closing one requires a logged field activity.
 */
class QualityFollowup extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'subject_type', 'subject_id', 'rejection_reason_id', 'trigger_count',
        'threshold', 'window_days', 'opened_at', 'closed_by_activity_id',
        'closed_at', 'closed_by_user_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'trigger_count' => 'integer',
            'threshold' => 'integer',
            'window_days' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RejectionReason::class);
    }

    public function closedByActivity(): BelongsTo
    {
        return $this->belongsTo(FieldActivity::class, 'closed_by_activity_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
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
