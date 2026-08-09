<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** §6.8 / §9 — leave types are reference data an administrator edits. */
class LeaveType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'annual_entitlement_days', 'requires_document', 'status', 'position',
    ];

    protected function casts(): array
    {
        return [
            'annual_entitlement_days' => 'integer',
            'requires_document' => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
