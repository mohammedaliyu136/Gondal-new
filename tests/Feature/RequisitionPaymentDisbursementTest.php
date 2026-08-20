<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PaymentBatch;
use App\Models\Requisition;
use App\Models\ServiceProvider;
use App\Services\Finance\RequisitionSpendService;
use App\Services\Purchases\RequisitionService;
use App\Support\Money;
use Tests\GondalTestCase;

class RequisitionPaymentDisbursementTest extends GondalTestCase
{
    public function test_only_approved_requisitions_are_listed_in_payments_index(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Accounting'], ['code' => 'ACC']);
        $user = $this->makeUser('Accounts Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $reqService = app(RequisitionService::class);

        // 1. Create an in-review requisition
        $inReviewReq = $reqService->create(
            ['title' => 'Office Stationery Draft', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [['item' => 'Paper Reams', 'quantity' => 10, 'unit' => 'packs', 'unit_price_minor' => Money::fromMajor(5000)]],
            $user
        );

        // 2. Create an approved requisition
        $provider = ServiceProvider::create([
            'name' => 'Apex Office Supplies Ltd ' . uniqid(),
            'bank_name' => 'Zenith Bank',
            'bank_account' => '1029384756',
            'account_name' => 'Apex Office Supplies Limited',
            'is_active' => true,
        ]);

        $approvedReq = $reqService->create(
            [
                'title' => 'Factory Equipment Spare Parts',
                'department_id' => $dept->id,
                'urgency' => 'urgent',
                'suggested_vendor' => $provider->name,
                'service_provider_id' => $provider->id,
            ],
            [['item' => 'Conveyor Belt Roller', 'quantity' => 2, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(75000)]],
            $user
        );
        $approvedReq->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_total_minor' => Money::fromMajor(150000),
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('requisition-payments.index'));
        $response->assertOk();
        $response->assertSee('Factory Equipment Spare Parts');
        $response->assertSee('Apex Office Supplies');
        $response->assertSee('1029384756');
        $response->assertDontSee('Office Stationery Draft');
    }

    public function test_can_disburse_requisition_payment_via_bank_transfer(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Logistics'], ['code' => 'LOG']);
        $user = $this->makeUser('Finance Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $provider = ServiceProvider::create([
            'name' => 'Kano Heavy Tyres Ltd ' . uniqid(),
            'bank_name' => 'First Bank of Nigeria',
            'bank_account' => '3098765432',
            'account_name' => 'Kano Heavy Tyres Limited',
            'is_active' => true,
        ]);

        $reqService = app(RequisitionService::class);
        $requisition = $reqService->create(
            [
                'title' => 'Truck Tyres Replacement',
                'department_id' => $dept->id,
                'urgency' => 'normal',
                'service_provider_id' => $provider->id,
            ],
            [['item' => 'Heavy Truck Tyre 12R22.5', 'quantity' => 4, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(80000)]],
            $user
        );
        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_total_minor' => Money::fromMajor(320000),
            'decided_at' => now(),
        ]);

        // Disburse full amount via direct bank transfer
        $response = $this->actingAs($user)->post(route('requisition-payments.disburse', $requisition), [
            'payment_method' => 'bank_transfer',
            'amount' => '320000.00',
            'notes' => 'Disbursed via Treasury Transfer Ref TT-9021',
        ]);

        $response->assertRedirect(route('requisition-payments.show', $requisition));
        $response->assertSessionHas('success');

        // Check PaymentBatch
        $this->assertDatabaseHas('payment_batches', [
            'source_type' => $requisition->getMorphClass(),
            'source_id' => $requisition->id,
            'gateway' => 'bank_transfer',
            'total_amount_minor' => Money::fromMajor(320000),
            'status' => PaymentBatch::STATUS_COMPLETED,
        ]);

        // Check RequisitionExpenditure
        $this->assertDatabaseHas('requisition_expenditures', [
            'requisition_id' => $requisition->id,
            'amount_minor' => Money::fromMajor(320000),
            'method' => 'bank',
        ]);

        $spendService = app(RequisitionSpendService::class);
        $this->assertSame(Money::fromMajor(320000), $spendService->spentMinor($requisition));
        $this->assertSame(0, $spendService->remainingMinor($requisition));
    }

    public function test_can_make_partial_disbursement_and_track_remaining_balance(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Maintenance'], ['code' => 'MNT']);
        $user = $this->makeUser('Disbursement Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $reqService = app(RequisitionService::class);
        $requisition = $reqService->create(
            [
                'title' => 'Generator Overhaul Service',
                'department_id' => $dept->id,
                'urgency' => 'urgent',
            ],
            [['item' => 'Service Labor & Oil Filter', 'quantity' => 1, 'unit' => 'job', 'unit_price_minor' => Money::fromMajor(200000)]],
            $user
        );
        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_total_minor' => Money::fromMajor(200000),
            'decided_at' => now(),
        ]);

        // Disburse advance payment of ₦80,000 (part 1)
        $this->actingAs($user)->post(route('requisition-payments.disburse', $requisition), [
            'payment_method' => 'bank_transfer',
            'amount' => '80000.00',
            'notes' => '40% advance payment for parts procurement',
        ]);

        $spendService = app(RequisitionSpendService::class);
        $this->assertSame(Money::fromMajor(80000), $spendService->spentMinor($requisition));
        $this->assertSame(Money::fromMajor(120000), $spendService->remainingMinor($requisition));

        // Disburse remaining payment of ₦120,000 (part 2)
        $this->actingAs($user)->post(route('requisition-payments.disburse', $requisition), [
            'payment_method' => 'bank_transfer',
            'amount' => '120000.00',
            'notes' => 'Final 60% settlement after service completion',
        ]);

        $this->assertSame(Money::fromMajor(200000), $spendService->spentMinor($requisition));
        $this->assertSame(0, $spendService->remainingMinor($requisition));
    }

    public function test_disbursement_exceeding_remaining_balance_is_rejected(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Admin'], ['code' => 'ADM']);
        $user = $this->makeUser('Finance Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $reqService = app(RequisitionService::class);
        $requisition = $reqService->create(
            [
                'title' => 'Office Cleaning Contract',
                'department_id' => $dept->id,
                'urgency' => 'normal',
            ],
            [['item' => 'Monthly Deep Cleaning', 'quantity' => 1, 'unit' => 'month', 'unit_price_minor' => Money::fromMajor(50000)]],
            $user
        );
        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_total_minor' => Money::fromMajor(50000),
            'decided_at' => now(),
        ]);

        // Attempting to disburse ₦60,000 on a ₦50,000 requisition
        $response = $this->actingAs($user)->post(route('requisition-payments.disburse', $requisition), [
            'payment_method' => 'bank_transfer',
            'amount' => '60000.00',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('payment_batches', [
            'source_id' => $requisition->id,
            'total_amount_minor' => Money::fromMajor(60000),
        ]);
    }

    public function test_can_view_payment_batch_receipt(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Operations'], ['code' => 'OPS']);
        $user = $this->makeUser('Treasury Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $reqService = app(RequisitionService::class);
        $requisition = $reqService->create(
            [
                'title' => 'Farm Storage Containers',
                'department_id' => $dept->id,
                'urgency' => 'normal',
            ],
            [['item' => 'Plastic Pallets', 'quantity' => 10, 'unit' => 'pcs', 'unit_price_minor' => Money::fromMajor(15000)]],
            $user
        );
        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_total_minor' => Money::fromMajor(150000),
            'decided_at' => now(),
        ]);

        $this->actingAs($user)->post(route('requisition-payments.disburse', $requisition), [
            'payment_method' => 'cash',
            'amount' => '150000.00',
            'notes' => 'Petty Cash disbursement receipt',
        ]);

        $batch = PaymentBatch::where('source_id', $requisition->id)->firstOrFail();

        $response = $this->actingAs($user)->get(route('requisition-payments.batch', $batch));
        $response->assertOk();
        $response->assertSee($batch->batch_reference);
        $response->assertSee('Cash');
        $response->assertSee('150,000.00');
    }

    public function test_can_disburse_multi_requisition_batch_with_partial_amounts(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Field Services'], ['code' => 'FLD']);
        $user = $this->makeUser('Senior Accountant', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $reqService = app(RequisitionService::class);
        $spendService = app(RequisitionSpendService::class);

        // Req 1 (₦100,000 approved)
        $req1 = $reqService->create(
            ['title' => 'Vaccines Batch 1', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [['item' => 'Cattle Vaccine', 'quantity' => 20, 'unit' => 'vials', 'unit_price_minor' => Money::fromMajor(5000)]],
            $user
        );
        $req1->update(['status' => Requisition::STATUS_APPROVED, 'approved_total_minor' => Money::fromMajor(100000), 'decided_at' => now()]);

        // Req 2 (₦200,000 approved)
        $req2 = $reqService->create(
            ['title' => 'Feed Supplements', 'department_id' => $dept->id, 'urgency' => 'normal'],
            [['item' => 'Mineral Blocks', 'quantity' => 50, 'unit' => 'blocks', 'unit_price_minor' => Money::fromMajor(4000)]],
            $user
        );
        $req2->update(['status' => Requisition::STATUS_APPROVED, 'approved_total_minor' => Money::fromMajor(200000), 'decided_at' => now()]);

        // Disburse batch: ₦100,000 full for Req 1 + ₦75,000 partial for Req 2 (Total batch: ₦175,000)
        $response = $this->actingAs($user)->post(route('requisition-payments.disburse-batch'), [
            'payment_method' => 'bank_transfer',
            'notes' => 'Combined weekly field services batch payout',
            'items' => [
                ['requisition_id' => $req1->id, 'amount' => '100000.00'],
                ['requisition_id' => $req2->id, 'amount' => '75000.00'],
            ],
        ]);

        $response->assertRedirect(route('requisition-payments.index'));
        $response->assertSessionHas('success');

        // Check Req 1
        $this->assertSame(Money::fromMajor(100000), $spendService->spentMinor($req1));
        $this->assertSame(0, $spendService->remainingMinor($req1));

        // Check Req 2
        $this->assertSame(Money::fromMajor(75000), $spendService->spentMinor($req2));
        $this->assertSame(Money::fromMajor(125000), $spendService->remainingMinor($req2));

        // Check unified batch creation
        $this->assertDatabaseHas('payment_batches', [
            'total_amount_minor' => Money::fromMajor(175000),
            'total_items_count' => 2,
            'successful_items_count' => 2,
            'status' => PaymentBatch::STATUS_COMPLETED,
        ]);
    }

    public function test_batch_in_processing_status_requires_otp_and_does_not_record_expenditure_until_authorized(): void
    {
        $dept = Department::firstOrCreate(['name' => 'IT'], ['code' => 'ITD']);
        $user = $this->makeUser('Finance Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $provider = ServiceProvider::create([
            'name' => 'Cloud Host Services Ltd',
            'bank_name' => 'GTBank',
            'bank_account' => '0123456789',
            'account_name' => 'Cloud Host Services Limited',
            'is_active' => true,
        ]);

        $reqService = app(RequisitionService::class);
        $spendService = app(RequisitionSpendService::class);

        $req = $reqService->create(
            [
                'title' => 'Annual Server Hosting Renewal',
                'department_id' => $dept->id,
                'urgency' => 'urgent',
                'service_provider_id' => $provider->id,
            ],
            [['item' => 'Dedicated Server Cluster', 'quantity' => 1, 'unit' => 'yr', 'unit_price_minor' => Money::fromMajor(450000)]],
            $user
        );
        $req->update(['status' => Requisition::STATUS_APPROVED, 'approved_total_minor' => Money::fromMajor(450000), 'decided_at' => now()]);

        // Create a batch that simulates awaiting OTP (status = processing)
        $batch = PaymentBatch::create([
            'batch_reference' => 'PB-REQ-TEST-OTP-1',
            'source_module' => 'requisition',
            'source_type' => $req->getMorphClass(),
            'source_id' => $req->id,
            'gateway' => 'bank_transfer',
            'currency' => 'NGN',
            'total_amount_minor' => Money::fromMajor(450000),
            'total_fee_minor' => 0,
            'total_items_count' => 1,
            'successful_items_count' => 0,
            'failed_items_count' => 0,
            'status' => PaymentBatch::STATUS_PROCESSING,
            'initiated_by_user_id' => $user->id,
            'disbursed_at' => now(),
            'meta' => [
                'requisitions' => [
                    ['id' => $req->id, 'amount_minor' => Money::fromMajor(450000)],
                ],
            ],
        ]);

        $batch->items()->create([
            'item_reference' => 'PBI-REQ-TEST-OTP-1',
            'recipient_type' => $req->getMorphClass(),
            'recipient_id' => $req->id,
            'recipient_name' => $provider->name,
            'recipient_account_number' => $provider->bank_account,
            'recipient_bank_code' => '058',
            'recipient_bank_name' => $provider->bank_name,
            'amount_minor' => Money::fromMajor(450000),
            'status' => \App\Models\PaymentBatchItem::STATUS_INITIALIZED,
            'narration' => 'Server Hosting Renewal',
        ]);

        // While in processing, the requisition must NOT be marked as spent!
        $this->assertSame(0, $spendService->spentMinor($req));
        $this->assertSame(Money::fromMajor(450000), $spendService->remainingMinor($req));
        $this->assertDatabaseMissing('requisition_expenditures', ['requisition_id' => $req->id]);

        // Submit OTP code to authorize and complete the batch
        $response = $this->actingAs($user)->post(route('requisition-payments.validate-batch-otp', $batch), [
            'otp' => '123456',
        ]);

        $response->assertRedirect(route('requisition-payments.batch', $batch));
        $response->assertSessionHas('success');

        // NOW the batch must be completed and the requisition expenditure recorded!
        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, $batch->successful_items_count);

        $this->assertSame(Money::fromMajor(450000), $spendService->spentMinor($req));
        $this->assertSame(0, $spendService->remainingMinor($req));
        $this->assertDatabaseHas('requisition_expenditures', [
            'requisition_id' => $req->id,
            'amount_minor' => Money::fromMajor(450000),
        ]);
    }

    public function test_can_sync_batch_status_with_gateway(): void
    {
        $dept = Department::firstOrCreate(['name' => 'Logistics'], ['code' => 'LOG']);
        $user = $this->makeUser('Finance Officer', ['department_id' => $dept->id]);
        $this->assignRole($user, 'Accounts');

        $provider = ServiceProvider::create([
            'name' => 'Kano Delivery Express',
            'bank_name' => 'Access Bank',
            'bank_account' => '0987654321',
            'account_name' => 'Kano Delivery Express',
            'is_active' => true,
        ]);

        $reqService = app(RequisitionService::class);
        $req = $reqService->create(
            [
                'title' => 'Courier Logistics Batch',
                'department_id' => $dept->id,
                'urgency' => 'normal',
                'service_provider_id' => $provider->id,
            ],
            [['item' => 'Delivery Service', 'quantity' => 1, 'unit' => 'trip', 'unit_price_minor' => Money::fromMajor(75000)]],
            $user
        );
        $req->update(['status' => Requisition::STATUS_APPROVED, 'approved_total_minor' => Money::fromMajor(75000), 'decided_at' => now()]);

        $batch = PaymentBatch::create([
            'batch_reference' => 'PB-REQ-SYNC-TEST-1',
            'source_module' => 'requisition',
            'source_type' => $req->getMorphClass(),
            'source_id' => $req->id,
            'gateway' => 'bank_transfer',
            'currency' => 'NGN',
            'total_amount_minor' => Money::fromMajor(75000),
            'total_fee_minor' => 0,
            'total_items_count' => 1,
            'successful_items_count' => 0,
            'failed_items_count' => 0,
            'status' => PaymentBatch::STATUS_INITIALIZED,
            'initiated_by_user_id' => $user->id,
            'disbursed_at' => now(),
        ]);

        $batch->items()->create([
            'item_reference' => 'PBI-REQ-SYNC-TEST-1',
            'recipient_type' => $req->getMorphClass(),
            'recipient_id' => $req->id,
            'recipient_name' => $provider->name,
            'recipient_account_number' => $provider->bank_account,
            'recipient_bank_code' => '044',
            'recipient_bank_name' => $provider->bank_name,
            'amount_minor' => Money::fromMajor(75000),
            'status' => \App\Models\PaymentBatchItem::STATUS_INITIALIZED,
            'narration' => 'Courier Logistics',
        ]);

        $response = $this->actingAs($user)->post(route('requisition-payments.sync-batch', $batch));
        $response->assertRedirect(route('requisition-payments.batch', $batch));
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_COMPLETED, $batch->status);
    }
}
