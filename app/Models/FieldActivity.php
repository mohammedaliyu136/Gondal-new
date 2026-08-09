<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 field activities — visits, training, enrolment drives.
 *
 * Phase 5 acceptance — closing a quality follow-up REQUIRES one of these, so
 * `closes_followup_id` is the only route to a closed follow-up.
 * ARCH-2 / NG-3 — `source` and `synced_at` exist so field capture from a future
 * mobile client is a data concern rather than a rewrite.
 */
class FieldActivity extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'extension_agent_id', 'activity_type_id', 'community_id',
        'farmer_id', 'activity_date', 'farmers_reached', 'topic', 'findings',
        'closes_followup_id', 'source', 'synced_at', 'is_test', 'created_by_user_id',
        'latitude', 'longitude', 'location_accuracy_m', 'located_at',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'farmers_reached' => 'integer',
            'synced_at' => 'datetime',
            'is_test' => 'boolean',
            /*
             * Strings, not floats. A coordinate is read and compared, never
             * arithmetic — and casting to float would round decimal(10,7) to
             * something that no longer round-trips to the value stored.
             */
            'latitude' => 'string',
            'longitude' => 'string',
            'location_accuracy_m' => 'integer',
            'located_at' => 'datetime',
        ];
    }

    /** Did the phone know where it was? */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function scopeResourceKey(): string
    {
        return 'community.extension';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereIn('field_activities.community_id', $ids),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'community',
                fn (Builder $inner) => $inner->whereIn('communities.lga_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereHas(
                'extensionAgent',
                fn (Builder $inner) => $inner->whereIn('extension_agents.user_id', $ids),
            ),
        ];
    }

    public function extensionAgent(): BelongsTo
    {
        return $this->belongsTo(ExtensionAgent::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function closesFollowup(): BelongsTo
    {
        return $this->belongsTo(QualityFollowup::class, 'closes_followup_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('activity_date', $year)->whereMonth('activity_date', $month);
    }
}
