<?php

use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->after('net_minor');
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->index('status');
        });

        // Backfill payslips for payroll runs that are already paid
        $paidRuns = DB::table('payroll_runs')->where('status', 'paid')->get(['id', 'paid_at']);
        foreach ($paidRuns as $run) {
            DB::table('payslips')->where('payroll_run_id', $run->id)->update([
                'status' => 'paid',
                'paid_at' => $run->paid_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'paid_at']);
        });
    }
};
