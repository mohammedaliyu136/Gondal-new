<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.9 — extension activity vocabulary as reference data.
 *
 * Phase 5 acceptance ("closing a follow-up requires a logged field activity")
 * needs to know which activity types are capable of closing one. That is the
 * administrator's call, hence `closes_quality_followup`.
 */
class ActivityType extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'help_text', 'closes_quality_followup', 'status', 'position',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['closes_quality_followup' => 'boolean'];
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
