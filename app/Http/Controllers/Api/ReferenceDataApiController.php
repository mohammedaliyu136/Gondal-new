<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\DiscrepancyCause;
use App\Models\Grade;
use App\Models\ProductCategory;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §9 — reference data over the API.
 *
 * This endpoint is what makes §18.7 ("no reference data appears as an enum,
 * constant or config value anywhere in the codebase") survive a future client:
 * a field app fetches grades, reasons, thresholds and cut-offs rather than
 * shipping its own copy that would drift the moment an administrator edits one.
 *
 * BR-13 — rates come back WITH their effective_from, so a client can never
 * present a rate as applying to a date it does not.
 */
class ReferenceDataApiController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->ok([
            'grades' => Grade::query()->active()->with('rates')->orderBy('position')->get()->map(fn (Grade $grade) => [
                'id' => $grade->id,
                'code' => $grade->code,
                'name' => $grade->name,
                'criteria' => $grade->criteria,
                'is_rejection' => (bool) $grade->is_rejection,
                'assignable' => $grade->status === 'active',
                // BR-13 — effective-dated, never a bare "current rate".
                'rates' => $grade->rates->map(fn ($rate) => [
                    'id' => $rate->id,
                    'rate_per_litre' => Money::decimal($rate->rate_per_litre_minor),
                    'effective_from' => $rate->effective_from->toDateString(),
                ]),
            ]),

            // BR-1 — with the stages each is enabled for.
            'rejection_reasons' => RejectionReason::query()->active()->orderBy('position')->get()->map(fn (RejectionReason $reason) => [
                'id' => $reason->id,
                'code' => $reason->code,
                'name' => $reason->name,
                'help_text' => $reason->help_text,
                'available_at' => array_values(array_filter([
                    $reason->available_at_point ? 'point' : null,
                    $reason->available_at_center ? 'center' : null,
                    $reason->available_at_factory ? 'factory' : null,
                ])),
                // BR-5
                'followup_threshold' => $reason->followup_threshold,
                'followup_window_days' => $reason->followup_window_days,
                // BR-2
                'excluded_from_payment' => (bool) $reason->excluded_from_payment,
                // BR-3
                'is_cutoff_breach' => (bool) $reason->is_cutoff_breach,
            ]),

            'adjustment_reasons' => AdjustmentReason::query()->active()->orderBy('position')->get()
                ->map(fn ($reason) => [
                    'id' => $reason->id,
                    'code' => $reason->code,
                    'name' => $reason->name,
                    'applies_to' => $reason->applies_to,
                ]),

            'discrepancy_causes' => DiscrepancyCause::query()->active()->orderBy('position')->get()
                ->map(fn ($cause) => ['id' => $cause->id, 'code' => $cause->code, 'name' => $cause->name]),

            'activity_types' => ActivityType::query()->active()->orderBy('position')->get()
                ->map(fn ($type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'closes_quality_followup' => (bool) $type->closes_quality_followup,
                ]),

            // BR-4 — the tests a grade cannot be assigned without.
            'quality_tests' => QualityTestDefinition::query()->active()->orderBy('position')->get()
                ->map(fn (QualityTestDefinition $definition) => [
                    'id' => $definition->id,
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'kind' => $definition->kind,
                    'min_value' => $definition->min_value,
                    'max_value' => $definition->max_value,
                    'unit' => $definition->unit,
                    'acceptable_range' => $definition->describeRange(),
                    'required' => (bool) $definition->is_required,
                ]),

            // BR-25 / BR-27 — category behaviour, so a client knows what a sale
            // will demand before it tries.
            'product_categories' => ProductCategory::query()->sellable()->orderBy('position')->get()
                ->map(fn (ProductCategory $category) => [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->name,
                    'default_unit' => $category->default_unit,
                    'requires_prescription' => (bool) $category->requires_prescription,
                    'track_expiry' => (bool) $category->track_expiry,
                    'allow_credit' => (bool) $category->allow_credit,
                    'requires_manager_approval' => (bool) $category->requires_manager_approval,
                ]),

            'settings' => [
                'delivery_cutoff_default' => Settings::string('milk.delivery_cutoff_default', '07:00'),
                'delivery_cutoff_latest_override' => Settings::string('milk.delivery_cutoff_latest_override', '08:00'),
                'batch_discrepancy_tolerance_pct' => Settings::decimalString('milk.batch_discrepancy_tolerance_pct', '1.0'),
            ],

            'conventions' => [
                // ARCH-6 / ARCH-9 / ARCH-10 — stated so a client cannot guess wrong.
                'currency' => config('gondal.currency.code'),
                'currency_symbol' => config('gondal.currency.symbol'),
                'money_format' => 'decimal string, two places; the server stores integer minor units',
                'volume_unit' => config('gondal.volume.unit'),
                'volume_format' => 'decimal string, two places',
                'timezone_display' => config('gondal.display_timezone'),
                'timestamps' => 'ISO-8601 UTC',
            ],
        ]);
    }
}
