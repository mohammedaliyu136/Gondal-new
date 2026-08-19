<?php

namespace App\Services\Workflow\Actions;

use App\Models\Requisition;
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

class RequisitionApprovePricingAction implements WorkflowStageActionHandler
{
    public function __construct(
        private readonly RequisitionService $requisitionService,
        private readonly AuditLogger $audit,
    ) {}

    public function key(): string
    {
        return 'requisition.approve_pricing';
    }

    public function label(): string
    {
        return 'Approve Final Pricing & Quantities (Internal Audit / Finance)';
    }

    public function description(): string
    {
        return 'Allows approvers (Internal Audit / Finance) to review line items, accept or reject items, and set the final approved quantity and approved unit price.';
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

        return view('workflows.actions.requisition-approve-pricing', [
            'instance' => $instance,
            'stage' => $stage,
            'requisition' => $requisition,
            'items' => $requisition->items,
        ])->render();
    }

    public function validate(Request $request, WorkflowInstance $instance, WorkflowStage $stage): array
    {
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
                    "stage_action_items.{$idx}.quantity" => 'Approved quantity must be greater than 0.',
                ]);
            }

            if ($unitPriceMajor < 0) {
                throw ValidationException::withMessages([
                    "stage_action_items.{$idx}.unit_price" => 'Approved unit price cannot be negative.',
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

            // Replace line items with accepted items having approved prices & quantities
            $this->requisitionService->replaceItems($requisition, $items);
            $requisition->refresh();

            $newTotal = (int) $requisition->total_minor;

            // Synchronise workflow instance amounts with the updated requisition total
            $instance->amount_minor = $newTotal;
            $instance->approved_amount_minor = $newTotal;
            $instance->save();

            // Set approved_total_minor on requisition
            $requisition->forceFill(['approved_total_minor' => $newTotal])->save();

            // Log detailed audit entry
            $this->audit->approval(
                $requisition,
                sprintf(
                    'Pricing and quantities approved at %s by %s — %s (was %s)',
                    $stage->name,
                    $actor->name,
                    Money::format($newTotal),
                    Money::format($oldTotal),
                ),
                [
                    'stage' => $stage->name,
                    'stage_action' => $this->key(),
                    'accepted_count' => count($items),
                    'rejected_count' => count($rejectedItems),
                    'old_total_minor' => $oldTotal,
                    'new_total_minor' => $newTotal,
                    'accepted_items' => $items,
                    'rejected_items' => $rejectedItems,
                ],
                $actor,
            );
        });
    }
}
