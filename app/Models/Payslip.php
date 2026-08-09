<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.8 payslips.
 *
 * §5.1 — `hr.payroll.view` sees every payslip; `hr.payslip.own.view` (granted by
 * ROLE-3 to everyone) sees only the holder's own. The `own` constraint reaches
 * the employee record behind the signed-in user.
 */
class Payslip extends Model implements Scopeable
{
    use AppliesDataScope;
    use SoftDeletes;

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'reference', 'gross_minor',
        'deductions_minor', 'net_minor', 'breakdown', 'ytd',
    ];

    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'breakdown' => 'array',
            'ytd' => 'array',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'hr.payroll';
    }

    /** A payslip is reachable by payroll staff and by the employee it belongs to. */
    public function scopeResourceKeys(): array
    {
        return ['hr.payroll', 'hr.payslip.own'];
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Department->value => fn (Builder $q, array $ids) => $q->whereHas(
                'employee',
                fn (Builder $inner) => $inner->whereIn('employees.department_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn(
                'payslips.employee_id',
                User::query()->whereIn('id', $ids)->whereNotNull('employee_id')->select('employee_id'),
            ),
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
