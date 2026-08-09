<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 extension agents. Unlike farmers and riders, an extension agent IS staff
 * and therefore has an account (USER-1 lists who does not).
 *
 * SCOPE-1 — the `communities` scope type exists for this role: an agent covers a
 * list of communities, not a single one.
 */
class ExtensionAgent extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'code', 'reports_to_user_id', 'visit_target_monthly',
        'enrolment_target_monthly', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visit_target_monthly' => 'integer',
            'enrolment_target_monthly' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'community.extension';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'communities',
                fn (Builder $inner) => $inner->whereIn('communities.id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'communities',
                fn (Builder $inner) => $inner->whereIn('communities.lga_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q
                ->whereIn('extension_agents.user_id', $ids)
                ->orWhereIn('extension_agents.reports_to_user_id', $ids),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_user_id');
    }

    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'agent_community')->withPivot('assigned_at');
    }

    public function fieldActivities(): HasMany
    {
        return $this->hasMany(FieldActivity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
