<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Farmer;
use App\Models\Lga;
use App\Services\Payment\BankService;
use Mockery;
use Tests\GondalTestCase;

class FarmerBankVerificationTest extends GondalTestCase
{
    public function test_can_enrol_farmer_with_bank_details(): void
    {
        $lga = Lga::firstOrCreate(['name' => 'Kano Municipal'], ['code' => 'KNM']);
        $comm = Community::firstOrCreate(['name' => 'Gidan Danbaba', 'lga_id' => $lga->id]);

        $user = $this->makeUser('Extension Officer', ['lga_id' => $lga->id]);
        $this->assignRole($user, 'Extension Agent');

        $response = $this->actingAs($user)->post(route('farmers.store'), [
            'code' => 'FMR-TEST-001',
            'name' => 'Garba Danbaba',
            'gender' => 'male',
            'year_of_birth' => 1985,
            'phone' => '08011223344',
            'community_id' => $comm->id,
            'herd_size' => 12,
            'lactating_count' => 5,
            'payout_method' => 'bank',
            'bank_name' => 'Guaranty Trust Bank (GTBank)',
            'bank_code' => '058',
            'bank_account' => '0027330224',
            'account_name' => 'GARBA DANBABA',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('farmers', [
            'code' => 'FMR-TEST-001',
            'name' => 'Garba Danbaba',
            'bank_name' => 'Guaranty Trust Bank (GTBank)',
            'bank_code' => '058',
            'bank_account' => '0027330224',
            'account_name' => 'GARBA DANBABA',
            'bank_account_masked' => '002***224',
            'payout_method' => 'bank',
        ]);
    }

    public function test_can_verify_farmer_bank_account_via_ajax(): void
    {
        $user = $this->makeUser('Extension Officer');
        $this->assignRole($user, 'Extension Agent');

        $mockBankService = Mockery::mock(BankService::class);
        $mockBankService->shouldReceive('verifyAccount')
            ->with('0027330224', '058')
            ->andReturn([
                'success' => true,
                'account_name' => 'GARBA DANBABA',
                'account_number' => '0027330224',
                'bank_code' => '058',
                'bank_name' => 'Guaranty Trust Bank (GTBank)',
                'gateway' => 'paystack',
                'message' => null,
            ]);

        $this->app->instance(BankService::class, $mockBankService);

        $response = $this->actingAs($user)->postJson(route('farmers.verify-bank'), [
            'bank_code' => '058',
            'account_number' => '0027330224',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => true,
            'account_name' => 'GARBA DANBABA',
            'account_number' => '0027330224',
            'bank_code' => '058',
        ]);
    }

    public function test_can_update_farmer_bank_details(): void
    {
        $lga = Lga::firstOrCreate(['name' => 'Kano Municipal'], ['code' => 'KNM']);
        $comm = Community::firstOrCreate(['name' => 'Gidan Danbaba', 'lga_id' => $lga->id]);

        $user = $this->makeUser('Extension Officer', ['lga_id' => $lga->id]);
        $this->assignRole($user, 'Extension Agent');

        $farmer = Farmer::create([
            'code' => 'FMR-TEST-002',
            'name' => 'Amina Bello',
            'community_id' => $comm->id,
            'lga_id' => $lga->id,
            'payout_method' => 'cash',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->put(route('farmers.update', $farmer), [
            'name' => 'Amina Bello Updated',
            'phone' => '08099887766',
            'status' => 'active',
            'payout_method' => 'bank',
            'bank_name' => 'Zenith Bank',
            'bank_code' => '057',
            'bank_account' => '1029384756',
            'account_name' => 'AMINA BELLO',
        ]);

        $response->assertRedirect();
        $farmer->refresh();

        $this->assertSame('Amina Bello Updated', $farmer->name);
        $this->assertSame('Zenith Bank', $farmer->bank_name);
        $this->assertSame('057', $farmer->bank_code);
        $this->assertSame('1029384756', $farmer->bank_account);
        $this->assertSame('AMINA BELLO', $farmer->account_name);
        $this->assertSame('102***756', $farmer->bank_account_masked);
        $this->assertSame('bank', $farmer->payout_method);
    }
}
