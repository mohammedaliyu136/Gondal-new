<?php

namespace App\Http\Resources;

use App\Models\Batch;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Batch */
class BatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'dispatched_at_wat' => Wat::dateTime($this->dispatched_at),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            // BR-9 / BR-10 / BR-11
            'litres_dispatched' => (string) $this->litres_dispatched,
            'litres_received' => $this->litres_received === null ? null : (string) $this->litres_received,
            'discrepancy_litres' => $this->discrepancy_litres === null ? null : (string) $this->discrepancy_litres,
            'discrepancy_percentage' => $this->discrepancyPercentage(),
            'tolerance_percentage' => $this->tolerancePercentage(),
            'exceeds_tolerance' => $this->exceedsTolerance(),
            'litres_rejected_at_factory' => (string) $this->litres_rejected_at_factory,
            'containers' => $this->containers,
            'containers_received' => $this->containers_received,
            'discrepancy_cause' => $this->whenLoaded('discrepancyCause', fn () => $this->discrepancyCause?->name),
            'rejection_reason' => $this->whenLoaded('rejectionReason', fn () => $this->rejectionReason?->name),
            'supervisor_notes' => $this->supervisor_notes,
            'collection_center' => $this->whenLoaded('collectionCenter', fn () => [
                'id' => $this->collectionCenter->id,
                'code' => $this->collectionCenter->code,
                'name' => $this->collectionCenter->name,
            ]),
            'consignments' => ConsignmentResource::collection($this->whenLoaded('consignments')),
            'lock_version' => (int) $this->lock_version,
            'is_test' => (bool) $this->is_test,
        ];
    }
}
