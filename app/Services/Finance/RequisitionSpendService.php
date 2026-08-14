<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\Department;
use App\Models\Requisition;
use App\Models\RequisitionExpenditure;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Wat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — recording that an approved requisition was actually paid.
 *
 * The half of purchasing that was missing. A requisition cleared its workflow,
 * `approved_total_minor` was stamped, and nothing in the system ever referred to
 * it again — so "what did Logistics cost us last quarter" had no answer and
 * `departments.cost_centre` was a varchar nothing read.
 *
 * OVERSPEND IS REFUSED, not absorbed. An approval is an authority for a figure,
 * and paying more than the figure means the authority did not cover it. The
 * correct route is a revising requisition, which the module already supports
 * (`revises_requisition_id`). Silently accepting the larger number would make
 * the approval decorative.
 *
 * A BUDGET, by contrast, is ADVISORY. `departments.budget_minor` is nullable and
 * nothing here refuses a payment because of it: blocking spend on a budget
 * nobody has configured would break purchasing on the day it ships, and a budget
 * that silently stops a feed delivery in the rainy season is worse than one that
 * is exceeded and says so. The overrun is reported; the decision stays human.
 */
class RequisitionSpendService
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Requisition $requisition, array $data, User $actor): RequisitionExpenditure
    {
        $this->access->authorize(
            $actor,
            'purchase.requisitions.spend',
            $requisition,
            'Record spend against '.$requisition->reference,
        );

        if ($requisition->status !== Requisition::STATUS_APPROVED) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s is %s. Only an approved requisition can be paid against.',
                    $requisition->reference, $requisition->status),
                ['status' => $requisition->status],
                'status',
            );
        }

        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw RuleViolationException::make(
                'BR-2', 'A payment of zero is not a payment.',
                ['amount_minor' => $amount], 'amount_minor',
            );
        }

        $remaining = $this->remainingMinor($requisition);

        if ($amount > $remaining) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('%s was approved for %s and %s is already recorded. That leaves %s — '
                    .'a larger payment needs a revising requisition, not a bigger number here.',
                    $requisition->reference,
                    Money::format($this->authorisedMinor($requisition)),
                    Money::format($this->spentMinor($requisition)),
                    Money::format($remaining)),
                ['remaining_minor' => $remaining, 'attempted_minor' => $amount],
                'amount_minor',
            );
        }

        $method = (string) ($data['method'] ?? 'bank');

        if (! in_array($method, RequisitionExpenditure::METHODS, true)) {
            throw RuleViolationException::make(
                'BR-2', 'That is not a payment method the system knows.',
                ['method' => $method], 'method',
            );
        }

        $department = $requisition->department;

        return DB::transaction(function () use ($requisition, $data, $actor, $amount, $method, $department): RequisitionExpenditure {
            $expenditure = RequisitionExpenditure::query()->create([
                'requisition_id' => $requisition->getKey(),
                // Snapshotted — moving a requester between departments next year
                // must not restate what a department spent last year.
                'department_id' => $department?->getKey(),
                'cost_centre' => $department?->cost_centre,
                'amount_minor' => $amount,
                'vendor' => $data['vendor'] ?? null,
                'invoice_reference' => $data['invoice_reference'] ?? null,
                'method' => $method,
                'spent_on' => $data['spent_on'] ?? Wat::today()->toDateString(),
                'recorded_by_user_id' => $actor->getKey(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->created(
                $expenditure,
                sprintf('%s paid against %s%s — %s of %s authorised',
                    Money::format($amount),
                    $requisition->reference,
                    $expenditure->vendor ? ' to '.$expenditure->vendor : '',
                    Money::format($this->spentMinor($requisition->fresh())),
                    Money::format($this->authorisedMinor($requisition))),
                'Purchases',
                [
                    'department' => $department?->name,
                    'cost_centre' => $expenditure->cost_centre,
                    'invoice' => $expenditure->invoice_reference,
                ],
                $actor,
            );

            return $expenditure;
        });
    }

    /**
     * What the approval authorised.
     *
     * `approved_total_minor` when an approver reduced the figure, otherwise the
     * requested total. Reading only the former would treat a requisition that
     * was approved unchanged — which leaves the column null — as authorised for
     * nothing, and refuse every payment against it.
     */
    public function authorisedMinor(Requisition $requisition): int
    {
        return (int) ($requisition->approved_total_minor ?? $requisition->total_minor);
    }

    public function spentMinor(Requisition $requisition): int
    {
        return (int) RequisitionExpenditure::query()
            ->where('requisition_id', $requisition->getKey())
            ->sum('amount_minor');
    }

    public function remainingMinor(Requisition $requisition): int
    {
        return max(0, $this->authorisedMinor($requisition) - $this->spentMinor($requisition));
    }

    /**
     * What each department has actually spent in a period, against its budget.
     *
     * The figure `departments.cost_centre` was created for and never given.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDepartment(Carbon $start, Carbon $end): array
    {
        /*
         * ARCH-9, and this was got wrong once.
         *
         * `spent_on` is a DATE column but Eloquent's date cast writes
         * "2026-08-12 00:00:00", and the callers pass the half-open UTC
         * interval Wat::daysRange() produces. Comparing that column against a
         * bare "2026-08-12" upper bound is a STRING comparison that evaluates
         * false — so the current day's spend silently vanished from every
         * report while yesterday's appeared. The bound is converted to a WAT
         * calendar date and compared as one.
         */
        $fromDate = Wat::of($start)->toDateString();
        $toDate = Wat::of($end)->subDay()->toDateString();

        $spend = RequisitionExpenditure::query()
            ->excludingTestData()
            ->whereDate('spent_on', '>=', $fromDate)
            ->whereDate('spent_on', '<=', $toDate)
            ->get()
            ->groupBy('department_id');

        return Department::query()
            ->orderBy('name')
            ->get()
            ->map(function (Department $department) use ($spend) {
                $rows = $spend->get($department->getKey(), collect());
                $spent = (int) $rows->sum('amount_minor');
                $budget = $department->budget_minor === null ? null : (int) $department->budget_minor;

                return [
                    'department' => $department->name,
                    'cost_centre' => $department->cost_centre,
                    'payments' => $rows->count(),
                    'spent_minor' => $spent,
                    'budget_minor' => $budget,
                    // Null, not zero, when no budget is set: "under by ₦0" and
                    // "nobody has set a budget" are different statements.
                    'remaining_minor' => $budget === null ? null : $budget - $spent,
                    'over_budget' => $budget !== null && $spent > $budget,
                ];
            })
            ->all();
    }
}
