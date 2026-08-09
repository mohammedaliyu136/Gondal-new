<?php

namespace App\Http\Resources;

use App\Models\Farmer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * USER-1 — a farmer is a record. This resource carries no credential, no token
 * and no self-service link, because none exists.
 *
 * @mixin Farmer
 */
class FarmerResource extends JsonResource
{
    use Concerns\HidesSensitiveFigures;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'status' => $this->status,
            'community' => $this->whenLoaded('community', fn () => [
                'id' => $this->community->id,
                'name' => $this->community->name,
                'lga' => $this->community->lga?->name,
            ]),
            'cooperative' => $this->whenLoaded('cooperative', fn () => $this->cooperative === null ? null : [
                'id' => $this->cooperative->id,
                'code' => $this->cooperative->code,
                'name' => $this->cooperative->name,
                'member_no' => $this->cooperative_member_no,
            ]),
            'default_collection_point' => $this->whenLoaded('defaultCollectionPoint', fn () => $this->defaultCollectionPoint === null ? null : [
                'id' => $this->defaultCollectionPoint->id,
                'name' => $this->defaultCollectionPoint->name,
            ]),
            'herd_size' => $this->herd_size,
            'lactating_count' => $this->lactating_count,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
        ];
    }
}
