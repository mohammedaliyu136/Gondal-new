<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\Attachment;
use App\Models\ExtensionAgent;
use App\Models\FieldActivity;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Support\Wat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\GondalTestCase;

/**
 * What a field worker captured, and where they were standing when they did.
 *
 * Two properties, and each is here because getting it wrong is quiet rather
 * than loud:
 *
 *   A MISSING FIX IS NOT A ZERO. 0,0 is a real coordinate in the Gulf of
 *   Guinea. A phone with no signal must leave the columns null, because a row
 *   reading 0,0 is a claim about a place the worker demonstrably was not.
 *
 *   A PHOTO BELONGS TO ONE RECORD, AND ONE UPLOADER. Photos arrive separately
 *   from the records they document and are matched by the client_uuid the PHONE
 *   generated, so the resolution is keyed on the uploader's own sync records —
 *   a uuid copied from another agent must resolve to nothing at all.
 */
class FieldCaptureRulesTest extends GondalTestCase
{
    /* ------------------------------------------------------------- location */

    public function test_a_synced_field_visit_records_where_it_was_captured(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        // Yola, Adamawa.
        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [[
                'client_uuid' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'community_id' => $world['communityA']->getKey(),
                'topics' => ['Clean milk production'],
                'notes' => 'Four households reached.',
                'visit_date' => Wat::today()->toDateString(),
                'latitude' => 9.2035089,
                'longitude' => 12.4954165,
                'location_accuracy_m' => 12.4,
            ]],
        ])->assertOk();

        $activity = FieldActivity::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertSame('9.2035089', (string) $activity->latitude);
        $this->assertSame('12.4954165', (string) $activity->longitude);
        // Metres are rounded to a whole number — a fix is not precise to the cm.
        $this->assertSame(12, $activity->location_accuracy_m);
        $this->assertNotNull($activity->located_at);
        $this->assertTrue($activity->hasLocation());
    }

    public function test_a_visit_with_no_fix_stores_no_coordinate_rather_than_zero(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [[
                'client_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002',
                'community_id' => $world['communityA']->getKey(),
                'notes' => 'No signal in the valley.',
                'visit_date' => Wat::today()->toDateString(),
            ]],
        ])->assertOk();

        $activity = FieldActivity::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertNull($activity->latitude);
        $this->assertNull($activity->longitude);
        $this->assertFalse($activity->hasLocation());

        // And the visit still landed. A missing fix must never lose the work.
        $this->assertSame('No signal in the valley.', $activity->findings);
    }

    public function test_an_impossible_coordinate_is_discarded_not_stored(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [[
                'client_uuid' => 'bbbbbbbb-0000-4000-8000-000000000003',
                'community_id' => $world['communityA']->getKey(),
                'notes' => 'Compass broken.',
                'visit_date' => Wat::today()->toDateString(),
                'latitude' => 91.5,        // no such latitude
                'longitude' => 12.49,
            ]],
        ])->assertOk();

        $activity = FieldActivity::withoutDataScope()->latest('id')->firstOrFail();

        // The visit is kept; only the nonsense is dropped.
        $this->assertNull($activity->latitude);
        $this->assertSame('Compass broken.', $activity->findings);
    }

    /* ---------------------------------------------------------------- scope */

    /**
     * ARCH-4 layer 2 / ARCH-2 — the sync path enforces what the web enforces.
     *
     * The agent record is a poor subject to authorise against on its own: it is
     * the agent's OWN row, so every agent passes. Until this was fixed,
     * `community_id` arrived having been checked for nothing but existence, and
     * a phone could log a visit against any community in the network — along
     * with the farmers-reached figure that feeds programme reporting.
     */
    public function test_a_synced_visit_cannot_name_a_community_outside_the_agents_scope(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        $response = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [
                [
                    'client_uuid' => 'dddddddd-0000-4000-8000-000000000001',
                    'community_id' => $world['communityA']->getKey(),
                    'notes' => 'Mine.',
                    'visit_date' => Wat::today()->toDateString(),
                ],
                [
                    'client_uuid' => 'dddddddd-0000-4000-8000-000000000002',
                    'community_id' => $world['communityB']->getKey(),
                    'notes' => 'Not mine.',
                    'visit_date' => Wat::today()->toDateString(),
                ],
            ],
        ])->assertOk();

        // The one they cover landed; the one they do not was refused by name.
        $this->assertSame(1, $response->json('accepted'));

        $errors = collect($response->json('results.errors'));
        $this->assertCount(1, $errors);
        $this->assertSame('dddddddd-0000-4000-8000-000000000002', $errors->first()['client_uuid']);

        $this->assertSame(1, FieldActivity::withoutDataScope()->count());
        $this->assertSame(
            $world['communityA']->getKey(),
            FieldActivity::withoutDataScope()->first()->community_id,
        );
    }

    /* --------------------------------------------------------------- photos */

    public function test_a_photo_attaches_to_the_record_its_client_uuid_names(): void
    {
        Storage::fake('local');

        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());
        $uuid = 'cccccccc-0000-4000-8000-000000000001';

        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [[
                'client_uuid' => $uuid,
                'community_id' => $world['communityA']->getKey(),
                'notes' => 'Training session.',
                'visit_date' => Wat::today()->toDateString(),
            ]],
        ])->assertOk();

        $activity = FieldActivity::withoutDataScope()->latest('id')->firstOrFail();

        $this->actingAsMobile($agent)->post('/api/v1/attachments', [
            'client_uuid' => $uuid,
            'photo' => UploadedFile::fake()->image('visit.jpg', 800, 600),
            'caption' => 'Households attending',
        ])->assertStatus(201);

        $attachment = Attachment::query()->latest('id')->firstOrFail();

        $this->assertSame(FieldActivity::class, $attachment->attachable_type);
        $this->assertSame($activity->getKey(), $attachment->attachable_id);
        $this->assertSame('Households attending', $attachment->filename);
        $this->assertSame($agent->getKey(), $attachment->uploaded_by_user_id);

        // Stored on the PRIVATE disk — a field photo shows a household and a herd.
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * The phone retries. A second upload of the same photo must answer with the
     * first rather than storing it twice.
     *
     * Runs WITH a caption and without. The captioned case is the one the app
     * actually sends, and it is the one that was broken: the row stored the
     * caption in `filename` while the retry check compared the uploaded file's
     * original name, so the two never matched and every retry stored another
     * copy. Without a caption the two expressions agree, which is why the first
     * version of this test passed against the bug.
     */
    public function test_re_uploading_the_same_photo_does_not_duplicate_it(): void
    {
        Storage::fake('local');

        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        foreach ([
            ['cccccccc-0000-4000-8000-000000000002', null],
            ['cccccccc-0000-4000-8000-000000000005', 'Households attending'],
        ] as [$uuid, $caption]) {
            $before = Attachment::query()->count();

            $this->syncedVisit($agent, $world['communityA']->getKey(), $uuid);

            $send = fn () => $this->actingAsMobile($agent)->post('/api/v1/attachments', array_filter([
                'client_uuid' => $uuid,
                'photo' => UploadedFile::fake()->image('same.jpg', 400, 400),
                'caption' => $caption,
            ], static fn ($value) => $value !== null));

            $first = $send()->assertStatus(201);
            $second = $send()->assertStatus(201);

            $this->assertSame(
                $first->json('data.id'),
                $second->json('data.id'),
                $caption === null ? 'uncaptioned retry duplicated' : 'captioned retry duplicated',
            );
            $this->assertSame($before + 1, Attachment::query()->count());
        }
    }

    /**
     * The one that matters. A client_uuid is generated on a phone and is
     * guessable; resolution is keyed on the UPLOADER's own sync records, so
     * another agent's uuid must resolve to nothing.
     */
    public function test_an_agent_cannot_attach_a_photo_to_another_agents_record(): void
    {
        Storage::fake('local');

        $world = $this->makeMilkWorld();
        $mine = $this->makeExtensionAgent($world['communityA']->getKey());
        $theirs = $this->makeExtensionAgent($world['communityB']->getKey(), 'Sadiya Habibu', 'EXT-002');

        $uuid = 'cccccccc-0000-4000-8000-000000000003';
        $this->syncedVisit($mine, $world['communityA']->getKey(), $uuid);

        // The other agent knows the uuid and tries to hang a photo on it.
        $this->actingAsMobile($theirs)->post('/api/v1/attachments', [
            'client_uuid' => $uuid,
            'photo' => UploadedFile::fake()->image('not-mine.jpg'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_photo_for_a_record_that_has_not_synced_is_refused_retryably(): void
    {
        Storage::fake('local');

        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());

        $response = $this->actingAsMobile($agent)->post('/api/v1/attachments', [
            'client_uuid' => 'cccccccc-0000-4000-8000-00000000dead',
            'photo' => UploadedFile::fake()->image('early.jpg'),
        ])->assertStatus(422);

        // The wording has to tell the phone to reorder its queue, not to give up.
        $this->assertStringContainsString('has not reached the server yet', $response->json('message'));
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_file_that_is_not_a_photograph_is_refused(): void
    {
        Storage::fake('local');

        $world = $this->makeMilkWorld();
        $agent = $this->makeExtensionAgent($world['communityA']->getKey());
        $uuid = 'cccccccc-0000-4000-8000-000000000004';

        $this->syncedVisit($agent, $world['communityA']->getKey(), $uuid);

        // An upload endpoint that trusts the extension is how a .jpg becomes a .php.
        $this->actingAsMobile($agent)->post('/api/v1/attachments', [
            'client_uuid' => $uuid,
            'photo' => UploadedFile::fake()->create('payload.jpg', 12, 'text/php'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::query()->count());
    }

    /* ------------------------------------------------------------- fixtures */

    private function syncedVisit(User $agent, int $communityId, string $uuid): void
    {
        $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'field_visits' => [[
                'client_uuid' => $uuid,
                'community_id' => $communityId,
                'notes' => 'Visit.',
                'visit_date' => Wat::today()->toDateString(),
            ]],
        ])->assertOk();
    }

    private function makeExtensionAgent(int $communityId, string $name = 'Yusuf Garba', string $code = 'EXT-001'): User
    {
        $visitor = $this->makeUser($name);
        $this->assignRole($visitor, 'Extension Agent', ScopeType::Communities, $communityId);

        $this->asSystem(function () use ($visitor, $communityId, $code): void {
            $agent = ExtensionAgent::query()->create([
                'user_id' => $visitor->getKey(),
                'code' => $code,
                'visit_target_monthly' => 40,
                'enrolment_target_monthly' => 10,
                'status' => 'active',
            ]);

            $agent->communities()->attach($communityId, ['assigned_at' => Wat::now()]);
        });

        return $visitor->fresh();
    }

    private function actingAsMobile(User $user): static
    {
        $token = app(ApiTokenService::class)->issue($user, request(), null)['token'];

        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
