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
        Schema::table('drivers', function (Blueprint $table) {
            if (!Schema::hasColumn('drivers', 'image')) {
                $table->string('image')->nullable()->after('type');
            }
            if (!Schema::hasColumn('drivers', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('image');
            }
            if (!Schema::hasColumn('drivers', 'bank_code')) {
                $table->string('bank_code', 32)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('drivers', 'bank_account')) {
                $table->string('bank_account', 32)->nullable()->after('bank_code');
            }
            if (!Schema::hasColumn('drivers', 'account_name')) {
                $table->string('account_name')->nullable()->after('bank_account');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['image', 'bank_name', 'bank_code', 'bank_account', 'account_name']);
        });
    }
};
