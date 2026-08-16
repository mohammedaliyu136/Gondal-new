<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrCompensationType extends Model
{
    use SoftDeletes;

    public const CATEGORY_ALLOWANCE = 'allowance';
    public const CATEGORY_LOAN = 'loan';
    public const CATEGORY_DEDUCTION = 'deduction';
    public const CATEGORY_COMMISSION = 'commission';
    public const CATEGORY_OVERTIME = 'overtime';

    protected $fillable = [
        'category',
        'code',
        'name',
        'description',
        'is_taxable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function staffLoans(): HasMany
    {
        return $this->hasMany(StaffLoan::class, 'compensation_type_id');
    }

    public function fixedAllowances(): HasMany
    {
        return $this->hasMany(EmployeeFixedAllowance::class, 'compensation_type_id');
    }
}
