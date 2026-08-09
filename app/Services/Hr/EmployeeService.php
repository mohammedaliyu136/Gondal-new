<?php

namespace App\Services\Hr;

use App\Exceptions\RuleViolationException;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;

/**
 * §6.8 — the employee register.
 *
 * `hr.employees.create` and `hr.employees.edit` were live permissions granted to
 * HR roles and checked by nothing: the controller had only index() and show(), so
 * an employee could reach the system exactly one way — by being seeded. HR could
 * not add the person they had just hired.
 *
 * Salary is the reason this is a service rather than controller code. A gross
 * figure is one of the sensitive numbers §5 withholds, so the before/after audit
 * has to record a change to it deliberately, and the money has to go through
 * integer minor units (ARCH-6) rather than a float that drifts.
 */
class EmployeeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Employee
    {
        $employee = Employee::query()->create($this->attributes($data));

        $this->audit->created(
            $employee,
            sprintf(
                '%s (%s) added to the employee register%s',
                $employee->name,
                $employee->code,
                $employee->department?->name ? ' — '.$employee->department->name : '',
            ),
            'Human Resources',
            [
                'code' => $employee->code,
                'department' => $employee->department?->name,
                'employment_type' => $employee->employment_type,
                // Recorded, never rendered to anyone without hr.payroll.view.
                'gross_monthly_minor' => (int) $employee->gross_monthly_minor,
            ],
            $actor,
        );

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data, User $actor): Employee
    {
        // An employee cannot be their own line manager, and the register should
        // not be the place a reporting loop is discovered.
        if (($data['line_manager_id'] ?? null) !== null
            && (int) $data['line_manager_id'] === $employee->getKey()) {
            throw RuleViolationException::make(
                'ST-1',
                'An employee cannot report to themselves.',
                ['employee' => $employee->code],
                'line_manager_id',
            );
        }

        $before = $employee->only([
            'name', 'phone', 'email', 'department_id', 'position', 'grade_level',
            'employment_type', 'duty_station', 'line_manager_id', 'status',
            'gross_monthly_minor',
        ]);

        $employee->fill($this->attributes($data, $employee))->save();

        $after = $employee->only(array_keys($before));

        $this->audit->edited(
            $employee,
            $this->describeChange($employee, $before, $after),
            'Human Resources',
            $before,
            $after,
            $actor,
        );

        return $employee;
    }

    /**
     * BR-32's shape, applied to the register: a leaver is marked, never deleted,
     * because payroll history and every record they touched still names them.
     */
    public function setStatus(Employee $employee, string $status, User $actor): Employee
    {
        $before = $employee->status;

        $employee->forceFill(['status' => $status])->save();

        $this->audit->edited(
            $employee,
            sprintf('%s (%s) marked %s', $employee->name, $employee->code, $status),
            'Human Resources',
            ['status' => $before],
            ['status' => $status],
            $actor,
        );

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Employee $existing = null): array
    {
        $attributes = [
            'code' => $data['code'] ?? $existing?->code,
            'name' => $data['name'] ?? $existing?->name,
            'phone' => $data['phone'] ?? $existing?->phone,
            'email' => $data['email'] ?? $existing?->email,
            'department_id' => $data['department_id'] ?? $existing?->department_id,
            'position' => $data['position'] ?? $existing?->position,
            'grade_level' => $data['grade_level'] ?? $existing?->grade_level,
            'employment_type' => $data['employment_type'] ?? $existing?->employment_type,
            'duty_station' => $data['duty_station'] ?? $existing?->duty_station,
            'line_manager_id' => $data['line_manager_id'] ?? $existing?->line_manager_id,
            'joined_on' => $data['joined_on'] ?? $existing?->joined_on?->toDateString(),
            'confirmed_on' => $data['confirmed_on'] ?? $existing?->confirmed_on?->toDateString(),
            'bank_name' => $data['bank_name'] ?? $existing?->bank_name,
            'next_of_kin_name' => $data['next_of_kin_name'] ?? $existing?->next_of_kin_name,
            'next_of_kin_phone' => $data['next_of_kin_phone'] ?? $existing?->next_of_kin_phone,
            /*
             * The register's own vocabulary is probation|confirmed|on_leave|exited,
             * and Employee::onPayroll() pays the first three. A new hire starts on
             * probation, matching the column default — anything outside this set
             * silently drops them from payroll.
             */
            'status' => $data['status'] ?? $existing?->status ?? 'probation',
        ];

        // ARCH-6 — naira in, kobo stored.
        if (array_key_exists('gross_monthly', $data)) {
            $attributes['gross_monthly_minor'] = Money::fromMajor($data['gross_monthly']) ?? 0;
        } elseif ($existing !== null) {
            $attributes['gross_monthly_minor'] = (int) $existing->gross_monthly_minor;
        }

        /*
         * Only the last four digits are ever kept. Nobody in this system needs a
         * full account number to do their job, and a register that holds one is a
         * register worth stealing.
         */
        if (! empty($data['bank_account'])) {
            $digits = preg_replace('/\D/', '', (string) $data['bank_account']) ?? '';
            $attributes['bank_account_masked'] = $digits === ''
                ? null
                : str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
        }

        return array_filter(
            $attributes,
            static fn ($value, $key) => $value !== null || in_array($key, ['line_manager_id', 'department_id'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function describeChange(Employee $employee, array $before, array $after): string
    {
        $changed = array_keys(array_filter(
            $after,
            static fn ($value, $key) => ($before[$key] ?? null) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return sprintf('%s (%s) saved with no changes', $employee->name, $employee->code);
        }

        // A pay change is called out by name — it is the one edit here that moves
        // money, and it should be findable in the log without opening the detail.
        if (in_array('gross_monthly_minor', $changed, true)) {
            return sprintf(
                '%s (%s) pay changed from %s to %s',
                $employee->name,
                $employee->code,
                Money::format((int) $before['gross_monthly_minor']),
                Money::format((int) $after['gross_monthly_minor']),
            );
        }

        return sprintf(
            '%s (%s) updated — %s',
            $employee->name,
            $employee->code,
            implode(', ', array_map(
                static fn (string $field) => str_replace('_', ' ', $field),
                $changed,
            )),
        );
    }
}
