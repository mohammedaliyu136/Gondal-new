<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->string('gateway_status', 64)->nullable()->after('gateway_batch_reference');
        });

        Schema::table('payment_batch_items', function (Blueprint $table): void {
            $table->string('gateway_status', 64)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_batch_items', function (Blueprint $table): void {
            $table->dropColumn('gateway_status');
        });

        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropColumn('gateway_status');
        });
    }
};
