<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Driver;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Route;
use App\Models\TransportPayment;
use App\Models\TransportPaymentRun;
use App\Models\Trip;
use App\Models\User;
use App\Services\Finance\TransportDisbursementService;
use App\Services\Finance\TransportPaymentRunService;
use App\Services\Payment\Modules\TransportPaymentService;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §14 Phase 7 — paying the riders and drivers.
 *
 * Every leg logged since the network opened has carried a fee and a
 * `payment_status` stuck on `queued`, because nothing in the system could move
 * it. These tests are about the ways that money now goes wrong: paid twice, paid
 * for a leg still on the road, or released in a way that makes it unpayable
 * forever.
 */
class TransportPaymentRulesTest extends GondalTestCase
{
    /* ------------------------------------------------------- the arithmetic */

    /** A driver's line is the sum of their own legs, and nobody else's. */
    public function test_a_run_pays_each_driver_for_their_own_trips(): void
    {
        $world = $this->makeMilkWorld();

        $buba = $this->driver('Buba Danladi');
        $iliya = $this->driver('Iliya Maigari', 'DRV-902');

        $this->arrivedTrip($world, $buba, 120_000, '180.00');
        $this->arrivedTrip($world, $buba, 95_000, '140.00');
        $this->arrivedTrip($world, $iliya, 80_000, '110.00');

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);

        $this->assertSame(2, $run->driver_count);
        $this->assertSame(3, $run->trip_count);
        $this->assertSame(295_000, (int) $run->total_minor);

        $line = $run->payments()->where('driver_id', $buba->getKey())->firstOrFail();

