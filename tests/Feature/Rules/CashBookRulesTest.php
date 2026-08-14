<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\CashFloat;
use App\Models\Delivery;
use App\Models\FarmerPayment;
use App\Models\Grade;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Finance\CashFloatService;
use App\Services\Finance\FarmerDisbursementService;
use App\Services\Finance\FarmerPaymentRunService;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §14 Phase 7 — the cash book.
 *
 * The second leg of a payout. Before this existed, "the officer took ₦500,000 to
 * Girei — what came back?" had no answer: an officer could draw half a million,
 * hand over four hundred thousand, record all of it correctly, and every screen
 * would agree every farmer had been paid.
 *
 * These tests are about the arithmetic and about the two-person rule, because
 * those are the only two things a cash book can actually enforce.
 */
class CashBookRulesTest extends GondalTestCase
{
    /* ---------------------------------------------------- the two-person rule */

    /** A float somebody issues to themselves is a spreadsheet, not a control. */
    public function test_a_float_cannot_be_issued_to_yourself(): void
    {
        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $this->expectException(RuleViolationException::class);

        app(CashFloatService::class)->issue($accountant, 500_000_00, $accountant);
    }

    /** Nor may the person who carried the bag sign it back in. */
    public function test_the_holder_cannot_reconcile_their_own_float(): void
    {
        $world = $this->makeMilkWorld();
        $accountant = $this->accountant();
        $officer = $this->officer($world);

        $this->actingAs($accountant);
        $float = app(CashFloatService::class)->issue($officer, 500_000_00, $accountant);

        // Granted reconcile for the sake of the test — the point is that even
        // holding the permission does not let you close your own bag.
        $this->assignRole($officer, 'Accounts');

        $this->actingAs($officer->fresh());
        $this->expectException(RuleViolationException::class);

        app(CashFloatService::class)->reconcile($float, 500_000_00, $officer->fresh());
    }

    /**
     * One open float per person.
     *
     * Two bags with the same name on them makes every variance arguable — "that
     * shortfall was on the other one".
     */
    public function test_an_officer_cannot_hold_two_floats_at_once(): void
    {
        $world = $this->makeMilkWorld();
        $accountant = $this->accountant();
        $officer = $this->officer($world);

        $this->actingAs($accountant);
        $service = app(CashFloatService::class);

        $service->issue($officer, 300_000_00, $accountant);

        $this->expectException(RuleViolationException::class);
        $service->issue($officer, 200_000_00, $accountant);
    }

    /* ------------------------------------------------------- the arithmetic */

    /**
     * drawn − disbursed − returned, counted by the system.
     *
     * The disbursed figure comes from the disbursement table filtered to this
     * officer, not from anything the person holding the money types in.
     */
    public function test_a_float_that_balances_reconciles_to_zero(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $this->actingAs($officer);
        app(FarmerDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->net_minor,
            'method' => 'cash',
            'received_by' => $payment->farmer?->name,
            'received_by_relation' => 'self',
        ], $officer);

        $service = app(CashFloatService::class);
        $disbursed = $service->disbursedMinor($float->fresh());

        $this->assertSame((int) $payment->net_minor, $disbursed, 'counted from the payout record');

        $this->actingAs($accountant);
        $reconciled = $service->reconcile(
            $float->fresh(),
            (int) $float->amount_drawn_minor - $disbursed,
            $accountant,
        );

