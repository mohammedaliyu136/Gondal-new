<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Driver;
use App\Models\Route;
use App\Models\TransportPayment;
use App\Models\TransportPaymentRun;
use App\Models\Trip;
use App\Models\User;
use App\Services\Finance\TransportDisbursementService;
use App\Services\Finance\TransportPaymentRunService;
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
        return $this->asSystem(fn () => Driver::query()->firstOrCreate(
            ['name' => $name],
            ['phone' => '0803'.substr($code, -6), 'licence_no' => $code, 'type' => 'rider', 'status' => 'active'],
        ));
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
