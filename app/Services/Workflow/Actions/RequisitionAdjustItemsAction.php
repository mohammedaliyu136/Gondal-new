<?php

namespace App\Services\Workflow\Actions;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Audit\AuditLogger;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\Contracts\WorkflowStageActionHandler;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionAdjustItemsAction implements WorkflowStageActionHandler
{
    public function __construct(
        private readonly RequisitionService $requisitionService,
        private readonly AuditLogger $audit,
    ) {}

    public function key(): string
    {
        return 'requisition.adjust_items';
    }

    public function label(): string
    {
        return 'Adjust Line Items & Quantities (HOD)';
    }

    public function description(): string
    {
        return 'Allows HOD / approvers to accept or reject individual items, adjust requested quantities, and modify estimated unit prices.';
    }

    public function appliesTo(): array
    {
        return ['requisition'];
    }

    public function renderForm(WorkflowInstance $instance, WorkflowStage $stage): string
    {
        $requisition = $instance->subject;
        if (! ($requisition instanceof Requisition)) {
            return '';
        }

        return view('workflows.actions.requisition-adjust-items', [
            'instance' => $instance,
            'stage' => $stage,
            'requisition' => $requisition,
            'items' => $requisition->items,
        ])->render();
    }

    public function validate(Request $request, WorkflowInstance $instance, WorkflowStage $stage): array
    {
        // If the request didn't send modified items, return empty array to keep existing items
        if (! $request->has('stage_action_items')) {
            return [];
        }

        $items = $request->input('stage_action_items');
        if (! is_array($items) || count($items) === 0) {
            throw ValidationException::withMessages([
                'stage_action_items' => 'Requisition must contain at least one line item.',
            ]);
        }

        $validatedAcceptedItems = [];
        $rejectedItems = [];

        foreach ($items as $idx => $itemData) {
            $name = trim($itemData['item'] ?? '');
            $status = trim($itemData['status'] ?? 'accept');
            $qty = (float) ($itemData['quantity'] ?? 0);
            $unitPriceMajor = (float) ($itemData['unit_price'] ?? 0);
            $unit = trim($itemData['unit'] ?? '');
            $purpose = trim($itemData['purpose'] ?? '');

            if ($status === 'reject') {
                $rejectedItems[] = [
                    'item' => $name,
                    'purpose' => $purpose ?: null,
                    'quantity' => $qty,
                    'unit' => $unit ?: null,
                    'unit_price_minor' => Money::fromMajor($unitPriceMajor) ?? 0,
                    'status' => 'rejected',
                ];
                continue;
            }

            if ($name === '') {
                throw ValidationException::withMessages([
                    "stage_action_items.{$idx}.item" => 'Item description cannot be blank.',
                ]);
            }

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    "stage_action_items.{$idx}.quantity" => 'Quantity must be greater than 0.',
                ]);
            }

            if ($unitPriceMajor < 0) {
                throw ValidationException::withMessages([
                    "stage_action_items.{$idx}.unit_price" => 'Unit price cannot be negative.',
                ]);
            }

            $unitPriceMinor = Money::fromMajor($unitPriceMajor) ?? 0;

            $validatedAcceptedItems[] = [
                'item' => $name,
                'purpose' => $purpose ?: null,
                'quantity' => $qty,
                'unit' => $unit ?: null,
                'unit_price_minor' => $unitPriceMinor,
                'status' => 'accepted',
            ];
        }

        if (count($validatedAcceptedItems) === 0) {
            throw ValidationException::withMessages([
                'stage_action_items' => 'At least one line item must be accepted. To reject the entire requisition, please use the Reject button.',
            ]);
        }

        return [
            'items' => $validatedAcceptedItems,
            'rejected_items' => $rejectedItems,
        ];
    }

    public function execute(WorkflowInstance $instance, WorkflowStage $stage, User $actor, array $payload): void
    {
        $requisition = $instance->subject;
        if (! ($requisition instanceof Requisition)) {
            return;
        }

        $items = $payload['items'] ?? null;
        if (! is_array($items) || count($items) === 0) {
            return;
        }

        $rejectedItems = $payload['rejected_items'] ?? [];

        DB::transaction(function () use ($requisition, $instance, $stage, $actor, $items, $rejectedItems): void {
            $oldTotal = (int) $requisition->total_minor;

            // Replace line items with accepted items
            $this->requisitionService->replaceItems($requisition, $items);
            $requisition->refresh();

            $newTotal = (int) $requisition->total_minor;

            // Synchronise workflow instance amounts with the updated requisition total
            $instance->amount_minor = $newTotal;
            $instance->approved_amount_minor = $newTotal;
            $instance->save();

            // Format summary notes for audit log
            $auditSummary = sprintf(
                'Line items reviewed at stage "%s" by %s (%s → %s). Accepted: %d item(s)%s.',
                $stage->name,
                $actor->name,
                Money::format($oldTotal),
                Money::format($newTotal),
                count($items),
                count($rejectedItems) > 0 ? sprintf(', Rejected: %d item(s)', count($rejectedItems)) : '',
            );

            // Log an audit trail
            $this->audit->approval(
                $requisition,
                $auditSummary,
                [
                    'stage' => $stage->name,
                    'stage_id' => $stage->id,
                    'old_total_minor' => $oldTotal,
                    'new_total_minor' => $newTotal,
                    'accepted_items_count' => count($items),
                    'accepted_items' => $items,
                    'rejected_items_count' => count($rejectedItems),
                    'rejected_items' => $rejectedItems,
                ],
                $actor,
            );
        });
    }
}
