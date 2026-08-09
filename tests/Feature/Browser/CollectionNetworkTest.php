<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\Adjustment;
use App\Models\AdjustmentReason;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Consignment;
use App\Models\Farmer;
use App\Models\Grade;
use App\Models\Lga;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;
use Tests\GondalTestCase;

/**
 * The collection network's master data — the centres points feed, and the points
 * farmers deliver to.
 *
 * Centres could only be created by a seeder: `milk.points.create` was granted and
 * the centre screen checked it nowhere, so a cooperative opening a new centre had
 * no way to record it and no way to point anything at it.
 */
class CollectionNetworkTest extends GondalTestCase
{
    public function test_a_collection_center_can_be_created_from_the_screen(): void
    {
        $admin = $this->makeUser('Network Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->get(route('collection-centers.index'))
            ->assertOk()
            ->assertSee('+ Add Collection Center')
            ->assertSee('modal-new-center');

        $this->post(route('collection-centers.store'), [
            'code' => 'CTR-GIREI',
            'name' => 'Girei',
            'lga_id' => $lga->id,
            'officer_user_id' => $admin->id,
            'cold_storage_litres' => '4000',
            'distance_to_factory_km' => '15',
            'transport_fee' => '2500',
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $centre = $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-GIREI')->firstOrFail());

        $this->assertSame('Girei', $centre->name);
        $this->assertSame($admin->id, $centre->officer_user_id);
        // ARCH-6 — naira in, kobo stored.
        $this->assertSame(2_500_00, (int) $centre->transport_fee_minor);

        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Milk Collection',
            'event_type' => 'data_create',
        ]);
    }

    /** The point that feeds it can then name it — the two halves must connect. */
    public function test_a_new_center_is_immediately_available_to_a_new_point(): void
    {
        $world = $this->makeMilkWorld();

        $admin = $this->makeUser('Network Admin Two');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('collection-centers.store'), [
            'code' => 'CTR-FUFORE', 'name' => 'Fufore', 'lga_id' => $lga->id, 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $centre = $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-FUFORE')->firstOrFail());

        // It appears on the point form...
        $this->get(route('collection-points.index'))->assertOk()->assertSee('Fufore');

        // ...and a point can be created against it.
        $this->post(route('collection-points.store'), [
            'code' => 'PT-GURIN',
            'name' => 'Gurin',
            'community_id' => $world['communityA']->id,
            'collection_center_id' => $centre->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $point = $this->asSystem(fn () => CollectionPoint::query()->where('code', 'PT-GURIN')->firstOrFail());

        $this->assertSame($centre->id, $point->collection_center_id);
    }

    public function test_a_duplicate_center_code_is_refused(): void
    {
        $admin = $this->makeUser('Network Admin Dup');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());
        $payload = ['code' => 'CTR-SONG', 'name' => 'Song', 'lga_id' => $lga->id, 'status' => 'active'];

        $this->post(route('collection-centers.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('collection-centers.store'), $payload + ['name' => 'Song Two'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-SONG')->count()));
    }

    /** Only the real status vocabulary is accepted — no invented values. */
    public function test_an_unknown_status_is_refused(): void
    {
        $admin = $this->makeUser('Network Admin Status');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('collection-centers.store'), [
            'code' => 'CTR-BAD', 'name' => 'Bad status', 'lga_id' => $lga->id, 'status' => 'idle',
        ])->assertSessionHasErrors('status');

        $this->assertSame(0, $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-BAD')->count()));
    }

    /** Without the grant there is no button and no way in. */
    public function test_the_network_stays_read_only_without_the_grant(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Point Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        if ($agent->fresh()->hasPermission('milk.points.create')) {
            $this->markTestSkipped('Collection Agent holds the create grant in this catalogue.');
        }

        $this->get(route('collection-centers.index'))->assertOk()->assertDontSee('+ Add Collection Center');

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('collection-centers.store'), [
            'code' => 'CTR-NOPE', 'name' => 'Should not exist', 'lga_id' => $lga->id,
        ])->assertStatus(403);

        $this->assertSame(0, $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-NOPE')->count()));
    }

    /** The pickers on the centre form must not render empty. */
    public function test_the_center_form_pickers_are_populated(): void
    {
        $admin = $this->makeUser('Picker Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin->fresh());

        $html = $this->get(route('collection-centers.index'))->assertOk()->getContent();

        foreach (['cc-lga' => 'LGA', 'cc-officer' => 'Collection officer'] as $id => $what) {
            preg_match('/<select id="'.$id.'".*?<\/select>/s', $html, $matches);

            $this->assertNotEmpty($matches, $what.' picker is missing.');
            $this->assertGreaterThan(
                1,
                substr_count($matches[0], '<option'),
                $what.' picker rendered with nothing but its placeholder.',
            );
        }
    }

    /**
     * A community had no create path at all — the seeded 26 were the only ones
     * that could ever exist. A supervisor could add a collection point but not
     * the settlement it stands in.
     */
    public function test_the_milk_supervisor_can_create_a_community(): void
    {
        $supervisor = $this->makeUser('Network Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->get(route('communities.index'))->assertOk()->assertSee('+ Add community');

        $this->post(route('communities.store'), [
            'lga_id' => $lga->id,
            'name' => 'Wuro Bokki',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $community = $this->asSystem(fn () => Community::query()
            ->where('name', 'Wuro Bokki')->firstOrFail());

        $this->assertSame($lga->id, $community->lga_id);

        // And it is immediately usable where a community is needed.
        $this->get(route('collection-points.index'))->assertOk()->assertSee('Wuro Bokki');
    }

    /** The engagement side reaches the same screen for its own reasons. */
    public function test_the_engagement_officer_can_create_a_community_too(): void
    {
        $officer = $this->makeUser('Engagement Officer');
        $this->assignRole($officer, 'Community Engagement Officer', ScopeType::Communities);
        $this->actingAs($officer->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('communities.store'), [
            'lga_id' => $lga->id, 'name' => 'Ruga Damare',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->asSystem(fn () => Community::query()
            ->where('name', 'Ruga Damare')->count()));
    }

    /**
     * Unique within its LGA, not globally: two settlements of the same name in
     * different LGAs is ordinary in Adamawa, two in the same one splits a
     * community's farmers across duplicate records.
     */
    public function test_a_community_name_is_unique_within_its_lga_only(): void
    {
        $supervisor = $this->makeUser('Duplicate Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $lgas = $this->asSystem(fn () => Lga::query()->orderBy('name')->limit(2)->get());

        /*
         * A name reference data does not already use. "Gurin" stood here until
         * it became a real seeded community of Fufore — which sorts first — and
         * the opening step of this test started colliding with the register it
         * was meant to be independent of.
         */
        $name = 'Ruga Njobdi';

        $this->post(route('communities.store'), ['lga_id' => $lgas[0]->id, 'name' => $name])
            ->assertSessionHasNoErrors();

        // Same name, same LGA — refused.
        $this->post(route('communities.store'), ['lga_id' => $lgas[0]->id, 'name' => $name])
            ->assertSessionHasErrors('name');

        // Same name, different LGA — allowed.
        $this->post(route('communities.store'), ['lga_id' => $lgas[1]->id, 'name' => $name])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->asSystem(fn () => Community::query()
            ->where('name', $name)->count()));
    }

    /** Viewing is wider than creating. */
    public function test_a_sales_officer_sees_communities_but_cannot_add_one(): void
    {
        $officer = $this->makeUser('Viewing Sales Officer');
        $this->assignRole($officer, 'Sales Officer', ScopeType::Own);
        $this->actingAs($officer->fresh());

        $this->get(route('communities.index'))->assertOk()->assertDontSee('+ Add community');

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('communities.store'), ['lga_id' => $lga->id, 'name' => 'Should Not Exist'])
            ->assertStatus(403);

        $this->assertSame(0, $this->asSystem(fn () => Community::query()
            ->where('name', 'Should Not Exist')->count()));
    }

    /**
     * A point needs a community and a centre. Opening one in a new settlement
     * used to mean abandoning the half-typed form, creating the community
     * elsewhere, and starting again.
     */
    public function test_a_point_can_create_its_community_and_center_in_the_same_submit(): void
    {
        $supervisor = $this->makeUser('Inline Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        // The controls are on the form.
        $this->get(route('collection-points.index'))
            ->assertOk()
            ->assertSee('Community not listed?')
            ->assertSee('Center not listed?');

        $this->post(route('collection-points.store'), [
            'code' => 'PT-WURO',
            'name' => 'Wuro Bokki Point',
            // Neither picker is used — both are created here.
            'new_community_name' => 'Wuro Bokki',
            'new_community_lga_id' => $lga->id,
            'new_center_code' => 'CTR-NEW',
            'new_center_name' => 'New Centre',
            'new_center_lga_id' => $lga->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $point = $this->asSystem(fn () => CollectionPoint::query()->where('code', 'PT-WURO')->firstOrFail());
        $community = $this->asSystem(fn () => Community::query()->where('name', 'Wuro Bokki')->firstOrFail());
        $centre = $this->asSystem(fn () => CollectionCenter::query()->where('code', 'CTR-NEW')->firstOrFail());

        $this->assertSame($community->id, $point->community_id);
        $this->assertSame($centre->id, $point->collection_center_id);
        // The point inherits the community's LGA.
        $this->assertSame($lga->id, $point->lga_id);

        // Both creations are on the record, not silent.
        $this->assertDatabaseHas('audit_entries', ['module' => 'Community Engagement', 'event_type' => 'data_create']);
    }

    /** Mixing is fine: an existing community, a brand-new centre. */
    public function test_one_can_be_picked_and_the_other_created(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Mixed Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        $this->post(route('collection-points.store'), [
            'code' => 'PT-MIXED',
            'name' => 'Mixed Point',
            'community_id' => $world['communityA']->id,
            'new_center_code' => 'CTR-MIXED',
            'new_center_name' => 'Mixed Centre',
            'new_center_lga_id' => $lga->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $point = $this->asSystem(fn () => CollectionPoint::query()->where('code', 'PT-MIXED')->firstOrFail());

        $this->assertSame($world['communityA']->id, $point->community_id);
        $this->assertNotNull($point->collection_center_id);
    }

    /** Neither given: the pickers are still required. */
    public function test_a_point_still_needs_a_community_and_a_center(): void
    {
        $supervisor = $this->makeUser('Empty Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $this->post(route('collection-points.store'), [
            'code' => 'PT-EMPTY', 'name' => 'Nowhere', 'status' => 'active',
        ])->assertSessionHasErrors(['community_id', 'collection_center_id']);

        $this->assertSame(0, $this->asSystem(fn () => CollectionPoint::query()->where('code', 'PT-EMPTY')->count()));
    }

    /**
     * A failed point must not leave an orphan community behind — the whole thing
     * is one transaction.
     */
    public function test_a_rejected_point_creates_nothing(): void
    {
        $supervisor = $this->makeUser('Rollback Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $lga = $this->asSystem(fn () => Lga::query()->orderBy('name')->firstOrFail());

        // Duplicate code on the centre — the point cannot be created.
        $this->post(route('collection-points.store'), [
            'code' => 'PT-ROLLBACK', 'name' => 'Rollback Point',
            'new_community_name' => 'Orphan Community',
            'new_community_lga_id' => $lga->id,
            'new_center_code' => str_repeat('X', 40), // too long — fails validation
            'new_center_name' => 'Bad Centre',
            'new_center_lga_id' => $lga->id,
            'status' => 'active',
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->asSystem(fn () => Community::query()
            ->where('name', 'Orphan Community')->count()), 'A refused point must not leave a community behind.');
        $this->assertSame(0, $this->asSystem(fn () => CollectionPoint::query()
            ->where('code', 'PT-ROLLBACK')->count()));
    }

    /**
     * A point's agent could never be set: the field was validated and stored, but
     * no form offered it and no list of candidates was ever passed to a view. The
     * detail screen said "Agent: Unassigned" with no way to change it.
     */
    public function test_an_agent_can_be_assigned_to_a_point_at_creation(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Assigning Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $agent = $this->makeUser('Field Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $this->actingAs($supervisor->fresh());

        // The picker is on the form and offers the agent.
        $this->get(route('collection-points.index'))
            ->assertOk()
            ->assertSee('Collection agent')
            ->assertSee($agent->email);

        $this->post(route('collection-points.store'), [
            'code' => 'PT-AGENT',
            'name' => 'Staffed Point',
            'community_id' => $world['communityA']->id,
            'collection_center_id' => $world['centerA']->id,
            'agent_user_id' => $agent->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $point = $this->asSystem(fn () => CollectionPoint::query()->where('code', 'PT-AGENT')->firstOrFail());

        $this->assertSame($agent->id, $point->agent_user_id);
    }

    /** And changed afterwards — the update route had no form posting to it. */
    public function test_a_points_agent_can_be_changed_later(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Reassigning Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $first = $this->makeUser('First Agent');
        $this->assignRole($first, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $second = $this->makeUser('Second Agent');
        $this->assignRole($second, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $this->actingAs($supervisor->fresh());

        $point = $world['pointA'];

        // The Edit control exists on the row.
        $this->get(route('collection-points.index'))
            ->assertOk()
            ->assertSee('modal-edit-point-'.$point->id);

        $this->put(route('collection-points.update', $point), [
            'name' => $point->name,
            'agent_user_id' => $first->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame($first->id, $point->refresh()->agent_user_id);

        // Reassign, and unassign.
        $this->put(route('collection-points.update', $point), [
            'name' => $point->name, 'agent_user_id' => $second->id, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $point->refresh()->agent_user_id);

        $this->put(route('collection-points.update', $point), [
            'name' => $point->name, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertNull($point->refresh()->agent_user_id, 'A point can be left unstaffed.');
    }

    /**
     * Only staff who can actually record a delivery are offered. Naming anyone
     * else leaves a point that looks staffed and cannot take milk.
     */
    public function test_only_staff_who_can_record_a_delivery_are_offered_as_agents(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Filtering Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $agent = $this->makeUser('Eligible Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $accountant = $this->makeUser('Ineligible Accountant');
        $this->assignRole($accountant, 'Accounts');

        $this->actingAs($supervisor->fresh());

        $html = $this->get(route('collection-points.index'))->assertOk()->getContent();

        preg_match('/<select id="np-agent".*?<\/select>/s', $html, $picker);

        $this->assertNotEmpty($picker, 'The agent picker is missing.');
        $this->assertStringContainsString($agent->email, $picker[0]);
        $this->assertStringNotContainsString(
            $accountant->email,
            $picker[0],
            'Someone who cannot record a delivery must not be offerable as an agent.',
        );
    }

    /**
     * A point's page showed a farmer count and nothing else — no roster, no way
     * to say who delivers there. The farmer's home point is a column on the
     * farmer, so it was only reachable by editing each farmer individually.
     */
    public function test_a_farmer_can_be_assigned_to_a_point_from_its_page(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Roster Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $point = $world['pointA'];
        $farmer = $world['farmerB'];   // defaults to point B

        // The detail page shows a summary and links to the roster.
        $this->get(route('collection-points.show', $point))
            ->assertOk()
            ->assertSee('View all')
            ->assertSee(route('collection-points.farmers', $point), false);

        $this->get(route('collection-points.farmers', $point))->assertOk()->assertSee('Assign farmer');

        $this->post(route('collection-points.farmers.assign', $point), [
            'farmer_id' => $farmer->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($point->id, $farmer->refresh()->default_collection_point_id);

        // They now appear on the roster.
        $this->get(route('collection-points.farmers', $point))->assertOk()->assertSee($farmer->code);

        // A farmer delivers to one point, so this is a move, not a duplicate.
        $this->assertSame(1, $this->asSystem(fn () => Farmer::withoutDataScope()
            ->where('id', $farmer->id)->whereNotNull('default_collection_point_id')->count()));

        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Community Engagement',
            'event_type' => 'data_edit',
        ]);
    }

    public function test_a_farmer_can_be_removed_from_a_point(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Removing Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $point = $world['pointA'];
        $farmer = $world['farmer'];    // already defaults to point A

        $this->assertSame($point->id, $farmer->default_collection_point_id);

        $this->delete(route('collection-points.farmers.unassign', [$point, $farmer]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($farmer->refresh()->default_collection_point_id,
            'The farmer stays on the register with no home point.');
    }

    /** Removing a farmer who belongs to a different point is not possible. */
    public function test_a_farmer_cannot_be_removed_from_a_point_they_do_not_belong_to(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Wrong Point Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        // farmerB belongs to point B, not point A.
        $this->delete(route('collection-points.farmers.unassign', [$world['pointA'], $world['farmerB']]))
            ->assertNotFound();

        $this->assertSame($world['pointB']->id, $world['farmerB']->refresh()->default_collection_point_id);
    }

    /** The agent can be set from the point's own page. */
    public function test_an_agent_can_be_assigned_from_the_point_page(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Page Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $agent = $this->makeUser('Page Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        $this->actingAs($supervisor->fresh());

        $this->get(route('collection-points.show', $world['pointA']))
            ->assertOk()
            ->assertSee('id="ep-agent"', false)
            ->assertSee($agent->email);

        $this->put(route('collection-points.update', $world['pointA']), [
            'name' => $world['pointA']->name,
            'agent_user_id' => $agent->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame($agent->id, $world['pointA']->refresh()->agent_user_id);
    }

    /** Without either grant, the roster is read-only. */
    public function test_farmer_assignment_needs_a_grant(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Roster Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        if ($agent->fresh()->hasPermission('milk.points.edit')
            || $agent->fresh()->hasPermission('community.farmers.edit')) {
            $this->markTestSkipped('Collection Agent holds an assignment grant in this catalogue.');
        }

        $this->get(route('collection-points.farmers', $world['pointA']))
            ->assertOk()
            ->assertDontSee('Assign farmer');

        $this->post(route('collection-points.farmers.assign', $world['pointA']), [
            'farmer_id' => $world['farmerB']->id,
        ])->assertStatus(403);

        $this->assertSame($world['pointB']->id, $world['farmerB']->refresh()->default_collection_point_id);
    }

    /**
     * The roster is a screen of its own, and its assign picker is searched on the
     * server rather than shipped whole.
     *
     * Rendering every assignable farmer as an <option> made this page 317 KB, of
     * which 287 KB was the dropdown — on connections that drop. It is now about a
     * twentieth of that, and the options only exist once somebody has searched.
     */
    public function test_the_roster_does_not_ship_the_whole_farmer_register(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Weight Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        // No search yet: no options at all, and an instruction instead.
        $page = $this->get(route('collection-points.farmers', $world['pointA']));
        $page->assertOk()->assertSee('Search for the farmer by name or code');

        $this->assertSame(
            0,
            substr_count($page->getContent(), '<option'),
            'The assign picker must not ship the register.',
        );

        // Searching returns the match, and the modal reopens around it.
        $searched = $this->get(route('collection-points.farmers', $world['pointA'])
            .'?assign='.urlencode($world['farmerB']->name));

        $searched->assertOk()->assertSee($world['farmerB']->code);
        $this->assertMatchesRegularExpression(
            '/id="modal-assign-farmer" class="modal\s+open/',
            $searched->getContent(),
            'The modal should reopen around the search results.',
        );

        // A search that matches nothing says so rather than showing an empty box.
        $this->get(route('collection-points.farmers', $world['pointA']).'?assign=zzzznotafarmer')
            ->assertOk()
            ->assertSee('No farmer matches');
    }

    /** The roster paginates rather than rendering every row. */
    public function test_the_roster_paginates(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Paging Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        // More farmers than one page holds.
        $this->asSystem(function () use ($world): void {
            foreach (range(1, 30) as $index) {
                Farmer::query()->create([
                    'code' => 'FRM-P'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                    'name' => 'Paged Farmer '.$index,
                    'community_id' => $world['communityA']->id,
                    'lga_id' => $world['communityA']->lga_id,
                    'default_collection_point_id' => $world['pointA']->id,
                    'status' => 'active',
                ]);
            }
        });

        $page = $this->get(route('collection-points.farmers', $world['pointA']));

        $page->assertOk();
        $this->assertLessThan(
            31,
            substr_count($page->getContent(), '>Remove</button>'),
            'The roster must paginate, not render every farmer.',
        );
    }

    /**
     * SCOPE-1 — a point-scoped agent may enrol a farmer and still see it.
     *
     * The farmer scope for a point-scoped user is
     * `default_collection_point_id IN (their points)`. Leaving the point blank
     * saved a real farmer with a NULL point, which is in no list — so the
     * redirect to the new record answered 403 and the agent could not tell
     * whether the enrolment had happened. It had; it was simply invisible.
     */
    public function test_an_agent_who_enrols_a_farmer_can_still_see_them(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Enrolling Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $response = $this->post(route('farmers.store'), [
            '_modal' => 'modal-enrol',
            'code' => 'ENR-001',
            'name' => 'Newly Enrolled Farmer',
            'community_id' => $world['pointA']->community_id,
            // Deliberately blank — this is what the operator does.
            'default_collection_point_id' => null,
        ]);

        $farmer = Farmer::withoutGlobalScopes()->where('code', 'ENR-001')->first();

        $this->assertNotNull($farmer, 'The farmer is created.');
        $this->assertSame(
            (int) $world['pointA']->id,
            (int) $farmer->default_collection_point_id,
            'The point defaults to the one the enroller covers.',
        );

        /*
         * The redirect must land on the record, not on an access denial. The
         * trailing '#' is CloseModalAfterWrite clearing the fragment so the
         * modal does not reopen over the new page — see that middleware.
         */
        $this->assertSame(
            route('farmers.show', $farmer).'#',
            $response->headers->get('Location'),
        );
        $this->get(route('farmers.show', $farmer))->assertOk();

        // ...and the farmer appears in their own register.
        $this->assertTrue(
            Farmer::query()->whereKey($farmer->getKey())->exists(),
            'The enrolled farmer is inside the enroller\'s own scope.',
        );
    }

    /**
     * SCOPE-1 — where the enroller covers several points, the form must ask.
     * Guessing would put the farmer at the wrong point, and every delivery
     * recorded afterwards would inherit that mistake.
     */
    public function test_an_agent_covering_two_points_is_asked_which_one(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Two Point Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, null, [
            $world['pointA']->id, $world['pointB']->id,
        ]);
        $this->actingAs($agent);

        $response = $this->from(route('farmers.index'))->post(route('farmers.store'), [
            '_modal' => 'modal-enrol',
            'code' => 'ENR-002',
            'name' => 'Ambiguous Farmer',
            'community_id' => $world['pointA']->community_id,
        ]);

        $response->assertSessionHasErrors('default_collection_point_id');
        $this->assertNull(Farmer::withoutGlobalScopes()->where('code', 'ENR-002')->first());
    }

    /** A network-scoped enroller may still leave the point blank — they can see it either way. */
    public function test_a_network_scoped_enroller_may_leave_the_point_blank(): void
    {
        $world = $this->makeMilkWorld();

        $lead = $this->makeUser('Delivery Lead');
        $this->assignRole($lead, 'Community Engagement Officer', ScopeType::Network);
        $this->actingAs($lead);

        $this->post(route('farmers.store'), [
            '_modal' => 'modal-enrol',
            'code' => 'ENR-003',
            'name' => 'Unassigned Farmer',
            'community_id' => $world['pointA']->community_id,
        ]);

        $farmer = Farmer::withoutGlobalScopes()->where('code', 'ENR-003')->first();

        $this->assertNotNull($farmer);
        $this->assertNull($farmer->default_collection_point_id, 'Not forced on a network scope.');
        $this->get(route('farmers.show', $farmer))->assertOk();
    }

    /**
     * A screen must offer a route to the action it is about.
     *
     * An officer holding `milk.batch.dispatch.create` opened Batches — the
     * obvious screen — and found nothing: no dispatch action, and no link to the
     * centre screen where the action actually lives. The capability existed, the
     * permission was granted, and the interface said nothing. That is the same
     * shape as every "there is no button to do X" report this project has had.
     *
     * A batch is dispatched from a CENTRE, because it must be attributed to one,
     * so the fix is a signpost rather than a duplicate form. The test asserts the
     * signpost, not its wording.
     */
    public function test_the_batches_screen_leads_a_dispatcher_to_where_batches_are_dispatched(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Batch Dispatcher');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $this->assertTrue($officer->hasPermission('milk.batch.dispatch.create'));

        $response = $this->get(route('batches.index'));

        $response->assertOk();
        $response->assertSee(route('collection-centers.index'), false);
    }

    /** ...and somebody who cannot dispatch is not offered the route. */
    public function test_a_non_dispatcher_is_not_pointed_at_the_centre_screen(): void
    {
        $world = $this->makeMilkWorld();

        $auditor = $this->makeUser('Read Only Auditor');
        $this->assignRole($auditor, 'Internal Audit');
        $this->actingAs($auditor);

        $this->assertFalse($auditor->hasPermission('milk.batch.dispatch.create'));

        $html = $this->get(route('batches.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Dispatch from a center', $html);
    }

    /* ---------------------------------------------------------------------
     | Centres were create-only
     * ------------------------------------------------------------------ */

    /**
     * REF-1 — a change to master data is made through the screen and audited.
     *
     * `collection-centers.update` was routed behind milk.points.edit and no
     * Blade file posted to it, so reassigning a departing centre officer,
     * correcting a transport fee or suspending a centre all meant a direct
     * database write that the audit log never saw. `scope_type = center` is
     * resolved through officer_user_id, so a stale officer is also an access
     * defect and not merely a stale name on a card.
     */
    public function test_ref1_a_collection_center_is_edited_from_its_detail_screen(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Centre Editor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        $successor = $this->makeUser('Incoming Centre Officer');

        $this->get(route('collection-centers.show', $world['centerA']))
            ->assertOk()
            ->assertSee('modal-edit-center')
            ->assertSee(route('collection-centers.update', $world['centerA']), false);

        $this->put(route('collection-centers.update', $world['centerA']), [
            'code' => $world['centerA']->code,
            'name' => 'Kumbotso Central',
            'lga_id' => $world['centerA']->lga_id,
            'officer_user_id' => $successor->id,
            'cold_storage_litres' => '5000',
            'distance_to_factory_km' => '24',
            'transport_fee' => '9,250.50',
            'status' => 'suspended',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $center = $this->asSystem(fn () => CollectionCenter::query()->findOrFail($world['centerA']->id));

        $this->assertSame('Kumbotso Central', $center->name);
        $this->assertSame($successor->id, $center->officer_user_id);
        $this->assertSame('suspended', $center->status);
        // ARCH-6 — naira in, kobo stored, commas and all.
        $this->assertSame(9_250_50, (int) $center->transport_fee_minor);

        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Milk Collection',
            'event_type' => 'data_edit',
            'actor_user_id' => $supervisor->id,
        ]);
    }

    /** §18.3 — and the refusal. A view grant renders no control and opens no door. */
    public function test_ref1_a_center_cannot_be_edited_without_the_edit_grant(): void
    {
        $world = $this->makeMilkWorld();

        $lead = $this->makeUser('Delivery Lead Reader');
        $this->assignRole($lead, 'Delivery Lead');
        $this->actingAs($lead->fresh());

        $this->assertTrue($lead->fresh()->hasPermission('milk.points.view'));
        $this->assertFalse($lead->fresh()->hasPermission('milk.points.edit'));

        $this->get(route('collection-centers.show', $world['centerA']))
            ->assertOk()
            ->assertDontSee('modal-edit-center');

        $this->put(route('collection-centers.update', $world['centerA']), [
            'code' => $world['centerA']->code,
            'name' => 'Renamed By Nobody',
            'lga_id' => $world['centerA']->lga_id,
        ])->assertStatus(403);

        $this->assertSame(
            'Kumbotso',
            $this->asSystem(fn () => CollectionCenter::query()->findOrFail($world['centerA']->id)->name),
        );
    }

    /**
     * ARCH-4 layer 2 — holding the grant is not holding it everywhere. A
     * supervisor scoped to one centre is refused on another, with the audited
     * denial rather than a silent success.
     */
    public function test_arch4_a_centre_scoped_editor_is_refused_on_another_centre(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Kumbotso Only Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($supervisor->fresh());

        $this->put(route('collection-centers.update', $world['centerB']), [
            'code' => $world['centerB']->code,
            'name' => 'Not Theirs',
            'lga_id' => $world['centerB']->lga_id,
        ])->assertStatus(403);

        $this->assertSame(
            'Dawakin Tofa',
            $this->asSystem(fn () => CollectionCenter::query()->findOrFail($world['centerB']->id)->name),
        );

        $this->assertDatabaseHas('audit_entries', [
            'event_type' => 'blocked_access',
            'actor_user_id' => $supervisor->id,
        ]);
    }

    /* ---------------------------------------------------------------------
     | The agent picker
     * ------------------------------------------------------------------ */

    /**
     * NFR-1 — "no screen issues a number of queries proportional to its rows."
     *
     * The candidate-agent dropdown on /collection-points was built by loading
     * every active user and calling hasPermission() on each, which costs three
     * queries per candidate: the page ran 159 queries, 113 of them existing only
     * to fill that one control, and the count grew with the payroll rather than
     * with the network. Measured as a difference rather than a budget, because
     * the absolute number is nobody's contract — the SHAPE is: hiring twenty
     * people must not cost the screen a single extra query.
     */
    public function test_nfr1_the_agent_picker_costs_the_same_whatever_the_headcount(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Picker Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor->fresh());

        // Warm anything that is cached per process before either measurement.
        $this->get(route('collection-points.index'))->assertOk();

        $before = $this->queriesToRender(route('collection-points.index'));

        $this->asSystem(function () use ($world): void {
            for ($i = 0; $i < 20; $i++) {
                $hire = $this->makeUser('New Hire '.$i);
                $this->assignRole($hire, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
            }
        });

        $after = $this->queriesToRender(route('collection-points.index'));

        $this->assertSame(
            $before,
            $after,
            "Rendering the collection-point register cost {$before} queries at 1 agent and {$after} at 21. "
                .'The candidate picker is resolving permissions per user again.',
        );
    }

    /** ...and the list it renders is still only staff who can record a delivery. */
    public function test_the_agent_picker_lists_only_holders_of_the_delivery_grant(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Picker Supervisor Two');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $agent = $this->makeUser('Eligible Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        // Holds no grant that can record milk, so naming them would staff the
        // point with somebody who cannot enter anything against it.
        $auditor = $this->makeUser('Ineligible Auditor');
        $this->assignRole($auditor, 'External Audit');

        $this->assertTrue($agent->fresh()->hasPermission('milk.deliveries.create'));
        $this->assertFalse($auditor->fresh()->hasPermission('milk.deliveries.create'));

        $this->actingAs($supervisor->fresh());

        $html = $this->get(route('collection-points.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Eligible Agent', $html);
        $this->assertStringNotContainsString('Ineligible Auditor', $html);
    }

    /**
     * NFR-1 again, on the centre's own screen.
     *
     * `Consignment::adjustmentTotal()` runs one SUM per call and the "Adjustments
     * today" tile called it once per confirmed consignment. The centre detail
     * page has no pagination at all, so the tile's cost was a full day's
     * confirmations — on the busiest screen in the milk module. NFR-5 — the tile
     * still has to add up, so the value is asserted alongside the shape.
     */
    public function test_nfr1_the_centre_adjustment_tile_costs_one_query_not_one_per_consignment(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Centre Tile Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        $this->seedConfirmedConsignments($world, 1, '-2.00');

        $this->get(route('collection-centers.show', $world['centerA']))->assertOk();
        $before = $this->queriesToRender(route('collection-centers.show', $world['centerA']));

        $this->seedConfirmedConsignments($world, 5, '-1.50');

        $after = $this->queriesToRender(route('collection-centers.show', $world['centerA']));

        $this->assertSame(
            $before,
            $after,
            "The centre screen cost {$before} queries at 1 confirmed consignment and {$after} at 6.",
        );

        // −2.00 plus five × −1.50.
        $this->get(route('collection-centers.show', $world['centerA']))
            ->assertOk()
            ->assertSee('9.50 L');
    }

    /**
     * Written straight to the table rather than through the dispatch/confirm
     * services: this measures the RENDER, and driving six consignments through
     * the whole chain would measure the chain.
     *
     * @param  array<string, mixed>  $world
     */
    private function seedConfirmedConsignments(array $world, int $count, string $delta): void
    {
        $this->asSystem(function () use ($world, $count, $delta): void {
            $reason = AdjustmentReason::query()->where('code', 'ADJ-MEAS')->firstOrFail();
            // Graded, so the screen's grade-modal loop stays out of the count and
            // the measurement is of the adjustment tile alone.
            $grade = Grade::query()->assignable()->orderBy('position')->firstOrFail();

            for ($i = 0; $i < $count; $i++) {
                $consignment = Consignment::query()->create([
                    'reference' => 'CNS-TILE-'.Consignment::query()->count().'-'.$i,
                    'collection_point_id' => $world['pointA']->id,
                    'collection_center_id' => $world['centerA']->id,
                    'dispatched_at' => Wat::now()->subHours(2),
                    'litres_dispatched' => '40.00',
                    'confirmed_at' => Wat::now()->subHour(),
                    'litres_confirmed' => '40.00',
                    'litres_rejected_at_center' => '0.00',
                    'grade_id' => $grade->getKey(),
                    'rate_per_litre_minor' => 250_00,
                    'status' => Consignment::STATUS_CONFIRMED,
                ]);

                Adjustment::query()->create([
                    'adjustable_type' => $consignment->getMorphClass(),
                    'adjustable_id' => $consignment->getKey(),
                    'adjustment_reason_id' => $reason->getKey(),
                    'litres_delta' => $delta,
                    'explanation' => 'Re-measured against the centre can.',
                ]);
            }
        });
    }

    private function queriesToRender(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->get($url)->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
