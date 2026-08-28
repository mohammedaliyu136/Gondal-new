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
        return 'Adjust Line Items & Quantities HOD';
    }

    public function description(): string
    {
        return 'Allows approvers to adjust item counts/quantities, modify unit prices, add new items, or remove line items at this stage.';
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

        $validatedItems = [];
        foreach ($items as $idx => $itemData) {
            $name = trim($itemData['item'] ?? '');
            $qty = (float) ($itemData['quantity'] ?? 0);
            $unitPriceMajor = (float) ($itemData['unit_price'] ?? 0);
            $unit = trim($itemData['unit'] ?? '');
            $purpose = trim($itemData['purpose'] ?? '');

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

            $validatedItems[] = [
                'item' => $name,
                'purpose' => $purpose ?: null,
                'quantity' => $qty,
                'unit' => $unit ?: null,
                'unit_price_minor' => $unitPriceMinor,
            ];
        }

        return ['items' => $validatedItems];
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

        DB::transaction(function () use ($requisition, $instance, $stage, $actor, $items): void {
            $oldTotal = (int) $requisition->total_minor;

            // Replace line items
            $this->requisitionService->replaceItems($requisition, $items);
            $requisition->refresh();

            $newTotal = (int) $requisition->total_minor;

            // Synchronise workflow instance amounts with the updated requisition total
            $instance->amount_minor = $newTotal;
            $instance->approved_amount_minor = $newTotal;
            $instance->save();

            // Log an audit trail
            $this->audit->approval(
                $requisition,
                sprintf(
                    'Line items adjusted at stage "%s" by %s (%s → %s)',
                    $stage->name,
                    $actor->name,
                    Money::format($oldTotal),
                    Money::format($newTotal),
                ),
                [
                    'stage' => $stage->name,
                    'stage_id' => $stage->id,
                    'old_total_minor' => $oldTotal,
                    'new_total_minor' => $newTotal,
                    'items_count' => count($items),
                    'items' => $items,
                ],
                $actor,
            );
        });
    }
}
