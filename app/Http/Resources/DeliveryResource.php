<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ARCH-6 — volumes are decimal strings, never floats, so a JSON client cannot
 * lose precision on the way in or out.
 *
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivered_at_wat' => Wat::dateTime($this->delivered_at),
            'litres_presented' => (string) $this->litres_presented,
            'litres_rejected' => (string) $this->litres_rejected,
            // DM-1 / BR-6 — stored, not computed on read.
            'litres_accepted' => (string) $this->litres_accepted,
            'containers' => $this->containers,
            'notes' => $this->notes,
            'farmer' => new FarmerResource($this->whenLoaded('farmer')),
            'collection_point' => new CollectionPointResource($this->whenLoaded('collectionPoint')),
            'rejection_reason' => $this->whenLoaded('rejectionReason', fn () => $this->rejectionReason === null ? null : [
                'id' => $this->rejectionReason->id,
                'code' => $this->rejectionReason->code,
                'name' => $this->rejectionReason->name,
            ]),
            // BR-3 — the cut-off story travels with the record.
            'was_after_cutoff' => (bool) $this->was_after_cutoff,
            'cutoff_applied' => $this->cutoff_applied,
            'cutoff_override_reason' => $this->cutoff_override_reason,
            // DM-2 — null until dispatched.
            'consignment' => $this->whenLoaded('consignment', fn () => $this->consignment === null ? null : [
                'id' => $this->consignment->id,
                'reference' => $this->consignment->reference,
                'status' => $this->consignment->status,
            ]),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            // TEST-1 — a client can tell test data apart.
            'is_test' => (bool) $this->is_test,
        ];
    }
}
