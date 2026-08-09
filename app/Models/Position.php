<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.8 positions — the vacancy register.
 *
 * §15.5 — recruitment applicants are a known gap, deliberately out of v1. This
 * table holds openings only; it is not an applicant tracker.
 */
class Position extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'title', 'department_id', 'grade_level', 'openings',
        'posted_on', 'closes_on', 'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'posted_on' => 'date',
            'closes_on' => 'date',
            'openings' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}
