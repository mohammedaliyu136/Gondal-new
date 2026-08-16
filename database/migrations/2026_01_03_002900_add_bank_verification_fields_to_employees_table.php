<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'bank_code')) {
                $table->string('bank_code', 16)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('employees', 'bank_account_name')) {
                $table->string('bank_account_name', 255)->nullable()->after('bank_account_masked');
            }
            if (!Schema::hasColumn('employees', 'bank_account_number')) {
                $table->string('bank_account_number', 32)->nullable()->after('bank_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['bank_code', 'bank_account_name', 'bank_account_number']);
        });
    }
};
