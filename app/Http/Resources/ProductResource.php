<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-29 — cost price, stock value and margin are shop.revenue figures and are
 * OMITTED from the payload without that grant, not merely nulled. The Inventory
 * Officer persona ("quantities only — no financial values") is the test case.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use Concerns\HidesSensitiveFigures;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'status' => $this->status,
            'quantity_on_hand' => (string) $this->quantity_on_hand,
            'reorder_level' => $this->reorder_level,
            'low_stock' => $this->isLowStock(),
            'selling_price' => Money::decimal($this->selling_price_minor),
            // BR-29 — the sensitive trio.
            'cost_price' => $this->whenPermitted($request, 'shop.revenue.view', Money::decimal($this->cost_price_minor)),
            'stock_value' => $this->whenPermitted($request, 'shop.revenue.view', Money::decimal($this->stockValueMinor())),
            'margin' => $this->whenPermitted($request, 'shop.revenue.view', Money::decimal($this->marginMinor())),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
                // BR-25 / BR-27 — behaviour flags travel with the category so a
                // client knows what a sale of this product will require.
                'requires_prescription' => (bool) $this->category->requires_prescription,
                'track_expiry' => (bool) $this->category->track_expiry,
                'allow_credit' => (bool) $this->category->allow_credit,
                'status' => $this->category->status,
            ]),
        ];
    }
}
