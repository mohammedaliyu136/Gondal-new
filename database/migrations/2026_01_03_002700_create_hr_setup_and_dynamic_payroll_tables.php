<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. HR Master Compensation Types (Allowances, Loans, Deductions, etc.)
        Schema::create('hr_compensation_types', function (Blueprint $table): void {
            $table->id();
            $table->string('category'); // allowance, loan, deduction, commission, overtime
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Staff Loans & Cash Advance Ledger
        Schema::create('staff_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('compensation_type_id')->nullable()->constrained('hr_compensation_types')->nullOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('principal_amount_minor');
            $table->unsignedBigInteger('monthly_installment_minor');
            $table->unsignedBigInteger('total_repaid_minor')->default(0);
            $table->unsignedBigInteger('balance_minor');
            $table->date('disbursed_on');
            $table->unsignedSmallInteger('start_period_year');
            $table->unsignedTinyInteger('start_period_month');
            $table->string('status')->default('active'); // active, paused, completed, written_off
            $table->text('notes')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Staff Loan Repayment Schedule & Records
        Schema::create('staff_loan_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_loan_id')->constrained('staff_loans')->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('repaid_on');
            $table->string('status')->default('pending'); // pending, confirmed, reversed
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 4. Employee Dynamic Commissions
        Schema::create('employee_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('compensation_type_id')->nullable()->constrained('hr_compensation_types')->nullOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('earned_on');
            $table->string('description');
            $table->string('status')->default('approved'); // pending, approved, processed_in_payroll, rejected
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 5. Employee Overtime Records
        Schema::create('employee_overtimes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->decimal('hours', 6, 2);
            $table->unsignedBigInteger('hourly_rate_minor');
            $table->unsignedBigInteger('total_amount_minor');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('worked_on');
            $table->string('description');
            $table->string('status')->default('approved'); // pending, approved, processed_in_payroll, rejected
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 6. Employee Dynamic Fixed Recurring Allowances
        Schema::create('employee_fixed_allowances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('compensation_type_id')->constrained('hr_compensation_types')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'compensation_type_id'], 'emp_allowance_unique');
        });

        // Seed initial default compensation types
        $now = now();
        DB::table('hr_compensation_types')->insert([
            // Allowances
            ['category' => 'allowance', 'code' => 'ALLW-HSG', 'name' => 'Housing Allowance', 'description' => 'Standard monthly housing benefit', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'allowance', 'code' => 'ALLW-TRN', 'name' => 'Transport Allowance', 'description' => 'Monthly commuting and travel allowance', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'allowance', 'code' => 'ALLW-UTL', 'name' => 'Utility & Meal Allowance', 'description' => 'Monthly electricity, internet and meal subsidy', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'allowance', 'code' => 'ALLW-MED', 'name' => 'Medical & Health Allowance', 'description' => 'Medical care subsidy', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'allowance', 'code' => 'ALLW-HZD', 'name' => 'Hazard Allowance', 'description' => 'Field or industrial operations hazard allowance', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'allowance', 'code' => 'ALLW-RSP', 'name' => 'Responsibility Allowance', 'description' => 'Acting or leadership responsibility allowance', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Loans
            ['category' => 'loan', 'code' => 'LOAN-ADV', 'name' => 'Salary Advance', 'description' => 'Short-term cash advance against salary', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'loan', 'code' => 'LOAN-EMG', 'name' => 'Emergency Staff Loan', 'description' => 'Interest-free staff emergency loan', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'loan', 'code' => 'LOAN-EQP', 'name' => 'Equipment / Tool Loan', 'description' => 'Staff tool and motorcycle/vehicle acquisition loan', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'loan', 'code' => 'LOAN-COP', 'name' => 'Cooperative Loan', 'description' => 'Staff welfare & cooperative society loan', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Deductions
            ['category' => 'deduction', 'code' => 'DED-NHIS', 'name' => 'Health Insurance (NHIS)', 'description' => 'National Health Insurance Scheme contribution', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'deduction', 'code' => 'DED-UNI', 'name' => 'Union / Cooperative Dues', 'description' => 'Monthly staff cooperative union check-off dues', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'deduction', 'code' => 'DED-WLF', 'name' => 'Staff Welfare Levy', 'description' => 'Voluntary staff welfare group contribution', 'is_taxable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

            // Commission & Overtime
            ['category' => 'commission', 'code' => 'COM-SLS', 'name' => 'Sales Target Commission', 'description' => 'Bonus on product retail or milk sales quotas', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'commission', 'code' => 'COM-MLK', 'name' => 'Milk Collection Milestone', 'description' => 'Performance commission for collection targets', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'overtime', 'code' => 'OT-REG', 'name' => 'Standard Overtime', 'description' => 'Regular overtime hours outside core work shift', 'is_taxable' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_fixed_allowances');
        Schema::dropIfExists('employee_overtimes');
        Schema::dropIfExists('employee_commissions');
        Schema::dropIfExists('staff_loan_repayments');
        Schema::dropIfExists('staff_loans');
        Schema::dropIfExists('hr_compensation_types');
    }
};
