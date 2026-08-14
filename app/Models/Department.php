<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.4 departments. Also a SCOPE-1 scope target: `department` scope means "only
 * that department's records".
 */
class Department extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'name', 'head_user_id', 'cost_centre', 'status', 'created_by_user_id',
        // Advisory, never a block — see migration 2026_01_03_002300. Nothing
        // refuses a payment because of these; the overrun is reported instead.
        'budget_minor', 'budget_period',
    ];

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
