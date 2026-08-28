<?php

namespace Tests\Feature;

use App\Authorization\ScopeType;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\FarmerWalletTransaction;
use App\Models\Grade;
use App\Models\PaymentRun;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Milk\DeliveryService;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Volume;
use App\Support\Wat;
use Tests\GondalTestCase;

class DirectWalletCreditFlowTest extends GondalTestCase
{
    public function test_setting_can_be_toggled_in_admin_settings(): void
    {
        $admin = $this->makeUser('System Admin');
        $this->assignRole($admin, 'System Administrator');

        // Check initial state (default false)
        $this->assertFalse(Settings::boolean('milk.direct_wallet_credit_enabled', false));

        // Submit form enabling the direct wallet credit setting
        $response = $this->actingAs($admin)->from(route('admin.settings'))->put(route('admin.settings.update'), [
            'milk_delivery_cutoff_default' => '07:00',
            'milk_delivery_cutoff_latest_override' => '08:00',
            'milk_batch_discrepancy_tolerance_pct' => '1.0',
            'milk_direct_wallet_credit_enabled' => '1',
            'cooperative_default_savings_deduction_pct' => '5',
            'cooperative_default_levy_pct' => '2',
            'cooperative_default_social_contribution' => '250',
            'shop_low_stock_warning_enabled' => '1',
            'payment_default_gateway' => 'paystack',
            'payment_paystack_mode' => 'test',
            'payment_monnify_mode' => 'test',
            'payment_zainpay_mode' => 'test',
        ]);

        $response->assertRedirect(route('admin.settings'));
        $this->assertTrue(Settings::boolean('milk.direct_wallet_credit_enabled'));

        // Visit settings page to ensure toggle renders checked
        $viewResponse = $this->actingAs($admin)->get(route('admin.settings'));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Direct Farmer Wallet Crediting (Bypass Consignment Dispatch &amp; Batch Reconciliation)', false);
    }

    public function test_delivery_intake_automatically_credits_farmer_wallet_when_setting_is_enabled(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];
        $point = $world['pointA'];

        $agent = $this->makeUser('Collection Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->id);

        // Enable bypass direct crediting setting
        Settings::put(['milk.direct_wallet_credit_enabled' => true]);

        // Grade A rate from world setup
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();
        $rateMinor = (int) $gradeA->currentRate()->rate_per_litre_minor;
        $this->assertGreaterThan(0, $rateMinor);

        $walletBefore = $farmer->getOrCreateWallet()->fresh();
        $this->assertSame(0, $walletBefore->balance_minor);

        // Record a 50L delivery before cutoff
        $deliveredAt = Wat::todayAt(6)->format('Y-m-d H:i');

        $response = $this->actingAs($agent)->post(route('deliveries.store'), [
            'collection_point_id' => $point->id,
            'farmer_id' => $farmer->id,
            'delivered_at' => $deliveredAt,
            'litres_presented' => '50.00',
            'litres_rejected' => '0.00',
        ]);

        $delivery = Delivery::query()->latest('id')->first();
        $this->assertNotNull($delivery);
        $this->assertSame('50.00', (string) $delivery->litres_payable);
        $this->assertNull($delivery->consignment_id);

        $expectedCredit = Money::valueVolume('50.00', $rateMinor);

        $walletAfter = $farmer->getOrCreateWallet()->fresh();
        $this->assertSame($expectedCredit, $walletAfter->balance_minor);
        $this->assertSame($expectedCredit, $walletAfter->total_credited_minor);

        // Verify transaction record
        $txn = FarmerWalletTransaction::query()
            ->where('farmer_id', $farmer->id)
            ->where('source_type', $delivery->getMorphClass())
            ->where('source_id', $delivery->id)
            ->first();

        $this->assertNotNull($txn);
        $this->assertSame($expectedCredit, $txn->amount_minor);
        $this->assertSame(FarmerWalletTransaction::TYPE_CREDIT, $txn->type);
        $this->assertSame('50.00', $txn->litres);
        $this->assertSame($rateMinor, $txn->rate_per_litre_minor);
    }

    public function test_delivery_intake_does_not_credit_wallet_when_setting_is_disabled(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];
        $point = $world['pointA'];

        $agent = $this->makeUser('Collection Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->id);

        // Direct crediting setting is disabled
        Settings::put(['milk.direct_wallet_credit_enabled' => false]);

        $deliveredAt = Wat::todayAt(6)->format('Y-m-d H:i');

        $response = $this->actingAs($agent)->post(route('deliveries.store'), [
            'collection_point_id' => $point->id,
            'farmer_id' => $farmer->id,
            'delivered_at' => $deliveredAt,
            'litres_presented' => '40.00',
            'litres_rejected' => '0.00',
        ]);

        $wallet = $farmer->getOrCreateWallet()->fresh();
        $this->assertSame(0, $wallet->balance_minor);
        $this->assertSame(0, $wallet->total_credited_minor);
    }

    public function test_payment_run_can_be_generated_and_disbursed_for_direct_intake_deliveries(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];
        $point = $world['pointA'];
        $center = $world['centerA'];

        $agent = $this->makeUser('Collection Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->id);

        $accounts = $this->makeUser('Accounts Officer');
        $this->assignRole($accounts, 'Accounts');
        $this->assignRole($accounts, 'Milk Collection Officer', ScopeType::Center, $center->id);

        Settings::put(['milk.direct_wallet_credit_enabled' => true]);

        // Record a 100L delivery for the farmer before cut-off
        $deliveredAt = Wat::todayAt(6)->format('Y-m-d H:i');

        app(DeliveryService::class)->record($point, $farmer, [
            'delivered_at' => $deliveredAt,
            'litres_presented' => '100.00',
            'litres_rejected' => '0.00',
        ], $agent);

        $delivery = Delivery::query()->latest('id')->first();
        $this->assertNotNull($delivery);
        $this->assertNull($delivery->consignment_id);

        // Generate payment run via service or controller
        $runService = app(FarmerPaymentRunService::class);
        $unpaidCount = $runService->unpaidDeliveryCount($center, PaymentRun::SCOPE_CENTER);
        $this->assertGreaterThanOrEqual(1, $unpaidCount);

        $run = $runService->generate($center, $accounts, Wat::today()->startOfMonth()->toDateString(), Wat::today()->toDateString());

        $this->assertInstanceOf(PaymentRun::class, $run);
        $this->assertSame(PaymentRun::STATUS_DRAFT, $run->status);
        $this->assertSame(1, $run->farmer_count);

        $payment = $run->payments()->where('farmer_id', $farmer->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(100.00, (float) $payment->litres_paid);
        $this->assertGreaterThan(0, $payment->gross_minor);
        $this->assertGreaterThan(0, $payment->net_minor);

        // Verify breakdown line references Direct Intake
        $lines = $payment->breakdown['lines'] ?? [];
        $this->assertCount(1, $lines);
        $this->assertSame('Direct Intake', $lines[0]['consignment']);
        $this->assertSame($delivery->id, $lines[0]['delivery_id']);

        // Submit payment run for approval
        $runService->submitForApproval($run, $accounts);

        $this->assertSame(PaymentRun::STATUS_PROCESSING, $run->fresh()->status);
    }
}
