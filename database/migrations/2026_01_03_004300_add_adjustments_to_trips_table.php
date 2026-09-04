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
        Schema::table('trips', function (Blueprint $table) {
            $table->unsignedBigInteger('plus_amount_minor')->default(0)->after('fee_minor');
            $table->string('plus_reason', 255)->nullable()->after('plus_amount_minor');
            $table->unsignedBigInteger('minus_amount_minor')->default(0)->after('plus_reason');
            $table->string('minus_reason', 255)->nullable()->after('minus_amount_minor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn([
                'plus_amount_minor',
                'plus_reason',
                'minus_amount_minor',
                'minus_reason',
            ]);
        });
    }
};
