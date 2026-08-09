<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §9 — LGAs are reference data an administrator edits, and a SCOPE-1 target.
 */
class Lga extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code'];

    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }

    public function collectionPoints(): HasMany
    {
        return $this->hasMany(CollectionPoint::class);
    }

    public function collectionCenters(): HasMany
    {
        return $this->hasMany(CollectionCenter::class);
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class);
    }
}
