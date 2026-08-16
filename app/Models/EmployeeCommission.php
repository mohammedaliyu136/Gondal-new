<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeCommission extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSED = 'processed_in_payroll';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id',
        'compensation_type_id',
        'reference',
        'amount_minor',
        'period_year',
        'period_month',
        'earned_on',
        'description',
        'status',
        'payslip_id',
        'approved_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'earned_on' => 'date',
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
