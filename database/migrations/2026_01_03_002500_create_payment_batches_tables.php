<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_reference')->unique(); // E.g. PB-PAY-20260815-A1B2C3D4
            $table->string('source_module');             // payroll, milk_collection, requisition, transport
            $table->nullableMorphs('source');            // Polymorphic to PayrollRun, PaymentRun, Requisition, etc.
            $table->string('gateway');                   // paystack, monnify, zainpay, bank_transfer, cash
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('total_amount_minor');      // Total net amount in minor units (kobo)
            $table->unsignedBigInteger('total_fee_minor')->default(0); // Total gateway transaction fee charged
            $table->unsignedInteger('total_items_count');
            $table->unsignedInteger('successful_items_count')->default(0);
            $table->unsignedInteger('failed_items_count')->default(0);
            $table->string('status', 32)->default('draft'); // draft, initialized, processing, completed, failed, partially_completed
            $table->string('gateway_batch_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_module', 'status']);
            $table->index(['gateway', 'status']);
        });

        Schema::create('payment_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained('payment_batches')->cascadeOnDelete();
            $table->string('item_reference')->unique(); // E.g. PBI-20260815-A1B2C3D4
            $table->nullableMorphs('recipient');        // Polymorphic to Employee, Farmer, Vendor, User
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_bank_code')->nullable();
            $table->string('recipient_bank_name')->nullable();
            $table->string('recipient_account_number');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->string('narration')->nullable();
            $table->string('status', 32)->default('pending'); // pending, initialized, successful, failed, reversed
            $table->string('gateway_reference')->nullable();
            $table->string('gateway_transfer_code')->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payment_batch_id', 'status']);
            $table->index('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_batch_items');
        Schema::dropIfExists('payment_batches');
    }
};
