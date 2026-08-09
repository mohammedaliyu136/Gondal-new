<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.8 employees.
 *
 * G-6 — gross_monthly_minor is sensitive and gated on hr.payroll.view.
 * NFR-9 — only a masked bank account is ever stored or displayed.
 */
class Employee extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'phone', 'email', 'department_id', 'position',
        'grade_level', 'employment_type', 'duty_station', 'line_manager_id',
        'joined_on', 'confirmed_on', 'gross_monthly_minor', 'bank_name',
        'bank_account_masked', 'next_of_kin_name', 'next_of_kin_phone',
        'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'confirmed_on' => 'date',
            'gross_monthly_minor' => 'integer',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'hr.employees';
    }

    /**
     * SCOPE-1 — `own` on an employee record means "the record that is me", which
     * is reached through users.employee_id rather than a created_by column.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Department->value => fn (Builder $q, array $ids) => $q->whereIn('employees.department_id', $ids),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn(
                'employees.id',
                User::query()->whereIn('id', $ids)->whereNotNull('employee_id')->select('employee_id'),
            ),
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lineManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'line_manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'line_manager_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->latest('starts_on');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class)->latest('id');
    }

    public function scopeOnPayroll(Builder $query): Builder
    {
        return $query->whereIn('status', ['probation', 'confirmed', 'on_leave']);
    }

    /**
     * BR-35 — "test accounts are excluded from all reports, aggregates and
     * payroll."
     *
     * The trait's version filters `employees.is_test`, and there is no such
     * column: an employee is not an account (USER-1), so the flag can only be
     * reached through the user who holds the record. Overridden here rather than
     * spelled out at each call site, because the payroll run and the register's
     * "Monthly gross" tile were computing the same population two different ways
     * and disagreeing by exactly the test employees' salaries.
     */
    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->whereDoesntHave('user', fn (Builder $user) => $user->where('is_test', true));
    }
}
