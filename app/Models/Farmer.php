<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * §6.2 farmers.
 *
 * USER-1 / USER-2 — a RECORD, not an account. There is deliberately no
 * authentication, no portal and no notification path to a farmer in v1.
 */
class Farmer extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'gender', 'year_of_birth', 'phone',
        'community_id', 'lga_id', 'cooperative_id', 'cooperative_member_no',
        'default_collection_point_id', 'herd_size', 'lactating_count',
        'enrolled_by_user_id', 'enrolled_on', 'last_validated_on', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'last_validated_on' => 'date',
            'year_of_birth' => 'integer',
            'herd_size' => 'integer',
            'lactating_count' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'community.farmers';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereIn('farmers.lga_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereIn('farmers.community_id', $ids),
            ScopeType::Point->value => fn (Builder $q, array $ids) => $q->whereIn('farmers.default_collection_point_id', $ids),
            ScopeType::Center->value => fn (Builder $q, array $ids) => $q->whereHas(
                'defaultCollectionPoint',
                fn (Builder $inner) => $inner->whereIn('collection_points.collection_center_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('farmers.enrolled_by_user_id', $ids),
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

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function defaultCollectionPoint(): BelongsTo
    {
        return $this->belongsTo(CollectionPoint::class, 'default_collection_point_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function fieldActivities(): HasMany
    {
        return $this->hasMany(FieldActivity::class);
    }

    /** BR-5 — follow-ups opened against this farmer. */
    public function qualityFollowups(): MorphMany
    {
        return $this->morphMany(QualityFollowup::class, 'subject');
    }

    /** BR-30 — deductions awaiting the farmer's next payment. */
    public function pendingDeductions(): HasMany
    {
        return $this->hasMany(PendingFarmerDeduction::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(FarmerValidation::class)->latest('assigned_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /* ---------------------------------------------------------------------
     | Revalidation
     * ------------------------------------------------------------------ */

    /**
     * How often a farmer's details must be verified again. A Settings row, so
     * M&E can change the cycle without a release; zero or less turns periodic
     * revalidation off and leaves it entirely to M&E's judgement.
     */
    public static function revalidationIntervalMonths(): int
    {
        return Settings::integer('community.revalidation_interval_months', 12);
    }

    /** The date this farmer's details stop counting as verified. */
    public function validationDueOn(): ?Carbon
    {
        $months = self::revalidationIntervalMonths();

        if ($months <= 0) {
            return null;
        }

        // Never validated: due since enrolment, not "not due". A farmer enrolled
        // three years ago and never checked is the most overdue of all, and a
        // null that reads as "fine" is how they stay invisible.
        $from = $this->last_validated_on ?? $this->enrolled_on;

        return $from === null ? null : $from->copy()->addMonths($months);
    }

    public function isValidationOverdue(): bool
    {
        $due = $this->validationDueOn();

        return $due !== null && $due->isBefore(Wat::today());
    }

    /**
     * BR-36 — an overdue farmer's milk is still collected; their PAYMENT waits.
     *
     * The asymmetry is the rule, not an implementation detail. Refusing a
     * delivery at 05:30 destroys milk that is already in the churn and costs the
     * farmer a day's income for a back-office lapse they cannot fix standing at
     * the point — and the agent has no way to resolve it either. Holding the
     * payment costs nobody anything irrecoverable: the money is owed, it is
     * recorded, and it is released the moment somebody goes and verifies the
     * details it would be paid against.
     *
     * §15.1 — THE PAYMENT MODULE DOES NOT EXIST YET. This method is the flag
     * Phase 7 must honour before it settles anything; until then the hold is
     * shown on screen and in the API and settles nothing, because there is
     * nothing to settle.
     */
    public function paymentIsHeldPendingValidation(): bool
    {
        if (! Settings::boolean('community.withhold_payment_when_unvalidated', true)) {
            return false;
        }

        return $this->isValidationOverdue();
    }

    /** Farmers whose details are past their revalidation date. */
    public function scopeValidationOverdue(Builder $query): Builder
    {
        $months = self::revalidationIntervalMonths();

        if ($months <= 0) {
            // Periodic revalidation is off, so nobody is overdue by the clock.
            return $query->whereRaw('1 = 0');
        }

        $cutoff = Wat::today()->subMonths($months)->toDateString();

        return $query->where(fn (Builder $inner) => $inner
            ->whereDate('farmers.last_validated_on', '<', $cutoff)
            ->orWhere(fn (Builder $never) => $never
                ->whereNull('farmers.last_validated_on')
                ->whereDate('farmers.enrolled_on', '<', $cutoff)));
    }

    public function age(): ?int
    {
        return $this->year_of_birth === null
            ? null
            : (int) Wat::local()->format('Y') - (int) $this->year_of_birth;
    }
}
