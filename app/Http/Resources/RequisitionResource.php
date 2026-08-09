<?php

namespace App\Http\Resources;

use App\Models\Requisition;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Requisition */
class RequisitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $instance = $this->whenLoaded('workflowInstance');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'status' => $this->status,
            'urgency' => $this->urgency,
            'category' => $this->category,
            'needed_by' => $this->needed_by?->toDateString(),
            // NG-6 — a string, because there is no vendor registry in v1.
            'suggested_vendor' => $this->suggested_vendor,
            'total' => Money::decimal($this->total_minor),
            // BR-22 — never above total.
            'approved_total' => Money::decimal($this->approved_total_minor),
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'item' => $item->item,
                'purpose' => $item->purpose,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => Money::decimal($item->unit_price_minor),
                'amount' => Money::decimal($item->amount_minor),
            ])),
            // BR-17 / BR-19 — where it is and how far it has to go.
            'workflow' => $this->when($this->workflowInstance !== null, fn () => [
                'instance_id' => $this->workflowInstance->id,
                'status' => $this->workflowInstance->status,
                'band' => $this->workflowInstance->band?->name,
                'current_stage' => $this->workflowInstance->currentStage?->name,
                'current_stage_role' => $this->workflowInstance->currentStage?->approvingRole?->name,
                'stage_number' => $this->workflowInstance->stageNumber(),
                'stage_count' => $this->workflowInstance->stageCount(),
                'due_at' => $this->workflowInstance->current_stage_due_at?->toIso8601String(),
                'overdue' => $this->workflowInstance->isOverdue(),
            ]),
            // BR-20 — the revision chain.
            'revises' => $this->whenLoaded('revises', fn () => $this->revises?->reference),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'is_test' => (bool) $this->is_test,
        ];
    }
}
