<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-10 / BR-11 — the cause a supervisor selects for a factory batch variance.
 * Reference data, so the list is editable without a deployment.
 */
class DiscrepancyCause extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'help_text', 'status', 'position', 'created_by_user_id'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
