<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-12 / BR-28 — "Every adjustment requires a reason and an explanation.
 * Adjustments are never silent." The reasons are rows.
 */
class AdjustmentReason extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'help_text', 'applies_to', 'status', 'position', 'created_by_user_id',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeFor(Builder $query, string $appliesTo): Builder
    {
        return $query->active()->whereIn('applies_to', [$appliesTo, 'any']);
    }
}
