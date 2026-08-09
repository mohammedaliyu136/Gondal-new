<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale rung up wrong had no remedy: no void, no return, nothing. The receipt
 * number existed only in a flash message, so the officer could not even find the
 * sale again, and a wrong milk-deduction stood permanently against a farmer's
 * next payment.
 *
 * Voiding is recorded ON the sale rather than by deleting it — the receipt was
 * given to a customer, so the record has to survive with its correction visible.
 * Every revenue and margin aggregate excludes voided sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('sold_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                ->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');

            $table->index('voided_at');
            $table->index('voided_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropIndex(['voided_at']);
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
