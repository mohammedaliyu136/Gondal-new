<?php

namespace App\Services\Notifications\Domain;

use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Services\Notifications\Contracts\HrNotificationServiceInterface;
use App\Services\Notifications\Contracts\NotificationServiceInterface;

class HrNotificationService implements HrNotificationServiceInterface
{
    public function __construct(private readonly NotificationServiceInterface $notifications) {}

    public function notifyLeaveRequested(LeaveRequest $leave): int
    {
        $recipients = $this->notifications->usersWithPermission('hr.leave.view', $leave);

        $employeeName = $leave->employee?->name ?? 'Staff Member';
        $title = "New Leave Request from {$employeeName}";
        $body = "{$employeeName} has submitted a request for {$leave->days} day(s) of leave starting on {$leave->starts_on?->toDateString()}.";
        $actionUrl = route('leave.index');

        return $this->notifications->send(
            eventCode: 'leave.requested',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $leave,
        );
    }

    public function notifyLeaveDecided(LeaveRequest $leave, string $decision, ?string $reason = null): int
    {
        $user = $leave->employee?->user;

        if (! $user) {
            return 0;
        }

        $decisionUpper = strtoupper($decision);
        $title = "Leave Request {$decisionUpper}";
        $body = "Your leave request for {$leave->days} day(s) starting on {$leave->starts_on?->toDateString()} has been {$decision}."
            . ($reason ? " Notes: {$reason}" : '');
        $actionUrl = route('leave.index');

        return $this->notifications->send(
            eventCode: 'leave.decided',
            recipients: [$user],
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $leave,
        );
    }

    public function notifyPayrollRunGenerated(PayrollRun $run): int
    {
        $recipients = $this->notifications->usersWithPermission('hr.payroll.view', $run);

        $monthYear = $run->period_start?->format('F Y') ?? 'current period';
        $title = "Payroll Run Generated ({$monthYear})";
        $body = "Payroll run {$run->reference} for {$monthYear} has been computed with {$run->employee_count} employees and is awaiting review.";
        $actionUrl = route('payroll.show', $run);

        return $this->notifications->send(
            eventCode: 'payroll.generated',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $run,
        );
    }

    public function notifyPayrollDisbursed(PayrollRun $run): int
    {
        $recipients = $this->notifications->usersWithPermission('hr.payroll.view', $run);

        $monthYear = $run->period_start?->format('F Y') ?? 'current period';
        $title = "Payroll Disbursed: {$run->reference} ({$monthYear})";
        $body = "Salaries for {$monthYear} have been disbursed successfully for {$run->employee_count} employees.";
        $actionUrl = route('payroll.show', $run);

        return $this->notifications->send(
            eventCode: 'payroll.disbursed',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $run,
        );
    }
}
