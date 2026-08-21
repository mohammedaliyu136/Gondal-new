<?php

namespace Tests\Feature;

use App\Models\CollectionCenter;
use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\FarmerWallet;
use App\Models\FarmerWalletTransaction;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Finance\FarmerWalletService;
use App\Services\Payment\Modules\FarmerPaymentService;
use Tests\GondalTestCase;

class FarmerPaymentDisbursementTest extends GondalTestCase
{
    private User $accountsUser;
    private CollectionCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $lga = \App\Models\Lga::first() ?? \App\Models\Lga::create(['name' => 'Mayo Belwa', 'code' => 'MBW', 'state' => 'Adamawa']);

        $this->center = CollectionCenter::create([
            'code' => 'CC-TEST-01',
            'name' => 'Mayo Belwa Center',
            'lga_id' => $lga->id,
            'is_active' => true,
        ]);

        $this->accountsUser = $this->makeUser('Accounts Officer');
        $this->assignRole($this->accountsUser, 'Accounts');
        $this->assignRole($this->accountsUser, 'Milk Collection Officer', \App\Authorization\ScopeType::Center, $this->center->id);
    }

    private function createApprovedRun(Farmer $farmer1, Farmer $farmer2): PaymentRun
    {
        $run = PaymentRun::create([
            'reference' => 'RUN-2026-08-TEST',
            'scope_type' => PaymentRun::SCOPE_CENTER,
            'scope_id' => $this->center->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'status' => PaymentRun::STATUS_APPROVED,
            'gross_total_minor' => 15000000,
            'deductions_total_minor' => 1000000,
            'net_total_minor' => 14000000,
            'cash_required_minor' => 14000000,
            'farmer_count' => 2,
            'held_count' => 0,
            'run_by_user_id' => $this->accountsUser->id,
            'approved_by_user_id' => $this->accountsUser->id,
            'approved_at' => now(),
        ]);

        FarmerPayment::create([
            'payment_run_id' => $run->id,
            'farmer_id' => $farmer1->id,
            'litres_paid' => 100.0,
            'gross_minor' => 8000000,
            'net_minor' => 7500000,
            'status' => FarmerPayment::STATUS_PAYABLE,
        ]);

        FarmerPayment::create([
            'payment_run_id' => $run->id,
            'farmer_id' => $farmer2->id,
            'litres_paid' => 90.0,
            'gross_minor' => 7000000,
            'net_minor' => 6500000,
            'status' => FarmerPayment::STATUS_PAYABLE,
        ]);

        return $run;
    }

    public function test_farmer_payment_service_creates_and_disburses_batch_with_wallet_deduction(): void
    {
        $community = \App\Models\Community::first();

        $farmer1 = Farmer::create([
            'code' => 'FAR-001',
            'name' => 'Bello Jauro',
            'community_id' => $community->id,
            'lga_id' => $community->lga_id,
            'status' => 'validated',
            'bank_name' => 'Access Bank',
            'bank_account_number' => '0123456789',
            'bank_account_name' => 'Bello Jauro',
        ]);

        $farmer2 = Farmer::create([
            'code' => 'FAR-002',
            'name' => 'Usman Alkali',
            'community_id' => $community->id,
            'lga_id' => $community->lga_id,
            'status' => 'validated',
            'bank_name' => 'GTBank',
            'bank_account_number' => '0987654321',
            'bank_account_name' => 'Usman Alkali',
        ]);

        // Pre-credit their wallets with milk delivery values
        $walletService = app(FarmerWalletService::class);
        $walletService->credit($farmer1, 10000000, FarmerWalletTransaction::TYPE_CREDIT, null, 'Milk delivery', $this->accountsUser);
        $walletService->credit($farmer2, 8000000, FarmerWalletTransaction::TYPE_CREDIT, null, 'Milk delivery', $this->accountsUser);

        $wallet1 = FarmerWallet::where('farmer_id', $farmer1->id)->first();
        $this->assertEquals(10000000, $wallet1->balance_minor);

        $run = $this->createApprovedRun($farmer1, $farmer2);

        /** @var FarmerPaymentService $service */
        $service = app(FarmerPaymentService::class);

        // 1. Create batch
        $batch = $service->createBatch($run, 'bank_transfer', $this->accountsUser, 'Test disbursement batch');
        $this->assertEquals(PaymentBatch::STATUS_INITIALIZED, $batch->status);
        $this->assertEquals(14000000, $batch->total_amount_minor);
        $this->assertEquals(2, $batch->total_items_count);

        // 2. Disburse batch
        $disbursedBatch = $service->disburseBatch($batch);

        $this->assertEquals(PaymentBatch::STATUS_COMPLETED, $disbursedBatch->status);
        $this->assertEquals(2, $disbursedBatch->successful_items_count);

        // 3. Verify Farmer 1 Wallet Deduction
        $wallet1->refresh();
        // 10,000,000 - 7,500,000 = 2,500,000
        $this->assertEquals(2500000, $wallet1->balance_minor);
        $this->assertEquals(7500000, $wallet1->total_debited_minor);

        $tx1 = FarmerWalletTransaction::where('farmer_id', $farmer1->id)
            ->where('type', FarmerWalletTransaction::TYPE_DEBIT)
            ->first();
        $this->assertNotNull($tx1);
        $this->assertEquals(7500000, $tx1->amount_minor);
        $this->assertEquals(10000000, $tx1->balance_before_minor);
        $this->assertEquals(2500000, $tx1->balance_after_minor);

        // 4. Verify Farmer 2 Wallet Deduction
        $wallet2 = FarmerWallet::where('farmer_id', $farmer2->id)->first();
        // 8,000,000 - 6,500,000 = 1,500,000
        $this->assertEquals(1500000, $wallet2->balance_minor);

        // 5. Verify FarmerPaymentDisbursement rows
        $this->assertDatabaseHas('farmer_payment_disbursements', [
            'amount_minor' => 7500000,
            'method' => FarmerPaymentDisbursement::METHOD_BANK,
        ]);
        $this->assertDatabaseHas('farmer_payment_disbursements', [
            'amount_minor' => 6500000,
            'method' => FarmerPaymentDisbursement::METHOD_BANK,
        ]);

        // 6. Verify payments and run statuses
        $payment1 = FarmerPayment::where('farmer_id', $farmer1->id)->where('payment_run_id', $run->id)->first();
        $this->assertEquals(FarmerPayment::STATUS_PAID, $payment1->status);

        $run->refresh();
        $this->assertEquals(PaymentRun::STATUS_PAID, $run->status);
    }

    public function test_controller_disburse_batch_endpoint(): void
    {
        $community = \App\Models\Community::first();

        $farmer = Farmer::create([
            'code' => 'FAR-003',
            'name' => 'Amina Adamu',
            'community_id' => $community->id,
            'lga_id' => $community->lga_id,
            'status' => 'validated',
            'bank_name' => 'Zenith Bank',
            'bank_account_number' => '2001122334',
        ]);

        $walletService = app(FarmerWalletService::class);
        $walletService->credit($farmer, 5000000, FarmerWalletTransaction::TYPE_CREDIT, null, 'Milk delivery', $this->accountsUser);

        $run = PaymentRun::create([
            'reference' => 'RUN-2026-08-CTR',
            'scope_type' => PaymentRun::SCOPE_CENTER,
            'scope_id' => $this->center->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'status' => PaymentRun::STATUS_APPROVED,
            'gross_total_minor' => 4000000,
            'deductions_total_minor' => 0,
            'net_total_minor' => 4000000,
            'cash_required_minor' => 4000000,
            'farmer_count' => 1,
            'held_count' => 0,
            'run_by_user_id' => $this->accountsUser->id,
        ]);

        $payment = FarmerPayment::create([
            'payment_run_id' => $run->id,
            'farmer_id' => $farmer->id,
            'litres_paid' => 50.0,
            'gross_minor' => 4000000,
            'net_minor' => 4000000,
            'status' => FarmerPayment::STATUS_PAYABLE,
        ]);

        $response = $this->actingAs($this->accountsUser)
            ->from(route('payment-runs.show', $run))
            ->post(route('payment-runs.disburse', $run), [
                'gateway' => 'bank_transfer',
                'notes' => 'Batch payout via test',
                'selected_payments' => [$payment->id],
                'amounts' => [$payment->id => 40000.00],
            ]);

        $response->assertRedirect(route('payment-runs.show', $run));
        $response->assertSessionHas('success');

        // Check wallet
        $wallet = FarmerWallet::where('farmer_id', $farmer->id)->first();
        $this->assertEquals(1000000, $wallet->balance_minor);

        $this->assertDatabaseHas('payment_batches', [
            'source_id' => $run->id,
            'total_amount_minor' => 4000000,
            'status' => PaymentBatch::STATUS_COMPLETED,
        ]);
    }

    public function test_batch_detail_view_and_gateway_sync(): void
    {
        $community = \App\Models\Community::first();

        $farmer = Farmer::create([
            'code' => 'FAR-004',
            'name' => 'Garba Shehu',
            'community_id' => $community->id,
            'lga_id' => $community->lga_id,
            'status' => 'validated',
            'bank_name' => 'First Bank',
            'bank_account_number' => '3001122334',
        ]);

        $walletService = app(FarmerWalletService::class);
        $walletService->credit($farmer, 3000000, FarmerWalletTransaction::TYPE_CREDIT, null, 'Milk delivery', $this->accountsUser);

        $run = PaymentRun::create([
            'reference' => 'RUN-2026-08-SYNC',
            'scope_type' => PaymentRun::SCOPE_CENTER,
            'scope_id' => $this->center->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'status' => PaymentRun::STATUS_APPROVED,
            'gross_total_minor' => 2000000,
            'deductions_total_minor' => 0,
            'net_total_minor' => 2000000,
            'cash_required_minor' => 2000000,
            'farmer_count' => 1,
            'held_count' => 0,
            'run_by_user_id' => $this->accountsUser->id,
        ]);

        $payment = FarmerPayment::create([
            'payment_run_id' => $run->id,
            'farmer_id' => $farmer->id,
            'litres_paid' => 25.0,
            'gross_minor' => 2000000,
            'net_minor' => 2000000,
            'status' => FarmerPayment::STATUS_PAYABLE,
        ]);

        /** @var FarmerPaymentService $service */
        $service = app(FarmerPaymentService::class);
        $batch = $service->createBatch($run, 'bank_transfer', $this->accountsUser, 'Batch sync test');

        // View payment run page
        $showResponse = $this->actingAs($this->accountsUser)->get(route('payment-runs.show', $run));
        $showResponse->assertOk();
        $showResponse->assertSee('Initiate Payout');
        $showResponse->assertSee('Garba Shehu');

        // View batch page
        $response = $this->actingAs($this->accountsUser)->get(route('payment-runs.batches.show', [$run, $batch]));
        $response->assertOk();
        $response->assertSee($batch->batch_reference);
        $response->assertSee('Garba Shehu');
        $response->assertSee('First Bank');

        // Sync batch
        $syncResponse = $this->actingAs($this->accountsUser)->post(route('payment-runs.batches.sync', [$run, $batch]));
        $syncResponse->assertRedirect();
    }

    public function test_initiating_new_batch_cancels_previous_pending_batch_to_prevent_duplicate_disbursement(): void
    {
        $community = \App\Models\Community::first();

        $farmer = Farmer::create([
            'code' => 'FAR-005',
            'name' => 'Yakubu Gowon',
            'community_id' => $community->id,
            'lga_id' => $community->lga_id,
            'status' => 'validated',
            'bank_name' => 'Zenith Bank',
            'bank_account_number' => '2001122339',
        ]);

        $run = PaymentRun::create([
            'reference' => 'RUN-2026-08-DUP',
            'scope_type' => PaymentRun::SCOPE_CENTER,
            'scope_id' => $this->center->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-15',
            'status' => PaymentRun::STATUS_APPROVED,
            'gross_total_minor' => 5000000,
            'deductions_total_minor' => 0,
            'net_total_minor' => 5000000,
            'cash_required_minor' => 5000000,
            'farmer_count' => 1,
            'held_count' => 0,
            'run_by_user_id' => $this->accountsUser->id,
        ]);

        $payment = FarmerPayment::create([
            'payment_run_id' => $run->id,
            'farmer_id' => $farmer->id,
            'litres_paid' => 50.0,
            'gross_minor' => 5000000,
            'net_minor' => 5000000,
            'status' => FarmerPayment::STATUS_PAYABLE,
        ]);

        /** @var FarmerPaymentService $service */
        $service = app(FarmerPaymentService::class);

        // 1. Create first batch (left in initialized/pending state)
        $batch1 = $service->createBatch($run, 'bank_transfer', $this->accountsUser, 'Batch 1');
        $this->assertEquals(PaymentBatch::STATUS_INITIALIZED, $batch1->status);
        $this->assertEquals(1, $batch1->items()->where('status', PaymentBatchItem::STATUS_INITIALIZED)->count());

        // 2. Create second batch for same run
        $batch2 = $service->createBatch($run, 'bank_transfer', $this->accountsUser, 'Batch 2 (re-initialized)');
        $this->assertEquals(PaymentBatch::STATUS_INITIALIZED, $batch2->status);

        // 3. Verify batch 1 was automatically cancelled to prevent double disbursement
        $batch1->refresh();
        $this->assertEquals(PaymentBatch::STATUS_CANCELLED, $batch1->status);
        $this->assertEquals(1, $batch1->items()->where('status', PaymentBatchItem::STATUS_CANCELLED)->count());

        // 4. Test manual cancellation of batch 2 via controller endpoint
        $response = $this->actingAs($this->accountsUser)->post(route('payment-runs.batches.cancel', [$run, $batch2]), [
            'reason' => 'User aborted batch',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch2->refresh();
        $this->assertEquals(PaymentBatch::STATUS_CANCELLED, $batch2->status);
        $this->assertEquals(1, $batch2->items()->where('status', PaymentBatchItem::STATUS_CANCELLED)->count());
    }
}
