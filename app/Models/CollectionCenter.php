<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 collection centers. Also a SCOPE-1 target: `center` scope means "that
 * center AND the points feeding it".
 */
class CollectionCenter extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'lga_id', 'officer_user_id', 'logistics_user_id',
        'cold_storage_litres', 'distance_to_factory_km', 'transport_fee_minor',
        'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cold_storage_litres' => 'decimal:2',
            'distance_to_factory_km' => 'decimal:2',
            'transport_fee_minor' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'milk.points';
    }

    /**
     * SCOPE-1 — a user scoped to a POINT can see the center that point feeds
     * (they need it to follow their own consignment), but nothing else about it.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereIn('collection_centers.id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoints',
                fn (Builder $inner) => $inner->whereIn('collection_points.id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereIn('collection_centers.lga_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoints',
                fn (Builder $inner) => $inner->whereIn('collection_points.community_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q
                ->whereIn('collection_centers.officer_user_id', $ids)
                ->orWhereIn('collection_centers.logistics_user_id', $ids),
        ];
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_user_id');
    }

    public function logisticsOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logistics_user_id');
    }

    public function collectionPoints(): HasMany
    {
        return $this->hasMany(CollectionPoint::class);
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * SCOPE-4 — the center's own confirmed volume for a day. Test activity is
     * excluded (BR-35) because this is an aggregate.
     */
    public function confirmedLitresOn(string $date): string
    {
        return Volume::fromCentilitres((int) round(100 * (float) $this->consignments()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->whereDate('confirmed_at', $date)
            ->sum('litres_confirmed')));
    }
}
