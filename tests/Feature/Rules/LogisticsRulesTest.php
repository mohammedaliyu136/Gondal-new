<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\CollectionCenter;
use App\Models\Driver;
use App\Models\Route as TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Logistics\TripService;
use Tests\GondalTestCase;

/**
 * §6.3 — logistics: trips, routes, riders and vehicles.
 *
 * The whole module had no test of any kind. `POST logistics/trips` was one of
 * the 54 unreferenced write endpoints, which is how the two defects proved here
 * survived: a trip had no location of its own, and its write skipped ARCH-4's
 * second layer entirely.
 */
class LogisticsRulesTest extends GondalTestCase
{
    /**
     * SCOPE-1 — "the scope attached to the permission narrows the records."
     *
     * A trip's geography used to be read through its ROUTE, and a route is a
     * tariff template: `routes.from_id`/`to_id` are nullable and every generic
     * point→center row is seeded with the types set and the ids NULL. Neither
     * branch of the old predicate could match a NULL, so a trip on a point→center
     * leg was visible only to a network-scoped user — and invisible to the
     * centre-scoped Logistics Officer who actually runs it.
     */
    public function test_scope1_a_trip_is_visible_to_the_centre_that_ran_it(): void
    {
        $world = $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $officer = $this->officerFor($world['centerA']);
        $trip = $this->logTrip($officer, $route, [
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'litres_carried' => '120.00',
        ]);

        // The route names nowhere, so only the trip's own endpoints can answer.
        $this->assertNull($route->from_id);
        $this->assertNull($route->to_id);

        $this->actingAs($officer->fresh());

        $this->assertTrue(
            Trip::query()->whereKey($trip->getKey())->exists(),
            'The centre-scoped officer who ran the leg cannot see their own trip.',
        );

        $this->get(route('logistics.index'))->assertOk()->assertSee($trip->reference);
    }

    /** SCOPE-1, the other half: another centre's officer must not see it. */
    public function test_scope1_a_trip_is_hidden_from_a_centre_that_did_not_run_it(): void
    {
        $world = $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $kumbotso = $this->officerFor($world['centerA']);
        $trip = $this->logTrip($kumbotso, $route, [
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
        ]);

        $dawakin = $this->officerFor($world['centerB'], 'Dawakin Logistics');

        $this->actingAs($dawakin->fresh());

        $this->assertFalse(
            Trip::query()->whereKey($trip->getKey())->exists(),
            'A trip leaked to a centre that had nothing to do with the leg.',
        );

        $this->get(route('logistics.index'))->assertOk()->assertDontSee($trip->reference);
    }

    /**
     * ARCH-4 — "authorisation in two distinct layers: permission check, and
     * scope check with the record in hand."
     *
     * `logistics.trips.create` on the route satisfied layer 1 and nothing asked
     * layer 2, so a Logistics Officer scoped to one centre could log a trip
     * anywhere in the network and have its fee counted into the queued transport
     * total. This is the refusal, from the screen, with the audit row §18.3 asks
     * for.
     */
    public function test_arch4_a_trip_on_another_centres_leg_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $kumbotso = $this->officerFor($world['centerA']);
        $this->actingAs($kumbotso->fresh());

        $this->post(route('logistics.trips.store'), [
            'route_id' => $route->id,
            // Dawakin Tofa's point — layer 1 passes, layer 2 must not.
            'collection_point_id' => $world['pointB']->id,
            'collection_center_id' => $world['centerB']->id,
            'litres_carried' => '90.00',
        ])->assertStatus(403);

        $this->assertSame(0, $this->asSystem(fn () => Trip::query()->count()));

