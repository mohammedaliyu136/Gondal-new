<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FarmerValidation;
use App\Models\Setting;
use App\Models\User;
use App\Models\ValidationReason;
use App\Models\ValidationRound;
use App\Services\Community\FarmerCohort;
use App\Services\Community\FarmerValidationService;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Support\Facades\Notification;
use Tests\GondalTestCase;

/**
 * BR-36 in bulk — "revalidate Tudun Wada", "revalidate Jamila's round".
 *
 * A round is one act of judgement over many farmers, and the risk it carries is
 * not arithmetic but REACH: the cohort is chosen by name, so the only thing
 * standing between "every farmer in this community" and "every farmer in the
 * network" is that the cohort is resolved through the scoped query. Two tests
 * here exist solely to fail if that ever stops being true.
 *
 * The other property is that bulk is not all-or-nothing. A farmer already in
 * somebody's queue, or a validation somebody else reviewed thirty seconds ago,
 * must be skipped and REPORTED — never silently dropped, and never allowed to
 * abandon the other ninety-nine.
 */
class BulkRevalidationRulesTest extends GondalTestCase
{
    /* ------------------------------------------------------- opening a round */

    public function test_a_round_over_a_community_assigns_every_farmer_in_it(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 4);

        $this->actingAs($evaluator);

        $farmers = app(FarmerCohort::class)
            ->resolve(FarmerCohort::BY_COMMUNITY, [$world['communityA']->getKey()]);

        $result = app(FarmerValidationService::class)->openRound(
            'Community round', $farmers, $this->reason('PERIODIC'), $evaluator,
        );

        // The seeded farmer plus the four added above.
        $this->assertSame(5, $result['assigned']);
        $this->assertSame([], $result['skipped']);
        $this->assertInstanceOf(ValidationRound::class, $result['round']);
        $this->assertStringStartsWith('VRND-', $result['round']->reference);

        // Every assignment belongs to the round, and none reached the other community.
        $this->assertSame(5, FarmerValidation::withoutDataScope()
            ->where('validation_round_id', $result['round']->getKey())->count());

