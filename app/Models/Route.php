<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.3 / §9 — routes carry the transport tariffs, which are reference data the
 * administrator edits under Settings → Locations & Routes.
 */
class Route extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const ENDPOINT_POINT = 'collection_point';

    public const ENDPOINT_CENTER = 'collection_center';

    public const ENDPOINT_FACTORY = 'factory';

    protected $fillable = [
        'name', 'from_type', 'from_id', 'to_type', 'to_id',
        'distance_km', 'tariff_minor', 'vehicle_type', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'tariff_minor' => 'integer',
        ];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function formattedTariff(): string
    {
        return Money::format($this->tariff_minor);
    }
}
