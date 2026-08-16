<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffLoan extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'employee_id',
        'compensation_type_id',
        'reference',
        'principal_amount_minor',
        'monthly_installment_minor',
        'total_repaid_minor',
        'balance_minor',
        'disbursed_on',
        'start_period_year',
        'start_period_month',
        'status',
        'notes',
        'approved_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount_minor' => 'integer',
            'monthly_installment_minor' => 'integer',
            'total_repaid_minor' => 'integer',
            'balance_minor' => 'integer',
            'disbursed_on' => 'date',
            'start_period_year' => 'integer',
            'start_period_month' => 'integer',
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

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffLoanRepayment::class)->latest('repaid_on');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('balance_minor', '>', 0);
    }

    /**
     * Compute repayment percentage progress (0 - 100%).
     */
    public function repaymentPercentage(): float
    {
        if ($this->principal_amount_minor <= 0) {
            return 100.0;
        }

        $pct = ($this->total_repaid_minor / $this->principal_amount_minor) * 100;
        return round(min(100.0, max(0.0, $pct)), 1);
    }

    /**
     * Recalculate balance and update status if fully paid.
     */
    public function recordRepayment(int $amountMinor, ?int $payslipId = null, ?int $payrollRunId = null, ?User $actor = null): StaffLoanRepayment
    {
        $repayment = $this->repayments()->create([
            'payslip_id' => $payslipId,
            'payroll_run_id' => $payrollRunId,
            'amount_minor' => $amountMinor,
            'repaid_on' => now(),
            'status' => 'confirmed',
            'recorded_by_user_id' => $actor?->id,
        ]);

        $this->total_repaid_minor += $amountMinor;
        $this->balance_minor = max(0, $this->principal_amount_minor - $this->total_repaid_minor);

        if ($this->balance_minor <= 0) {
            $this->status = self::STATUS_COMPLETED;
        }

        $this->save();

        return $repayment;
    }
}
