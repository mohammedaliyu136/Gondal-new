<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Grade;
use App\Models\PaymentRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Reporting\MilkCostAnalysis;
use App\Services\Reporting\PeriodReports;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §15.5 — the money reports.
 *
 * There were five reports and not one about money, on a system whose whole
 * purpose is buying milk. These pin the two things a finance report can get
 * wrong in ways nobody notices: an arithmetic slip, and a period boundary that
 * counts two different sets of milk in the same fraction.
 */
class FinanceReportRulesTest extends GondalTestCase
{
    /**
     * The trap the first version of this fell into.
     *
     * Cost per litre divided by litres the factory RECEIVED in the same window
     * reads as the more meaningful figure and is not a figure at all: milk
     * delivered on the 30th is confirmed on the 31st and batched next month, so
     * numerator and denominator count different milk. On the real database it
     * reported 338 L "lost" — an artefact of the window, quoted in naira.
     */
    public function test_cost_per_litre_is_not_distorted_by_the_period_boundary(): void
    {
        $world = $this->makeMilkWorld();

        // 40 L delivered and priced at ₦250, and NOTHING batched to the factory
        // in this window — the exact shape that produced the phantom loss.
        $this->payableDelivery($world, '40.00', 25_000);

        $this->actingAs($this->accountant());

        [$start, $end] = Wat::daysRange(Wat::today()->subDay()->toDateString(), Wat::today()->toDateString());

        $analysis = app(MilkCostAnalysis::class)->forPeriod($start, $end);

        $this->assertSame('0.00', $analysis['shrinkage_litres'],
            'nothing was lost — nothing had reached the factory yet');
        $this->assertSame(0, $analysis['shrinkage_minor']);
        $this->assertSame('40.00', $analysis['litres']['priced']);

        // ₦10,000 over 40 L is ₦250 a litre, and the denominator is the milk
        // that was actually paid for.
        $this->assertSame(1_000_000, $analysis['farmer_gross_minor']);
        $this->assertSame(25_000, $analysis['farmer_cost_per_litre_minor']);
    }

    /**
     * A real loss IS counted — measured on the consignment, not across dates.
     *
     * Declared less confirmed is BR-10's discrepancy. It compares a thing to
     * itself, so it cannot move when the period boundary does.
     */
    public function test_milk_lost_between_point_and_centre_is_measured_on_the_consignment(): void
    {
        $world = $this->makeMilkWorld();
        $delivery = $this->payableDelivery($world, '40.00', 25_000);

        // The centre confirmed two litres fewer than the point declared.
        $this->asSystem(fn () => $delivery->consignment
            ->forceFill(['litres_dispatched' => '40.00', 'litres_confirmed' => '38.00'])->save());

        $this->actingAs($this->accountant());

        [$start, $end] = Wat::daysRange(Wat::today()->subDay()->toDateString(), Wat::today()->toDateString());

        $analysis = app(MilkCostAnalysis::class)->forPeriod($start, $end);

        $this->assertSame('2.00', $analysis['litres']['lost_point_to_centre']);
        $this->assertSame('2.00', $analysis['shrinkage_litres']);
        // Valued at the period's own average rate: 2 L × ₦250.
        $this->assertSame(50_000, $analysis['shrinkage_minor']);
    }

    /** Milk with no rate yet is excluded and SAID, not silently absorbed. */
    public function test_unpriced_milk_is_reported_rather_than_folded_in(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);
        $this->unpricedDelivery($world, '15.00');

        $this->actingAs($this->accountant());

        [$start, $end] = Wat::daysRange(Wat::today()->subDay()->toDateString(), Wat::today()->toDateString());

        $analysis = app(MilkCostAnalysis::class)->forPeriod($start, $end);

