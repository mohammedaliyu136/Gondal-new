<?php

namespace Tests\Feature\Rules;

use App\Authorization\Access;
use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\Farmer;
use App\Models\FarmerValidation;
use App\Models\Setting;
use App\Models\User;
use App\Models\ValidationReason;
use App\Models\ValidationRound;
use App\Services\Community\FarmerValidationService;
use App\Services\Milk\DeliveryService;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Support\Facades\Notification;
use Tests\GondalTestCase;

/**
 * BR-36 — farmer revalidation.
 *
 * "Monitoring & Evaluation decide which farmers need validation and who does it
 * for us. Agents, collection agents and other field workers carry it out."
 *
 * Two properties carry the whole feature, and each has a test here that fails
 * loudly if it is ever traded away:
 *
 *   THE SCHEDULER IS NOT THE CHECKER. M&E may assign and accept; they may not
 *   validate. A field worker may validate; they may not assign themselves work.
 *
 *   AN OVERDUE FARMER LOSES THEIR PAYMENT, NEVER THEIR COLLECTION. Refusing
 *   milk at 05:30 destroys it; holding money is recoverable the moment somebody
 *   goes and checks.
 */
class FarmerRevalidationRulesTest extends GondalTestCase
{
    /** BR-36 — M&E assigns; the named field worker is told. */
    public function test_br36_monitoring_and_evaluation_assigns_a_check_to_a_field_worker(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $validation = app(FarmerValidationService::class)->assign(
            $world['farmer'],
            $this->reason('PERIODIC'),
            $evaluator,
            ['assigned_to_user_id' => $agent->getKey(), 'due_on' => Wat::today()->addDays(14)->toDateString()],
        );

        $this->assertSame(FarmerValidation::STATUS_PENDING, $validation->status);
        $this->assertSame($agent->getKey(), $validation->assigned_to_user_id);
        $this->assertSame($evaluator->getKey(), $validation->assigned_by_user_id);
        $this->assertStringStartsWith('VAL-', $validation->reference);

        // It reaches the person who has to do it.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->getKey(),
            'title' => 'Revalidation assigned: '.$world['farmer']->name,
        ]);
    }

    /**
     * BR-36 — a task nobody can complete is worse than no task: it sits in a
     * queue looking like progress while the farmer stays unverified.
     */
    public function test_br36_a_check_cannot_be_assigned_to_somebody_who_cannot_do_it(): void
    {
        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        $accountant = $this->makeUser('Aliyu Danjuma');
        $this->assignRole($accountant, 'Accounts');

        $this->expectException(RuleViolationException::class);

        app(FarmerValidationService::class)->assign(
            $world['farmer'],
            $this->reason('PERIODIC'),
            $evaluator,
            ['assigned_to_user_id' => $accountant->fresh()->getKey()],
        );
    }

    /**
     * §16 / ROLE-2 — the separation the feature exists for.
     *
     * M&E schedules and accepts. They do not hold `community.farmers.validate`,
     * so they cannot walk their own assignment through to "verified" from a
     * desk — which would make the whole exercise self-certifying.
     */
    public function test_br36_the_scheduler_cannot_also_be_the_checker(): void
    {
        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        $this->assertFalse(
            app(Access::class)->allows($evaluator, 'community.farmers.validate', $world['farmer']),
            'M&E must not be able to carry out a check they scheduled.',
        );

        // And the reverse: a Collection Agent works the queue, never fills it.
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $this->assertTrue(app(Access::class)->allows($agent, 'community.farmers.validate', $world['farmer']));
        $this->assertFalse($agent->hasPermission('community.validation.create'));
        $this->assertFalse($agent->hasPermission('community.farmers.edit'),
            'A Collection Agent validates without gaining the run of the register.');
    }

    /**
     * BR-36 — the answer comes back, the correction lands, the clock resets.
     * Auto-approve is on by default, so a periodic sweep needs no second pair
     * of eyes.
     */
    public function test_br36_a_submitted_check_corrects_the_record_and_clears_the_farmer(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $farmer = $world['farmer'];
        $farmer->forceFill(['phone' => '08010000000', 'herd_size' => 9])->save();

        $validation = app(FarmerValidationService::class)->assign(
            $farmer, $this->reason('DATA_GAP'), $evaluator, ['assigned_to_user_id' => $agent->getKey()],
        );

        $submitted = app(FarmerValidationService::class)->submit($validation, [
            'outcome' => FarmerValidation::OUTCOME_CORRECTED,
            'phone' => '08039999999',
            'herd_size' => 11,
            'findings' => 'Two heifers added since enrolment; number changed last year.',
        ], $agent);

        $this->assertSame(FarmerValidation::STATUS_ACCEPTED, $submitted->status);
        $this->assertSame($agent->getKey(), $submitted->submitted_by_user_id);

        $farmer->refresh();
        $this->assertSame('08039999999', $farmer->phone);
        $this->assertSame(11, $farmer->herd_size);
        $this->assertSame(Wat::today()->toDateString(), $farmer->last_validated_on?->toDateString());

        // Both sides are kept, or "corrected" says nothing a reviewer can check.
        $this->assertSame('08010000000', $submitted->before['phone']);
        $this->assertSame('08039999999', $submitted->after['phone']);
    }

    /**
     * BR-36 — `validate` is not `edit`. A field worker may correct what they can
     * see standing with the farmer; moving them into another cooperative moves
     * money, and belongs to somebody holding the register.
     */
    public function test_br36_a_field_check_cannot_move_a_farmer_between_cooperatives(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $agent = $this->collectionAgent($world['pointA']->getKey());
        $farmer = $world['farmer'];
        $originalCooperative = $farmer->cooperative_id;

        $validation = app(FarmerValidationService::class)->assign(
            $farmer, $this->reason('PERIODIC'), $this->evaluator(), ['assigned_to_user_id' => $agent->getKey()],
        );

        app(FarmerValidationService::class)->submit($validation, [
            'outcome' => FarmerValidation::OUTCOME_CORRECTED,
            'phone' => '08031111111',
            // Sent, and deliberately ignored.
            'cooperative_id' => 999,
            'code' => 'FRM-HACKED',
        ], $agent);

        $farmer->refresh();

        $this->assertSame($originalCooperative, $farmer->cooperative_id);
        $this->assertSame('FRM-00001', $farmer->code);
        $this->assertSame('08031111111', $farmer->phone, 'What they could see, they could correct.');
    }

    /**
     * BR-36 — a farmer nobody could find has not been verified. The assignment
     * closes honestly; the farmer stays overdue, and stays held.
     */
    public function test_br36_not_found_closes_the_task_without_clearing_the_farmer(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $agent = $this->collectionAgent($world['pointA']->getKey());
        $farmer = $world['farmer'];

        $validation = app(FarmerValidationService::class)->assign(
            $farmer, $this->reason('DORMANT'), $this->evaluator(), ['assigned_to_user_id' => $agent->getKey()],
        );

        $submitted = app(FarmerValidationService::class)->submit($validation, [
            'outcome' => FarmerValidation::OUTCOME_NOT_FOUND,
            'findings' => 'Household empty; neighbours say they moved to Zaria.',
        ], $agent);

        $this->assertSame(FarmerValidation::STATUS_ACCEPTED, $submitted->status);
        $this->assertFalse($submitted->verified());
        $this->assertNull($farmer->refresh()->last_validated_on,
            'A farmer nobody could find must not read as verified.');
    }

    /**
     * BR-36 — M&E decides whether what comes back needs their eyes, per round.
     * With auto-approve off, a submission waits and the farmer is not cleared
     * until somebody accepts it.
     */
    public function test_br36_monitoring_and_evaluation_decides_whether_a_result_needs_review(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $round = $this->asSystem(fn () => ValidationRound::query()->create([
            'reference' => 'VRND-2026-001',
            'name' => 'Rejection-pattern sweep',
            'validation_reason_id' => $this->reason('REJECTION')->getKey(),
            // The point of the flag: this round is not self-certifying.
            'auto_approve' => false,
            'status' => ValidationRound::STATUS_OPEN,
            'opened_by_user_id' => $evaluator->getKey(),
        ]));

        $validation = app(FarmerValidationService::class)->assign(
            $world['farmer'], $this->reason('REJECTION'), $evaluator,
            ['assigned_to_user_id' => $agent->getKey(), 'validation_round_id' => $round->getKey()],
        );

        $submitted = app(FarmerValidationService::class)->submit($validation, [
            'outcome' => FarmerValidation::OUTCOME_CONFIRMED,
        ], $agent);

        $this->assertSame(FarmerValidation::STATUS_SUBMITTED, $submitted->status);
        $this->assertNull($world['farmer']->refresh()->last_validated_on,
            'Nothing is cleared until M&E has looked at it.');

        $accepted = app(FarmerValidationService::class)->accept($submitted, $evaluator, 'Matches the follow-up notes.');

        $this->assertSame(FarmerValidation::STATUS_ACCEPTED, $accepted->status);
        $this->assertSame($evaluator->getKey(), $accepted->reviewed_by_user_id);
        $this->assertSame(Wat::today()->toDateString(), $world['farmer']->refresh()->last_validated_on?->toDateString());
    }

    /** BR-36 — sent back, it returns to the field worker's queue. */
    public function test_br36_a_returned_check_reopens_for_the_field_worker(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $this->setSetting('community.validation_auto_approve', false);

        $validation = app(FarmerValidationService::class)->assign(
            $world['farmer'], $this->reason('MANUAL'), $evaluator, ['assigned_to_user_id' => $agent->getKey()],
        );

        $submitted = app(FarmerValidationService::class)->submit(
            $validation, ['outcome' => FarmerValidation::OUTCOME_CONFIRMED], $agent,
        );

        $returned = app(FarmerValidationService::class)->returnToField(
            $submitted, $evaluator, 'The herd size still does not match the last three deliveries.',
        );

        $this->assertSame(FarmerValidation::STATUS_RETURNED, $returned->status);
        $this->assertTrue($returned->isOpen(), 'A returned check is work the field worker still owes.');
        $this->assertTrue(
            FarmerValidation::withoutDataScope()->forFieldWorker($agent)->where('id', $returned->id)->exists(),
            'And it is back on their list.',
        );
    }

    /**
     * BR-36 — THE RULE WITH MONEY ATTACHED.
     *
     * An overdue farmer's milk is still collected. Their payment waits.
     *
     * The asymmetry is deliberate and is the reason this test is here: refusing
     * a delivery at the point destroys milk already in the churn over a
     * back-office lapse the agent cannot fix, while holding the payment costs
     * nobody anything that verifying the record does not immediately return.
     */
    public function test_br36_an_overdue_farmer_is_still_collected_from_but_not_paid(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        // Enrolled two years ago, never checked.
        $farmer->forceFill([
            'enrolled_on' => Wat::today()->subYears(2)->toDateString(),
            'last_validated_on' => null,
        ])->save();

        $farmer->refresh();

        $this->assertTrue($farmer->isValidationOverdue());
        $this->assertTrue($farmer->paymentIsHeldPendingValidation());

        // THE HALF THAT MATTERS MOST: the milk is still taken.
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $delivery = app(DeliveryService::class)->record(
            $world['pointA'],
            $farmer,
            ['litres_presented' => '20.00', 'delivered_at' => Wat::todayAt(6, 10)],
            $agent,
        );

        $this->assertSame('20.00', (string) $delivery->litres_accepted,
            'BR-36 must never refuse a delivery. Milk at the point is already in the churn.');

        // A check clears the hold.
        $validation = app(FarmerValidationService::class)->assign(
            $farmer, $this->reason('PERIODIC'), $this->evaluator(), ['assigned_to_user_id' => $agent->getKey()],
        );

        app(FarmerValidationService::class)->submit(
            $validation, ['outcome' => FarmerValidation::OUTCOME_CONFIRMED], $agent,
        );

        $this->assertFalse($farmer->refresh()->paymentIsHeldPendingValidation());
    }

    /** BR-36 — the hold is policy, not code: an administrator can turn it off. */
    public function test_br36_the_payment_hold_is_a_settings_row(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        $farmer->forceFill([
            'enrolled_on' => Wat::today()->subYears(2)->toDateString(),
            'last_validated_on' => null,
        ])->save();

        $this->assertTrue($farmer->refresh()->paymentIsHeldPendingValidation());

        $this->setSetting('community.withhold_payment_when_unvalidated', false);

        $this->assertTrue($farmer->refresh()->isValidationOverdue(), 'Still overdue…');
        $this->assertFalse($farmer->paymentIsHeldPendingValidation(), '…but no longer held.');
    }

    /**
     * BR-36 — the overdue query and the per-record check must agree. A report
     * that lists a farmer the record says is fine (or misses one it says is
     * overdue) is worse than no report.
     */
    public function test_br36_the_overdue_list_agrees_with_the_individual_records(): void
    {
        $world = $this->makeMilkWorld();

        $this->asSystem(function () use ($world): void {
            $world['farmer']->forceFill([
                'enrolled_on' => Wat::today()->subYears(2)->toDateString(),
                'last_validated_on' => Wat::today()->subMonths(18)->toDateString(),
            ])->save();

            $world['farmerB']->forceFill([
                'last_validated_on' => Wat::today()->subMonth()->toDateString(),
            ])->save();
        });

        $overdue = $this->asSystem(fn () => Farmer::withoutDataScope()->validationOverdue()->pluck('code')->all());

        $this->assertContains('FRM-00001', $overdue);
        $this->assertNotContains('FRM-00002', $overdue);

        $this->asSystem(function () use ($overdue): void {
            foreach (Farmer::withoutDataScope()->get() as $farmer) {
                $this->assertSame(
                    in_array($farmer->code, $overdue, true),
                    $farmer->isValidationOverdue(),
                    "The list and the record disagree about {$farmer->code}.",
                );
            }
        });
    }

    /** BR-36 — two field workers must not be sent to the same household. */
    public function test_br36_a_farmer_has_only_one_open_check(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        app(FarmerValidationService::class)->assign($world['farmer'], $this->reason('PERIODIC'), $evaluator);

        $this->expectException(RuleViolationException::class);

        app(FarmerValidationService::class)->assign($world['farmer'], $this->reason('DATA_GAP'), $evaluator);
    }

    /** SCOPE-2 — an agent sees the queue for their own farmers and no others. */
    public function test_scope2_a_field_worker_sees_only_their_own_queue(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        $agentA = $this->collectionAgent($world['pointA']->getKey());
        $agentB = $this->collectionAgent($world['pointB']->getKey(), 'Tumfafi Agent');

        app(FarmerValidationService::class)->assign(
            $world['farmer'], $this->reason('PERIODIC'), $evaluator, ['assigned_to_user_id' => $agentA->getKey()],
        );
        app(FarmerValidationService::class)->assign(
            $world['farmerB'], $this->reason('PERIODIC'), $evaluator, ['assigned_to_user_id' => $agentB->getKey()],
        );

        $this->actingAs($agentA->fresh());
        $visible = FarmerValidation::query()->with('farmer')->get()->pluck('farmer.code')->all();

        $this->assertSame(['FRM-00001'], $visible);
    }

    /** ARCH-4 — and a field worker outside the farmer's scope is refused. */
    public function test_arch4_a_check_cannot_be_completed_outside_the_workers_scope(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $agentB = $this->collectionAgent($world['pointB']->getKey(), 'Tumfafi Agent');

        $this->expectException(AccessDeniedException::class);

        // farmerA belongs to point A; agentB covers point B.
        app(Access::class)->authorize($agentB, 'community.farmers.validate', $world['farmer']);
    }

    /**
     * BR-36 — M&E can now do the job from a browser.
     *
     * The feature shipped with a field app and no web screen, so the role that
     * OWNS the queue held a write grant it had no way to exercise: the only way
     * to schedule a check was a database insert. These three assertions are the
     * whole loop from M&E's side — see the queue, fill it, sign off what comes
     * back — plus the boundary that makes the loop worth anything.
     */
    public function test_br36_monitoring_and_evaluation_can_work_the_queue_from_the_web(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $this->actingAs($evaluator);

        $this->get(route('validations.index'))->assertOk();

        $this->post(route('validations.store'), [
            'farmer_id' => $world['farmer']->getKey(),
            'validation_reason_id' => $this->reason('PERIODIC')->getKey(),
            'assigned_to_user_id' => $agent->getKey(),
            'due_on' => Wat::today()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $validation = FarmerValidation::withoutDataScope()->latest('id')->firstOrFail();
        $this->assertSame($agent->getKey(), $validation->assigned_to_user_id);

        // The field worker answers, with review switched on so there is something
        // for M&E to sign off.
        $this->setSetting('community.validation_auto_approve', false);

        app(FarmerValidationService::class)->submit(
            $validation, ['outcome' => FarmerValidation::OUTCOME_CONFIRMED], $agent,
        );

        $this->actingAs($evaluator->fresh());

        $this->post(route('validations.accept', $validation), ['note' => 'Matches the register.'])
            ->assertRedirect();

        $this->assertSame(
            FarmerValidation::STATUS_ACCEPTED,
            $validation->refresh()->status,
        );
        $this->assertSame($evaluator->getKey(), $validation->reviewed_by_user_id);
    }

    /**
     * BR-36 — a field worker READS the queue and cannot FILL it.
     *
     * Both halves matter and they are easy to get backwards. Seeing their own
     * assignments on the web is the point of giving them `validation.view`, and
     * SCOPE-1 narrows it to theirs. But scheduling a check is M&E's judgement
     * and signing one off is M&E's review, so a worker who could do either would
     * be assigning themselves work and then declaring it done — the exact
     * self-certification the split exists to prevent.
     */
    public function test_br36_a_field_worker_reads_the_queue_but_cannot_fill_or_sign_off(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());
        $otherAgent = $this->collectionAgent($world['pointB']->getKey(), 'Tumfafi Agent');

        $mine = app(FarmerValidationService::class)->assign(
            $world['farmer'], $this->reason('PERIODIC'), $evaluator,
            ['assigned_to_user_id' => $agent->getKey()],
        );
        $theirs = app(FarmerValidationService::class)->assign(
            $world['farmerB'], $this->reason('PERIODIC'), $evaluator,
            ['assigned_to_user_id' => $otherAgent->getKey()],
        );

        $this->actingAs($agent->fresh());

        // SCOPE-1 — their own work, and only their own.
        $this->get(route('validations.index'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);

        // But the queue is not theirs to fill…
        $this->post(route('validations.store'), [
            'farmer_id' => $world['farmer']->getKey(),
            'validation_reason_id' => $this->reason('DATA_GAP')->getKey(),
        ])->assertStatus(403);

        // …nor to sign off.
        $this->post(route('validations.accept', $mine))->assertStatus(403);

        $this->assertSame(2, FarmerValidation::withoutDataScope()->count(),
            'A field worker must not be able to schedule their own work.');
    }

    /* ------------------------------------------------------------------ */

    private function evaluator(): User
    {
        $user = $this->makeUser('Programme Evaluator');
        $this->assignRole($user, 'Monitoring & Evaluation');

        return $user->fresh();
    }

    private function collectionAgent(int $pointId, string $name = 'Sani Bello'): User
    {
        $user = $this->makeUser($name);
        $this->assignRole($user, 'Collection Agent', ScopeType::Point, $pointId);

        return $user->fresh();
    }

    private function reason(string $code): ValidationReason
    {
        return ValidationReason::query()->where('code', $code)->firstOrFail();
    }

    /**
     * §9 — a setting is a ROW. Written the way the settings screen writes it,
     * so the test exercises the same storage the administrator would.
     */
    private function setSetting(string $key, mixed $value): void
    {
        Setting::query()->where('key', $key)->update(['value' => json_encode(['v' => $value])]);

        Settings::flush();
    }
}
