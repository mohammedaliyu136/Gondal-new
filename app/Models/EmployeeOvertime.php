<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeOvertime extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSED = 'processed_in_payroll';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id',
        'reference',
        'hours',
        'hourly_rate_minor',
        'total_amount_minor',
        'period_year',
        'period_month',
        'worked_on',
        'description',
        'status',
        'payslip_id',
        'approved_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'float',
            'hourly_rate_minor' => 'integer',
            'total_amount_minor' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'worked_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    public function scopeReadyForPayroll(Builder $query, int $year, int $month): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING])
            ->whereNull('payslip_id')
            ->where('period_year', $year)
            ->where('period_month', $month);
    }
}
