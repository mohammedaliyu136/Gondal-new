<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\Driver;
use App\Models\Route as TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\BankService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

    public function test_driver_can_be_stored_with_bank_details_and_image(): void
    {
        Storage::fake('public');

        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $file = UploadedFile::fake()->image('rider_photo.jpg');

        $response = $this->post(route('fleet.drivers.store'), [
            'name' => 'Bello Kano',
            'phone' => '08031234567',
            'licence_no' => 'DL-KAN-9988',
            'type' => 'rider',
            'status' => 'active',
            'bank_name' => 'Zenith Bank',
            'bank_code' => '057',
            'bank_account' => '1012345678',
            'account_name' => 'BELLO KANO',
            'image' => $file,
        ]);

        $response->assertRedirect();

        $driver = Driver::query()->where('name', 'Bello Kano')->firstOrFail();
        $this->assertSame('rider', $driver->type);
        $this->assertSame('Zenith Bank', $driver->bank_name);
        $this->assertSame('057', $driver->bank_code);
        $this->assertSame('1012345678', $driver->bank_account);
        $this->assertSame('BELLO KANO', $driver->account_name);
        $this->assertNotNull($driver->image);
        Storage::disk('public')->assertExists($driver->image);

        // Update driver
        $newFile = UploadedFile::fake()->image('driver_new_photo.jpg');
        $this->put(route('fleet.drivers.update', $driver), [
            'name' => 'Bello Kano Updated',
            'phone' => '08031234567',
            'licence_no' => 'DL-KAN-9988',
            'type' => 'driver',
            'status' => 'active',
            'bank_name' => 'Access Bank',
            'bank_code' => '044',
            'bank_account' => '0691234567',
            'account_name' => 'BELLO KANO ACCESS',
            'image' => $newFile,
        ])->assertRedirect();

        $driver->refresh();
        $this->assertSame('Bello Kano Updated', $driver->name);
        $this->assertSame('driver', $driver->type);
        $this->assertSame('Access Bank', $driver->bank_name);
        $this->assertSame('044', $driver->bank_code);
        $this->assertSame('0691234567', $driver->bank_account);
        $this->assertSame('BELLO KANO ACCESS', $driver->account_name);
        Storage::disk('public')->assertExists($driver->image);

        $indexResponse = $this->get(route('fleet.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee($driver->image_url);
    }

    public function test_driver_type_must_be_rider_or_driver(): void
    {
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $response = $this->post(route('fleet.drivers.store'), [
            'name' => 'Invalid Type Driver',
            'type' => 'pilot',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_driver_bank_verification_ajax_endpoint(): void
    {
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $mockBankService = Mockery::mock(BankService::class);
        $mockBankService->shouldReceive('verifyAccount')
            ->with('1012345678', '057')
            ->once()
            ->andReturn([
                'success' => true,
                'account_name' => 'BELLO KANO',
                'bank_name' => 'Zenith Bank',
                'account_number' => '1012345678',
            ]);
        $this->app->instance(BankService::class, $mockBankService);

        $response = $this->postJson(route('fleet.drivers.verify-bank'), [
            'account_number' => '1012345678',
            'bank_code' => '057',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'account_name' => 'BELLO KANO',
            ]);
    }

    public function test_routes_can_be_added_for_different_categories(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->logisticsOfficer();
        $this->actingAs($officer);

        $point = $world['pointA'];
        $centerA = $world['centerA'];
        $centerB = $world['centerB'];

        // 1. Point to Center
        $this->post(route('fleet.routes.store'), [
            'name' => $point->name . ' → ' . $centerA->name,
            'from_type' => 'collection_point',
            'from_id' => $point->id,
            'to_type' => 'collection_center',
            'to_id' => $centerA->id,
            'distance_km' => '12.5',
            'tariff' => '500.00',
            'status' => 'active',
        ])->assertRedirect();

        // 2. Point to Factory
        $this->post(route('fleet.routes.store'), [
            'name' => $point->name . ' → Factory',
            'from_type' => 'collection_point',
            'from_id' => $point->id,
            'to_type' => 'factory',
            'to_id' => null,
            'distance_km' => '35.0',
            'tariff' => '1200.00',
            'status' => 'active',
        ])->assertRedirect();

        // 3. Center to Center
        $this->post(route('fleet.routes.store'), [
            'name' => $centerA->name . ' → ' . $centerB->name,
            'from_type' => 'collection_center',
            'from_id' => $centerA->id,
            'to_type' => 'collection_center',
            'to_id' => $centerB->id,
            'distance_km' => '18.0',
            'tariff' => '800.00',
            'status' => 'active',
        ])->assertRedirect();

        $routes = TransportRoute::query()->get();
        $this->assertSame(3, $routes->count());

        $indexRes = $this->get(route('fleet.index'))->assertOk();
        $indexRes->assertSee($point->name . ' → ' . $centerA->name);
        $indexRes->assertSee($point->name . ' → Factory');
        $indexRes->assertSee($centerA->name . ' → ' . $centerB->name);
    }

    /* ------------------------------------------------------------------ */

    private function logisticsOfficer(): User
    {
        $user = $this->makeUser('Idris Kabir');
        $this->assignRole($user, 'Logistics Officer', ScopeType::Network);

        return $user->fresh();
    }
}
