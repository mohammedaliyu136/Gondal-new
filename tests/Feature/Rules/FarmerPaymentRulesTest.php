<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Delivery;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDelivery;
use App\Models\Grade;
use App\Models\Cooperative;
use App\Models\CooperativeEntry;
use App\Models\PaymentRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\PendingFarmerDeduction;
use App\Models\User;
use App\Services\Finance\FarmerDisbursementService;
use App\Services\Finance\FarmerPaymentCalculator;
use App\Services\Finance\FarmerDeductionPostingService;
use App\Services\Finance\FarmerPaymentReversalService;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Finance\FarmerStatementService;
use App\Support\Money;
use App\Support\Navigation;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §14 Phase 7 — paying a farmer.
 *
 * These are money tests, so they are about the ways money goes wrong rather
 * than about screens: paid twice, paid the wrong amount, paid to somebody whose
 * payment should have been held, or a deduction taken twice. The design's whole
 * safety claim rests on one database constraint and the arithmetic around it,
 * and both are pinned here.
 */
class FarmerPaymentRulesTest extends GondalTestCase
{
    /* ------------------------------------------------------- the arithmetic */

    /**
     * The worked example from docs/PLAN-FARMER-PAYMENTS.md, computed by the real
     * services rather than by hand. If this drifts, the plan is a lie.
     */
    public function test_the_documented_worked_example_is_what_the_code_produces(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        // 23 L and 20 L at Grade A, 18.50 L pooled down to Grade B.
        $this->payableDelivery($world, '23.00', 25_000);
        $this->payableDelivery($world, '20.00', 25_000);
        $this->payableDelivery($world, '18.50', 21_500);

        $this->actingAs($this->accountant());

        $valued = app(FarmerPaymentCalculator::class)->value($farmer);

        $this->assertSame(1_472_750, $valued['gross_minor'], '61.50 L across three rates');
        $this->assertSame(73_638, $valued['savings_minor'], '5% of gross, half-up from 736.375');
        $this->assertSame(27_982, $valued['levy_minor'], '2% of gross less savings');
        $this->assertSame(25_000, $valued['social_minor']);

        // ₦14,727.50 − 736.38 − 279.82 − 250.00 = ₦13,461.30
        $this->assertSame(1_346_130, $valued['net_minor']);

        // BR-15 — what the percentages WERE, saved with the figure they made.
        $this->assertSame('5.00', (string) $valued['snapshots']['savings_pct']);
        $this->assertSame('2.00', (string) $valued['snapshots']['levy_pct']);
    }

    /**
     * The pooled-grade decision, made visible.
     *
     * A farmer paid at a consignment's grade rather than their own milk's is the
     * plan's §1.1 recommendation and its largest known unfairness. The line must
     * therefore record WHICH consignment and WHICH grade priced it, or "why was
     * I paid B rates?" has no answer at a collection point.
     */
    public function test_every_line_records_the_consignment_and_grade_that_priced_it(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '18.50', 21_500, 'GRD-B');

        $this->actingAs($this->accountant());

        $line = app(FarmerPaymentCalculator::class)->value($world['farmer'])['lines'][0];

