<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Tests\GondalTestCase;

class ServiceProviderTest extends GondalTestCase
{
    public function test_user_with_permission_can_list_and_search_service_providers(): void
    {
        $user = $this->makeUser('Accounts User');
        $this->assignRole($user, 'Accounts');

        ServiceProvider::create([
            'name' => 'Zenith Power Systems ' . uniqid(),
            'email' => 'support@zenithpower.com',
            'contact' => '08031234567',
            'bank_name' => 'Zenith Bank',
            'bank_account' => '1012345678',
            'account_name' => 'Zenith Power Systems Ltd',
            'is_active' => true,
        ]);

        ServiceProvider::create([
            'name' => 'Global Logistics Inc ' . uniqid(),
            'email' => 'info@globallogistics.com',
            'bank_name' => 'Access Bank',
            'bank_account' => '0098765432',
            'account_name' => 'Global Logistics',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get(route('service-providers.index'));
        $response->assertOk();
        $response->assertSee('Zenith Power Systems');
        $response->assertSee('1012345678');
        $response->assertSee('Global Logistics Inc');

        // Test search filter
        $searchResponse = $this->actingAs($user)->get(route('service-providers.index', ['search' => 'Zenith Power Systems']));
        $searchResponse->assertOk();
        $searchResponse->assertSee('Zenith Power Systems');
    }

    public function test_can_create_service_provider_with_bank_and_billing_details(): void
    {
        Storage::fake('public');

        $user = $this->makeUser('Accounts Officer');
        $this->assignRole($user, 'Accounts');

        $uniqueName = 'Northern Feed Suppliers ' . uniqid();

        $response = $this->actingAs($user)->post(route('service-providers.store'), [
            'name' => $uniqueName,
            'email' => 'accounts@northernfeeds.ng',
            'contact' => '+234 802 345 6789',
            'bank_name' => 'First Bank of Nigeria',
            'bank_account' => '3012987654',
            'bank_code' => '011',
            'account_name' => 'Northern Feed Suppliers Limited',
            'is_active' => '1',
            'billing_name' => 'Alhaji Musa Danbaba',
            'billing_country' => 'Nigeria',
            'billing_state' => 'Kano',
            'billing_city' => 'Kano',
            'billing_phone' => '+234 802 345 6789',
            'billing_address' => 'Plot 42 Bompai Industrial Area',
        ]);

        $response->assertRedirect(route('service-providers.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('service_providers', [
            'name' => $uniqueName,
            'email' => 'accounts@northernfeeds.ng',
            'bank_name' => 'First Bank of Nigeria',
            'bank_account' => '3012987654',
            'account_name' => 'Northern Feed Suppliers Limited',
            'billing_city' => 'Kano',
            'is_active' => 1,
        ]);
    }

    public function test_can_update_and_delete_service_provider(): void
    {
        $user = $this->makeUser('Accounts Lead');
        $this->assignRole($user, 'Accounts');

        $provider = ServiceProvider::create([
            'name' => 'Old Supplier Name ' . uniqid(),
            'email' => 'old@supplier.com',
            'bank_name' => 'GTBank',
            'bank_account' => '0123456789',
            'is_active' => true,
        ]);

        $updatedName = 'Updated Premier Supplier ' . uniqid();

        $response = $this->actingAs($user)->put(route('service-providers.update', $provider), [
            'name' => $updatedName,
            'email' => 'new@premiersupplier.com',
            'bank_name' => 'Guaranty Trust Bank',
            'bank_account' => '0123456789',
            'account_name' => 'Premier Supplier Ltd',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('service-providers.index'));
        $this->assertSame($updatedName, $provider->fresh()->name);
        $this->assertSame('Guaranty Trust Bank', $provider->fresh()->bank_name);

        // Delete
        $deleteResponse = $this->actingAs($user)->delete(route('service-providers.destroy', $provider));
        $deleteResponse->assertRedirect(route('service-providers.index'));

        $this->assertSoftDeleted('service_providers', ['id' => $provider->id]);
    }

    public function test_can_fetch_banks_and_verify_bank_account_endpoint(): void
    {
        $user = $this->makeUser('Accounts Staff');
        $this->assignRole($user, 'Accounts');

        // Test banks list endpoint
        $banksResponse = $this->actingAs($user)->getJson(route('service-providers.banks'));
        $banksResponse->assertOk();
        $banksResponse->assertJsonFragment(['name' => 'Zenith Bank', 'code' => '057']);

        // Test bank verification endpoint validation
        $verifyResponse = $this->actingAs($user)->postJson(route('service-providers.verify-bank'), [
            'account_number' => '0123456789',
            'bank_code' => '058',
        ]);

        $this->assertContains($verifyResponse->status(), [200, 422]);
    }
}
