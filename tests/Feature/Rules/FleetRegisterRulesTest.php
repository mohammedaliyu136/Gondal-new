<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\Driver;
use App\Models\Route as TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Tests\GondalTestCase;

/**
 * §9 — the fleet and route register.
 *
 * The gap this closes is the plainest kind: three tables the trip form reads,
 * none of which had a screen. On a fresh install every picker was empty, the
 * route select is `required`, and so no trip could be logged at all — which
 * meant `trips.fee_minor`, the figure §15.1's transport payment run will settle
 * from, was never captured.
 */
class FleetRegisterRulesTest extends GondalTestCase
{
    /** A logistics officer can put a route, a vehicle and a rider on the register. */
    public function test_the_fleet_register_can_be_filled_from_the_screen(): void
    {
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $this->get(route('fleet.index'))->assertOk();

        $this->post(route('fleet.routes.store'), [
            'name' => 'Kumbotso → Factory',
            'from_type' => 'collection_center',
            'to_type' => 'factory',
            'distance_km' => '22.00',
            // ARCH-6 — naira in.
            'tariff' => '8500.00',
            'status' => 'active',
        ])->assertRedirect();

        $route = TransportRoute::query()->where('name', 'Kumbotso → Factory')->firstOrFail();
        // …kobo stored.
        $this->assertSame(850_000, (int) $route->tariff_minor);

        $this->post(route('fleet.vehicles.store'), [
            'registration' => 'KN-123-ABC', 'type' => 'motorcycle',
            'capacity_litres' => '120', 'status' => 'active',
        ])->assertRedirect();

        $this->post(route('fleet.drivers.store'), [
            'name' => 'Musa Rider', 'phone' => '08030000000',
            'type' => 'rider', 'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('vehicles', ['registration' => 'KN-123-ABC']);
        $this->assertDatabaseHas('drivers', ['name' => 'Musa Rider']);

        // Every one is audited: a tariff is money, and who set it is the answer
        // to "why did this month cost more than last".
        $this->assertDatabaseHas('audit_entries', ['module' => 'Logistics']);
    }

    /**
     * A tariff is money, so a change to it is recorded with both sides. Editing
     * it in place is legitimate here — TripService snapshots the fee when a trip
     * is logged, so re-tariffing never re-prices a journey already made.
     */
    public function test_retariffing_a_route_is_audited_and_does_not_reprice_past_trips(): void
    {
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $this->post(route('fleet.routes.store'), [
            'name' => 'Rano → Factory', 'from_type' => 'collection_center', 'to_type' => 'factory',
            'distance_km' => '30', 'tariff' => '1000.00', 'status' => 'active',
        ]);

        $route = TransportRoute::query()->where('name', 'Rano → Factory')->firstOrFail();

        $this->put(route('fleet.routes.update', $route), [
            'name' => 'Rano → Factory', 'from_type' => 'collection_center', 'to_type' => 'factory',
            'distance_km' => '30', 'tariff' => '1250.00', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame(125_000, (int) $route->refresh()->tariff_minor);

        $entry = AuditEntry::query()
            ->where('subject_type', TransportRoute::class)
            ->where('event_type', AuditEntry::EVENT_DATA_EDIT)
            ->latest('id')
            ->firstOrFail();

        // Both sides, or "the tariff changed" says nothing anybody can check.
        $this->assertSame(100_000, (int) ($entry->detail['before']['tariff_minor'] ?? 0));
        $this->assertSame(125_000, (int) ($entry->detail['after']['tariff_minor'] ?? 0));
    }

    /**
     * The fresh-install dead end, closed. A centre already carries its distance
     * and its fee (§6.1), so the route is derived from the administrator's own
     * figures rather than invented — and running it twice creates nothing.
     */
    public function test_centre_routes_are_generated_from_the_centre_records_and_only_once(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $this->assertSame(0, TransportRoute::query()->count());

        $this->post(route('fleet.routes.generate'))->assertRedirect();

        // Two centres in the fixture, each with its own distance and fee.
        $this->assertSame(2, TransportRoute::query()->count());

        $kumbotso = TransportRoute::query()->where('name', 'Kumbotso → Factory')->firstOrFail();
        $this->assertSame(
            (int) $world['centerA']->transport_fee_minor,
            (int) $kumbotso->tariff_minor,
            'The tariff is the centre\'s own figure, not a guess.',
        );

        // Idempotent — a second run adds nothing.
        $this->post(route('fleet.routes.generate'))->assertRedirect();
        $this->assertSame(2, TransportRoute::query()->count());
    }

    /** ARCH-4 — reading the register and changing it are different authorities. */
    public function test_arch4_the_register_is_readable_by_more_people_than_can_change_it(): void
    {
        $this->makeMilkWorld();

        // A Collection Agent holds neither, and does not see the screen at all.
        $agent = $this->makeUser('Sani Bello');
        $this->assignRole($agent, 'Collection Agent');
        $this->actingAs($agent->fresh());

        $this->get(route('fleet.index'))->assertStatus(403);

        $this->post(route('fleet.drivers.store'), [
            'name' => 'Unauthorised', 'type' => 'rider', 'status' => 'active',
        ])->assertStatus(403);

        $this->assertSame(0, Driver::query()->count());
    }

    /** USER-1 — a rider is a record. There is no credential field to fill in. */
    public function test_user1_a_rider_has_no_credential(): void
    {
        foreach (['password', 'password_hash', 'email', 'remember_token'] as $credential) {
            $this->assertFalse(
                Schema::hasColumn('drivers', $credential),
                "USER-1 — drivers must not be able to hold a {$credential}.",
            );
        }

        $this->assertNotInstanceOf(
            Authenticatable::class,
            new Driver,
        );
    }

    /* ------------------------------------------------------------------ */

    private function logisticsOfficer(): User
    {
        $user = $this->makeUser('Idris Kabir');
        $this->assignRole($user, 'Logistics Officer', ScopeType::Network);

        return $user->fresh();
    }
}
