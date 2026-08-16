<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();

            // Base & Allowances
            $table->unsignedBigInteger('basic_salary_minor')->default(0);
            $table->unsignedBigInteger('housing_allowance_minor')->default(0);
            $table->unsignedBigInteger('transport_allowance_minor')->default(0);
            $table->unsignedBigInteger('utility_allowance_minor')->default(0);
            $table->unsignedBigInteger('medical_allowance_minor')->default(0);
            $table->unsignedBigInteger('other_allowance_minor')->default(0);

            // Variable Earnings
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('overtime_minor')->default(0);
            $table->unsignedBigInteger('bonus_minor')->default(0);

            // Statutory Settings
            $table->decimal('pension_rate_pct', 5, 2)->default(8.00);
            $table->boolean('is_pension_exempt')->default(false);
            $table->decimal('tax_rate_pct', 5, 2)->default(7.00);
            $table->boolean('is_tax_exempt')->default(false);

            // Institutional & Voluntary Deductions
            $table->unsignedBigInteger('nhis_minor')->default(0);
            $table->unsignedBigInteger('union_dues_minor')->default(0);
            $table->unsignedBigInteger('other_deduction_minor')->default(0);

            // Loan & Cash Advance Repayments
            $table->unsignedBigInteger('loan_deduction_minor')->default(0);
            $table->unsignedBigInteger('loan_balance_minor')->default(0);

            // Pre-computed summary cache
            $table->unsignedBigInteger('gross_monthly_minor')->default(0);
            $table->unsignedBigInteger('total_deductions_minor')->default(0);
            $table->unsignedBigInteger('net_monthly_minor')->default(0);

            $table->date('effective_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_profiles');
    }
};
