<?php

namespace Tests\Feature;

use App\Authorization\ScopeType;
use App\Models\Batch;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\DiscrepancyCause;
use App\Models\Farmer;
use App\Models\FarmerWallet;
use App\Models\FarmerWalletTransaction;
use App\Models\Grade;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Services\Finance\FarmerWalletService;
use App\Services\Milk\BatchService;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Support\Money;
use App\Support\Wat;
use Tests\GondalTestCase;

class FarmerWalletTest extends GondalTestCase
{
    public function test_farmer_has_wallet_and_can_be_lazily_created(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        $walletService = app(FarmerWalletService::class);
        $wallet = $walletService->getOrCreateWallet($farmer);

        $this->assertInstanceOf(FarmerWallet::class, $wallet);
        $this->assertSame($farmer->id, $wallet->farmer_id);
        $this->assertSame(0, $wallet->balance_minor);
        $this->assertSame(0, $wallet->total_credited_minor);
        $this->assertSame(0, $wallet->total_debited_minor);
        $this->assertSame(FarmerWallet::STATUS_ACTIVE, $wallet->status);
    }

    public function test_can_credit_and_debit_farmer_wallet_with_audit_transactions(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];
        $officer = $this->makeUser('Finance Officer');

        $walletService = app(FarmerWalletService::class);

        // Credit ₦25,000 (2,500,000 minor)
        $creditTxn = $walletService->credit(
            farmer: $farmer,
            amountMinor: 2500000,
            type: FarmerWalletTransaction::TYPE_CREDIT,
            source: null,
            description: 'Manual adjustment credit',
            actor: $officer,
        );

        $this->assertSame(2500000, $creditTxn->amount_minor);
        $this->assertSame(0, $creditTxn->balance_before_minor);
        $this->assertSame(2500000, $creditTxn->balance_after_minor);
        $this->assertSame(FarmerWalletTransaction::TYPE_CREDIT, $creditTxn->type);

        $wallet = $farmer->getOrCreateWallet()->fresh();
        $this->assertSame(2500000, $wallet->balance_minor);
        $this->assertSame(2500000, $wallet->total_credited_minor);
        $this->assertSame(0, $wallet->total_debited_minor);

        // Debit ₦10,000 (1,000,000 minor)
        $debitTxn = $walletService->debit(
            farmer: $farmer,
            amountMinor: 1000000,
            type: FarmerWalletTransaction::TYPE_DEBIT,
            source: null,
            description: 'Cash withdrawal payout',
            actor: $officer,
        );

        $this->assertSame(1000000, $debitTxn->amount_minor);
        $this->assertSame(2500000, $debitTxn->balance_before_minor);
        $this->assertSame(1500000, $debitTxn->balance_after_minor);

        $wallet->refresh();
        $this->assertSame(1500000, $wallet->balance_minor);
        $this->assertSame(2500000, $wallet->total_credited_minor);
        $this->assertSame(1000000, $wallet->total_debited_minor);

        $this->assertCount(2, $farmer->walletTransactions);
    }

    public function test_batch_reconciliation_automatically_credits_farmer_wallet_and_is_idempotent(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        $officer = $this->makeUser('Reconciliation Supervisor');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->assignRole($officer, 'Milk Collection Supervisor');
        $this->actingAs($officer);

        // 1. Deliver 100.00 Litres for farmer
        $delivery = app(DeliveryService::class)->record($world['pointA'], $farmer, [
            'litres_presented' => '100.00',
            'litres_rejected' => '0.00',
            'delivered_at' => Wat::todayAt(6, 0),
        ], $officer);

        // 2. Dispatch consignment
        $consignment = app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['dispatched_at' => Wat::todayAt(6, 45)],
            $officer,
        );

        // Quality tests
        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '17' : '1'),
                $officer,
            );
        }

        // Confirm consignment with Grade A
        $grade = Grade::query()->where('code', 'GRD-A')->firstOrFail();
        $consignment = app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'grade_id' => $grade->id,
        ], $officer);

        $rateMinor = (int) $consignment->rate_per_litre_minor;
        $this->assertGreaterThan(0, $rateMinor);

        // 3. Dispatch batch
        $batch = app(BatchService::class)->dispatch(
            $world['centerA'],
            [$consignment->id],
            ['dispatched_at' => Wat::todayAt(7, 30)],
            $officer,
        );

        $walletBefore = $farmer->getOrCreateWallet()->fresh();
        $this->assertSame(0, $walletBefore->balance_minor);

        // 4. Reconcile batch at factory
        $reconciled = app(BatchService::class)->reconcile($batch, [
            'litres_received' => '100.00',
            'discrepancy_cause_id' => null,
        ], $officer);

        $this->assertSame(Batch::STATUS_RECONCILED, $reconciled->status);

        // 5. Verify farmer wallet was credited!
        $walletAfter = $farmer->getOrCreateWallet()->fresh();
        $expectedCredit = Money::valueVolume('100.00', $rateMinor);

        $this->assertSame($expectedCredit, $walletAfter->balance_minor);
        $this->assertSame($expectedCredit, $walletAfter->total_credited_minor);

        // Check transaction record
        $transaction = FarmerWalletTransaction::query()
            ->where('farmer_id', $farmer->id)
            ->where('source_type', Delivery::class)
            ->where('source_id', $delivery->id)
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame($expectedCredit, $transaction->amount_minor);
        $this->assertSame('100.00', (string) $transaction->litres);
        $this->assertSame($rateMinor, $transaction->rate_per_litre_minor);
        $this->assertStringContainsString($batch->reference, $transaction->description);

        // 6. Test Idempotency: Re-running wallet crediting should not double-credit
        $walletService = app(FarmerWalletService::class);
        $result = $walletService->creditForReconciledBatch($batch, $officer);

        $this->assertSame(0, $result['total_credited_minor']);
        $this->assertSame(0, $result['deliveries_credited_count']);

        $walletAfterSecondRun = $farmer->fresh()->getOrCreateWallet();
        $this->assertSame($expectedCredit, $walletAfterSecondRun->balance_minor);
    }

    public function test_farmer_show_page_displays_wallet_balance_and_ledger(): void
    {
        $world = $this->makeMilkWorld();
        $farmer = $world['farmer'];

        $user = $this->makeUser('Finance Officer');
        $this->assignRole($user, 'Accounts');

        $walletService = app(FarmerWalletService::class);
        $walletService->credit(
            farmer: $farmer,
            amountMinor: 6500000,
            type: FarmerWalletTransaction::TYPE_CREDIT,
            source: null,
            description: 'Milk delivery payout credit',
            actor: $user,
            litres: '100.00',
            rateMinor: 65000,
        );

        $response = $this->actingAs($user)->get(route('farmers.show', $farmer));

        $response->assertOk();
        $response->assertSee('Farmer Wallet Balance');
        $response->assertSee('65,000.00');
        $response->assertSee('Wallet Activity &amp; Milk Earnings Ledger', false);
        $response->assertSee('Milk delivery payout credit');
    }
}
