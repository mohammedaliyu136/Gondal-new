<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 collection points. A SCOPE-1 target in its own right.
 */
class CollectionPoint extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'community_id', 'lga_id', 'agent_user_id',
        'collection_center_id', 'cutoff_time', 'transport_fee_minor',
        'status', 'opened_on', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'transport_fee_minor' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'milk.points';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('collection_points.id', $ids),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereIn('collection_points.collection_center_id', $ids),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereIn('collection_points.lga_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereIn('collection_points.community_id', $ids),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('collection_points.agent_user_id', $ids),
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function collectionCenter(): BelongsTo
    {
        return $this->belongsTo(CollectionCenter::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'default_collection_point_id');
    }

    /**
     * BR-3 — the cut-off actually applied to a delivery here. A point may
     * override the default from Settings, but never past the latest permitted
     * override, so a bad row cannot quietly widen the window.
     */
    public function effectiveCutoff(): string
    {
        $default = Settings::string('milk.delivery_cutoff_default', '07:00');
        $latest = Settings::string('milk.delivery_cutoff_latest_override', '08:00');

        $own = $this->cutoff_time === null ? null : substr((string) $this->cutoff_time, 0, 5);

        if ($own === null) {
            return $default;
        }

        return $own > $latest ? $latest : $own;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