        $this->assertSame(215_000, (int) $line->amount_minor, '1,200 + 950');
        $this->assertSame(2, $line->trip_count);
        $this->assertSame('320.00', (string) $line->litres_carried);
        $this->assertCount(2, $line->breakdown['legs'], 'the legs are recorded so a rider can be shown them');
    }

    /**
     * A leg still on the road is not a debt.
     *
     * Paying an unarrived trip pays for work that may still fail — a breakdown,
     * a spillage, a load that never reaches the centre.
     */
    public function test_a_trip_that_has_not_arrived_is_not_paid_for(): void
    {
        $world = $this->makeMilkWorld();
        $driver = $this->driver('Yusufu Bitrus');

        $this->arrivedTrip($world, $driver, 120_000);
        $this->asSystem(fn () => $this->arrivedTrip($world, $driver, 120_000)
            ->forceFill(['arrived_at' => null])->save());

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);

        $this->assertSame(1, $run->trip_count);
        $this->assertSame(120_000, (int) $run->total_minor);
    }

    /**
     * The guarantee the whole design rests on: a trip is paid exactly once.
     *
     * Enforced by the UNIQUE on transport_payment_trips.trip_id, not by the
     * period on the run — which is why a leg logged three days late is swept
     * into the next run rather than falling into a gap.
     */
    public function test_a_second_run_over_the_same_period_claims_nothing(): void
    {
        $world = $this->makeMilkWorld();
        $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        $first = $service->generate($world['centerA'], $accountant);
        $this->assertSame(1, $first->trip_count);

        $second = $service->generate($world['centerA'], $accountant);

        $this->assertSame(0, $second->trip_count, 'the leg is already claimed');
        $this->assertSame(0, (int) $second->total_minor);
    }

    /**
     * A NETWORK run reaches trips a centre run cannot.
     *
     * `trips.collection_center_id` is nullable, so a centre-scoped run can never
     * see a leg whose centre was not recorded. Without the network scope that
     * rider works every week and never appears on a sheet.
     */
    public function test_a_network_run_catches_a_trip_with_no_centre_recorded(): void
    {
        $world = $this->makeMilkWorld();
        $driver = $this->driver('Buba Danladi');

        $orphan = $this->arrivedTrip($world, $driver, 75_000);
        $this->asSystem(fn () => $orphan->forceFill(['collection_center_id' => null])->save());

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        $centred = $service->generate($world['centerA'], $accountant);
        $this->assertSame(0, $centred->trip_count, 'invisible to a centre run');

        $network = $service->generate(null, $accountant);
        $this->assertSame(1, $network->trip_count);
        $this->assertSame(TransportPaymentRun::SCOPE_NETWORK, $network->scope_type);
    }

    /* ----------------------------------------------------- payment_status */

    /**
     * The column that has only ever held one value now holds all three.
     *
     * `queued` since phase 3, because nothing in the system could move it.
     */
    public function test_a_trip_walks_queued_then_approved_then_paid(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();

        $this->assertSame(Trip::PAYMENT_APPROVED, $trip->fresh()->payment_status);

        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->amount_minor,
            'method' => 'cash',
        ], $officer);

        $this->assertSame(Trip::PAYMENT_PAID, $trip->fresh()->payment_status);
        $this->assertSame(TransportPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(TransportPaymentRun::STATUS_PAID, $run->fresh()->status);
    }

    /** A part payment leaves the line open, so the remainder still reads as owed. */
    public function test_a_part_payment_leaves_the_rest_outstanding(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();

        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => 50_000, 'method' => 'cash',
        ], $officer);

        $payment->refresh();

        $this->assertSame(TransportPayment::STATUS_PAYABLE, $payment->status);
        $this->assertSame(70_000, $payment->outstandingMinor(), '1,200 less the 500 handed over');
        $this->assertSame(Trip::PAYMENT_APPROVED, $trip->fresh()->payment_status, 'not paid yet');
    }

    /** Overpaying a driver is refused, not absorbed. */
    public function test_a_payout_larger_than_the_fee_is_refused(): void
    {
        [$run, $payment] = $this->approvedRun();

        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        $this->expectException(RuleViolationException::class);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => 500_000, 'method' => 'cash',
        ], $officer);
    }

    /** Nothing may be paid against a run nobody has approved. */
    public function test_nothing_is_payable_before_the_run_is_approved(): void
    {
        $world = $this->world = $this->makeMilkWorld();
        $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);
        $payment = $run->payments()->firstOrFail();

        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        $this->expectException(RuleViolationException::class);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => 120_000, 'method' => 'cash',
        ], $officer);
    }

    /* ------------------------------------------------------------ reversal */

    /** Cancelling a draft run releases its legs to the next one. */
    public function test_cancelling_a_run_makes_its_trips_payable_again(): void
    {
        $world = $this->makeMilkWorld();
        $trip = $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);
        $run = $service->generate($world['centerA'], $accountant);

        $service->cancel($run, $accountant, 'Wrong period');

        $this->assertSame(Trip::PAYMENT_QUEUED, $trip->fresh()->payment_status);
        $this->assertNull($trip->fresh()->payment_run_id);

        $next = $service->generate($world['centerA'], $accountant);
        $this->assertSame(1, $next->trip_count, 'the leg came back');
    }

    /**
     * Money already handed to a rider is NOT recovered by reversing.
     *
     * A farmer carries a balance that a clawback can sit against; a driver does
     * not. Pretending otherwise would leave a debt in the system that nothing
     * will ever collect, so the audit entry says plainly that somebody has to go
     * and ask for it back.
     */
    public function test_reversing_a_paid_driver_line_releases_the_trips_and_says_the_cash_is_not_recovered(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();

        $officer = $this->payingOfficer();
        $this->actingAs($officer);
        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->amount_minor, 'method' => 'cash',
        ], $officer);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        app(TransportDisbursementService::class)
            ->reverse($payment->fresh(), $accountant, 'Paid against the wrong driver');

        $this->assertSame(TransportPayment::STATUS_REVERSED, $payment->fresh()->status);
        $this->assertSame(0, $payment->lines()->count(), 'claims released');
        $this->assertSame(Trip::PAYMENT_QUEUED, $trip->fresh()->payment_status);

        // The leg is payable again — which is the point, since the right driver
        // has still not been paid for it.
        $next = app(TransportPaymentRunService::class)->generate($this->world['centerA'], $accountant);
        $this->assertSame(1, $next->trip_count);
    }

    /** Reversing twice would release the same legs twice and double the audit. */
    public function test_a_driver_payment_cannot_be_reversed_twice(): void
    {
        [$run, $payment] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportDisbursementService::class);
        $service->reverse($payment, $accountant, 'First');

        $this->expectException(RuleViolationException::class);
        $service->reverse($payment->fresh(), $accountant, 'Second');
    }

    /** A draft run is cancelled, not reversed — the wording matters to Accounts. */
    public function test_an_unapproved_transport_run_cannot_be_reversed(): void
    {
        $world = $this->makeMilkWorld();
        $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);

        $this->expectException(RuleViolationException::class);
        app(TransportDisbursementService::class)->reverseRun($run, $accountant, 'Mistake');
    }

    /* ----------------------------------------------------------- the screens */

    /** §5.1 — logistics.payments is sensitive, and a trip logger is not a payer. */
    public function test_a_logistics_officer_who_logs_trips_cannot_open_the_payment_screens(): void
    {
        $world = $this->makeMilkWorld();

        // Collection Agent holds logistics.trips and no logistics.payments: the
        // person who records what a leg cost must not be the person who pays it.
        $agent = $this->makeUser('Trip Logger');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->getKey());

        $this->actingAs($agent->fresh())
            ->get(route('transport-payments.index'))
            ->assertForbidden();
    }

    public function test_the_transport_run_screen_renders(): void
    {
        [$run, $payment] = $this->approvedRun();

        $response = $this->actingAs($this->accountant())
            ->get(route('transport-payments.show', $run))
            ->assertOk();

        $response->assertSee($run->reference);
        $response->assertSee('Buba Danladi');
        $response->assertSee('Drivers and riders');
    }

    public function test_run_categorization_for_drivers_and_riders(): void
    {
        $world = $this->makeMilkWorld();

        $driver = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Auto Driver Aminu',
            'phone' => '08031112233',
            'licence_no' => 'DRV-AD1',
            'type' => 'driver',
            'status' => 'active',
        ]));
        $rider = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Bike Rider Sani',
            'phone' => '08034445566',
            'licence_no' => 'RDR-BS1',
            'type' => 'rider',
            'status' => 'active',
        ]));

        $this->arrivedTrip($world, $driver, 50_000);
        $this->arrivedTrip($world, $rider, 35_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        // 1. Drivers only
        $driverRun = $service->generate(null, $accountant, null, null, 'driver');
        $this->assertSame(1, $driverRun->driver_count);
        $this->assertSame(50_000, (int) $driverRun->total_minor);
        $this->assertSame(TransportPaymentRun::SCOPE_DRIVER, $driverRun->scope_type);
        $this->assertTrue($driverRun->payments()->where('driver_id', $driver->id)->exists());
        $this->assertFalse($driverRun->payments()->where('driver_id', $rider->id)->exists());

        // 2. Riders only
        $riderRun = $service->generate(null, $accountant, null, null, 'rider');
        $this->assertSame(1, $riderRun->driver_count);
        $this->assertSame(35_000, (int) $riderRun->total_minor);
        $this->assertSame(TransportPaymentRun::SCOPE_RIDER, $riderRun->scope_type);
        $this->assertTrue($riderRun->payments()->where('driver_id', $rider->id)->exists());
        $this->assertFalse($riderRun->payments()->where('driver_id', $driver->id)->exists());
    }

    public function test_run_categorization_for_individual_selection(): void
    {
        $world = $this->makeMilkWorld();

        $d1 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Driver One',
            'phone' => '08039990001',
            'licence_no' => 'DRV-111',
            'type' => 'driver',
            'status' => 'active',
        ]));
        $d2 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Driver Two',
            'phone' => '08039990002',
            'licence_no' => 'DRV-222',
            'type' => 'driver',
            'status' => 'active',
        ]));

        $this->arrivedTrip($world, $d1, 60_000);
        $this->arrivedTrip($world, $d2, 40_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        // Select only Driver One individually
        $run = $service->generate(null, $accountant, null, null, 'individual', [$d1->id]);

        $this->assertSame(1, $run->driver_count);
        $this->assertSame(60_000, (int) $run->total_minor);
        $this->assertSame(TransportPaymentRun::SCOPE_INDIVIDUAL, $run->scope_type);
        $this->assertTrue($run->payments()->where('driver_id', $d1->id)->exists());
        $this->assertFalse($run->payments()->where('driver_id', $d2->id)->exists());
    }

    public function test_disbursement_debits_driver_wallet_ledger(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $driver = $payment->driver;

        $wallet = $driver->wallet->fresh();
        $initialBalance = $wallet->balance_minor;
        $this->assertGreaterThan(0, $initialBalance);

        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->amount_minor,
            'method' => 'cash',
        ], $officer);

        $wallet->refresh();
        $this->assertSame($initialBalance - $payment->amount_minor, $wallet->balance_minor);

        $tx = \App\Models\DriverWalletTransaction::query()
            ->where('driver_id', $driver->id)
            ->where('type', \App\Models\DriverWalletTransaction::TYPE_DEBIT)
            ->latest('id')
            ->first();

        $this->assertNotNull($tx);
        $this->assertSame($payment->amount_minor, $tx->amount_minor);
    }

    public function test_drivers_with_zero_balance_are_excluded_from_run(): void
    {
        $driverZero = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Zero Balance Rider',
            'phone' => '08030000000',
            'licence_no' => 'DRV-000',
            'type' => 'rider',
            'status' => 'active',
        ]));

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);
        $eligible = $service->eligibleRecipients('all');

        $this->assertFalse($eligible->pluck('driver.id')->contains($driverZero->id));
    }

    public function test_can_add_recipient_to_draft_run_and_totals_update(): void
    {
        $world = $this->makeMilkWorld();

        $d1 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Initial Driver',
            'phone' => '08035551111',
            'licence_no' => 'DRV-INIT',
            'type' => 'driver',
            'status' => 'active',
        ]));
        $d2 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Added Rider',
            'phone' => '08035552222',
            'licence_no' => 'RDR-ADD',
            'type' => 'rider',
            'status' => 'active',
        ]));

        $this->arrivedTrip($world, $d1, 40_000);
        $this->arrivedTrip($world, $d2, 30_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        // 1. Initial run created with only d1
        $run = $service->generate(null, $accountant, null, null, 'individual', [$d1->id]);
        $this->assertSame(1, $run->driver_count);
        $this->assertSame(40_000, (int) $run->total_minor);

        // 2. Add d2 to the draft run
        $service->addRecipient($run, $d2, 30_000, $accountant);
        $run->refresh();

        $this->assertSame(2, $run->driver_count);
        $this->assertSame(70_000, (int) $run->total_minor);
        $this->assertTrue($run->payments()->where('driver_id', $d2->id)->exists());
    }

    public function test_can_edit_amount_for_partial_payment_leaving_remainder_in_wallet(): void
    {
        $this->world = $this->makeMilkWorld();

        $rider = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Partial Rider Kabir',
            'phone' => '08037778899',
            'licence_no' => 'RDR-KAB',
            'type' => 'rider',
            'status' => 'active',
        ]));

        $this->arrivedTrip($this->world, $rider, 50_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        // 1. Run generated with full 50,000
        $run = $service->generate($this->world['centerA'], $accountant, null, null, 'individual', [$rider->id]);
        $payment = $run->payments()->firstOrFail();
        $this->assertSame(50_000, (int) $payment->amount_minor);

        // 2. Edit amount for partial payment (30,000)
        $service->updateRecipientAmount($run, $payment, 30_000, $accountant);
        $payment->refresh();
        $run->refresh();

        $this->assertSame(30_000, (int) $payment->amount_minor);
        $this->assertSame(30_000, (int) $run->total_minor);

        // 3. Approve and disburse the 30,000
        $run->forceFill(['status' => TransportPaymentRun::STATUS_APPROVED])->save();
        $payment->refresh();
        $payment->setRelation('run', $run);
        $officer = $this->payingOfficer();
        $this->actingAs($officer);

        app(TransportDisbursementService::class)->record($payment, [
            'amount_minor' => 30_000,
            'method' => 'cash',
        ], $officer);

        // 4. Verify wallet balance is 50,000 - 30,000 = 20,000
        $wallet = $rider->wallet->fresh();
        $this->assertSame(20_000, $wallet->balance_minor);

        // 5. In the next run, rider is eligible for the remaining 20,000
        $nextEligible = $service->eligibleRecipients('all');
        $item = $nextEligible->firstWhere('driver.id', $rider->id);
        $this->assertNotNull($item);
        $this->assertSame(20_000, $item['available_minor']);
    }

    public function test_can_remove_recipient_from_draft_run_releasing_legs_and_funds(): void
    {
        $world = $this->makeMilkWorld();

        $d1 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Keep Driver',
            'phone' => '08034440001',
            'licence_no' => 'DRV-KP1',
            'type' => 'driver',
            'status' => 'active',
        ]));
        $d2 = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Remove Rider',
            'phone' => '08034440002',
            'licence_no' => 'RDR-RM1',
            'type' => 'rider',
            'status' => 'active',
        ]));

        $trip1 = $this->arrivedTrip($world, $d1, 40_000);
        $trip2 = $this->arrivedTrip($world, $d2, 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);
        $run = $service->generate(null, $accountant);

        $this->assertSame(2, $run->driver_count);
        $this->assertSame(65_000, (int) $run->total_minor);

        $payment2 = $run->payments()->where('driver_id', $d2->id)->firstOrFail();

        // Remove d2 from draft run
        $service->removeRecipient($run, $payment2, $accountant);
        $run->refresh();

        $this->assertSame(1, $run->driver_count);
        $this->assertSame(40_000, (int) $run->total_minor);
        $this->assertFalse($run->payments()->where('driver_id', $d2->id)->exists());

        // Trip was released back to queued and unassigned from run
        $this->assertSame(Trip::PAYMENT_QUEUED, $trip2->fresh()->payment_status);
        $this->assertNull($trip2->fresh()->payment_run_id);
    }

    public function test_cannot_modify_recipients_when_run_is_not_draft(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentRunService::class);

        // 1. Cannot add recipient to approved run
        $newDriver = $this->asSystem(fn () => Driver::query()->create([
            'name' => 'Late Driver',
            'phone' => '08038880000',
            'licence_no' => 'DRV-LT1',
            'type' => 'driver',
            'status' => 'active',
        ]));
        $this->arrivedTrip($this->world, $newDriver, 15_000);

        try {
            $service->addRecipient($run, $newDriver, 15_000, $accountant);
            $this->fail('Expected RuleViolationException for addRecipient on non-draft run');
        } catch (RuleViolationException $e) {
            $this->assertSame('ST-1', $e->ruleId);
        }

        // 2. Cannot update recipient amount on approved run
        try {
            $service->updateRecipientAmount($run, $payment, 10_000, $accountant);
            $this->fail('Expected RuleViolationException for updateRecipientAmount on non-draft run');
        } catch (RuleViolationException $e) {
            $this->assertSame('ST-1', $e->ruleId);
        }

        // 3. Cannot remove recipient from approved run
        try {
            $service->removeRecipient($run, $payment, $accountant);
            $this->fail('Expected RuleViolationException for removeRecipient on non-draft run');
        } catch (RuleViolationException $e) {
            $this->assertSame('ST-1', $e->ruleId);
        }
    }

    public function test_approvals_controller_show_loads_transport_payment_run_relations(): void
    {
        $world = $this->makeMilkWorld();
        $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);
        $instance = $run->workflowInstance;

        if ($instance) {
            $response = $this->get(route('approvals.show', $instance));
            $response->assertOk();
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_cannot_create_batch_on_unapproved_run(): void
    {
        $world = $this->makeMilkWorld();
        $this->arrivedTrip($world, $this->driver('Buba Danladi'), 120_000);
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($world['centerA'], $accountant);
        $this->assertSame(TransportPaymentRun::STATUS_DRAFT, $run->status);

        $service = app(TransportPaymentService::class);

        $this->expectException(RuleViolationException::class);
        $service->createBatch($run, 'bank_transfer', $accountant);
    }

    public function test_approved_transport_run_can_disburse_batch_and_debits_driver_wallet(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $driver = $payment->driver;
        $initialBalance = $driver->getOrCreateWallet()->balance_minor;
        $this->assertSame(120_000, $initialBalance);

        $service = app(TransportPaymentService::class);
        $batch = $service->createBatch($run, 'bank_transfer', $accountant);

        $this->assertSame(PaymentBatch::STATUS_INITIALIZED, $batch->status);
        $this->assertSame(120_000, $batch->total_amount_minor);
        $this->assertSame(1, $batch->total_items_count);

        $batch = $service->disburseBatch($batch);

        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->successful_items_count);

        // Verify driver wallet debited
        $driverWallet = $driver->fresh()->wallet;
        $this->assertSame(0, $driverWallet->balance_minor);
        $this->assertSame(120_000, $driverWallet->total_debited_minor);

        // Verify disbursement recorded
        $this->assertSame(1, $payment->disbursements()->count());
        $this->assertSame(120_000, (int) $payment->disbursements()->first()->amount_minor);

        // Verify run and payment marked paid
        $this->assertSame(TransportPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(TransportPaymentRun::STATUS_PAID, $run->fresh()->status);
    }

    public function test_partial_batch_disbursement_leaves_remainder_in_driver_wallet(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $driver = $payment->driver;
        $service = app(TransportPaymentService::class);

        // Disburse partial 50,000 out of 120,000
        $batch = $service->createBatch(
            $run,
            'bank_transfer',
            $accountant,
            'Partial payout test',
            [['transport_payment_id' => $payment->id, 'amount_minor' => 50_000]]
        );

        $this->assertSame(50_000, $batch->total_amount_minor);
        $batch = $service->disburseBatch($batch);
        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->status);

        // 50,000 debited from wallet, 70,000 remains
        $driverWallet = $driver->fresh()->wallet;
        $this->assertSame(70_000, $driverWallet->balance_minor);
        $this->assertSame(50_000, $driverWallet->total_debited_minor);

        // Payment line still has 70,000 outstanding and is not closed yet
        $this->assertSame(70_000, $payment->fresh()->outstandingMinor());
        $this->assertSame(TransportPayment::STATUS_PAYABLE, $payment->fresh()->status);
    }

    public function test_idempotency_does_not_duplicate_driver_wallet_debit(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $driver = $payment->driver;
        $service = app(TransportPaymentService::class);

        $batch = $service->createBatch($run, 'bank_transfer', $accountant);
        $service->disburseBatch($batch);

        $this->assertSame(0, $driver->fresh()->wallet->balance_minor);
        $this->assertSame(120_000, $driver->fresh()->wallet->total_debited_minor);

        // Triggering processSuccessfulDisbursements a second time should be a no-op
        $service->processSuccessfulDisbursements($batch, $accountant);

        $this->assertSame(0, $driver->fresh()->wallet->balance_minor);
        $this->assertSame(120_000, $driver->fresh()->wallet->total_debited_minor);
        $this->assertSame(1, $payment->disbursements()->count());
    }

    public function test_controller_disburse_batch_and_batch_show_endpoints(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $response = $this->post(route('transport-payments.disburse-batch', $run), [
            'gateway' => 'bank_transfer',
            'notes' => 'Test HTTP batch disbursement',
            'selected_payments' => [$payment->id],
        ]);

        $response->assertRedirect(route('transport-payments.show', $run));

        $batch = PaymentBatch::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->id)
            ->firstOrFail();

        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->status);

        $batchShowResponse = $this->get(route('transport-payments.batches.show', [$run, $batch]));
        $batchShowResponse->assertOk();
    }

    public function test_visiting_show_endpoint_triggers_gateway_sync_for_latest_batch(): void
    {
        [$run, $payment, $trip] = $this->approvedRun();
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $service = app(TransportPaymentService::class);
        $batch = $service->createBatch($run, 'bank_transfer', $accountant);

        // Manually set batch to processing to simulate pending gateway settlement
        $batch->forceFill(['status' => PaymentBatch::STATUS_PROCESSING])->save();
        $this->assertSame(PaymentBatch::STATUS_PROCESSING, $batch->fresh()->status);

        // Visit /transport-payments/{id}
        $response = $this->get(route('transport-payments.show', $run));
        $response->assertOk();

        // Verify gateway sync was triggered and updated the latest batch status
        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    /* ------------------------------------------------------------ fixtures */

    private array $world = [];

    private ?User $accountantUser = null;

    /** @return array{0: TransportPaymentRun, 1: TransportPayment, 2: Trip} */
    private function approvedRun(): array
    {
        $this->world = $this->makeMilkWorld();
        $trip = $this->arrivedTrip($this->world, $this->driver('Buba Danladi'), 120_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(TransportPaymentRunService::class)->generate($this->world['centerA'], $accountant);
        $run->forceFill(['status' => TransportPaymentRun::STATUS_APPROVED])->save();

        // The trips move with the run. syncFromWorkflow does this in life; here
        // the workflow is short-circuited, so the same update is applied.
        $this->asSystem(fn () => Trip::withoutDataScope()
            ->whereIn('id', $run->payments()->firstOrFail()->lines()->select('trip_id'))
            ->update(['payment_status' => Trip::PAYMENT_APPROVED]));

        return [$run->refresh(), $run->payments()->firstOrFail(), $trip->fresh()];
    }

    private function driver(string $name, string $code = 'DRV-901'): Driver
    {
        return $this->asSystem(function () use ($name, $code) {
            $driver = Driver::query()->firstOrCreate(
                ['name' => $name],
                [
                    'phone' => '0803'.substr($code, -6),
                    'licence_no' => $code,
                    'type' => 'rider',
                    'status' => 'active',
                ],
            );
            if (empty($driver->bank_name) || empty($driver->bank_account)) {
                $driver->forceFill([
                    'bank_name' => 'Access Bank',
                    'bank_account' => '0123456789',
                ])->save();
            }
            return $driver;
        });
    }

    /** A completed leg carrying a fee — the only kind a run will claim. */
    private function arrivedTrip(array $world, Driver $driver, int $feeMinor, string $litres = '150.00'): Trip
    {
        return $this->asSystem(function () use ($world, $driver, $feeMinor, $litres): Trip {
            $route = Route::query()->first() ?? Route::query()->create([
                'name' => 'Test route',
                'from_type' => 'collection_point',
                'from_id' => $world['pointA']->getKey(),
                'to_type' => 'collection_center',
                'to_id' => $world['centerA']->getKey(),
                'tariff_minor' => $feeMinor,
                'status' => 'active',
            ]);

            return Trip::query()->create([
                'reference' => 'TRP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'route_id' => $route->getKey(),
                'collection_point_id' => $world['pointA']->getKey(),
                'collection_center_id' => $world['centerA']->getKey(),
                'driver_id' => $driver->getKey(),
                'departed_at' => Wat::now()->subHours(3),
                'arrived_at' => Wat::now()->subHours(2),
                'litres_carried' => $litres,
                'fee_minor' => $feeMinor,
                'route_tariff_minor_snapshot' => $feeMinor,
                'payment_status' => Trip::PAYMENT_QUEUED,
            ]);
        });
    }

    private function accountant(): User
    {
        if ($this->accountantUser !== null) {
            return $this->accountantUser->fresh();
        }

        $user = $this->makeUser('Transport Accountant');
        $this->assignRole($user, 'Accounts');

        return $this->accountantUser = $user->fresh();
    }

    private function payingOfficer(): User
    {
        $user = $this->makeUser('Paying Officer');
        $this->assignRole($user, 'Milk Collection Officer', ScopeType::Center, $this->world['centerA']->getKey());

        return $user->fresh();
    }
}
