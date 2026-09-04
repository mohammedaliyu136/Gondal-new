<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.3 trips.
 *
 * SCOPE-1 — a trip carries its OWN endpoints. The route is the tariff template;
 *   see 2026_01_03_000400 for what reaching through it cost.
 * BR-2 — the transport FEE is the route tariff snapshotted at logging time, and
 *   rejected volume cannot enter a flat fee. `litres_carried` is an operator's
 *   observation and is not derived from accepted/confirmed volume. This docblock
 *   used to claim it was; it never has been.
 *
 *   > OPEN — BR-2's transport half. Whether `litres_carried` should be DERIVED
 *   > from the consignments and batches attached to the trip (making the field
 *   > read-only and the rule enforceable the moment a per-litre tariff appears)
 *   > or stay a recorded observation is a business decision nobody has made. Do
 *   > not guess it: a wrong answer either erases a real observation or invents a
 *   > figure. See docs/OPEN-DECISIONS.md and finding "BR-2's transport half".
 *
 * §15.1 — payment_run_id has no foreign key: the payment module's home is an
 *   open decision and Phase 7 is blocked. The fee is captured correctly now.
 */
class Trip extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const PAYMENT_QUEUED = 'queued';

    public const PAYMENT_APPROVED = 'approved';

    public const PAYMENT_PAID = 'paid';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'route_id', 'collection_point_id', 'collection_center_id',
        'vehicle_id', 'driver_id', 'logged_by_user_id',
        'departed_at', 'arrived_at', 'litres_carried', 'fee_minor',
        'plus_amount_minor', 'plus_reason', 'minus_amount_minor', 'minus_reason',
        'route_tariff_minor_snapshot', 'payment_status', 'payment_run_id',
        'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'litres_carried' => 'decimal:2',
            'fee_minor' => 'integer',
            'plus_amount_minor' => 'integer',
            'minus_amount_minor' => 'integer',
            'route_tariff_minor_snapshot' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function formattedFee(): string
    {
        return \App\Support\Money::format($this->fee_minor);
    }

    public function formattedBaseTariff(): string
    {
        return \App\Support\Money::format($this->route_tariff_minor_snapshot ?? $this->fee_minor);
    }

    public function formattedPlusAmount(): ?string
    {
        return $this->plus_amount_minor > 0 ? \App\Support\Money::format($this->plus_amount_minor) : null;
    }

    public function formattedMinusAmount(): ?string
    {
        return $this->minus_amount_minor > 0 ? \App\Support\Money::format($this->minus_amount_minor) : null;
    }


    public function scopeResourceKey(): string
    {
        return 'logistics.trips';
    }

    /**
     * SCOPE-1 — the trip's own endpoints, not the route's.
     *
     * These predicates used to reach through `routes`, which cannot work: a
     * route's endpoint ids are nullable and the generic point→center tariff rows
     * carry NULL, so no branch could match and the officer who runs the leg was
     * the one person who could not see it. The route remains the tariff
     * template; the geography is the trip's.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereIn('trips.collection_center_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('trips.collection_point_id', $ids),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q
                ->whereIn(
                    'trips.collection_center_id',
                    CollectionCenter::withoutDataScope()->whereIn('lga_id', $ids)->select('id'),
                )
                ->orWhereIn(
                    'trips.collection_point_id',
                    CollectionPoint::withoutDataScope()->whereIn('lga_id', $ids)->select('id'),
                ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('trips.logged_by_user_id', $ids),
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** Nullable: a center→factory leg starts at no point. */
    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class);
    }

    public function collectionCenter(): BelongsTo
    {
        return $this->belongsTo(CollectionCenter::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeQueuedForPayment(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAYMENT_QUEUED);
    }
}
