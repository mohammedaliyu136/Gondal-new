<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeFixedAllowance extends Model
{
    protected $fillable = [
        'employee_id',
        'compensation_type_id',
        'amount_minor',
        'is_active',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function compensationType(): BelongsTo
    {
        return $this->belongsTo(HrCompensationType::class, 'compensation_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
