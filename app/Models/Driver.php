<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.3 drivers and riders.
 *
 * USER-1 — a record, not an account. There is deliberately no user_id here.
 */
class Driver extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = ['name', 'phone', 'licence_no', 'type', 'status', 'created_by_user_id'];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
