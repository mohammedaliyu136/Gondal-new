<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\Money;
use Tests\GondalTestCase;

/**
 * The employee register was read-only.
 *
 * `hr.employees.create` and `hr.employees.edit` were live permissions granted to
 * HR roles and checked by nothing — no route, no action, no form — so an employee
 * could reach the system exactly one way, by being seeded. HR could not add the
 * person they had just hired, and payroll can only pay who is in this register.
 */
class EmployeeRegisterTest extends GondalTestCase
{
    public function test_hr_can_add_an_employee_from_the_register(): void
    {
        $hr = $this->makeUser('HR Officer');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $department = $this->asSystem(fn () => Department::query()->create([
            'name' => 'Milk Collection', 'status' => 'active',
        ]));

        // The control is on the screen, with a suggested staff number.
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Add employee')
            ->assertSee('modal-employee');

        $this->post(route('employees.store'), [
            'code' => 'EMP-9001',
            'name' => 'Aisha Sambo',
            'phone' => '08030000001',
            'department_id' => $department->id,
            'position' => 'Collection Officer',
            'employment_type' => 'permanent',
            'joined_on' => '2026-07-01',
            'gross_monthly' => '185000',
            'bank_name' => 'First Bank',
            'bank_account' => '3012345678',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $employee = $this->asSystem(fn () => Employee::query()->where('code', 'EMP-9001')->firstOrFail());

        $this->assertSame('Aisha Sambo', $employee->name);
        // ARCH-6 — naira in, kobo stored.
        $this->assertSame(185_000_00, (int) $employee->gross_monthly_minor);
        // Only the last four digits are kept.
        $this->assertStringEndsWith('5678', (string) $employee->bank_account_masked);
        $this->assertStringNotContainsString('3012345678', (string) $employee->bank_account_masked);

        // And the payroll run can now see them.
        $this->assertTrue($this->asSystem(fn () => Employee::query()->onPayroll()
            ->where('code', 'EMP-9001')->exists()));

        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Human Resources',
            'event_type' => 'data_create',
        ]);
    }

