<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('farmer_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_id')->unique()->constrained('farmers')->cascadeOnDelete();
            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('total_credited_minor')->default(0);
            $table->bigInteger('total_debited_minor')->default(0);
            $table->string('status', 32)->default('active');
            $table->string('currency', 8)->default('NGN');
            $table->timestamps();
        });

        Schema::create('farmer_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_wallet_id')->constrained('farmer_wallets')->cascadeOnDelete();
            $table->foreignId('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('type', 32); // credit, debit, adjustment
            $table->nullableMorphs('source'); // Delivery, Batch, FarmerPayment, etc.
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_before_minor');
            $table->bigInteger('balance_after_minor');
            $table->decimal('litres', 10, 2)->nullable();
            $table->integer('rate_per_litre_minor')->nullable();
            $table->string('description', 255);
            $table->json('meta')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farmer_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farmer_wallet_transactions');
        Schema::dropIfExists('farmer_wallets');
    }
};
