<?php

namespace App\Http\Resources;

use App\Models\Sale;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    use Concerns\HidesSensitiveFigures;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'customer_type' => $this->customer_type,
            'customer' => $this->customerLabel(),
            'payment_method' => $this->payment_method,
            'total' => Money::decimal($this->total_minor),
            'amount_received' => Money::decimal($this->amount_received_minor),
            // BR-27
            'prescription_reference' => $this->prescription_reference,
            // BR-29 — margin is a shop.revenue figure.
            'margin' => $this->whenPermitted($request, 'shop.revenue.view', Money::decimal($this->marginMinor())),
            'sold_at' => $this->sold_at?->toIso8601String(),
            'sold_at_wat' => Wat::dateTime($this->sold_at),
            'sales_officer' => $this->whenLoaded('salesOfficer', fn () => $this->salesOfficer?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => array_filter([
                'product' => $item->product?->name,
                'sku' => $item->product?->sku,
                'quantity' => (string) $item->quantity,
                'unit_price' => Money::decimal($item->unit_price_minor),
                'amount' => Money::decimal($item->amount_minor),
                // BR-29 — omitted without the grant.
                'unit_cost' => $this->maySee($request, 'shop.revenue.view')
                    ? Money::decimal($item->unit_cost_minor_snapshot)
                    : null,
            ], fn ($value) => $value !== null))),
            'is_test' => (bool) $this->is_test,
        ];
    }
}
