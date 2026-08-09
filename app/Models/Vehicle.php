<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** §6.3 vehicles. */
class Vehicle extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = ['registration', 'type', 'capacity_litres', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['capacity_litres' => 'decimal:2'];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