        $this->assertSame(0, FarmerValidation::withoutDataScope()
            ->where('farmer_id', $world['farmerB']->getKey())->count());
    }

    /**
     * The one that matters. An officer holding community A must not be able to
     * schedule community B by naming it — the cohort resolves through the
     * scoped query, so the ids they post are simply not theirs to use.
     */
    public function test_a_cohort_cannot_reach_outside_the_actors_data_scope(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Community A Officer');
        $this->assignRole(
            $officer, 'Community Engagement Officer',
            ScopeType::Communities, $world['communityA']->getKey(),
        );

        $this->actingAs($officer->fresh());

        $cohort = app(FarmerCohort::class);

        // Their own community resolves.
        $this->assertCount(1, $cohort->resolve(FarmerCohort::BY_COMMUNITY, [$world['communityA']->getKey()]));

        // The neighbouring one they do NOT hold resolves to nobody, rather than
        // to Amina Bello — who exists, and is one posted id away.
        $this->assertCount(0, $cohort->resolve(FarmerCohort::BY_COMMUNITY, [$world['communityB']->getKey()]));

        // And naming both does not smuggle the second one in alongside the first.
        $both = $cohort->resolve(FarmerCohort::BY_COMMUNITY, [
            $world['communityA']->getKey(), $world['communityB']->getKey(),
        ]);

        $this->assertCount(1, $both);
        $this->assertSame($world['farmer']->getKey(), $both->first()->getKey());
    }

    public function test_the_same_scope_boundary_holds_for_an_lga_cohort(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Single Community Officer');
        $this->assignRole(
            $officer, 'Community Engagement Officer',
            ScopeType::Communities, $world['communityA']->getKey(),
        );

        $this->actingAs($officer->fresh());

        // An LGA is a wider handle than a community, and the temptation is to
        // treat it as a shortcut past the scope. It is not.
        $this->assertCount(0, app(FarmerCohort::class)
            ->resolve(FarmerCohort::BY_LGA, [$world['dawakin']->getKey()]));
    }

    public function test_a_farmer_already_in_the_queue_is_skipped_not_fatal(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();

        $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 2);

        // One of them is already being checked.
        app(FarmerValidationService::class)->assign(
            $world['farmer'], $this->reason('DATA_GAP'), $evaluator,
        );

        $this->actingAs($evaluator);

        $farmers = app(FarmerCohort::class)
            ->resolve(FarmerCohort::BY_COMMUNITY, [$world['communityA']->getKey()]);

        $result = app(FarmerValidationService::class)->openRound(
            'Second pass', $farmers, $this->reason('PERIODIC'), $evaluator,
        );

        // The other two still went out, and the round says what it left.
        $this->assertSame(2, $result['assigned']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('already has an open revalidation', $result['skipped'][0]);
    }

    public function test_an_oversized_cohort_is_refused_rather_than_truncated(): void
    {
        $world = $this->makeMilkWorld();

        $this->actingAs($this->evaluator());

        // Cheaper than creating 501 farmers: the guard reads a count, so drive
        // it through a cohort whose count exceeds the cap.
        $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 3);

        $cohort = new class extends FarmerCohort
        {
            public const MAX = 2;
        };

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessageMatches('/capped at/');

        $cohort->resolve(FarmerCohort::BY_COMMUNITY, [$world['communityA']->getKey()]);
    }

    /**
     * Every cohort type, because only two of the five were ever exercised.
     *
     * The agent resolver joined `agent_community` and then filtered on a bare
     * `id`, which SQLite rejected as ambiguous — so choosing an extension agent
     * did not return the wrong farmers, it failed outright with a SQL error.
     * The community and LGA resolvers have no join, which is exactly why the
     * original tests passed while a third of the feature was broken.
     */
    public function test_every_cohort_type_resolves(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());
        $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 2);

        $this->actingAs($this->evaluator());

        $cohort = app(FarmerCohort::class);
        $agentId = ExtensionAgent::query()->where('user_id', $agent->getKey())->value('id');

        $cases = [
            [FarmerCohort::BY_COMMUNITY, [$world['communityA']->getKey()], 3],
            [FarmerCohort::BY_LGA, [$world['kumbotso']->getKey()], 3],
            [FarmerCohort::BY_AGENT, [$agentId], 3],
            [FarmerCohort::BY_POINT, [$world['pointA']->getKey()], 3],
            [FarmerCohort::BY_COOPERATIVE, [$world['cooperative']->getKey()], 1],
        ];

        foreach ($cases as [$type, $ids, $expected]) {
            $this->assertCount(
                $expected,
                $cohort->resolve($type, $ids),
                sprintf('The %s cohort did not resolve as expected.', $type),
            );

            // count() and resolve() must agree — the picker shows one and the
            // round schedules the other.
            $this->assertSame(
                $expected,
                $cohort->count($type, $ids),
                sprintf('count() and resolve() disagree for the %s cohort.', $type),
            );
        }
    }

    public function test_an_empty_cohort_selection_is_refused(): void
    {
        $this->actingAs($this->evaluator());

        $this->expectException(RuleViolationException::class);

        app(FarmerCohort::class)->resolve(FarmerCohort::BY_COMMUNITY, []);
    }

    /* -------------------------------------------------------- bulk accepting */

    public function test_accepting_in_bulk_accepts_only_what_is_awaiting_review(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $extra = $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 2);

        /*
         * Auto-approval off, or a submission is accepted the moment it lands and
         * there is nothing left for a reviewer to accept in bulk. This is the
         * setting M&E turns off for a round raised by a rejection pattern —
         * exactly the kind of round somebody then reviews as a batch.
         */
        $this->setSetting('community.validation_auto_approve', false);

        $service = app(FarmerValidationService::class);

        // Two are submitted and waiting; one is still out in the field.
        $submitted = collect([$world['farmer'], $extra[0]])->map(function (Farmer $farmer) use ($service, $evaluator, $agent) {
            $validation = $service->assign($farmer, $this->reason('PERIODIC'), $evaluator, [
                'assigned_to_user_id' => $agent->getKey(),
            ]);

            return $service->submit($validation, ['outcome' => FarmerValidation::OUTCOME_CONFIRMED], $agent);
        });

        $stillPending = $service->assign($extra[1], $this->reason('PERIODIC'), $evaluator, [
            'assigned_to_user_id' => $agent->getKey(),
        ]);

        $this->actingAs($evaluator);

        $result = $service->acceptMany(
            $submitted->push($stillPending),
            $evaluator,
            'Reviewed together at the monthly meeting',
        );

        $this->assertSame(2, $result['accepted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('nothing to review', $result['skipped'][0]);

        // The pending one is untouched, not quietly closed.
        $this->assertSame(
            FarmerValidation::STATUS_PENDING,
            $stillPending->fresh()->status,
        );
    }

    public function test_a_bulk_acceptance_moves_the_farmers_validation_clock(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $evaluator = $this->evaluator();
        $agent = $this->collectionAgent($world['pointA']->getKey());

        $this->asSystem(fn () => $world['farmer']
            ->forceFill(['last_validated_on' => Wat::today()->subYears(3)->toDateString()])->save());

        $this->setSetting('community.validation_auto_approve', false);

        $service = app(FarmerValidationService::class);

        $validation = $service->submit(
            $service->assign($world['farmer'], $this->reason('PERIODIC'), $evaluator, [
                'assigned_to_user_id' => $agent->getKey(),
            ]),
            ['outcome' => FarmerValidation::OUTCOME_CONFIRMED],
            $agent,
        );

        $this->actingAs($evaluator);

        $service->acceptMany(collect([$validation]), $evaluator);

        // BR-36 — the hold lifts because the check actually verified the farmer.
        $this->assertSame(
            Wat::today()->toDateString(),
            $this->asSystem(fn () => $world['farmer']->fresh()->last_validated_on?->toDateString()),
        );
    }

    /* --------------------------------------------------------------- screens */

    public function test_the_round_endpoint_refuses_a_user_without_the_grant(): void
    {
        $world = $this->makeMilkWorld();

        // A field worker may carry checks OUT; scheduling a hundred is not theirs.
        $agent = $this->collectionAgent($world['pointA']->getKey());
        $this->actingAs($agent->fresh());

        $this->post(route('validations.rounds.store'), [
            'cohort_type' => FarmerCohort::BY_COMMUNITY,
            'cohort_target_ids' => [$world['communityA']->getKey()],
            'validation_reason_id' => $this->reason('PERIODIC')->getKey(),
        ])->assertStatus(403);

        $this->assertSame(0, ValidationRound::query()->count());
    }

    public function test_an_evaluator_opens_a_round_from_the_screen(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $this->extraFarmersIn($world['communityA'], $world['kumbotso'], $world['pointA'], 2);

        $this->actingAs($this->evaluator());

        $this->post(route('validations.rounds.store'), [
            'cohort_type' => FarmerCohort::BY_COMMUNITY,
            'cohort_target_ids' => [$world['communityA']->getKey()],
            'validation_reason_id' => $this->reason('PERIODIC')->getKey(),
            'due_on' => Wat::today()->addDays(21)->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $round = ValidationRound::query()->latest('id')->firstOrFail();

        $this->assertSame(3, FarmerValidation::withoutDataScope()
            ->where('validation_round_id', $round->getKey())->count());
    }

    /**
     * A cohort that resolves to nobody must say so, rather than opening an
     * empty round that looks like work was scheduled.
     */
    public function test_a_cohort_with_no_reachable_farmers_is_reported_not_opened(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Boxed In Officer');
        $this->assignRole(
            $officer, 'Community Engagement Officer',
            ScopeType::Communities, $world['communityA']->getKey(),
        );

        $this->actingAs($officer->fresh());

        $this->post(route('validations.rounds.store'), [
            'cohort_type' => FarmerCohort::BY_COMMUNITY,
            'cohort_target_ids' => [$world['communityB']->getKey()],
            'validation_reason_id' => $this->reason('PERIODIC')->getKey(),
        ])->assertSessionHasErrors('cohort_target_ids');

        $this->assertSame(0, ValidationRound::query()->count());
    }

    /* ------------------------------------------------------------- fixtures */

    /**
     * @return array<int, Farmer>
     */
    private function extraFarmersIn($community, $lga, $point, int $count): array
    {
        return $this->asSystem(function () use ($community, $lga, $point, $count): array {
            $made = [];

            for ($i = 1; $i <= $count; $i++) {
                $made[] = Farmer::query()->create([
                    'code' => 'FRM-9'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'name' => 'Cohort Farmer '.$i,
                    'community_id' => $community->getKey(),
                    'lga_id' => $lga->getKey(),
                    'default_collection_point_id' => $point->getKey(),
                    'herd_size' => 5, 'lactating_count' => 2,
                    'enrolled_on' => Wat::today()->subYear()->toDateString(),
                    'status' => 'active',
                ]);
            }

            return $made;
        });
    }

    /** An extension agent whose register row covers one community. */
    private function makeExtensionAgent(int $communityId, string $name = 'Cohort Agent', string $code = 'EXT-900'): User
    {
        $user = $this->makeUser($name);
        $this->assignRole($user, 'Extension Agent', ScopeType::Communities, $communityId);

        $this->asSystem(function () use ($user, $communityId, $code): void {
            $agent = ExtensionAgent::query()->create([
                'user_id' => $user->getKey(),
                'code' => $code,
                'visit_target_monthly' => 30,
                'enrolment_target_monthly' => 10,
                'status' => 'active',
            ]);

            $agent->communities()->attach($communityId, ['assigned_at' => Wat::now()]);
        });

        return $user->fresh();
    }

    private function evaluator(): User
    {
        $user = $this->makeUser('Bulk Evaluator');
        $this->assignRole($user, 'Monitoring & Evaluation');

        return $user->fresh();
    }

    private function collectionAgent(int $pointId, string $name = 'Bulk Agent'): User
    {
        $user = $this->makeUser($name);
        $this->assignRole($user, 'Collection Agent', ScopeType::Point, $pointId);

        return $user->fresh();
    }

    private function reason(string $code): ValidationReason
    {
        return ValidationReason::query()->where('code', $code)->firstOrFail();
    }

    /** §9 — a setting is a ROW, written the way the settings screen writes it. */
    private function setSetting(string $key, mixed $value): void
    {
        Setting::query()->where('key', $key)->update(['value' => json_encode(['v' => $value])]);

        Settings::flush();
    }
}
