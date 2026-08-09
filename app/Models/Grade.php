<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §9 — milk grades are reference data. BR-16 needs to know which grade means
 * "rejected"; the administrator marks it with `is_rejection` so that no rule
 * ever matches on the literal code GRD-R.
 */
class Grade extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'criteria', 'status', 'is_rejection', 'is_system',
        'position', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_rejection' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(GradeRate::class)->orderByDesc('effective_from');
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    /**
     * BR-13 — the rate in force on a given date. Never "the latest rate":
     * a delivery confirmed yesterday must still report yesterday's rate.
     *
     * Resolved against the loaded rate history when the caller has eager-loaded
     * it. The grade and re-grade modals call this inside `@foreach ($grades)`
     * nested in `@foreach ($consignments)`, which was one query per consignment
     * per grade — 32 on a 16-row page, 100 at the default 25. The pick is the
     * same either way: latest effective_from on or before the date, id as the
     * tiebreak.
     */
    public function rateOn(string|\DateTimeInterface|null $date = null): ?GradeRate
    {
        $on = Wat::of($date ?? Wat::now())?->toDateString() ?? Wat::today()->toDateString();

        if ($this->relationLoaded('rates')) {
            return $this->rates
                ->filter(fn (GradeRate $rate) => $rate->effective_from?->toDateString() <= $on)
                ->sortByDesc(fn (GradeRate $rate) => [$rate->effective_from?->toDateString(), $rate->getKey()])
                ->first();
        }

        return $this->rates()
            ->whereDate('effective_from', '<=', $on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function currentRate(): ?GradeRate
    {
        return $this->rateOn(Wat::today());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'system']);
    }

    /** BR-4 — grades an officer may actually assign. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
