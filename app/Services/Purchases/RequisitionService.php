<?php

namespace App\Services\Purchases;

use App\Exceptions\RuleViolationException;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — requisitions.
 *
 * BR-19 the amount decides the band, so the total is computed from the lines
 *       before the workflow starts
 * BR-20 a rejection returns it to the requester; resubmission creates a NEW
 *       requisition linked to the old one AND a new workflow instance, so the
 *       original chain is retained in full
 * NG-6  no vendor registry, no purchase order, no GRN (§15.5)
 */
class RequisitionService
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function create(array $data, array $items, User $requester): Requisition
    {
        if ($items === []) {
            throw RuleViolationException::make(
                'BR-19',
                'A requisition needs at least one line item.',
                [],
                'items',
            );
        }

        return DB::transaction(function () use ($data, $items, $requester): Requisition {
            $requisition = Requisition::query()->create([
                'reference' => Sequences::next('requisitions'),
                'requester_user_id' => $requester->getKey(),
                'department_id' => $data['department_id'] ?? $requester->department_id,
                'title' => $data['title'] ?? null,
                'category' => $data['category'] ?? null,
                'urgency' => $data['urgency'] ?? 'normal',
                'needed_by' => $data['needed_by'] ?? null,
                // NG-6 — free text until a vendor registry exists.
                'suggested_vendor' => $data['suggested_vendor'] ?? null,
                'total_minor' => 0,
                'status' => Requisition::STATUS_DRAFT,
                'revises_requisition_id' => $data['revises_requisition_id'] ?? null,
            ]);

            $this->replaceItems($requisition, $items);

            return $requisition;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function replaceItems(Requisition $requisition, array $items): Requisition
    {
        DB::transaction(function () use ($requisition, $items): void {
            $requisition->items()->delete();

            $total = 0;

            foreach (array_values($items) as $position => $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = (int) ($item['unit_price_minor'] ?? 0);
                $amount = (int) round($quantity * $unitPrice);

                /*
                 * BR-19 — a negative line is not a discount, it is a cheaper
                 * approval band.
                 *
                 * submit() guards only the TOTAL, and start() fixes the band from
                 * that total and never revisits it. So ₦2,000,000 filed against a
                 * −₦1,600,000 line bands at ₦400,000 and skips the Executive
                 * Director and the General Manager, with an approver reading a
                 * ₦400,000 total and no reason to look further. Refused here as
                 * well as in the request rules because the API and the resubmit
                 * path both arrive at this method without them.
                 */
                if ($unitPrice < 0 || $amount < 0) {
                    throw RuleViolationException::make(
                        'BR-19',
                        sprintf(
                            'A requisition line cannot carry a negative amount — "%s" comes to %s.',
                            (string) ($item['item'] ?? 'line '.($position + 1)),
                            Money::format($amount),
                        ),
                        [
                            'item' => $item['item'] ?? null,
                            'unit_price_minor' => $unitPrice,
                            'amount_minor' => $amount,
                        ],
                        'items',
                    );
                }

                $total += $amount;

                RequisitionItem::query()->create([
                    'requisition_id' => $requisition->getKey(),
                    'item' => $item['item'],
                    'purpose' => $item['purpose'] ?? null,
                    'quantity' => $quantity,
                    'unit' => $item['unit'] ?? null,
                    'unit_price_minor' => $unitPrice,
                    'amount_minor' => $amount,
                    'position' => $position,
                ]);
            }

            $requisition->forceFill(['total_minor' => $total])->save();
        });

        return $requisition->refresh();
    }

    /**
     * BR-19 — starting the chain picks the band from the total.
     */
    public function submit(Requisition $requisition, User $requester): Requisition
    {
        if (! in_array($requisition->status, [Requisition::STATUS_DRAFT, Requisition::STATUS_REJECTED], true)) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('A requisition can only be submitted from `draft` or `rejected`. %s is `%s`.', $requisition->reference, $requisition->status),
                ['status' => $requisition->status],
            );
        }

        if ((int) $requisition->total_minor <= 0) {
            throw RuleViolationException::make(
                'BR-19',
                'A requisition needs a total above zero before it can be submitted.',
                [],
                'items',
            );
        }

        $instance = $this->workflow->start(
            Workflow::APPLIES_REQUISITION,
            $requisition,
            $requester,
            (int) $requisition->total_minor,
        );

        $requisition->forceFill([
            'workflow_instance_id' => $instance->getKey(),
            'status' => Requisition::STATUS_IN_REVIEW,
            'submitted_at' => Wat::now(),
            'approved_total_minor' => null,
            'decided_at' => null,
        ])->save();

        $this->audit->approval(
            $requisition,
            sprintf('%s submitted for approval — %s', $requisition->reference, Money::format((int) $requisition->total_minor)),
            ['band' => $instance->band?->name, 'stages' => $instance->stageCount(), 'rule' => 'BR-19'],
            $requester,
        );

        return $requisition->refresh();
    }

    /**
     * BR-20 — "Resubmission starts a NEW instance; the old one is retained."
     *
     * A rejected requisition is not edited in place: a new requisition is created
     * that `revises` it. That keeps the rejected figures, the rejection comment
     * and the whole original chain intact and readable.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function resubmit(Requisition $rejected, array $data, array $items, User $requester): Requisition
    {
        if ($rejected->status !== Requisition::STATUS_REJECTED) {
            throw RuleViolationException::make(
                'BR-20',
                'Only a rejected requisition can be revised and resubmitted.',
                ['status' => $rejected->status],
            );
        }

        $revision = $this->create(
            array_merge([
                'department_id' => $rejected->department_id,
                'title' => $rejected->title,
                'category' => $rejected->category,
                'urgency' => $rejected->urgency,
                'needed_by' => $rejected->needed_by?->toDateString(),
                'suggested_vendor' => $rejected->suggested_vendor,
            ], $data, ['revises_requisition_id' => $rejected->getKey()]),
            $items === [] ? $rejected->items->map(fn (RequisitionItem $item) => [
                'item' => $item->item,
                'purpose' => $item->purpose,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price_minor' => (int) $item->unit_price_minor,
            ])->all() : $items,
            $requester,
        );

        $this->submit($revision, $requester);

        $this->audit->approval(
            $revision,
            sprintf('%s submitted as a revision of the rejected %s', $revision->reference, $rejected->reference),
            ['revises' => $rejected->reference, 'rule' => 'BR-20'],
            $requester,
        );

        return $revision;
    }

    /**
     * Called after the workflow reaches a terminal state, to mirror it onto the
     * subject. BR-22 — the approved total is whatever the chain settled on.
     */
    public function syncFromWorkflow(Requisition $requisition): Requisition
    {
        $instance = $requisition->workflowInstance;

        if ($instance === null) {
            return $requisition;
        }

        $status = match ($instance->status) {
            WorkflowInstance::STATUS_APPROVED => Requisition::STATUS_APPROVED,
            WorkflowInstance::STATUS_REJECTED => Requisition::STATUS_REJECTED,
            WorkflowInstance::STATUS_CANCELLED => Requisition::STATUS_CANCELLED,
            default => Requisition::STATUS_IN_REVIEW,
        };

        $requisition->forceFill([
            'status' => $status,
            'approved_total_minor' => $instance->status === WorkflowInstance::STATUS_APPROVED
                ? $instance->approved_amount_minor
                : null,
            'decided_at' => $instance->completed_at,
        ])->save();

        return $requisition;
    }
}
