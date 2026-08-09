<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-1 — "Milk may be rejected only for a reason present in rejection_reasons
 * and enabled for that stage. Free-text reasons are never accepted."
 *
 * BR-3 — the reason that means "arrived after the cut-off" is identified by the
 * `is_cutoff_breach` flag, not by matching the code REJ-LATE (§18.7).
 */
class RejectionReason extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const STAGE_POINT = 'point';

    public const STAGE_CENTER = 'center';

    public const STAGE_FACTORY = 'factory';

    protected $fillable = [
        'code', 'name', 'help_text',
        'available_at_point', 'available_at_center', 'available_at_factory',
        'followup_threshold', 'followup_window_days',
        'excluded_from_payment', 'is_cutoff_breach', 'status', 'position',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'available_at_point' => 'boolean',
            'available_at_center' => 'boolean',
            'available_at_factory' => 'boolean',
            'excluded_from_payment' => 'boolean',
            'is_cutoff_breach' => 'boolean',
            'followup_threshold' => 'integer',
            'followup_window_days' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(QualityFollowup::class);
    }

    public function isAvailableAt(string $stage): bool
    {
        return match ($stage) {
            self::STAGE_POINT => (bool) $this->available_at_point,
            self::STAGE_CENTER => (bool) $this->available_at_center,
            self::STAGE_FACTORY => (bool) $this->available_at_factory,
            default => false,
        };
    }

    /** BR-5 — does this reason open a quality follow-up at all? */
    public function opensFollowups(): bool
    {
        return $this->followup_threshold !== null
            && $this->followup_threshold > 0
            && $this->followup_window_days !== null
            && $this->followup_window_days > 0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** BR-1 — the picker for one stage of the chain. */
    public function scopeAvailableAt(Builder $query, string $stage): Builder
    {
        return $query->active()->where(match ($stage) {
            self::STAGE_POINT => 'available_at_point',
            self::STAGE_CENTER => 'available_at_center',
            self::STAGE_FACTORY => 'available_at_factory',
            default => 'id',
        }, true);
    }

    /** BR-3 */
    public static function cutoffBreach(): ?self
    {
        return static::query()->active()->where('is_cutoff_breach', true)->first();
    }
}
