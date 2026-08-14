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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 cooperatives.
 *
 * USER-1 — chairman, secretary and treasurer are names, not accounts.
 * NG-1 — loans and investments are deferred; only the two accounts §9 names
 *   (general, social) exist.
 * §15.3 — the manual cooperative forms are outstanding. Extend this record when
 *   they arrive rather than guessing at fields now.
 */
class Cooperative extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const ACCOUNT_GENERAL = 'general';

    public const ACCOUNT_SOCIAL = 'social';

    /**
     * §14 Phase 7 — members' savings, held by the cooperative.
     *
     * Kept apart from the general account on purpose. General is the
     * cooperative's own trading account and a credit purchase from the shop
     * draws it down; savings is members' money and must not be eaten by the
     * cooperative's debts. Still POOLED, not per-farmer — see
     * docs/PLAN-FARMER-PAYMENTS.md §8 increment 7.
     */
    public const ACCOUNT_SAVINGS = 'savings';

    protected $fillable = [
        'code', 'name', 'registered_on', 'community_id', 'lga_id',
        'chairman_name', 'secretary_name', 'treasurer_name', 'contact_phone',
        'collection_point_id', 'savings_deduction_pct', 'levy_pct',
        'social_contribution_minor', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'registered_on' => 'date',
            'savings_deduction_pct' => 'decimal:2',
            'levy_pct' => 'decimal:2',
            'social_contribution_minor' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'community.cooperatives';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereIn('cooperatives.lga_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereIn('cooperatives.community_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('cooperatives.collection_point_id', $ids),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereHas(
                'collectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.collection_center_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('cooperatives.created_by_user_id', $ids),
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

    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Farmer::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CooperativeAccount::class);
    }

    /** BR-15 — the deduction percentages over time, newest first. */
    public function rates(): HasMany
    {
        return $this->hasMany(CooperativeRate::class)->orderByDesc('effective_from');
    }

    /**
     * BR-15 — the percentages in force on a given date. Never "the current
     * columns": a payable calculated for August must still report August's
     * levy after the committee raises it in September.
     *
     * Resolved against the loaded history when the caller has eager-loaded it,
     * for the same reason Grade::rateOn() does — the detail screen asks per row.
     */
    public function rateOn(string|\DateTimeInterface|null $date = null): ?CooperativeRate
    {
        $on = Wat::of($date ?? Wat::now())?->toDateString() ?? Wat::today()->toDateString();

        if ($this->relationLoaded('rates')) {
            return $this->rates
                ->filter(fn (CooperativeRate $rate) => $rate->effective_from?->toDateString() <= $on)
                ->sortByDesc(fn (CooperativeRate $rate) => [$rate->effective_from?->toDateString(), $rate->getKey()])
                ->first();
        }

        return $this->rates()
            ->whereDate('effective_from', '<=', $on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function currentRate(): ?CooperativeRate
    {
        return $this->rateOn(Wat::today());
    }

    public function generalAccount(): ?CooperativeAccount
    {
        return $this->accounts->firstWhere('kind', self::ACCOUNT_GENERAL)
            ?? $this->accounts()->where('kind', self::ACCOUNT_GENERAL)->first();
    }

    public function socialAccount(): ?CooperativeAccount
    {
        return $this->accounts->firstWhere('kind', self::ACCOUNT_SOCIAL)
            ?? $this->accounts()->where('kind', self::ACCOUNT_SOCIAL)->first();
    }

    public function savingsAccount(): ?CooperativeAccount
    {
        return $this->accounts->firstWhere('kind', self::ACCOUNT_SAVINGS)
            ?? $this->accounts()->where('kind', self::ACCOUNT_SAVINGS)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
