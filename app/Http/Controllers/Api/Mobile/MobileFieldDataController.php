<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\FarmerResource;
use App\Models\ActivityType;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\Farmer;
use App\Models\Product;
use App\Models\RejectionReason;
use App\Services\Mobile\SaleVocabulary;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pickers and lists a field client needs to work offline.
 *
 * Everything here is read through the data scope (SCOPE-2), so "the communities
 * in the picker" are this user's communities and nobody else's — the phone
 * never holds a list it was not entitled to see, which matters more on a device
 * that keeps a local copy for days than it does in a browser tab.
 */
class MobileFieldDataController extends ApiController
{
    /**
     * Everything the registration and visit forms need, in one round trip — a
     * client on a 2G connection in a community should pay for one request, not
     * six.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizeAnyAccess(
            ['community.farmers.view', 'community.extension.view', 'milk.deliveries.view'],
            null,
            'Mobile: form options',
        );

        return $this->ok([
            'communities' => Community::query()->with('lga')->orderBy('name')->get()
                ->map(fn (Community $community) => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'lga' => $community->lga?->name,
                ]),

            'cooperatives' => Cooperative::query()->orderBy('name')->get()
                ->map(fn (Cooperative $cooperative) => [
                    'id' => $cooperative->id,
                    'code' => $cooperative->code,
                    'name' => $cooperative->name,
                ]),

            'collection_points' => CollectionPoint::query()->active()->with('collectionCenter')->orderBy('name')->get()
                ->map(fn (CollectionPoint $point) => [
                    'id' => $point->id,
                    'code' => $point->code,
                    'name' => $point->name,
                    'center' => $point->collectionCenter?->name,
                    // BR-3 — so the phone can warn before it accepts a late
                    // delivery, rather than discovering it at sync time.
                    'cutoff_time' => $point->effectiveCutoff(),
                ]),

            // BR-1 — the only reasons a point may use. §18.7: the client is
            // told them, it does not carry its own copy.
            'rejection_reasons' => RejectionReason::query()
                ->availableAt(RejectionReason::STAGE_POINT)
                ->orderBy('position')
                ->get()
                ->map(fn (RejectionReason $reason) => [
                    'id' => $reason->id,
                    'code' => $reason->code,
                    'name' => $reason->name,
                    'help_text' => $reason->help_text,
                ]),

            'activity_types' => ActivityType::query()->where('status', 'active')->orderBy('position')->get()
                ->map(fn (ActivityType $type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'help_text' => $type->help_text,
                    // BR-5 — only some activity types may close a follow-up, and
                    // which ones is the administrator's call.
                    'closes_followup' => (bool) $type->closes_quality_followup,
                ]),
        ]);
    }

    /**
     * Farmer lookup for the pickers. Paginated (NFR-2) and scoped, so an agent
     * searching "Zainab" finds the Zainabs they are responsible for.
     */
    public function searchFarmers(Request $request): JsonResponse
    {
        $this->authorizeAccess('community.farmers.view', null, 'Mobile: farmer search');

        $term = trim((string) $request->string('q'));

        $farmers = Farmer::query()
            ->with(['community.lga', 'cooperative', 'defaultCollectionPoint'])
            ->when($term !== '', fn ($query) => $query->where(function ($inner) use ($term) {
                $like = '%'.$term.'%';
                $inner->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            }))
            ->when($request->filled('community'), fn ($query) => $query->where('community_id', $request->integer('community')))
            ->active()
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return FarmerResource::collection($farmers)->response();
    }

    /**
     * The One-Stop Shop catalogue.
     *
     * BR-29 / §16 — the Inventory Officer sees quantities and the Sales Officer
     * sees prices, and neither sees what the other does. So the price is only
     * attached for a caller who may record a sale, and the on-hand quantity only
     * for one who may see stock: the same list, told two ways, decided here
     * rather than by whichever screen happens to render it.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeAnyAccess(['shop.inventory.view', 'shop.sales.view'], null, 'Mobile: shop catalogue');

        $seesStock = $this->allows('shop.inventory.view');
        $seesPrice = $this->allowsAny(['shop.sales.view', 'shop.sales.create']);

        $products = Product::query()
            ->with('category')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return $this->ok($products->map(fn (Product $product) => array_filter([
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'category' => $product->category?->name,
            'requires_prescription' => (bool) $product->category?->requires_prescription,
            /*
             * ARCH-6 / NFR-5 — money crosses the wire as integer kobo.
             *
             * `price` is Money::decimal, which is a DISPLAY string: number_format
             * puts a thousands separator in it, so "12,500.00" parsed by a client
             * as a number is 12, or nothing at all. The app's parser read zero for
             * every product priced over ₦999 and the cart totalled nothing. The
             * decimal string stays for a caller that only renders it; anything
             * that computes reads `price_minor`.
             */
            'price' => $seesPrice ? Money::decimal($product->selling_price_minor) : null,
            'price_minor' => $seesPrice ? (int) $product->selling_price_minor : null,
            'quantity_on_hand' => $seesStock ? $product->quantity_on_hand : null,
            // The reorder threshold is the administrator's, held on the product or
            // its category — so the flag is computed here rather than left to a
            // client that would have to carry its own copy of the rule (§18.7).
            'is_low_stock' => $seesStock ? $product->isLowStock() : null,
        ], static fn ($value) => $value !== null))->values(), 200, [
            /*
             * §18.7's reasoning applied to the client: a vocabulary the phone
             * carries as its own constant drifts from the one the server
             * enforces, silently. It did — the sale screen offered the display
             * string 'Cooperative Credit', SaleService compares against `credit`,
             * so BR-25's allow_credit check was skipped and the cooperative's
             * ledger was never posted. The picker is served the ERP's own list,
             * the same way MobileValidationController serves `outcomes`.
             */
            'payment_methods' => SaleVocabulary::paymentMethods(),
            'customer_types' => SaleVocabulary::customerTypes(),
        ]);
    }
}
