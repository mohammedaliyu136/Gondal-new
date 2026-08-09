<?php

namespace App\Http\Resources;

use App\Models\Consignment;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Consignment */
class ConsignmentResource extends JsonResource
{
    use Concerns\HidesSensitiveFigures;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'dispatched_at_wat' => Wat::dateTime($this->dispatched_at),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            // BR-7 / BR-8
            'litres_dispatched' => (string) $this->litres_dispatched,
            'litres_confirmed' => $this->litres_confirmed === null ? null : (string) $this->litres_confirmed,
            'litres_rejected_at_center' => (string) $this->litres_rejected_at_center,
            'adjustment_total' => $this->adjustmentTotal(),
            'containers' => $this->containers,
            'intake_temperature_c' => $this->intake_temperature_c === null ? null : (string) $this->intake_temperature_c,
            'grade' => $this->whenLoaded('grade', fn () => $this->grade === null ? null : [
                'id' => $this->grade->id,
                'code' => $this->grade->code,
                'name' => $this->grade->name,
            ]),
            /*
             * BR-14 — the snapshot. It is exposed because it is the record of what
             * was agreed, but it is a payment figure, so it needs the grade or
             * payments permission to see.
             */
            'rate_snapshot' => $this->whenPermitted($request, 'milk.grade.view', [
                'grade_rate_id' => $this->grade_rate_id,
                'rate_per_litre' => Money::decimal($this->rate_per_litre_minor),
                'payable_value' => Money::decimal($this->payableValueMinor()),
                // BR-13 — which day the rate was read for. Server-stamped at
                // confirmation, so it is evidence rather than an assertion.
                'rate_anchored_at' => $this->rate_anchored_at?->toIso8601String(),
            ]),
            'collection_point' => new CollectionPointResource($this->whenLoaded('collectionPoint')),
            'collection_center' => $this->whenLoaded('collectionCenter', fn () => [
                'id' => $this->collectionCenter->id,
                'code' => $this->collectionCenter->code,
                'name' => $this->collectionCenter->name,
            ]),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch === null ? null : [
                'id' => $this->batch->id,
                'reference' => $this->batch->reference,
                'status' => $this->batch->status,
            ]),
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
            'quality_tests' => $this->whenLoaded('qualityTests', fn () => $this->qualityTests->map(fn ($test) => [
                'test_type' => $test->test_type,
                'reading' => $test->reading,
                'acceptable_range' => $test->acceptable_range,
                'passed' => (bool) $test->passed,
            ])),
            // NFR-4 — the client must send this back to write safely.
            'lock_version' => (int) $this->lock_version,
            'is_test' => (bool) $this->is_test,
        ];
    }
}
