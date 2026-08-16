<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeSalaryProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'basic_salary_minor',
        'housing_allowance_minor',
        'transport_allowance_minor',
        'utility_allowance_minor',
        'medical_allowance_minor',
        'other_allowance_minor',
        'pension_rate_pct',
        'is_pension_exempt',
        'tax_rate_pct',
        'is_tax_exempt',
        'nhis_minor',
        'union_dues_minor',
        'other_deduction_minor',
        'gross_monthly_minor',
        'total_deductions_minor',
        'net_monthly_minor',
        'effective_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary_minor' => 'integer',
            'housing_allowance_minor' => 'integer',
            'transport_allowance_minor' => 'integer',
            'utility_allowance_minor' => 'integer',
            'medical_allowance_minor' => 'integer',
            'other_allowance_minor' => 'integer',
            'pension_rate_pct' => 'float',
            'is_pension_exempt' => 'boolean',
            'tax_rate_pct' => 'float',
            'is_tax_exempt' => 'boolean',
            'nhis_minor' => 'integer',
            'union_dues_minor' => 'integer',
            'other_deduction_minor' => 'integer',
            'gross_monthly_minor' => 'integer',
            'total_deductions_minor' => 'integer',
            'net_monthly_minor' => 'integer',
            'effective_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->gross_monthly_minor = $model->computeGrossMinor();
            $model->total_deductions_minor = $model->computeDeductionsMinor();
            $model->net_monthly_minor = $model->computeNetMinor();
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Calculate base regular monthly gross earnings (Basic + Fixed Allowances).
     */
    public function computeGrossMinor(): int
    {
        return (int) (
            $this->basic_salary_minor +
            $this->housing_allowance_minor +
            $this->transport_allowance_minor +
            $this->utility_allowance_minor +
            $this->medical_allowance_minor +
            $this->other_allowance_minor
        );
    }

    /**
     * Compute pension deduction amount.
     */
    public function computePensionMinor(int $grossMinor): int
    {
        if ($this->is_pension_exempt) {
            return 0;
        }

        $rate = $this->pension_rate_pct > 0 ? $this->pension_rate_pct : 8.00;
        return (int) round(($grossMinor * $rate) / 100);
    }

    /**
     * Compute PAYE tax deduction amount.
     */
    public function computeTaxMinor(int $taxableIncomeMinor): int
    {
        if ($this->is_tax_exempt) {
            return 0;
        }

        $rate = $this->tax_rate_pct > 0 ? $this->tax_rate_pct : 7.00;
        return (int) round((max(0, $taxableIncomeMinor) * $rate) / 100);
    }

    /**
     * Calculate total regular monthly deductions.
     */
    public function computeDeductionsMinor(): int
    {
        $gross = $this->computeGrossMinor();
        $pension = $this->computePensionMinor($gross);
        $tax = $this->computeTaxMinor($gross - $pension);

        return (int) (
            $pension +
            $tax +
            $this->nhis_minor +
            $this->union_dues_minor +
            $this->other_deduction_minor
        );
    }

    /**
     * Calculate net monthly take-home pay.
     */
    public function computeNetMinor(): int
    {
        return max(0, $this->computeGrossMinor() - $this->computeDeductionsMinor());
    }

    /**
     * Refresh computed totals and persist.
     */
    public function refreshComputedTotals(): self
    {
        $this->gross_monthly_minor = $this->computeGrossMinor();
        $this->total_deductions_minor = $this->computeDeductionsMinor();
        $this->net_monthly_minor = $this->computeNetMinor();
        $this->save();

        return $this;
    }

    /**
     * Format itemized payroll breakdown for base payslip structure.
     *
     * @return array{earnings: array<int, array{label: string, amount_minor: int}>, deductions: array<int, array{label: string, amount_minor: int}>}
     */
    public function toBreakdownArray(): array
    {
        $earnings = [];
        if ($this->basic_salary_minor > 0) {
            $earnings[] = ['label' => 'Basic Salary', 'amount_minor' => (int) $this->basic_salary_minor];
        }
        if ($this->housing_allowance_minor > 0) {
            $earnings[] = ['label' => 'Housing Allowance', 'amount_minor' => (int) $this->housing_allowance_minor];
        }
        if ($this->transport_allowance_minor > 0) {
            $earnings[] = ['label' => 'Transport Allowance', 'amount_minor' => (int) $this->transport_allowance_minor];
        }
        if ($this->utility_allowance_minor > 0) {
            $earnings[] = ['label' => 'Utility / Meal Allowance', 'amount_minor' => (int) $this->utility_allowance_minor];
        }
        if ($this->medical_allowance_minor > 0) {
            $earnings[] = ['label' => 'Medical Allowance', 'amount_minor' => (int) $this->medical_allowance_minor];
        }
        if ($this->other_allowance_minor > 0) {
            $earnings[] = ['label' => 'Other Fixed Allowance', 'amount_minor' => (int) $this->other_allowance_minor];
        }

        if (empty($earnings)) {
            $earnings[] = ['label' => 'Basic Salary', 'amount_minor' => (int) $this->gross_monthly_minor];
        }

        $gross = $this->computeGrossMinor();
        $pension = $this->computePensionMinor($gross);
        $tax = $this->computeTaxMinor($gross - $pension);

        $deductions = [];
        if ($pension > 0) {
            $deductions[] = [
                'label' => sprintf('Pension Contribution (%.1f%%)', $this->pension_rate_pct ?: 8.0),
                'amount_minor' => $pension,
            ];
        }
        if ($tax > 0) {
            $deductions[] = [
                'label' => sprintf('PAYE Income Tax (%.1f%%)', $this->tax_rate_pct ?: 7.0),
                'amount_minor' => $tax,
            ];
        }
        if ($this->nhis_minor > 0) {
            $deductions[] = ['label' => 'Health Insurance (NHIS)', 'amount_minor' => (int) $this->nhis_minor];
        }
        if ($this->union_dues_minor > 0) {
            $deductions[] = ['label' => 'Union / Cooperative Dues', 'amount_minor' => (int) $this->union_dues_minor];
        }
        if ($this->other_deduction_minor > 0) {
            $deductions[] = ['label' => 'Other Deductions', 'amount_minor' => (int) $this->other_deduction_minor];
        }

        return [
            'earnings' => $earnings,
            'deductions' => $deductions,
        ];
    }
}