        $this->assertSame('40.00', $analysis['litres']['priced']);
        $this->assertSame('15.00', $analysis['unpriced_litres']);
        $this->assertSame(25_000, $analysis['farmer_cost_per_litre_minor'],
            'the unpriced milk does not drag the average down');
    }

    /** The payments report totals what a run actually settled. */
    public function test_the_farmer_payment_report_shows_gross_net_and_outstanding(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        $report = app(PeriodReports::class)->run(
            'farmer_payments',
            Wat::today()->subDay()->toDateString(),
            Wat::today()->toDateString(),
        );

        $this->assertCount(1, $report['rows']);

        $row = $report['rows'][0];

        $this->assertSame($run->reference, $row['Run']);
        $this->assertSame('10000.00', $row['Gross'], 'a plain decimal a spreadsheet can sum');
        $this->assertSame(number_format($payment->net_minor / 100, 2, '.', ''), $row['Net']);
        $this->assertSame($row['Net'], $row['Outstanding'], 'nothing handed over yet');
        $this->assertSame(0, $row['Reversed']);
    }

    /** Deductions are reported per cooperative — that is who holds them. */
    public function test_the_deductions_report_groups_by_cooperative(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);

        $report = app(PeriodReports::class)->run(
            'deductions',
            Wat::today()->subDay()->toDateString(),
            Wat::today()->toDateString(),
        );

        $row = $report['rows'][0];

        $this->assertSame($world['farmer']->cooperative->name, $row['Cooperative']);
        $this->assertSame('500.00', $row['Savings'], '5% of ₦10,000');
        $this->assertSame('190.00', $row['Levy'], '2% of gross less savings');
    }

    /**
     * §15.5 — a report is refused the same way a screen is.
     *
     * An Extension Agent may open a farmer and must not be able to total the
     * network's money by asking for a report instead.
     */
    public function test_a_field_worker_cannot_run_a_finance_report(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Report Curious');
        $this->assignRole($agent, 'Extension Agent', ScopeType::Communities, $world['farmer']->community_id);
        $this->actingAs($agent->fresh());

        $this->assertNotContains('farmer_payments',
            app(PeriodReports::class)->availableTo($agent->fresh()),
            'not offered');

        $this->expectException(\App\Exceptions\AccessDeniedException::class);

        app(PeriodReports::class)->run('farmer_payments',
            Wat::today()->subDay()->toDateString(), Wat::today()->toDateString());
    }

    /**
     * Every role that may run the cost report can also see a delivery.
     *
     * THE BUG THIS CATCHES, which shipped once: SCOPE-4 sends every aggregate
     * through the model's global scope, and a role holding no `milk.deliveries`
     * scope resolves to an EMPTY SET rather than to an error. The cost-per-litre
     * report therefore answered "₦0.00 per litre" to the Accounts role — the
     * only role with a reason to run it — with no error, no empty state, and
     * nothing in the log.
     *
     * Asserted across the whole role catalogue rather than against Accounts,
     * because the next report gated on one permission while reading another
     * fails exactly the same way. The report also warns at runtime if this is
     * ever untrue again; a warning is the fallback, and this is the fence.
     */
    public function test_every_role_that_can_run_the_cost_report_can_see_deliveries(): void
    {
        $blind = [];

        foreach (Role::query()->whereNull('retired_at')->with('permissions')->get() as $role) {
            $keys = $role->permissions
                ->whereNull('retired_at')
                ->map(fn ($permission) => $permission->resource_key.'.'.$permission->action);

            if (! $keys->contains('finance.farmer_payments.view')) {
                continue;
            }

            if (! $keys->contains('milk.deliveries.view')) {
                $blind[] = $role->name;
            }
        }

        $this->assertSame([], $blind, implode(', ', $blind)
            .' can open the cost report and would be shown zeros, because they cannot see a delivery');
    }

    /** And the runtime warning exists, so a future gap is said rather than shown. */
    public function test_the_cost_report_can_say_that_the_reader_is_scope_blind(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $this->actingAs($this->accountant());

        [$start, $end] = Wat::daysRange(Wat::today()->subDay()->toDateString(), Wat::today()->toDateString());

        $analysis = app(MilkCostAnalysis::class)->forPeriod($start, $end);

        $this->assertArrayHasKey('scope_blind', $analysis);
        $this->assertFalse($analysis['scope_blind'], 'Accounts can see deliveries — that was the fix');
    }

    /* ------------------------------------------------------------ fixtures */

    private ?User $accountantUser = null;

    private function accountant(): User
    {
        if ($this->accountantUser !== null) {
            return $this->accountantUser->fresh();
        }

        $user = $this->makeUser('Report Accountant');
        $this->assignRole($user, 'Accounts');

        return $this->accountantUser = $user->fresh();
    }

    private function payableDelivery(array $world, string $litres, int $rateMinor): Delivery
    {
        return $this->asSystem(function () use ($world, $litres, $rateMinor): Delivery {
            $grade = Grade::query()->where('code', 'GRD-A')->firstOrFail();

            $consignment = Consignment::query()->create([
                'reference' => 'CNS-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'collection_point_id' => $world['pointA']->getKey(),
                'collection_center_id' => $world['centerA']->getKey(),
                'litres_declared' => $litres,
                'litres_dispatched' => $litres,
                'litres_confirmed' => $litres,
                'status' => 'confirmed',
                'dispatched_at' => Wat::now()->subHour(),
                'confirmed_at' => Wat::now(),
                'grade_id' => $grade->getKey(),
                'rate_per_litre_minor' => $rateMinor,
                'rate_anchored_at' => Wat::now(),
            ]);

            return Delivery::query()->create([
                'reference' => 'DEL-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'collection_point_id' => $world['pointA']->getKey(),
                'farmer_id' => $world['farmer']->getKey(),
                'litres_presented' => $litres,
                'litres_rejected' => '0.00',
                'litres_accepted' => $litres,
                'litres_payable' => $litres,
                'delivered_at' => Wat::now()->subHours(2),
                'status' => 'accepted',
                'consignment_id' => $consignment->getKey(),
            ]);
        });
    }

    /** Real milk nobody has valued yet — no consignment, so no rate. */
    private function unpricedDelivery(array $world, string $litres): Delivery
    {
        return $this->asSystem(fn () => Delivery::query()->create([
            'reference' => 'DEL-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'collection_point_id' => $world['pointA']->getKey(),
            'farmer_id' => $world['farmer']->getKey(),
            'litres_presented' => $litres,
            'litres_rejected' => '0.00',
            'litres_accepted' => $litres,
            'litres_payable' => $litres,
            'delivered_at' => Wat::now()->subHours(2),
            'status' => 'accepted',
        ]));
    }
}
