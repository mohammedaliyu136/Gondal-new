<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\ApiToken;
use App\Models\CollectionPoint;
use App\Models\Delivery;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\LoginCode;
use App\Models\RejectionReason;
use App\Models\Role;
use App\Models\User;
use App\Models\ValidationReason;
use App\Services\Admin\UserAdminService;
use App\Services\Auth\ApiTokenService;
use App\Services\Community\FarmerValidationService;
use App\Support\Wat;
use Illuminate\Support\Facades\Notification;
use Tests\GondalTestCase;

/**
 * The mobile surface (`/api/v1`), tested against the rules it inherits.
 *
 * The point of every test here is that the phone is NOT a second system. It
 * meets AUTH-1's two steps, AUTH-2's device trust, BR-32's block, ARCH-4's two
 * authorisation layers and ARCH-7's idempotency, because it reaches the same
 * services the browser does. A test that passed here while the equivalent web
 * path refused would mean the API had grown its own opinions.
 */
class MobileApiRulesTest extends GondalTestCase
{
    /**
     * AUTH-1 — "Sign-in is email + password, then a 6-digit emailed code. Both
     * steps are required unless the device carries a valid trust token."
     */
    public function test_auth1_the_phone_meets_both_steps(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent();

        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ]);

        // Correct credentials, and deliberately NOT a session: the code step is
        // still owed, and the response says so in its own right rather than
        // looking like a failure.
        $first->assertOk()
            ->assertJsonPath('status', 'code_required')
            ->assertJsonPath('is_success', false)
            ->assertJsonMissingPath('data.token');

        $challenge = $first->json('data.challenge');
        $this->assertNotEmpty($challenge);

        // The address is echoed back masked — it is a secret here too.
        $this->assertStringContainsString('•', (string) $first->json('data.masked_email'));

        // A token cannot be had by guessing at the second step either.
        $this->postJson('/api/v1/auth/verify', [
            'challenge' => $challenge,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $second = $this->postJson('/api/v1/auth/verify', [
            'challenge' => $challenge,
            'code' => $this->codeFor($agent),
        ]);

        $second->assertOk()->assertJsonPath('status', 'signed_in');
        $this->assertNotEmpty($second->json('data.token'));

        // And the token works.
        $this->asPhoneHolding($second->json('data.token'))
            ->getJson('/api/v1/agent/permissions')
            ->assertOk()
            ->assertJsonPath('data.user.email', $agent->email);
    }

    /**
     * AUTH-2 — "Trust this device for 30 days issues a device token that skips
     * the code step."
     */
    public function test_auth2_a_trusted_phone_skips_the_code_step(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent();

        // First sign-in, asking to be remembered.
        $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ]);

        $verified = $this->postJson('/api/v1/auth/verify', [
            'challenge' => $this->challengeFor($agent),
            'code' => $this->codeFor($agent),
            'remember_device' => true,
        ])->assertOk();

        $deviceToken = $verified->json('data.device_token');
        $this->assertNotEmpty($deviceToken, 'AUTH-2 — a remembered device must be given its trust token.');

        // Second sign-in on the same phone: one step, straight to a token.
        $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
            'device_token' => $deviceToken,
        ])->assertOk()->assertJsonPath('status', 'signed_in');

        // And a phone presenting somebody else's noise is not trusted.
        $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
            'device_token' => 'not-a-real-trust-token',
        ])->assertOk()->assertJsonPath('status', 'code_required');
    }

    /**
     * BR-32 — "Deactivating a user blocks sign-in and revokes sessions but
     * preserves all attribution." A phone already holding a token is no
     * exception: the token stops working on the next request.
     */
    public function test_br32_deactivation_reaches_the_phone(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent(['two_factor_enabled' => false]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertOk()->json('data.token');

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertOk();

        $agent->forceFill(['status' => 'deactivated', 'deactivated_at' => Wat::now()])->save();

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertStatus(403);

        // The token itself is dead, not merely refused for this request.
        $this->assertNotNull(ApiToken::query()->where('user_id', $agent->getKey())->first()->revoked_at);

        // A fresh sign-in is refused too, and says why.
        $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertStatus(422)->assertJsonPath('reason', 'deactivated');
    }

    /**
     * AUTH-4 — "Reset revokes all sessions." A phone is a session.
     *
     * ApiTokenService::revokeAllFor() carried the docblock "every token this user
     * holds, e.g. after a password change" and was called from exactly one place:
     * the deactivation path. A reset, a change, and the administrator's "sign out
     * everywhere" all left the token alive for the rest of its 30 days — against
     * POST /sync/batch, which records deliveries, sales and farmer registrations.
     * That is the exact scenario a reset is performed for: the phone is gone.
     */
    public function test_auth4_a_password_reset_kills_every_phone_this_user_holds(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent(['two_factor_enabled' => false]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertOk()->json('data.token');

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertOk();

        // The whole web reset, as the user would walk it.
        $this->asBrowser()->flushSession();
        $this->post(route('password.forgot.store'), ['email' => $agent->email]);
        $this->post(route('password.verify.store'), ['code' => $this->codeFor($agent, LoginCode::PURPOSE_RESET)])
            ->assertRedirect(route('password.reset.form'));
        $this->post(route('password.reset.store'), [
            'password' => 'Harmattan-Dust-77',
            'password_confirmation' => 'Harmattan-Dust-77',
        ])->assertRedirect(route('login'));

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertStatus(401);

        $record = ApiToken::query()->where('user_id', $agent->getKey())->firstOrFail();
        $this->assertNotNull($record->revoked_at, 'The row itself is dead, not merely refused once.');
        $this->assertSame('password_reset', $record->revoked_reason);
    }

    /** BR-33 — "changing a password revokes all other sessions", phones included. */
    public function test_br33_a_password_change_kills_every_phone_this_user_holds(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent(['two_factor_enabled' => false]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertOk()->json('data.token');

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertOk();

        $this->asBrowser()->actingAs($agent->fresh())->post(route('password.change.store'), [
            'current_password' => 'Correct-Horse-9',
            'password' => 'Harmattan-Dust-77',
            'password_confirmation' => 'Harmattan-Dust-77',
        ])->assertRedirect(route('profile'));

        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertStatus(401);
        $this->assertSame(
            'password_change',
            ApiToken::query()->where('user_id', $agent->getKey())->value('revoked_reason'),
        );
    }

    /**
     * BR-32 — the administrator's "sign out everywhere" reaches the phones.
     *
     * An operator told that a field device was lost has one lever on the user
     * screen. Before this it ended browser sessions only, and the sessions list
     * it showed was empty anyway: a mobile sign-in writes no register row. So the
     * screen said "no open sessions", the button said "ended 0", and the phone
     * kept syncing.
     */
    public function test_br32_sign_out_everywhere_reaches_the_phone_and_the_screen_shows_it(): void
    {
        Notification::fake();

        $agent = $this->makeCollectionAgent(['two_factor_enabled' => false]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email,
            'password' => 'Correct-Horse-9',
        ])->assertOk()->json('data.token');

        $admin = $this->makeUser('Lost Phone Admin');
        $this->assignRole($admin, 'System Administrator');

        // The token is on the administrator's screen before they act on it.
        $this->asBrowser()->actingAs($admin)->get(route('admin.users.show', $agent))
            ->assertOk()
            ->assertSee('Mobile sign-ins')
            ->assertSee('1 live');

        $result = app(UserAdminService::class)->signOutEverywhere($agent->fresh(), $admin);

        $this->assertSame(1, $result['tokens']);
        $this->asPhoneHolding($token)->getJson('/api/v1/agent/permissions')->assertStatus(401);
    }

    /** AUTH-8 / ARCH-2 — an unauthenticated request gets a 401, never HTML. */
    public function test_the_mobile_api_refuses_without_a_token(): void
    {
        $this->getJson('/api/v1/agent/permissions')->assertStatus(401);
        $this->asPhoneHolding('9999|nonsense')->getJson('/api/v1/agent/permissions')->assertStatus(401);
    }

    /**
     * §16 — "personas.html is the authoritative persona reference: each with its
     * responsibilities, landing screen, data scope and key restriction."
     *
     * The phone is told the JOB, not only the permission keys. A list of
     * `milk.deliveries.create` does not tell an agent that their day starts at
     * the point at 05:30 with a lactometer.
     */
    public function test_the_phone_is_told_the_job_not_only_the_permissions(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $payload = $this->actingAsMobile($agent)
            ->getJson('/api/v1/agent/permissions')
            ->assertOk()
            ->json('data');

        $role = collect($payload['roles'])->firstWhere('name', 'Collection Agent');

        $this->assertNotNull($role, 'The signed-in user must be told which role they hold.');

        // The "Their day" list, from the role row the seeder wrote.
        $this->assertNotEmpty($role['responsibilities']);
        $this->assertStringContainsString(
            'lactometer',
            implode(' ', $role['responsibilities']),
            '§16 — the Collection Agent runs the lactometer check.',
        );

        // The "Cannot see" list — the half a permission matrix cannot state.
        $this->assertStringContainsString(
            'network total',
            implode(' ', $role['restrictions']),
        );

        // SCR-1 — "Your Data Scope".
        $this->assertSame('Tudun Wada Point', $role['scope']);
        $this->assertSame(['Tudun Wada'], $payload['assigned_points']);

        // The landing surface comes from the role, not from a switch in the app.
        $this->assertSame('milk_collection', $payload['home']);

        // Capabilities AND the underlying keys, so a client can check either.
        $this->assertTrue($payload['permissions']['can_record_milk_intake']);
        $this->assertFalse($payload['permissions']['can_grade_milk']);
        $this->assertContains('milk.deliveries.create', $payload['permission_keys']);

        unset($world);
    }

    /**
     * §16 — "No volumes or payment figures for the farmers they visit."
     *
     * The Extension Agent's boundary, on the mobile payload: not a zero litre
     * count, but no litre count at all. Zero would be a claim about production;
     * absence is the truth, which is that it is not theirs to see.
     */
    public function test_the_extension_agent_is_sent_no_volumes_at_all(): void
    {
        $world = $this->makeMilkWorld();

        $visitor = $this->makeExtensionAgent($world['communityA']->getKey());

        $payload = $this->actingAsMobile($visitor)
            ->getJson('/api/v1/agent/permissions')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('volume_collected', $payload['metrics']);
        $this->assertArrayNotHasKey('oss_credit_issued', $payload['metrics']);
        $this->assertFalse($payload['permissions']['can_view_deliveries']);
        $this->assertFalse($payload['permissions']['can_record_coop_savings']);

        // What they DO hold — the job is enrolment, visits and training.
        $this->assertTrue($payload['permissions']['can_register_farmers']);
        $this->assertTrue($payload['permissions']['can_log_field_visits']);
    }

    /**
     * ARCH-4 — "Authorisation implemented in two distinct layers." A batch is
     * authorised per record, so an Extension Agent's phone cannot record a
     * delivery whatever it sends, and the refusal names the record.
     */
    public function test_arch4_the_sync_batch_authorises_every_record_individually(): void
    {
        $world = $this->makeMilkWorld();

        $visitor = $this->makeExtensionAgent($world['communityA']->getKey());

        $response = $this->actingAsMobile($visitor)->postJson('/api/v1/sync/batch', [
            'milk_collections' => [[
                'client_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
                'farmer_db_id' => $world['farmer']->getKey(),
                'volume' => 22,
                'date' => Wat::today()->toDateString(),
            ]],
            'field_visits' => [[
                'client_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002',
                'community_id' => $world['communityA']->getKey(),
                'topics' => ['Clean milk production'],
                'notes' => 'Six households reached.',
                'farmers' => [['farmer_id' => $world['farmer']->getKey()]],
                'visit_date' => Wat::today()->toDateString(),
            ]],
        ])->assertOk();

        $this->assertSame(0, Delivery::withoutDataScope()->count(),
            'ARCH-4 — an Extension Agent holds no milk.deliveries.create, on any surface.');

        $errors = collect($response->json('results.errors'));
        $this->assertCount(1, $errors);
        $this->assertSame('milk_collections', $errors->first()['type']);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $errors->first()['client_uuid']);

        // And the reason says it was a refusal, not a malformed record. A field
        // worker reading this hours later needs to know to ask an
        // administrator rather than to re-enter the delivery.
        $this->assertStringContainsString('Not permitted', $errors->first()['error']);

        // The visit in the SAME batch still landed. One record's refusal must
        // not discard a morning's other work.
        $this->assertSame(1, $response->json('accepted'));
        $this->assertNotEmpty($response->json('results.field_visits.0.db_id'));
    }

    /**
     * BR-1 / BR-3 / BR-6 — a delivery captured on a phone goes through
     * DeliveryService, so the rules apply exactly as they do at a desk.
     */
    public function test_a_synced_delivery_meets_the_same_milk_rules(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $response = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'milk_collections' => [[
                'client_uuid' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'farmer_db_id' => $world['farmer']->getKey(),
                'collection_point_id' => $world['pointA']->getKey(),
                'volume' => '22.0',
                'litres_rejected' => '2.0',
                'rejection_reason_id' => RejectionReason::query()->firstOrFail()->getKey(),
                'delivered_at' => Wat::today()->setTime(6, 15)->toDateTimeString(),
            ]],
        ])->assertOk();

        $this->assertSame(1, $response->json('accepted'));

        $delivery = Delivery::withoutDataScope()->firstOrFail();

        // BR-6 — accepted litres are STORED, not computed on read.
        $this->assertSame('20.00', (string) $delivery->litres_accepted);
        $this->assertSame($agent->getKey(), $delivery->recorded_by_user_id);
    }

    /**
     * ARCH-7 — "replays return the original result. Required for
     * unreliable-connectivity capture."
     *
     * The phone's retry is rarely byte-identical to the batch it retries: new
     * records join it in the meantime. So idempotency is per record, keyed on
     * the client_uuid, and this is the test that says so.
     */
    public function test_arch7_a_replayed_batch_writes_nothing_twice(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $record = [
            'client_uuid' => 'cccccccc-0000-4000-8000-000000000001',
            'farmer_db_id' => $world['farmer']->getKey(),
            'collection_point_id' => $world['pointA']->getKey(),
            'volume' => '18.0',
            'delivered_at' => Wat::today()->setTime(6, 30)->toDateTimeString(),
        ];

        $first = $this->actingAsMobile($agent)
            ->postJson('/api/v1/sync/batch', ['milk_collections' => [$record]])
            ->assertOk();

        // The retry carries the same record PLUS one the phone captured since.
        $second = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'milk_collections' => [
                $record,
                [
                    'client_uuid' => 'cccccccc-0000-4000-8000-000000000002',
                    'farmer_db_id' => $world['farmer']->getKey(),
                    'collection_point_id' => $world['pointA']->getKey(),
                    'volume' => '11.0',
                    'delivered_at' => Wat::today()->setTime(6, 40)->toDateTimeString(),
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, Delivery::withoutDataScope()->count(),
            'ARCH-7 — the replayed record must not be written a second time.');

        // And the phone is told the original id, so it can mark its row synced.
        $this->assertSame(
            $first->json('results.milk_collections.0.db_id'),
            $second->json('results.milk_collections.0.db_id'),
        );
        $this->assertTrue($second->json('results.milk_collections.0.duplicate'));
    }

    /**
     * §16 — "Meets farmers at the point… Enrols new farmers." A farmer enrolled
     * from a phone is a farmer enrolled by that agent (USER-1: staff record on
     * their behalf), and the community decides whether they may.
     */
    public function test_a_farmer_enrolled_from_the_phone_names_the_agent_who_enrolled_them(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'farmer_registrations' => [[
                'client_uuid' => 'dddddddd-0000-4000-8000-000000000001',
                'name' => 'Hauwa Ibrahim',
                'phone' => '08030000000',
                'community_id' => $world['communityA']->getKey(),
                'herd_size' => 7,
            ]],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $enrolled = Farmer::withoutDataScope()->where('name', 'Hauwa Ibrahim')->firstOrFail();

        $this->assertSame($agent->getKey(), $enrolled->enrolled_by_user_id);
        $this->assertSame($world['communityA']->getKey(), $enrolled->community_id);

        // ARCH-6 — the phone cannot invent a community; the register is the
        // administrator's, and a typo must not grow a near-duplicate.
        $refused = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'farmer_registrations' => [[
                'client_uuid' => 'dddddddd-0000-4000-8000-000000000002',
                'name' => 'Somebody Else',
                'community' => 'Kumbotsoo',
            ]],
        ])->assertOk();

        $this->assertSame(1, $refused->json('rejected'));
        $this->assertStringContainsString('not a community', $refused->json('results.errors.0.error'));
    }

    /**
     * ROLE-6 — "Editing a role takes effect on the assigned users' NEXT REQUEST.
     * No re-login required." Including on a phone holding a 30-day token.
     */
    public function test_role6_a_role_change_reaches_the_phone_without_a_new_token(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $this->assertTrue(
            $this->actingAsMobile($agent)->getJson('/api/v1/agent/permissions')
                ->json('data.permissions.can_record_milk_intake'),
        );

        // The administrator retires the role out from under the live token.
        $this->asSystem(function (): void {
            Role::query()->where('name', 'Collection Agent')
                ->update(['status' => Role::STATUS_DISABLED]);
        });

        $this->assertFalse(
            $this->actingAsMobile($agent->fresh())->getJson('/api/v1/agent/permissions')
                ->json('data.permissions.can_record_milk_intake'),
        );

        unset($world);
    }

    /**
     * BR-36 — the field half of revalidation, over the wire.
     *
     * The queue is the server's, the submission closes the assignment it was
     * given, and a submission with no assignment behind it is refused — an
     * unrequested "validation" is an edit wearing a costume, and under BR-36 it
     * would release a held payment.
     */
    public function test_br36_the_phone_works_the_revalidation_queue_it_was_given(): void
    {
        Notification::fake();

        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        $evaluator = $this->makeUser('Programme Evaluator');
        $this->assignRole($evaluator, 'Monitoring & Evaluation');

        $farmer = $world['farmer'];
        $farmer->forceFill([
            'enrolled_on' => Wat::today()->subYears(2)->toDateString(),
            'last_validated_on' => null,
        ])->save();

        $validation = app(FarmerValidationService::class)->assign(
            $farmer,
            ValidationReason::query()->where('code', 'PERIODIC')->firstOrFail(),
            $evaluator->fresh(),
            ['assigned_to_user_id' => $agent->getKey(), 'due_on' => Wat::today()->subDay()->toDateString()],
        );

        // The queue arrives with the reason, the due date and — the thing the
        // farmer will ask about — the payment hold this visit releases.
        $queue = $this->actingAsMobile($agent)->getJson('/api/v1/validations')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $queue['assignments']);
        $this->assertSame($validation->id, $queue['assignments'][0]['id']);
        $this->assertTrue($queue['assignments'][0]['is_overdue']);
        $this->assertTrue($queue['assignments'][0]['farmer']['payment_held']);
        $this->assertNotEmpty($queue['outcomes']);

        // The visit comes back through the ordinary offline batch.
        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'farmer_validations' => [[
                'client_uuid' => 'eeeeeeee-0000-4000-8000-000000000001',
                'validation_id' => $validation->id,
                'outcome' => 'corrected',
                'phone' => '08037777777',
                'findings' => 'Number changed; herd unchanged.',
            ]],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $farmer->refresh();

        $this->assertSame('08037777777', $farmer->phone);
        $this->assertSame(Wat::today()->toDateString(), $farmer->last_validated_on?->toDateString());
        $this->assertFalse($farmer->paymentIsHeldPendingValidation(), 'The visit released the hold.');

        // And a second, unrequested one is refused rather than quietly applied.
        $refused = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'farmer_validations' => [[
                'client_uuid' => 'eeeeeeee-0000-4000-8000-000000000002',
                'farmer_db_id' => $farmer->getKey(),
                'outcome' => 'confirmed',
            ]],
        ])->assertOk();

        $this->assertSame(1, $refused->json('rejected'));
        $this->assertStringContainsString('no open revalidation', $refused->json('results.errors.0.error'));
    }

    /* ------------------------------------------------------------------ */

    private function makeCollectionAgent(array $attributes = []): User
    {
        $point = CollectionPoint::query()->where('code', 'PT-001')->first()
            ?? $this->makeMilkWorld()['pointA'];

        $agent = $this->makeUser('Sani Bello', $attributes);
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->getKey());

        return $agent->fresh();
    }

    /**
     * §16 — Yusuf Garba: four communities, no volumes, no money.
     *
     * The `extension_agents` row is not decoration: a field visit belongs to an
     * agent record, and the targets and follow-up trail hang off it.
     */
    private function makeExtensionAgent(int $communityId): User
    {
        $visitor = $this->makeUser('Yusuf Garba');
        $this->assignRole($visitor, 'Extension Agent', ScopeType::Communities, $communityId);

        $this->asSystem(function () use ($visitor, $communityId): void {
            $agent = ExtensionAgent::query()->create([
                'user_id' => $visitor->getKey(),
                'code' => 'EXT-001',
                'visit_target_monthly' => 40,
                'enrolment_target_monthly' => 10,
                'status' => 'active',
            ]);

            // SCOPE-1 — an agent record covers the communities it is attached
            // to. Without this the agent is in scope for nothing, which is the
            // right answer for an agent nobody has given a patch to.
            $agent->communities()->attach($communityId, ['assigned_at' => Wat::now()]);
        });

        return $visitor->fresh();
    }

    /** Signs the given user in on the token guard, as the phone would be. */
    private function actingAsMobile(User $user): static
    {
        $token = app(ApiTokenService::class)
            ->issue($user, request(), null)['token'];

        return $this->asPhoneHolding($token);
    }

    /**
     * Sends the next request as a phone carrying this token.
     *
     * `forgetGuards()` is what makes the test honest. Every real request builds
     * a fresh container and therefore resolves the bearer token from scratch;
     * the test harness reuses ONE container across requests, so a RequestGuard
     * that has already answered "who is this?" would keep answering with the
     * user it saw first. Without this, a test could deactivate an account and
     * still be served as the live one — and would then be asserting nothing.
     */
    private function asPhoneHolding(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * Back to a browser after asPhoneHolding().
     *
     * Both halves persist across requests on the test client: the Authorization
     * header, and the default guard `auth:api` selected with Auth::shouldUse().
     * Leaving either in place sends a web request that the `guest` middleware and
     * AuthenticateSession answer for the wrong surface.
     */
    private function asBrowser(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    /** The plaintext of the code just issued — the test stands in for the inbox. */
    private function codeFor(User $user, string $purpose = LoginCode::PURPOSE_SIGNIN): string
    {
        // The stored code is hashed (NFR-9), so the test brute-forces the six
        // digits rather than reaching into the notification. Cheap, and it
        // proves the hash comparison is the thing being satisfied.
        $record = LoginCode::query()
            ->where('user_id', $user->getKey())
            ->forPurpose($purpose)
            ->latest('id')
            ->firstOrFail();

        for ($candidate = 0; $candidate < 1_000_000; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);

            if (hash_equals((string) $record->code_hash, hash('sha256', $code))) {
                return $code;
            }
        }

        $this->fail('Could not resolve the issued sign-in code.');
    }

    private function challengeFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-9',
        ])->json('data.challenge');
    }
}
