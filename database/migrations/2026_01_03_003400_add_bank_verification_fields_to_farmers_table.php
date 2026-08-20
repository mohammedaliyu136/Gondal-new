<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table): void {
            if (!Schema::hasColumn('farmers', 'bank_code')) {
                $table->string('bank_code', 16)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('farmers', 'bank_account')) {
                $table->string('bank_account', 32)->nullable()->after('bank_code');
            }
            if (!Schema::hasColumn('farmers', 'account_name')) {
                $table->string('account_name', 255)->nullable()->after('bank_account');
            }
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table): void {
            $table->dropColumn(['bank_code', 'bank_account', 'account_name']);
        });
    }
};
