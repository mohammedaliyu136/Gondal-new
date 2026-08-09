<?php

namespace App\Http\Resources;

use App\Models\WorkflowInstance;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-23 — an item in somebody's approval queue. The stage's ROLE is what put it
 * there, so the payload names it.
 *
 * @mixin WorkflowInstance
 */
class ApprovalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow' => $this->whenLoaded('workflow', fn () => [
                'code' => $this->workflow->code,
                'name' => $this->workflow->name,
                'applies_to' => $this->workflow->applies_to,
            ]),
            'subject' => [
                'type' => class_basename($this->subject_type),
                'id' => $this->subject_id,
                'reference' => $this->subject?->reference,
                'title' => $this->subject?->title ?? null,
            ],
            'status' => $this->status,
            // BR-19
            'band' => $this->band?->name,
            'stage' => $this->whenLoaded('currentStage', fn () => [
                'name' => $this->currentStage?->name,
                'position' => $this->currentStage?->position,
                'role' => $this->currentStage?->approvingRole?->name,
                'sla_hours' => $this->currentStage?->sla_hours,
                'can_reject' => (bool) $this->currentStage?->can_reject,
            ]),
            'stage_number' => $this->stageNumber(),
            'stage_count' => $this->stageCount(),
            'amount' => Money::decimal($this->amount_minor),
            // BR-22
            'approved_amount' => Money::decimal($this->approved_amount_minor),
            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester?->id,
                'name' => $this->requester?->name,
            ]),
            'due_at' => $this->current_stage_due_at?->toIso8601String(),
            'due_at_wat' => Wat::dateTime($this->current_stage_due_at),
            // NOTIF-4
            'overdue' => $this->isOverdue(),
            'hours_remaining' => $this->hoursRemaining(),
            'started_at' => $this->started_at?->toIso8601String(),
        ];
    }
}
