<?php

namespace App\Http\Resources;

use App\Models\CollectionPoint;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CollectionPoint */
class CollectionPointResource extends JsonResource
{
    use Concerns\HidesSensitiveFigures;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'community' => $this->whenLoaded('community', fn () => [
                'id' => $this->community->id,
                'name' => $this->community->name,
                'lga' => $this->community->lga?->name,
            ]),
            'collection_center' => $this->whenLoaded('collectionCenter', fn () => [
                'id' => $this->collectionCenter->id,
                'code' => $this->collectionCenter->code,
                'name' => $this->collectionCenter->name,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent?->id,
                'name' => $this->agent?->name,
            ]),
            // BR-3 — the cut-off actually applied, not the raw column.
            'cutoff_time' => $this->effectiveCutoff(),
            // §5.1 — transport fees are part of logistics.payments (sensitive).
            'transport_fee' => $this->whenPermitted(
                $request,
                'logistics.payments.view',
                Money::decimal($this->transport_fee_minor),
            ),
            'opened_on' => $this->opened_on?->toDateString(),
        ];
    }
}
