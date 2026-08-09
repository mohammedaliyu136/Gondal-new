<?php

namespace App\Services\Hr;

use App\Exceptions\RuleViolationException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Phase 8 — leave, routed through the configurable workflow (WF-002).
 *
 * The instance's `amount_minor` carries the number of DAYS here, which is what
 * lets the "over 5 days adds HR" stage condition work without a special case in
 * the engine.
 *
 * BLOCKED ON A BUSINESS DECISION — there is no attendance system (the HR
 * migration says so openly), so nothing here flips employees.status to
 * `on_leave` when an approved period begins or back again when it ends: doing
 * that needs a daily job, and the business has not yet said whether they run a
 * clock-in at all, whether unpaid leave exists as a category, or whether the
 * prototype's lateness penalty is policy. Until then "who is away this week" is
 * answered by reading approved requests, not by a column, and PayrollService
 * pays full gross with no proration — which /payroll now states on the screen.
 */
class LeaveService
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data, User $actor): LeaveRequest
    {
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        $starts = Carbon::parse($data['starts_on']);
        $ends = Carbon::parse($data['ends_on']);

        if ($ends->lessThan($starts)) {
            throw RuleViolationException::make(
                'ST-1',
                'Leave cannot end before it starts.',
                [],
                'ends_on',
            );
        }

        $days = $starts->diffInDays($ends) + 1;

        /*
         * A member of staff cannot be in two places at once, and the register is
         * what answers "who is away that week". Two overlapping requests — the
         * usual cause being a resubmission of one already in review — produced two
         * live rows and double-counted the absence.
         */
        $clash = $this->liveRequestsFor($employee)
            ->where('starts_on', '<=', $ends->toDateString())
            ->where('ends_on', '>=', $starts->toDateString())
            ->first();

        if ($clash !== null) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf(
                    'This overlaps leave already requested for %s to %s.',
                    Wat::date($clash->starts_on),
                    Wat::date($clash->ends_on),
                ),
                ['clashes_with' => $clash->getKey()],
                'starts_on',
            );
        }

        /*
         * The entitlement is ANNUAL, and the check compared one request against it
         * in isolation — so 21 days of annual leave could be taken four times in
         * a year, each request passing on its own. What must fit inside the
         * entitlement is the year's total: days already live plus this request.
         *
         * The leave year is the calendar year the leave starts in. It is not
         * configurable anywhere in §9 today; if the business runs an anniversary
         * year instead, that is a leave_types column, not a rule change here.
         */
        $entitlement = (int) $type->annual_entitlement_days;

        if ($entitlement > 0) {
            $alreadyTaken = $this->daysTakenIn($employee, $type, (int) $starts->format('Y'));

            if ($alreadyTaken + $days > $entitlement) {
                throw RuleViolationException::make(
                    'ST-1',
                    sprintf(
                        '%s allows %d days in %d. %d are already booked and this request is %d.',
                        $type->name,
                        $entitlement,
                        (int) $starts->format('Y'),
                        $alreadyTaken,
                        $days,
                    ),
                    ['entitlement' => $entitlement, 'already_taken' => $alreadyTaken, 'requested' => $days],
                    'ends_on',
                );
            }
        }

        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::STATUS_DRAFT,
        ]);

        $this->audit->created(
            $request,
            sprintf('%s requested %d day(s) of %s', $employee->name, $days, $type->name),
            'Human Resources',
            ['starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString()],
            $actor,
        );

        return $request;
    }

    public function submit(LeaveRequest $request, User $actor): LeaveRequest
    {
        if (! in_array($request->status, [LeaveRequest::STATUS_DRAFT, LeaveRequest::STATUS_REJECTED], true)) {
            throw RuleViolationException::make(
                'ST-1',
                'That leave request has already been submitted.',
                ['status' => $request->status],
            );
        }

        // `amount_minor` carries days for this workflow — see the class docblock.
        $instance = $this->workflow->start(
            Workflow::APPLIES_LEAVE,
            $request,
            $actor,
            (int) $request->days,
        );

        $request->forceFill([
            'workflow_instance_id' => $instance->getKey(),
            'status' => LeaveRequest::STATUS_IN_REVIEW,
            'submitted_at' => Wat::now(),
        ])->save();

        return $request->refresh();
    }

    public function syncFromWorkflow(LeaveRequest $request): LeaveRequest
    {
        $instance = $request->workflowInstance;

        if ($instance === null) {
            return $request;
        }

        $request->forceFill([
            'status' => match ($instance->status) {
                WorkflowInstance::STATUS_APPROVED => LeaveRequest::STATUS_APPROVED,
                WorkflowInstance::STATUS_REJECTED => LeaveRequest::STATUS_REJECTED,
                WorkflowInstance::STATUS_CANCELLED => LeaveRequest::STATUS_CANCELLED,
                default => LeaveRequest::STATUS_IN_REVIEW,
            },
            'decided_at' => $instance->completed_at,
        ])->save();

        return $request;
    }

    /**
     * Days of a type already committed in a leave year: approved, plus those
     * still in review.
     *
     * A request awaiting a decision has to count, or two requests filed on the
     * same morning each see an empty year and both pass — the entitlement would
     * only bind against leave that had already been decided.
     */
    public function daysTakenIn(Employee $employee, LeaveType $type, int $year, ?LeaveRequest $excluding = null): int
    {
        return (int) $this->liveRequestsFor($employee, $excluding)
            ->where('leave_type_id', $type->getKey())
            ->whereYear('starts_on', $year)
            ->sum('days');
    }

    /**
     * The entitlement position for every type, for the employee's own screen —
     * a balance nobody could see was a balance nobody could plan against.
     *
     * @return array<int, array{type: LeaveType, entitlement: int, taken: int, remaining: int}>
     */
    public function balancesFor(Employee $employee, int $year): array
    {
        return LeaveType::query()->orderBy('name')->get()
            ->map(function (LeaveType $type) use ($employee, $year): array {
                $entitlement = (int) $type->annual_entitlement_days;
                $taken = $this->daysTakenIn($employee, $type, $year);

                return [
                    'type' => $type,
                    'entitlement' => $entitlement,
                    'taken' => $taken,
                    'remaining' => max(0, $entitlement - $taken),
                ];
            })
            ->all();
    }

    /**
     * Requests that hold a claim on the calendar: approved, or awaiting a
     * decision. A draft has not been asked for yet and a rejected or cancelled
     * one never happened, so neither blocks anything.
     */
    private function liveRequestsFor(Employee $employee, ?LeaveRequest $excluding = null): Builder
    {
        return LeaveRequest::withoutDataScope()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [LeaveRequest::STATUS_IN_REVIEW, LeaveRequest::STATUS_APPROVED])
            ->when($excluding !== null, fn (Builder $query) => $query->whereKeyNot($excluding->getKey()));
    }
}