        // BR-34 / AUDIT-5 — the denial is a recorded event, not a silent bounce.
        $this->assertDatabaseHas('audit_entries', [
            'event_type' => AuditEntry::EVENT_BLOCKED_ACCESS,
            'actor_user_id' => $kumbotso->id,
        ]);
    }

    /** ARCH-4 layer 2 lives in the service, so no future API surface can skip it. */
    public function test_arch4_the_scope_check_is_enforced_by_the_service_not_the_screen(): void
    {
        $world = $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $kumbotso = $this->officerFor($world['centerA']);
        $this->actingAs($kumbotso->fresh());

        $this->expectException(AccessDeniedException::class);

        app(TripService::class)->log($route, [
            'collection_point_id' => $world['pointB']->id,
        ], $kumbotso->fresh());
    }

    /**
     * SCOPE-1 — a trip with no endpoint at all belongs to nobody: it appears on
     * no scoped list and its cost can be set against no point. Refused at the
     * write rather than stored and lost.
     */
    public function test_scope1_a_trip_that_names_nowhere_is_refused(): void
    {
        $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $officer = $this->makeUser('Network Logistics');
        $this->assignRole($officer, 'Logistics Officer');
        $this->actingAs($officer->fresh());

        try {
            app(TripService::class)->log($route, ['litres_carried' => '10.00'], $officer->fresh());

            $this->fail('A trip with no resolvable location should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('SCOPE-1', $exception->ruleId);
            $this->assertSame('collection_center_id', $exception->field);
        }

        $this->assertSame(0, $this->asSystem(fn () => Trip::query()->count()));
    }

    /**
     * The success path, end to end from the screen.
     *
     * BR-14 in spirit — the route tariff is snapshotted onto the trip, so
     * re-tariffing the route later never moves a logged trip's fee.
     */
    public function test_a_logistics_officer_logs_a_trip_from_the_screen(): void
    {
        $world = $this->makeMilkWorld();
        $route = $this->makeUnlocatedPointToCentreRoute();

        $officer = $this->officerFor($world['centerA']);
        $this->actingAs($officer->fresh());

        [$vehicle, $driver] = $this->makeFleet();

        $this->get(route('logistics.index'))->assertOk()->assertSee('tr-center', false);

        $this->post(route('logistics.trips.store'), [
            'route_id' => $route->id,
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'litres_carried' => '118.00',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $trip = $this->asSystem(fn () => Trip::query()->latest('id')->firstOrFail());

        $this->assertSame($world['pointA']->id, $trip->collection_point_id);
        $this->assertSame($world['centerA']->id, $trip->collection_center_id);
        $this->assertSame(1_500_00, (int) $trip->fee_minor);
        $this->assertSame(1_500_00, (int) $trip->route_tariff_minor_snapshot);
        $this->assertSame(Trip::PAYMENT_QUEUED, $trip->payment_status);

        // Every write is audited, and the leg is in the entry.
        $this->assertDatabaseHas('audit_entries', [
            'module' => 'Logistics',
            'event_type' => 'data_create',
        ]);

        // Re-tariffing the route afterwards does not move the logged fee.
        $this->asSystem(fn () => $route->forceFill(['tariff_minor' => 9_000_00])->save());
        $this->assertSame(1_500_00, (int) $trip->fresh()->fee_minor);
    }

    /**
     * A route that DOES name its endpoints still locates the trip, so an
     * operator who leaves the pickers alone on a center→factory leg is not
     * blocked by a field they have nothing to say about.
     */
    public function test_a_route_that_names_its_endpoints_still_locates_the_trip(): void
    {
        $world = $this->makeMilkWorld();

        $route = $this->asSystem(fn () => TransportRoute::query()->create([
            'name' => 'Kumbotso → Factory',
            'from_type' => TransportRoute::ENDPOINT_CENTER,
            'from_id' => $world['centerA']->id,
            'to_type' => TransportRoute::ENDPOINT_FACTORY,
            'to_id' => null,
            'distance_km' => '22.00',
            'tariff_minor' => 4_000_00,
            'status' => 'active',
        ]));

        $officer = $this->officerFor($world['centerA']);
        $trip = $this->logTrip($officer, $route, ['litres_carried' => '3400.00']);

        $this->assertSame($world['centerA']->id, $trip->collection_center_id);
        $this->assertNull($trip->collection_point_id);
    }

    /**
     * A required picker that renders with nothing in it is a screen that cannot
     * be used, and the endpoint selects are new. Both must be populated for the
     * role whose whole job this screen is.
     */
    public function test_the_trip_forms_endpoint_pickers_are_populated(): void
    {
        $world = $this->makeMilkWorld();
        $this->makeUnlocatedPointToCentreRoute();

        $officer = $this->officerFor($world['centerA']);
        $this->actingAs($officer->fresh());

        $html = $this->get(route('logistics.index'))->assertOk()->getContent();

        foreach (['tr-center' => 'Collection center', 'tr-point' => 'Collection point'] as $id => $what) {
            preg_match('/<select id="'.$id.'".*?<\/select>/s', $html, $matches);

            $this->assertNotEmpty($matches, $what.' picker is missing from the trip form.');
            $this->assertGreaterThan(
                1,
                substr_count($matches[0], '<option'),
                $what.' picker rendered with nothing but its placeholder.',
            );
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * The shape DemoDataSeeder writes for the two generic point→center tariffs:
     * the endpoint TYPES are set and the ids are NULL, because the row prices a
     * class of leg rather than one pair of places.
     */
    private function makeUnlocatedPointToCentreRoute(): TransportRoute
    {
        return $this->asSystem(fn () => TransportRoute::query()->create([
            'name' => 'Point → Center (motorcycle)',
            'from_type' => TransportRoute::ENDPOINT_POINT,
            'from_id' => null,
            'to_type' => TransportRoute::ENDPOINT_CENTER,
            'to_id' => null,
            'distance_km' => '8.00',
            'tariff_minor' => 1_500_00,
            'vehicle_type' => 'motorcycle',
            'status' => 'active',
        ]));
    }

    private function officerFor(CollectionCenter $center, string $name = 'Kumbotso Logistics'): User
    {
        $officer = $this->makeUser($name);
        $this->assignRole($officer, 'Logistics Officer', ScopeType::Center, $center->id);

        return $officer;
    }

    /** @return array{0: Vehicle, 1: Driver} */
    private function makeFleet(): array
    {
        return $this->asSystem(fn (): array => [
            Vehicle::query()->create([
                'registration' => 'KAN-114-XA', 'type' => 'motorcycle',
                'capacity_litres' => '120.00', 'status' => 'active',
            ]),
            Driver::query()->create([
                'name' => 'Musa Garba', 'phone' => '08030000000',
                'type' => 'rider', 'status' => 'active',
            ]),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function logTrip(User $officer, TransportRoute $route, array $data): Trip
    {
        $this->actingAs($officer->fresh());

        return app(TripService::class)->log($route, $data, $officer->fresh());
    }
}