    public function test_a_pay_change_is_named_in_the_audit_log(): void
    {
        $hr = $this->makeUser('HR Officer Pay');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $this->post(route('employees.store'), [
            'code' => 'EMP-9002', 'name' => 'Buba Hamman', 'gross_monthly' => '150000',
        ])->assertSessionHasNoErrors();

        $employee = $this->asSystem(fn () => Employee::query()->where('code', 'EMP-9002')->firstOrFail());

        $this->put(route('employees.update', $employee), [
            'code' => 'EMP-9002', 'name' => 'Buba Hamman', 'gross_monthly' => '190000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(190_000_00, (int) $employee->refresh()->gross_monthly_minor);

        $entry = $this->asSystem(fn () => AuditEntry::query()
            ->where('event_type', 'data_edit')->latest('id')->firstOrFail());

        // A change that moves money is findable without opening the record.
        $this->assertStringContainsString('pay changed', $entry->summary);
        $this->assertStringContainsString(Money::format(150_000_00), $entry->summary);
        $this->assertStringContainsString(Money::format(190_000_00), $entry->summary);
    }

    public function test_an_employee_cannot_report_to_themselves(): void
    {
        $hr = $this->makeUser('HR Officer Loop');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $this->post(route('employees.store'), ['code' => 'EMP-9003', 'name' => 'Modibbo Aliyu']);

        $employee = $this->asSystem(fn () => Employee::query()->where('code', 'EMP-9003')->firstOrFail());

        $this->put(route('employees.update', $employee), [
            'code' => 'EMP-9003',
            'name' => 'Modibbo Aliyu',
            'line_manager_id' => (string) $employee->id,
        ])->assertSessionHasErrors();

        $this->assertNull($employee->refresh()->line_manager_id);
    }

    public function test_a_duplicate_staff_number_is_refused(): void
    {
        $hr = $this->makeUser('HR Officer Dup');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $this->post(route('employees.store'), ['code' => 'EMP-9004', 'name' => 'First Person'])
            ->assertSessionHasNoErrors();

        $this->post(route('employees.store'), ['code' => 'EMP-9004', 'name' => 'Second Person'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->asSystem(fn () => Employee::query()->where('code', 'EMP-9004')->count()));
    }

    /** Someone without the permission sees no button and cannot post. */
    public function test_the_register_stays_closed_without_the_permission(): void
    {
        $agent = $this->makeUser('Curious Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Network);
        $this->actingAs($agent->fresh());

        // They cannot even open the register.
        $this->get(route('employees.index'))->assertStatus(403);
        $this->post(route('employees.store'), ['code' => 'EMP-9005', 'name' => 'Should Not Exist'])
            ->assertStatus(403);

        $this->assertSame(0, $this->asSystem(fn () => Employee::query()->where('code', 'EMP-9005')->count()));
    }

    /** A viewer who cannot create gets the screen without the button. */
    public function test_a_viewer_without_create_sees_no_button(): void
    {
        $auditor = $this->makeUser('Read Only Auditor');
        $this->assignRole($auditor, 'Internal Audit');
        $this->actingAs($auditor->fresh());

        $response = $this->get(route('employees.index'));

        if ($auditor->fresh()->hasPermission('hr.employees.create')) {
            $this->markTestSkipped('Internal Audit holds the create grant in this catalogue.');
        }

        $response->assertOk();
        $response->assertDontSee('+ Add employee');
    }

    /** Departments were read-only too — and every employee needs one. */
    public function test_hr_can_add_a_department(): void
    {
        $hr = $this->makeUser('HR Dept Officer');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $this->get(route('departments.index'))->assertOk()->assertSee('Add department');

        $this->post(route('departments.store'), [
            'name' => 'Quality Assurance',
            'cost_centre' => 'QA-001',
            'head_user_id' => $hr->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $department = $this->asSystem(fn () => Department::query()->where('name', 'Quality Assurance')->firstOrFail());

        $this->assertSame('QA-001', $department->cost_centre);
        $this->assertSame($hr->id, $department->head_user_id);
        $this->assertSame('active', $department->status);

        // Duplicate names refused — departments route approvals, so two with one
        // name is a routing ambiguity, not a cosmetic problem.
        $this->post(route('departments.store'), ['name' => 'Quality Assurance'])
            ->assertSessionHasErrors('name');

        // And it is immediately usable on the employee form.
        $this->get(route('employees.index'))->assertOk()->assertSee('Quality Assurance');
    }

    /** Positions likewise. */
    public function test_hr_can_open_a_position(): void
    {
        $hr = $this->makeUser('HR Position Officer');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $this->get(route('positions.index'))->assertOk()->assertSee('Open a position');

        $this->post(route('positions.store'), [
            'title' => 'Collection Agent — Girei',
            'openings' => '3',
            'posted_on' => '2026-08-01',
            'closes_on' => '2026-08-31',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $position = $this->asSystem(fn () => Position::query()
            ->where('title', 'Collection Agent — Girei')->firstOrFail());

        $this->assertSame(3, (int) $position->openings);
        $this->assertSame('open', $position->status);

        // A closing date before the posting date is refused.
        $this->post(route('positions.store'), [
            'title' => 'Bad dates',
            'posted_on' => '2026-08-31',
            'closes_on' => '2026-08-01',
        ])->assertSessionHasErrors('closes_on');
    }

    /** Neither screen offers the control without the grant. */
    public function test_master_data_stays_read_only_without_the_grant(): void
    {
        $auditor = $this->makeUser('Audit Viewer');
        $this->assignRole($auditor, 'Internal Audit');
        $this->actingAs($auditor->fresh());

        if ($auditor->fresh()->hasPermission('hr.employees.create')) {
            $this->markTestSkipped('Internal Audit holds the create grant in this catalogue.');
        }

        $this->get(route('departments.index'))->assertOk()->assertDontSee('+ Add department');
        $this->post(route('departments.store'), ['name' => 'Should Not Exist'])->assertStatus(403);

        $this->assertSame(0, $this->asSystem(fn () => Department::query()->where('name', 'Should Not Exist')->count()));
    }

    /**
     * A picker that renders empty is worse than one that renders wrong: nothing
     * looks broken, the field is simply unusable.
     *
     * This one WAS empty. The register's status vocabulary is
     * probation|confirmed|on_leave|exited, and the query asked for "active" — no
     * such status, so it matched none of the 42 employees and the "Reports to"
     * field offered only its placeholder.
     */
    public function test_the_pickers_on_the_employee_form_are_populated(): void
    {
        $hr = $this->makeUser('HR Picker Officer');
        $this->assignRole($hr, 'HR Manager');
        $this->actingAs($hr->fresh());

        $department = $this->asSystem(fn () => Department::query()->create([
            'name' => 'Logistics', 'status' => 'active',
        ]));

        // Somebody to be a line manager, in each of the on-payroll statuses.
        foreach (['probation', 'confirmed', 'on_leave'] as $index => $status) {
            $this->asSystem(fn () => Employee::query()->create([
                'code' => 'EMP-77'.$index,
                'name' => 'Manager '.$status,
                'department_id' => $department->id,
                'status' => $status,
                'gross_monthly_minor' => 100_000_00,
            ]));
        }

        // And one who has left, who must NOT be offered as a manager.
        $this->asSystem(fn () => Employee::query()->create([
            'code' => 'EMP-7799', 'name' => 'Departed Person',
            'department_id' => $department->id, 'status' => 'exited',
            'gross_monthly_minor' => 0,
        ]));

        $page = $this->get(route('employees.index'));
        $page->assertOk();

        $managerPicker = $this->selectMarkup($page->getContent(), 'emp-manager');

        foreach (['Manager probation', 'Manager confirmed', 'Manager on_leave'] as $name) {
            $this->assertStringContainsString($name, $managerPicker,
                'Every on-payroll employee should be offerable as a line manager.');
        }

        $this->assertStringNotContainsString('Departed Person', $managerPicker,
            'Somebody who has left should not be offered as a line manager.');

        // The department picker is fed the same way and must not be empty either.
        $this->assertStringContainsString('Logistics', $this->selectMarkup($page->getContent(), 'emp-dept'));
    }

    /** Returns just the markup of one select, so a count is unambiguous. */
    private function selectMarkup(string $html, string $id): string
    {
        preg_match('/<select id="'.preg_quote($id, '/').'".*?<\/select>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The '.$id.' picker is not on the page at all.');
        $this->assertGreaterThan(
            1,
            substr_count($matches[0], '<option'),
            'The '.$id.' picker rendered with nothing but its placeholder.',
        );

        return $matches[0];
    }
}
