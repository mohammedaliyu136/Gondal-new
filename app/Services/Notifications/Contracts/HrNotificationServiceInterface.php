<?php

namespace App\Services\Notifications\Contracts;

use App\Models\LeaveRequest;
use App\Models\PayrollRun;

interface HrNotificationServiceInterface
{
    /**
     * Notify HR officers / managers that an employee has submitted a leave request.
     */
    public function notifyLeaveRequested(LeaveRequest $leave): int;

    /**
     * Notify the requesting employee that their leave request was approved or rejected.
     */
    public function notifyLeaveDecided(LeaveRequest $leave, string $decision, ?string $reason = null): int;

    /**
     * Notify finance and HR approvers that a payroll run has been generated and awaits review.
     */
    public function notifyPayrollRunGenerated(PayrollRun $run): int;

    /**
     * Notify staff and department leads that a payroll run has been disbursed.
     */
    public function notifyPayrollDisbursed(PayrollRun $run): int;
}