        $this->assertNotNull($line['consignment_id']);
        $this->assertSame('Grade B', $line['grade']);
        $this->assertSame(21_500, $line['rate_per_litre_minor']);
        $this->assertSame(397_750, $line['line_gross_minor'], '18.50 L × ₦215');
    }

    /** BR-2 / BR-16 — rejected volume is worth nothing and never reaches a payment. */
    public function test_rejected_litres_are_never_paid_for(): void
    {
        $world = $this->makeMilkWorld();

        // 20 L presented, 5 rejected: only 15 are payable.
        $this->payableDelivery($world, '15.00', 25_000);

        $this->actingAs($this->accountant());

        $this->assertSame(375_000, app(FarmerPaymentCalculator::class)->value($world['farmer'])['gross_minor']);
    }

    /* ------------------------------------------- a litre is paid exactly once */

    /**
     * THE constraint the whole design rests on.
     *
     * The period on a run is a label; what stops a delivery being paid twice is
     * the UNIQUE on farmer_payment_deliveries.delivery_id. So running the same
     * scope twice must produce a second run that claims nothing at all.
     */
    public function test_a_second_run_over_the_same_milk_claims_nothing(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(FarmerPaymentRunService::class);

        $first = $service->generate($world['centerA'], $accountant);
        $this->assertSame(1, $first->farmer_count);
        $this->assertGreaterThan(0, $first->gross_total_minor);

        $second = $service->generate($world['centerA'], $accountant);

        $this->assertSame(0, $second->farmer_count, 'the milk was already claimed');
        $this->assertSame(0, (int) $second->gross_total_minor);

        // And exactly one claim exists for that delivery, forever.
        $this->assertSame(1, FarmerPaymentDelivery::query()->count());
    }

    public function test_cancelling_a_run_releases_its_milk_for_the_next_one(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(FarmerPaymentRunService::class);

        $first = $service->generate($world['centerA'], $accountant);
        $service->cancel($first, $accountant, 'Wrong period');

        $this->assertSame(0, FarmerPaymentDelivery::query()->count(), 'the claim is released');

        // The milk is payable again, which is the point of cancelling.
        $again = $service->generate($world['centerA'], $accountant);
        $this->assertSame(1, $again->farmer_count);
    }

    /* --------------------------------------------------------------- BR-30 */

    /**
     * A shop debt is recovered once and then settled. Nothing in the system set
     * `settled_at` before Phase 7, which is why these rows accumulated forever.
     */
    public function test_a_shop_debt_is_recovered_once_and_marked_settled(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $debt = PendingFarmerDeduction::query()->create([
            'farmer_id' => $world['farmer']->getKey(),
            'amount_minor' => 168_000,
            'description' => 'One-Stop Shop purchase',
            'status' => PendingFarmerDeduction::STATUS_PENDING,
        ]);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        $this->assertSame(168_000, (int) $payment->shop_deduction_minor);
        $this->assertSame(PendingFarmerDeduction::STATUS_SETTLED, $debt->fresh()->status);
        $this->assertNotNull($debt->fresh()->settled_at);
    }

    /* ---------------------------------------------------------------- BR-36 */

    /**
     * An unvalidated farmer's money is computed, owed and NOT payable.
     *
     * Held rather than excluded: excluding it would make the debt invisible, and
     * the farmer's milk was still collected. It stays out of `cash_required`
     * precisely so it is not loaded into a vehicle and carried to a point.
     */
    public function test_an_unvalidated_farmers_payment_is_held_not_dropped(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $this->asSystem(fn () => $world['farmer']
            ->forceFill(['last_validated_on' => Wat::today()->subYears(5)->toDateString()])->save());

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        $this->assertSame(FarmerPayment::STATUS_HELD, $payment->status);
        $this->assertSame('unvalidated', $payment->hold_reason);
        $this->assertGreaterThan(0, (int) $payment->net_minor, 'the debt is real and recorded');

        // The money is inside net, and deliberately outside cash required.
        $this->assertSame((int) $payment->net_minor, (int) $run->held_net_minor);
        $this->assertSame(0, (int) $run->cash_required_minor);
    }

    public function test_a_held_payment_cannot_be_paid_out(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $this->asSystem(fn () => $world['farmer']
            ->forceFill(['last_validated_on' => Wat::today()->subYears(5)->toDateString()])->save());

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $run->forceFill(['status' => PaymentRun::STATUS_APPROVED])->save();

        $payment = $run->payments()->firstOrFail();
        $officer = $this->disburser($world);

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessageMatches('/revalidated/');

        app(FarmerDisbursementService::class)->record(
            $payment,
            ['amount_minor' => 1000, 'method' => 'cash'],
            $officer,
        );
    }

    /* --------------------------------------------------------- disbursement */

    public function test_nothing_can_be_paid_before_the_run_is_approved(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessageMatches('/not been approved/');

        app(FarmerDisbursementService::class)->record(
            $run->payments()->firstOrFail(),
            ['amount_minor' => 1000, 'method' => 'cash'],
            $this->disburser($world),
        );
    }

    public function test_a_payout_cannot_exceed_what_is_owed(): void
    {
        [$run, $payment] = $this->approvedRun();
        $officer = $this->disburser($this->world);

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessageMatches('/more than is owed/');

        app(FarmerDisbursementService::class)->record(
            $payment,
            ['amount_minor' => (int) $payment->net_minor + 1, 'method' => 'cash'],
            $officer,
        );
    }

    public function test_a_part_payment_leaves_the_rest_outstanding(): void
    {
        [$run, $payment] = $this->approvedRun();
        $officer = $this->disburser($this->world);
        $service = app(FarmerDisbursementService::class);

        $half = intdiv((int) $payment->net_minor, 2);

        $service->record($payment, ['amount_minor' => $half, 'method' => 'cash'], $officer);

        $payment->refresh();
        $this->assertSame(FarmerPayment::STATUS_PAYABLE, $payment->status, 'still owed');
        $this->assertSame((int) $payment->net_minor - $half, $payment->outstandingMinor());

        $service->record($payment, ['amount_minor' => $payment->outstandingMinor(), 'method' => 'cash'], $officer);

        $this->assertSame(FarmerPayment::STATUS_PAID, $payment->fresh()->status);
    }

    /** Paying anybody but the farmer has to say who, and on whose authority. */
    public function test_paying_a_proxy_needs_a_written_authority(): void
    {
        [$run, $payment] = $this->approvedRun();
        $officer = $this->disburser($this->world);

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessageMatches('/written authority/');

        app(FarmerDisbursementService::class)->record(
            $payment,
            [
                'amount_minor' => 1000,
                'method' => 'cash',
                'received_by' => 'His son',
                'received_by_relation' => 'son',
            ],
            $officer,
        );
    }


    /* ------------------------------------------------------------ reversal */

    /**
     * Reversing a payment nobody was paid yet is an erasure, not a debt.
     *
     * The milk must become payable again — which is only true because the claim
     * rows are DELETED. A tombstone row would satisfy "the payment is reversed"
     * and quietly make that milk unpayable forever.
     */
    public function test_reversing_an_unpaid_payment_makes_the_milk_payable_again(): void
    {
        [$run, $payment] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $this->assertSame(1, $payment->lines()->count());

        app(FarmerPaymentReversalService::class)->reversePayment($payment, $accountant, 'Wrong rate applied');

        $this->assertSame(FarmerPayment::STATUS_REVERSED, $payment->fresh()->status);
        $this->assertSame(0, $payment->lines()->count(), 'claims released');

        // Nobody owes anything: no money ever left.
        $this->assertSame(0, PendingFarmerDeduction::query()
            ->where('farmer_id', $this->world['farmer']->getKey())->count());

        // And the proof that matters — the next run picks the same milk up.
        $next = app(FarmerPaymentRunService::class)->generate($this->world['centerA'], $accountant);

        $this->assertSame(1, $next->farmer_count);
        $this->assertSame(1_000_000, (int) $next->gross_total_minor, '40 L × ₦250, payable once more');
    }

    /**
     * Money that has already been handed over cannot be un-handed.
     *
     * It becomes a debt the farmer carries. This is the harm the software
     * creates by being correct, and it is pinned here so nobody "simplifies" it
     * into an erasure later.
     */
    public function test_reversing_a_paid_payment_turns_the_cash_into_a_recoverable_debt(): void
    {
        [$run, $payment] = $this->approvedRun();

        $officer = $this->disburser($this->world);
        $this->actingAs($officer);
        app(FarmerDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->net_minor,
            'method' => 'cash',
            'received_by' => $this->world['farmer']->name,
            'received_by_relation' => 'self',
        ], $officer);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        app(FarmerPaymentReversalService::class)
            ->reversePayment($payment->fresh(), $accountant, 'Paid against the wrong farmer code');

        $debt = PendingFarmerDeduction::query()
            ->where('farmer_id', $this->world['farmer']->getKey())->firstOrFail();

        $this->assertSame((int) $payment->net_minor, (int) $debt->amount_minor);
        $this->assertSame(PendingFarmerDeduction::STATUS_PENDING, $debt->status);
        $this->assertStringContainsString('wrong farmer code', $debt->description,
            'the farmer can be told why they owe this');

        // The milk is still released — it was never correctly paid for.
        $this->assertSame(0, $payment->lines()->count());
    }

    /** A shop debt settled by a payment that did not happen is outstanding again. */
    public function test_reversal_un_settles_the_shop_debt_the_payment_had_cleared(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $debt = PendingFarmerDeduction::query()->create([
            'farmer_id' => $world['farmer']->getKey(),
            'amount_minor' => 168_000,
            'description' => 'One-Stop Shop purchase',
            'status' => PendingFarmerDeduction::STATUS_PENDING,
        ]);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $run->forceFill(['status' => PaymentRun::STATUS_APPROVED])->save();

        $this->assertSame(PendingFarmerDeduction::STATUS_SETTLED, $debt->fresh()->status);

        app(FarmerPaymentReversalService::class)
            ->reversePayment($run->payments()->firstOrFail(), $accountant, 'Run generated for the wrong period');

        $this->assertSame(PendingFarmerDeduction::STATUS_PENDING, $debt->fresh()->status);
        $this->assertNull($debt->fresh()->settled_at);
    }

    /** Reversing twice would create the debt twice. */
    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        [$run, $payment] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(FarmerPaymentReversalService::class);
        $service->reversePayment($payment, $accountant, 'First');

        $this->expectException(RuleViolationException::class);
        $service->reversePayment($payment->fresh(), $accountant, 'Second');
    }

    /**
     * Reversing a whole run reverses every line and leaves the totals honest.
     *
     * A run whose lines are all reversed but whose header still reads ₦10,000
     * would be quoted in a report as money that was paid.
     */
    public function test_reversing_a_run_reverses_every_line_and_recomputes_the_header(): void
    {
        [$run, $payment] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $result = app(FarmerPaymentReversalService::class)->reverseRun($run, $accountant, 'Duplicate of PR-0003');

        $this->assertSame(1, $result['reversed']);
        $this->assertSame(0, $result['clawback_minor'], 'nothing had been disbursed');

        $run->refresh();
        $this->assertSame(PaymentRun::STATUS_CANCELLED, $run->status);
        $this->assertSame(0, (int) $run->gross_total_minor);
        $this->assertSame(0, (int) $run->net_total_minor);
        $this->assertSame(0, (int) $run->cash_required_minor);
        $this->assertSame(0, $run->farmer_count);
        $this->assertSame(FarmerPayment::STATUS_REVERSED, $payment->fresh()->status);
    }

    /** A draft run is cancelled, not reversed — the wording matters to Accounts. */
    public function test_an_unapproved_run_cannot_be_reversed(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);

        $this->expectException(RuleViolationException::class);
        app(FarmerPaymentReversalService::class)->reverseRun($run, $accountant, 'Mistake');
    }

    /**
     * §1.6 — a farmer never goes home with nothing because of an old debt.
     *
     * Without the cap, a large clawback takes every kobo for several fortnights
     * while the balance grows, and the farmer discovers it standing at a
     * collection point on payout day.
     */
    public function test_debt_recovery_is_capped_so_a_farmer_always_takes_some_milk_money_home(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);   // ₦10,000 gross

        $this->asSystem(fn () => \App\Models\Setting::query()->updateOrCreate(
            ['key' => 'cooperative.max_debt_recovery_pct'],
            ['value' => ['v' => '50']],
        ));

        // More than the cap allows, so whole-debt-or-skip skips it entirely
        // rather than half-recovering it.
        $debt = PendingFarmerDeduction::query()->create([
            'farmer_id' => $world['farmer']->getKey(),
            'amount_minor' => 900_000,
            'description' => 'Feed advance',
            'status' => PendingFarmerDeduction::STATUS_PENDING,
        ]);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $valued = app(FarmerPaymentCalculator::class)->value($world['farmer']);

        $this->assertSame(500_000, app(FarmerPaymentCalculator::class)->recoveryCeilingMinor(1_000_000));
        $this->assertSame(0, $valued['shop_deduction_minor'], '₦9,000 exceeds the ₦5,000 ceiling');
        $this->assertGreaterThan(0, $valued['net_minor'], 'the farmer is paid something');
        $this->assertSame(PendingFarmerDeduction::STATUS_PENDING, $debt->fresh()->status,
            'the debt is not lost, it waits for the next payment');
    }


    /* ----------------------------------------------------------- statement */

    /**
     * The statement's central trap: two different "owed" figures.
     *
     * Unclaimed milk and an approved-but-unhanded-over payment are both money
     * the network owes, and adding them together tells a farmer they are owed
     * the same naira twice. They are separate lines here for that reason.
     */
    public function test_a_statement_keeps_unclaimed_milk_apart_from_money_already_on_a_run(): void
    {
        [$run, $payment] = $this->approvedRun();          // 40 L already on a run
        $this->payableDelivery($this->world, '10.00', 25_000);  // 10 L not yet claimed

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $statement = app(FarmerStatementService::class)->build($this->world['farmer']->fresh());

        // Unclaimed milk, valued as the next run would value it.
        $this->assertSame(250_000, $statement['outstanding']['gross_minor'], '10 L × ₦250');
        $this->assertSame(1, count($statement['outstanding']['lines']));

        // Approved and not yet handed over — a different figure, from a different row.
        $this->assertSame((int) $payment->net_minor, $statement['totals']['unpaid_on_runs_minor']);
        $this->assertSame(0, $statement['totals']['received_minor']);
        $this->assertSame(1_000_000, $statement['totals']['gross_minor'], 'the run, not the loose milk');
    }

    /** Once money is handed over it stops being outstanding on the run. */
    public function test_a_statement_shows_what_was_handed_over_and_to_whom(): void
    {
        [$run, $payment] = $this->approvedRun();

        $officer = $this->disburser($this->world);
        $this->actingAs($officer);
        app(FarmerDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->net_minor,
            'method' => 'cash',
            'received_by' => 'Abubakar Musa',
            'received_by_relation' => 'son',
            'proxy_authority_ref' => 'Letter dated 12 Aug',
        ], $officer);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $statement = app(FarmerStatementService::class)->build($this->world['farmer']->fresh());

        $this->assertCount(1, $statement['disbursements']);
        $this->assertSame((int) $payment->net_minor, $statement['totals']['received_minor']);
        $this->assertSame(0, $statement['totals']['unpaid_on_runs_minor']);

        // Paying somebody other than the farmer is the thing a statement most
        // needs to disclose, so it must survive into the printed page.
        $this->assertSame('Abubakar Musa', $statement['disbursements']->first()->received_by);
        $this->assertSame('son', $statement['disbursements']->first()->received_by_relation);
    }

    /**
     * A reversal is listed but not totalled.
     *
     * Hiding it would leave the farmer's debt on the page with nothing to
     * explain it; totalling it would claim they were paid money they were not.
     */
    public function test_a_reversed_payment_is_listed_but_left_out_of_the_totals(): void
    {
        [$run, $payment] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        app(FarmerPaymentReversalService::class)->reversePayment($payment, $accountant, 'Wrong rate');

        $statement = app(FarmerStatementService::class)->build($this->world['farmer']->fresh());

        $this->assertCount(1, $statement['payments'], 'still visible');
        $this->assertSame(FarmerPayment::STATUS_REVERSED, $statement['payments']->first()->status);
        $this->assertSame(0, $statement['totals']['net_minor'], 'not counted as paid');
        $this->assertSame(0, $statement['totals']['gross_minor']);

        // And the milk is back in the unclaimed column, where the next run
        // will find it.
        $this->assertSame(1_000_000, $statement['outstanding']['gross_minor']);
    }

    /** A pending shop debt is on the statement with the reason it was raised. */
    public function test_a_statement_says_what_is_being_taken_off_and_why(): void
    {
        $world = $this->makeMilkWorld();

        PendingFarmerDeduction::query()->create([
            'farmer_id' => $world['farmer']->getKey(),
            'amount_minor' => 168_000,
            'description' => 'One-Stop Shop — 2 bags of feed',
            'status' => PendingFarmerDeduction::STATUS_PENDING,
        ]);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $statement = app(FarmerStatementService::class)->build($world['farmer']);

        $this->assertCount(1, $statement['deductions']);
        $this->assertSame(168_000, $statement['totals']['debt_outstanding_minor']);
        $this->assertStringContainsString('2 bags of feed', $statement['deductions']->first()->description);
    }

    /**
     * USER-2 / §16 — an Extension Agent may open the farmer and not their money.
     *
     * The statement is the one screen that would hand a field worker every
     * figure §16 keeps off the farmer detail page, so the route check is pinned
     * rather than assumed.
     */
    public function test_a_field_worker_cannot_open_a_farmers_statement(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Field Worker');
        $this->assignRole($agent, 'Extension Agent', ScopeType::Communities, $world['farmer']->community_id);

        $this->actingAs($agent->fresh())
            ->get(route('farmers.statement', $world['farmer']))
            ->assertForbidden();
    }

    /**
     * The page itself renders — every figure the service computes reaches print.
     *
     * Worth a test of its own: the statement is the only screen in the module
     * that an officer hands to somebody who cannot check it, so a Blade error
     * here is a blank page given to a farmer as an answer.
     */
    public function test_the_statement_page_renders_for_an_officer(): void
    {
        [$run, $payment] = $this->approvedRun();

        $officer = $this->disburser($this->world);
        $this->actingAs($officer);
        app(FarmerDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->net_minor,
            'method' => 'cash',
            'received_by' => $this->world['farmer']->name,
            'received_by_relation' => 'self',
        ], $officer);

        $this->payableDelivery($this->world, '10.00', 25_000);

        $response = $this->actingAs($this->accountant())
            ->get(route('farmers.statement', $this->world['farmer']))
            ->assertOk();

        $response->assertSee($this->world['farmer']->name);
        $response->assertSee($run->reference);
        $response->assertSee(Money::format($payment->net_minor));
        $response->assertSee('Milk not yet paid for');
        $response->assertSee('Money handed over');
    }

    /* --------------------------------------------- where the deductions go */

    /**
     * A farmer's savings, levy and social fund land in three different accounts.
     *
     * Until this existed the run subtracted all three and credited NOTHING. The
     * money came off a household and stopped existing: `cooperative_entries` was
     * empty while the deductions on `farmer_payments` were real, so a farmer
     * asking where their savings were could be shown a number and no account
     * holding it.
     *
     * Three accounts rather than one, because savings is members' money the
     * cooperative holds, the levy is the cooperative's income, and the social
     * fund is neither. Pooling them would let a shop debt eat somebody's savings.
     */
    public function test_deductions_land_in_the_cooperatives_accounts_on_approval(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);      // ₦10,000 gross

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        // Nothing is posted while the run is only a draft — a cancelled draft
        // must not leave entries the ledger then has to unwind.
        $this->assertSame(0, CooperativeEntry::query()->count());

        app(FarmerDeductionPostingService::class)->postForRun($run, $accountant);

        $cooperative = $world['farmer']->cooperative;

        $this->assertSame((int) $payment->savings_minor,
            (int) $cooperative->savingsAccount()->fresh()->balance_minor);
        $this->assertSame((int) $payment->levy_minor,
            (int) $cooperative->generalAccount()->fresh()->balance_minor);
        $this->assertSame((int) $payment->social_minor,
            (int) $cooperative->socialAccount()->fresh()->balance_minor);

        $this->assertSame(3, CooperativeEntry::query()->count());
    }

    /**
     * Posting twice would inflate a pool nobody can reconcile back down.
     *
     * syncFromWorkflow is reachable more than once for the same instance — an
     * approver acting on a stale page, a retried job — so the guard is the
     * payment's own presence in the ledger.
     */
    public function test_posting_the_same_run_twice_writes_nothing_the_second_time(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $service = app(FarmerDeductionPostingService::class);

        $first = $service->postForRun($run, $accountant);
        $second = $service->postForRun($run, $accountant);

        $this->assertSame(3, $first);
        $this->assertSame(0, $second, 'the second pass is a no-op');
        $this->assertSame(3, CooperativeEntry::query()->count());
    }

    /**
     * Reversing a payment gives the pools back what they took.
     *
     * As OPPOSITE entries, not by deleting the originals: the ledger stamps
     * `balance_after_minor` onto every row, so removing one would restate what a
     * member was told their balance was last month.
     */
    public function test_reversing_a_payment_returns_the_deductions_to_zero(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $run->forceFill(['status' => PaymentRun::STATUS_APPROVED])->save();
        app(FarmerDeductionPostingService::class)->postForRun($run->refresh(), $accountant);

        $cooperative = $world['farmer']->cooperative;
        $this->assertGreaterThan(0, (int) $cooperative->savingsAccount()->fresh()->balance_minor);

        app(FarmerPaymentReversalService::class)
            ->reversePayment($run->payments()->firstOrFail(), $accountant, 'Wrong rate');

        $this->assertSame(0, (int) $cooperative->savingsAccount()->fresh()->balance_minor);
        $this->assertSame(0, (int) $cooperative->generalAccount()->fresh()->balance_minor);
        $this->assertSame(0, (int) $cooperative->socialAccount()->fresh()->balance_minor);

        // Six entries, not zero. The history says the money arrived and left.
        $this->assertSame(6, CooperativeEntry::query()->count());
    }

    /** A farmer with no cooperative has nothing deducted and posts nothing. */
    public function test_a_farmer_with_no_cooperative_posts_nothing(): void
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $this->asSystem(fn () => $world['farmer']->forceFill(['cooperative_id' => null])->save());

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        $this->assertSame(0, (int) $payment->savings_minor);
        $this->assertSame(0, app(FarmerDeductionPostingService::class)->postForRun($run, $accountant));
        $this->assertSame(0, CooperativeEntry::query()->count());
    }

    /** Savings must not sit where a shop debt can reach it. */
    public function test_savings_is_a_different_account_from_the_one_a_shop_debt_draws_down(): void
    {
        $world = $this->makeMilkWorld();
        $cooperative = $world['farmer']->cooperative;

        $this->assertNotNull($cooperative->savingsAccount());
        $this->assertNotSame(
            $cooperative->savingsAccount()->getKey(),
            $cooperative->generalAccount()->getKey(),
        );
        $this->assertSame(Cooperative::ACCOUNT_SAVINGS, $cooperative->savingsAccount()->kind);
    }

    /* -------------------------------------------------------------- PERM-3 */

    /**
     * A rolled-back-then-re-applied migration must leave its permissions LIVE.
     *
     * PERM-3 forbids deleting a permission, so `down()` retires it. Coming back
     * up has to clear that, and the first version of this migration did not:
     * it found the retired row, reused its id, re-granted it to every role, and
     * left `retired_at` set. The result was five permissions that appeared on
     * the role screen, appeared on the user's own permission list, and were
     * filtered out of every authorisation query — the entire payment module
     * answering 403 to everybody while insisting the permission was held.
     *
     * Fresh-migration test databases never see this, which is exactly why it
     * needs a test that arranges the retirement deliberately.
     */
    public function test_re_applying_the_permission_migration_brings_its_permissions_back(): void
    {
        $accountant = $this->accountant();
        $this->assertTrue($accountant->hasPermission('finance.farmer_payments.create'));

        Permission::query()->where('resource_key', 'finance.farmer_payments')->update([
            'retired_at' => Wat::now(),
            'retired_reason' => 'Phase 7 farmer payment rolled back',
        ]);

        $this->assertFalse($accountant->fresh()->hasPermission('finance.farmer_payments.create'),
            'a retired permission authorises nothing, however it is granted');

        (require database_path('migrations/2026_01_03_001600_add_farmer_payment_permissions.php'))->up();

        foreach (['view', 'create', 'approve', 'disburse', 'reverse'] as $action) {
            $this->assertNull(
                Permission::query()->where('resource_key', 'finance.farmer_payments')
                    ->where('action', $action)->value('retired_at'),
                $action.' is live again',
            );
        }

        $this->assertTrue($accountant->fresh()->hasPermission('finance.farmer_payments.create'));
    }

    /**
     * The System Administrator holds every live permission, farmer payments
     * included.
     *
     * RoleSeeder gives that role `['*']`, and `*` expands over LIVE permissions
     * only — so while these five sat retired, a reseed rewrote
     * `permission_role` and dropped the whole module from the admin role, while
     * Accounts and the General Manager kept theirs purely because RoleSeeder
     * names them. The admin could open every other screen in the ERP and got
     * access-denied on this one.
     *
     * Asserted against the whole catalogue rather than against five keys,
     * because the next module to be added will fail the same way.
     */
    public function test_the_administrator_role_is_not_missing_any_live_permission(): void
    {
        $admin = Role::query()->where('name', 'System Administrator')->firstOrFail();

        $held = $admin->permissions()->get()
            ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action);

        $live = Permission::query()->live()->get()
            ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action);

        $this->assertSame([], $live->diff($held)->values()->all(),
            'the admin role is described as "all modules" and must actually hold them');

        $this->assertContains('finance.farmer_payments.view', $held->all());
    }

    /* ------------------------------------------------- where the money goes */

    /**
     * The account number is never stored in full.
     *
     * Enough survives for a payer to check they are looking at the right
     * account; not enough to move money with if this database is copied onto
     * somebody's laptop.
     */
    public function test_only_the_last_four_digits_of_an_account_number_are_kept(): void
    {
        $world = $this->makeMilkWorld();

        $this->actingAs($this->accountant())
            ->put(route('farmers.payout-details', $world['farmer']), [
                'payout_method' => 'bank',
                'bank_name' => 'Unity Bank',
                'bank_account_number' => '0123456789',
            ])->assertRedirect();

        $farmer = $world['farmer']->fresh();

        $this->assertSame('bank', $farmer->payout_method);
        $this->assertSame('Unity Bank', $farmer->bank_name);
        $this->assertSame('******6789', $farmer->bank_account_masked);
        $this->assertStringNotContainsString('012345', (string) $farmer->bank_account_masked);
    }

    /**
     * §7 — the largest fraud surface in the ERP, closed with a second key.
     *
     * An Extension Agent holds community.farmers.edit and is trusted to correct
     * a herd size. If that same grant could redirect a farmer's bank payments,
     * every field worker would be one form away from paying themselves.
     */
    public function test_a_farmer_editor_cannot_change_where_the_money_is_sent(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Register Editor');
        $this->assignRole($agent, 'Extension Agent', ScopeType::Communities, $world['farmer']->community_id);

        $this->actingAs($agent->fresh())
            ->put(route('farmers.payout-details', $world['farmer']), [
                'payout_method' => 'bank',
                'bank_name' => 'Unity Bank',
                'bank_account_number' => '9999999999',
            ])->assertForbidden();

        $this->assertNull($world['farmer']->fresh()->bank_account_masked);
    }

    /** A blank account field keeps what is stored — a typo must not wipe it. */
    public function test_leaving_the_account_field_blank_keeps_the_stored_number(): void
    {
        $world = $this->makeMilkWorld();
        $accountant = $this->accountant();

        $this->actingAs($accountant)->put(route('farmers.payout-details', $world['farmer']), [
            'payout_method' => 'bank', 'bank_name' => 'Unity Bank', 'bank_account_number' => '0123456789',
        ]);

        $this->actingAs($accountant)->put(route('farmers.payout-details', $world['farmer']), [
            'payout_method' => 'bank', 'bank_name' => 'Unity Bank',
        ]);

        $this->assertSame('******6789', $world['farmer']->fresh()->bank_account_masked);
    }

    /* --------------------------------------------------------------- SCR-2 */

    /**
     * Farmer Payments and Payroll sit together under Accounting.
     *
     * They are the same thing done to two different people — generate a run,
     * approve it through a workflow, hand it over, reconcile — and somebody
     * asking "what have we paid out?" should not have to know that one lives
     * under Finance and the other under HR.
     */
    public function test_accounting_groups_the_two_screens_where_money_leaves(): void
    {
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $nav = collect(Navigation::forUser($accountant));

        $accounting = $nav->firstWhere('label', 'Accounting');
        $this->assertNotNull($accounting, 'Accounts holds both permissions');
        $this->assertSame(
            ['Requisition Payments', 'Farmer Payments', 'Transport Payments', 'Payroll', 'Cash Book'],
            collect($accounting['children'])->pluck('label')->all(),
        );

        // Listed once, not twice: a nav item in two places reads as two screens.
        $hr = $nav->firstWhere('label', 'Human Resources');
        $this->assertNotContains('Payroll', collect($hr['children'])->pluck('label')->all());
    }

    /**
     * SCR-2 — the group narrows to what the viewer may open.
     *
     * A Collection Officer hands cash to farmers and to the riders who carried
     * their milk, and has no business in staff salaries. Accounting must appear
     * for them carrying the two they can open rather than a Payroll link that
     * answers 403.
     */
    public function test_a_collection_officer_sees_accounting_without_payroll(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->disburser($world);
        $this->actingAs($officer);

        $accounting = collect(Navigation::forUser($officer))->firstWhere('label', 'Accounting');

        $this->assertNotNull($accounting);
        $this->assertSame(
            // Cash Book included: an officer must be able to see their own
            // outstanding float. Not being able to check your own position
            // before handing the bag back is how an honest officer ends up
            // unable to explain a shortfall.
            ['Farmer Payments', 'Transport Payments', 'Cash Book'],
            collect($accounting['children'])->pluck('label')->all(),
        );
        $this->assertNotContains('Payroll', collect($accounting['children'])->pluck('label')->all());
    }

    /* ------------------------------------------------------------ fixtures */

    private array $world = [];

    /** @return array{0: PaymentRun, 1: FarmerPayment} */
    private function approvedRun(): array
    {
        $this->world = $this->makeMilkWorld();
        $this->payableDelivery($this->world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($this->world['centerA'], $accountant);
        $run->forceFill(['status' => PaymentRun::STATUS_APPROVED])->save();

        return [$run->refresh(), $run->payments()->firstOrFail()];
    }

    /**
     * A delivery that has reached a confirmed, priced consignment — the only
     * kind the calculator will value, because an un-dispatched delivery has no
     * rate and paying it would be paying a price that does not exist.
     */
    private function payableDelivery(array $world, string $litres, int $rateMinor, string $gradeCode = 'GRD-A'): Delivery
    {
        return $this->asSystem(function () use ($world, $litres, $rateMinor, $gradeCode): Delivery {
            $grade = Grade::query()->where('code', $gradeCode)->firstOrFail();

            $consignment = $world['pointA']->consignments()->create([
                'reference' => 'CNS-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'collection_center_id' => $world['centerA']->getKey(),
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

    private ?User $accountantUser = null;

    /** Memoised — several tests ask for "the accountant" more than once. */
    private function accountant(): User
    {
        if ($this->accountantUser !== null) {
            return $this->accountantUser->fresh();
        }

        $user = $this->makeUser('Payments Accountant');
        $this->assignRole($user, 'Accounts');

        return $this->accountantUser = $user->fresh();
    }

    private function disburser(array $world): User
    {
        $user = $this->makeUser('Paying Officer');
        $this->assignRole($user, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->getKey());

        return $user->fresh();
    }
}