        $this->assertSame(0, (int) $reconciled->variance_minor);
        $this->assertSame(CashFloat::STATUS_RECONCILED, $reconciled->status);
        $this->assertSame($disbursed, (int) $reconciled->disbursed_minor, 'stamped, not recomputed');
    }

    /**
     * A gap has to be explained in words before the float can be closed.
     *
     * Not because the words are verifiable — they are not. Because "₦4,000
     * short, no reason" and "₦4,000 short, farmer did not come" are different
     * records to somebody reading fourteen of these looking for a pattern.
     */
    public function test_a_variance_cannot_be_filed_without_an_explanation(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $this->actingAs($accountant);

        $this->expectException(RuleViolationException::class);

        // Nothing disbursed, and less than the full amount handed back.
        app(CashFloatService::class)->reconcile($float, (int) $float->amount_drawn_minor - 400_000, $accountant);
    }

    /** Explained, it files — and the number is kept, not floored away. */
    public function test_an_explained_shortfall_is_recorded_as_unaccounted_for(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $this->actingAs($accountant);

        $reconciled = app(CashFloatService::class)->reconcile(
            $float,
            (int) $float->amount_drawn_minor - 400_000,
            $accountant,
            'A note would not change and was left with the point',
        );

        $this->assertSame(400_000, (int) $reconciled->variance_minor);
        $this->assertStringContainsString('note would not change', $reconciled->variance_explanation);
    }

    /**
     * Paying out MORE than was drawn is a negative variance, not a zero.
     *
     * It usually means the officer topped up from their own pocket, and it is
     * its own kind of problem: the cooperative now owes somebody, off the books.
     */
    public function test_paying_over_the_float_shows_as_a_negative_variance(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $this->actingAs($officer);
        app(FarmerDisbursementService::class)->record($payment, [
            'amount_minor' => $payment->net_minor,
            'method' => 'cash',
            'received_by' => $payment->farmer?->name,
            'received_by_relation' => 'self',
        ], $officer);

        $this->actingAs($accountant);

        // Everything drawn is handed back AS WELL as the payout being made —
        // so the officer must have found the money somewhere else.
        $reconciled = app(CashFloatService::class)->reconcile(
            $float->fresh(),
            (int) $float->amount_drawn_minor,
            $accountant,
            'Officer topped up from own pocket',
        );

        $this->assertSame(-(int) $payment->net_minor, (int) $reconciled->variance_minor);
    }

    /** A float is closed once. */
    public function test_a_float_cannot_be_reconciled_twice(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $this->actingAs($accountant);
        $service = app(CashFloatService::class);

        $service->reconcile($float, (int) $float->amount_drawn_minor, $accountant);

        $this->expectException(RuleViolationException::class);
        $service->reconcile($float->fresh(), 0, $accountant, 'again');
    }

    /* ------------------------------------------------------------- the screen */

    /**
     * An officer sees their OWN float.
     *
     * Being unable to check your own position before handing the bag back is how
     * an honest officer ends up unable to explain a shortfall.
     */
    public function test_an_officer_can_see_the_float_they_are_carrying(): void
    {
        [$float, $payment, $officer, $accountant] = $this->floatOverARun();

        $response = $this->actingAs($officer)->get(route('cash-floats.index'))->assertOk();

        $response->assertSee($float->reference);
        $response->assertSee('Not yet accounted for');
    }

    /** But cannot sign it back in: the button is not on their screen. */
    public function test_the_carrier_is_not_shown_the_reconcile_control(): void
    {
        [$float, $payment, $officer] = $this->floatOverARun();

        $this->actingAs($officer)
            ->get(route('cash-floats.index'))
            ->assertOk()
            ->assertDontSee(route('cash-floats.reconcile', $float));
    }

    /* ------------------------------------------------------------ fixtures */

    private ?User $accountantUser = null;

    /** @return array{0: CashFloat, 1: FarmerPayment, 2: User, 3: User} */
    private function floatOverARun(): array
    {
        $world = $this->makeMilkWorld();
        $this->payableDelivery($world, '40.00', 25_000);

        $accountant = $this->accountant();
        $this->actingAs($accountant);

        $run = app(FarmerPaymentRunService::class)->generate($world['centerA'], $accountant);
        $run->forceFill(['status' => PaymentRun::STATUS_APPROVED])->save();

        $officer = $this->officer($world);

        $float = app(CashFloatService::class)->issue(
            $officer,
            1_000_000,               // ₦10,000 drawn
            $accountant,
            $run->refresh(),
            $world['centerA']->getKey(),
        );

        return [$float, $run->payments()->firstOrFail(), $officer, $accountant];
    }

    private function accountant(): User
    {
        if ($this->accountantUser !== null) {
            return $this->accountantUser->fresh();
        }

        $user = $this->makeUser('Cash Accountant');
        $this->assignRole($user, 'Accounts');

        return $this->accountantUser = $user->fresh();
    }

    private function officer(array $world): User
    {
        $user = $this->makeUser('Float Carrier');
        $this->assignRole($user, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->getKey());

        return $user->fresh();
    }

    /** A delivery that has reached a confirmed, priced consignment. */
    private function payableDelivery(array $world, string $litres, int $rateMinor): Delivery
    {
        return $this->asSystem(function () use ($world, $litres, $rateMinor): Delivery {
            $grade = Grade::query()->where('code', 'GRD-A')->firstOrFail();

            $consignment = \App\Models\Consignment::query()->create([
                'reference' => 'CNS-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'collection_point_id' => $world['pointA']->getKey(),
                'collection_center_id' => $world['centerA']->getKey(),
                'litres_declared' => $litres,
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
}
