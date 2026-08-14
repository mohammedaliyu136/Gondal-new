<?php

namespace Tests\Feature\Rules;

use App\Exceptions\RuleViolationException;
use App\Models\Department;
use App\Models\Requisition;
use App\Models\RequisitionExpenditure;
use App\Models\User;
use App\Services\Finance\RequisitionSpendService;
use App\Services\Reporting\PeriodReports;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §14 Phase 7 — a requisition that is approved and then forgotten.
 *
 * `approved_total_minor` was stamped when the workflow cleared and nothing in
 * the system ever referred to it again, so a requisition approved at ₦400,000
 * and settled at ₦520,000 looked identical to one settled at ₦380,000 — and
 * both looked identical to one nobody ever bought anything against.
 */
class RequisitionSpendRulesTest extends GondalTestCase
{
    /** The basic fact that was missing: money left, against this authority. */
    public function test_a_payment_is_recorded_against_an_approved_requisition(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);

        $this->actingAs($accountant);

        $spend = app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 380_000_00,
            'vendor' => 'Adamawa Feeds Ltd',
            'invoice_reference' => 'INV-4471',
            'method' => 'bank',
        ], $accountant);

        $service = app(RequisitionSpendService::class);

        $this->assertSame(380_000_00, (int) $spend->amount_minor);
        $this->assertSame(380_000_00, $service->spentMinor($requisition));
        $this->assertSame(20_000_00, $service->remainingMinor($requisition));
        $this->assertSame('Adamawa Feeds Ltd', $spend->vendor);
    }

    /**
     * Overspend is refused, not absorbed.
     *
     * An approval is an authority for a figure. Paying more than the figure
     * means the authority did not cover it, and the module already has the right
     * route for that: a revising requisition. Accepting the larger number here
     * would make the approval decorative.
     */
    public function test_paying_more_than_was_approved_is_refused(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);

        $this->actingAs($accountant);

        $this->expectException(RuleViolationException::class);

        app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 520_000_00,
            'method' => 'bank',
        ], $accountant);
    }

    /** Part payments accumulate, and the last one that fits is allowed. */
    public function test_part_payments_accumulate_up_to_the_authorised_figure(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);
        $this->actingAs($accountant);

        $service = app(RequisitionSpendService::class);

        $service->record($requisition, ['amount_minor' => 250_000_00, 'method' => 'bank'], $accountant);
        $service->record($requisition, ['amount_minor' => 150_000_00, 'method' => 'cash'], $accountant);

        $this->assertSame(400_000_00, $service->spentMinor($requisition));
        $this->assertSame(0, $service->remainingMinor($requisition));

        $this->expectException(RuleViolationException::class);
        $service->record($requisition, ['amount_minor' => 100, 'method' => 'cash'], $accountant);
    }

    /**
     * A requisition approved unchanged leaves `approved_total_minor` null.
     *
     * Reading only that column would treat it as authorised for nothing and
     * refuse every payment against it — which is how a correct-looking guard
     * blocks the ordinary case.
     */
    public function test_a_requisition_approved_unchanged_is_authorised_for_its_own_total(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(null, 275_000_00);

        $this->actingAs($accountant);

        $service = app(RequisitionSpendService::class);

        $this->assertNull($requisition->approved_total_minor);
        $this->assertSame(275_000_00, $service->authorisedMinor($requisition));

        $service->record($requisition, ['amount_minor' => 275_000_00, 'method' => 'bank'], $accountant);

        $this->assertSame(0, $service->remainingMinor($requisition));
    }

    /** Nothing may be paid against a requisition nobody has approved. */
    public function test_an_unapproved_requisition_cannot_be_paid_against(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);

        $this->asSystem(fn () => $requisition->forceFill(['status' => Requisition::STATUS_IN_REVIEW])->save());

        $this->actingAs($accountant);

        $this->expectException(RuleViolationException::class);

        app(RequisitionSpendService::class)->record($requisition->fresh(), [
            'amount_minor' => 100_00, 'method' => 'bank',
        ], $accountant);
    }

    /**
     * The requester cannot confirm their own money left.
     *
     * The same separation BR-18 makes on approvals and the cash book makes on
     * floats: asking for money and confirming it was paid are two signatures.
     */
    public function test_the_requester_cannot_record_the_payment(): void
    {
        [$requisition, $accountant, $requester] = $this->approvedRequisition(400_000_00);

        $this->actingAs($requester);

        $this->expectException(\App\Exceptions\AccessDeniedException::class);

        app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 100_00, 'method' => 'bank',
        ], $requester);
    }

    /**
     * The department is snapshotted, not read through the requisition.
     *
     * Moving a requester between departments next year must not silently restate
     * what a department spent last year — the same reasoning BR-15 applies to a
     * cooperative's percentages.
     */
    public function test_the_department_and_cost_centre_are_snapshotted(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);
        $this->actingAs($accountant);

        $spend = app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 100_000_00, 'method' => 'bank',
        ], $accountant);

        $original = $requisition->department;

        $this->assertSame($original->getKey(), $spend->department_id);
        $this->assertSame($original->cost_centre, $spend->cost_centre);

        // The requisition moves department; the historic payment does not.
        $other = $this->asSystem(fn () => Department::query()->create([
            'name' => 'Somewhere Else', 'cost_centre' => 'CC-999', 'status' => 'active',
        ]));

        $this->asSystem(fn () => $requisition->forceFill(['department_id' => $other->getKey()])->save());

        $this->assertSame($original->getKey(), $spend->fresh()->department_id);
        $this->assertSame($original->cost_centre, $spend->fresh()->cost_centre);
    }

    /**
     * A budget is advisory. It reports an overrun; it does not refuse a payment.
     *
     * Blocking spend on a budget nobody has configured would break purchasing on
     * the day it ships, and a budget that silently stops a feed delivery in the
     * rainy season is worse than one that is exceeded and says so.
     */
    public function test_a_budget_reports_an_overrun_rather_than_blocking_it(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);

        $this->asSystem(fn () => $requisition->department
            ->forceFill(['budget_minor' => 100_000_00, 'budget_period' => 'monthly'])->save());

        $this->actingAs($accountant);

        // Four times the budget, and it goes through.
        app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 400_000_00, 'method' => 'bank',
        ], $accountant);

        $rows = collect(app(RequisitionSpendService::class)->byDepartment(
            Wat::today()->subDay(), Wat::today()->addDay(),
        ))->firstWhere('department', $requisition->department->name);

        $this->assertSame(400_000_00, $rows['spent_minor']);
        $this->assertSame(100_000_00, $rows['budget_minor']);
        $this->assertSame(-300_000_00, $rows['remaining_minor']);
        $this->assertTrue($rows['over_budget']);
    }

    /** No budget set reads as "no budget", not as "under by nothing". */
    public function test_a_department_with_no_budget_reports_blank_rather_than_zero(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);
        $this->actingAs($accountant);

        $rows = collect(app(RequisitionSpendService::class)->byDepartment(
            Wat::today()->subDay(), Wat::today()->addDay(),
        ))->firstWhere('department', $requisition->department->name);

        $this->assertNull($rows['budget_minor']);
        $this->assertNull($rows['remaining_minor']);
        $this->assertFalse($rows['over_budget']);
    }

    /** The report reads what `departments.cost_centre` was created for. */
    public function test_the_spend_report_shows_the_cost_centre(): void
    {
        [$requisition, $accountant] = $this->approvedRequisition(400_000_00);
        $this->actingAs($accountant);

        app(RequisitionSpendService::class)->record($requisition, [
            'amount_minor' => 120_000_00, 'method' => 'bank',
        ], $accountant);

        $report = app(PeriodReports::class)->run(
            'spend',
            Wat::today()->subDay()->toDateString(),
            Wat::today()->toDateString(),
        );

        $row = collect($report['rows'])->firstWhere('Department', $requisition->department->name);

        $this->assertSame($requisition->department->cost_centre, $row['Cost centre']);
        $this->assertSame('120000.00', $row['Spent']);
        $this->assertSame(1, $row['Payments']);
    }

    /* ------------------------------------------------------------ fixtures */

    /** @return array{0: Requisition, 1: User, 2: User} */
    private function approvedRequisition(?int $approvedMinor, int $totalMinor = 400_000_00): array
    {
        $department = $this->asSystem(fn () => Department::query()->firstOrCreate(
            ['name' => 'Logistics'],
            ['cost_centre' => 'CC-LOG', 'status' => 'active'],
        ));

        $requester = $this->makeUser('Store Keeper');
        $this->assignRole($requester, 'Inventory Officer');

        $accountant = $this->makeUser('Spend Accountant');
        $this->assignRole($accountant, 'Accounts');

        $requisition = $this->asSystem(fn () => Requisition::query()->create([
            'reference' => 'REQ-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'requester_user_id' => $requester->getKey(),
            'department_id' => $department->getKey(),
            'title' => 'Concentrate feed',
            'category' => 'supplies',
            'urgency' => 'normal',
            'total_minor' => $totalMinor,
            'approved_total_minor' => $approvedMinor,
            'status' => Requisition::STATUS_APPROVED,
            'submitted_at' => Wat::now()->subDay(),
            'decided_at' => Wat::now(),
        ]));

        return [$requisition, $accountant->fresh(), $requester->fresh()];
    }
}
