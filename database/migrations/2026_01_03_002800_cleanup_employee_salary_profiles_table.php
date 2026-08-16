<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'commission_minor',
                'overtime_minor',
                'bonus_minor',
                'loan_deduction_minor',
                'loan_balance_minor',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('commission_minor')->default(0)->after('other_allowance_minor');
            $table->unsignedBigInteger('overtime_minor')->default(0)->after('commission_minor');
            $table->unsignedBigInteger('bonus_minor')->default(0)->after('overtime_minor');
            $table->unsignedBigInteger('loan_deduction_minor')->default(0)->after('other_deduction_minor');
            $table->unsignedBigInteger('loan_balance_minor')->default(0)->after('loan_deduction_minor');
        });
    }
};
