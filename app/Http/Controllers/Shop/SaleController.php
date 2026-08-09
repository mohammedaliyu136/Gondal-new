<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\Farmer;
use App\Models\PendingFarmerDeduction;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Shop\SaleService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * sales.html.
 *
 * BR-29 — "Users holding shop.sales but not shop.revenue see their OWN
 *   transactions and no aggregate revenue, margin or stock-value figure."
 *
 *   Two mechanisms, both needed:
 *     · the Sale model's `own` scope constraint narrows the LIST (SCOPE-2), and
 *       the Sales Officer role is assigned with scope `own`;
 *     · the aggregates below are not computed at all without shop.revenue.view.
 */
class SaleController extends Controller
{
    /**
     * ARCH-6 — the shape Money::fromMajor parses, minus the minus sign.
     *
     * Grouping commas are what an officer actually types into a naira field, so
     * `numeric` cannot be the rule; what the field must never accept is a
     * negative, which the old `['nullable','string']` did.
     */
    private const MONEY_FORMAT = 'regex:/^[0-9][0-9,]*(\.[0-9]{1,2})?$/';

    public function __construct(private readonly SaleService $sales) {}

    public function index(Request $request): View
    {
        $date = $request->date('date')?->toDateString() ?? Wat::today()->toDateString();

        /*
         * ARCH-9 — `sold_at` is a UTC instant and $date is a WAT calendar day, so
         * the two only line up through a range. `whereDate('sold_at', $date)` asked
         * for the UTC day of the same name, which begins an hour late: an officer
         * opening this screen at 00:30 saw no transactions, ₦0 revenue and ₦0
         * margin for sales they had just rung up, and the receipt they had in their
         * hand was filed under yesterday.
         */
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $sales = Sale::query()
            ->with(['items.product', 'farmer', 'cooperative', 'salesOfficer'])
            ->when($request->filled('payment_method'), fn ($query) => $query->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('customer_type'), fn ($query) => $query->where('customer_type', $request->string('customer_type')))
            ->when($request->filled('q'), fn ($query) => $query->where('receipt_no', 'like', '%'.$request->string('q').'%'))
            ->when(! $request->filled('q'), fn ($query) => $query
                ->where('sold_at', '>=', $dayStart)
                ->where('sold_at', '<', $dayEnd))
            ->latest('sold_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // BR-29 — the gate.
        $seesRevenue = $this->allows('shop.revenue.view');

        return view('shop.sales.index', [
            'sales' => $sales,
            'date' => $date,
            'seesRevenue' => $seesRevenue,
            // Withheld entirely — never computed then hidden.
            'revenueTodayMinor' => $seesRevenue
                ? (int) Sale::query()->excludingTestData()->notVoided()
                    ->where('sold_at', '>=', $dayStart)->where('sold_at', '<', $dayEnd)
                    ->sum('total_minor')
                : null,
            /*
             * NFR-1 — the same figure, added up by the database.
             *
             * This used to hydrate every non-void sale of the day `with('items')`
             * and sum marginMinor() in PHP. Bounded by the day, but unbounded
             * within it on a table that only grows, and the arithmetic is a plain
             * SUM the database can do without materialising a row. Still exact:
             * ARCH-6 / NFR-5 hold because kobo × an integer quantity of hundredths
             * is integer arithmetic in a bigint, and ROUND matches what
             * Sale::marginMinor() does per line.
             *
             * Built from Sale::query() rather than SaleItem so the DataScope global
             * scope still narrows it — a viewer with shop.revenue and a narrow sale
             * scope must not be shown the network's margin through a join.
             */
            'marginTodayMinor' => $seesRevenue
                ? (int) Sale::query()
                    ->excludingTestData()
                    ->notVoided()
                    ->where('sold_at', '>=', $dayStart)
                    ->where('sold_at', '<', $dayEnd)
                    ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
                    ->whereNull('sale_items.deleted_at')
                    ->selectRaw(
                        'coalesce(sum(sale_items.amount_minor'
                        .' - round(sale_items.quantity * coalesce(sale_items.unit_cost_minor_snapshot, 0))), 0) as margin_minor'
                    )
                    ->value('margin_minor')
                : null,
            /*
             * What is still owed, not what was ever lent.
             *
             * This was the lifetime sum of `total_minor` over every credit sale,
             * with nothing subtracted — a figure labelled "outstanding" that no
             * repayment could ever reduce and that only ever climbed. The one
             * settlement mechanism that exists (a cooperative-ledger `in` entry)
             * touches the account and never touches `sales`, so the moment a
             * cooperative paid, this tile and the cooperative's own balance
             * described the same debt differently.
             *
             * Credit is only ever extended to a cooperative (SaleService::
             * guardCreditNamesADebtor), so the deficit on the general funds IS the
             * shop's exposure, and it falls when the debt is settled.
             *
             * §15.4.3 / OPEN-DECISIONS §4.3: nothing yet CLOSES a balance from the
             * shop's side. That gap is unchanged — but the number now moves when
             * the money does.
             */
            'creditOutstandingMinor' => $seesRevenue
                ? -(int) CooperativeAccount::query()
                    ->where('kind', Cooperative::ACCOUNT_GENERAL)
                    ->where('balance_minor', '<', 0)
                    ->sum('balance_minor')
                : null,
            /*
             * BR-35 — "test accounts are excluded from all reports, aggregates and
             * payroll". This count was the one aggregate on the screen that did not
             * exclude them, so a test sale made the card read two transactions
             * beside a revenue figure that counted one.
             */
            'saleCountToday' => Sale::query()
                ->excludingTestData()
                ->where('sold_at', '>=', $dayStart)
                ->where('sold_at', '<', $dayEnd)
                ->count(),
            /*
             * BR-25 — `sellable()`, not `active()`. `active()` asks about the
             * PRODUCT's status, so retiring a category left every product under it
             * in this picker, priced and selling. The service refuses such a sale
             * now; the picker not offering it is what stops the officer wasting the
             * customer's time discovering that.
             */
            'products' => Product::query()->sellable()->with('category')->orderBy('name')->get(),
            /*
             * IDENTITY LOOKUPS, not browses — so they bypass the data scope, the
             * same way the leave form looks up an employee.
             *
             * The Sales Officer's scope on Farmer is "own", which for a farmer
             * record means "enrolled by me". A sales officer enrols nobody, so the
             * scoped query rendered a customer picker with zero farmers in it and
             * a farmer standing at the counter could not buy against their milk.
             * Picking a customer for a transaction is not reading their record:
             * the officer sees a name and a code, and the record itself stays
             * behind community.farmers.view.
             *
             * The 500-row cap is gone with it — it silently hid every farmer from
             * G onwards, which is worse than a long list.
             */
            'farmers' => Farmer::withoutDataScope()->active()->orderBy('name')->get(['id', 'name', 'code']),
            'cooperatives' => Cooperative::withoutDataScope()->active()->orderBy('name')->get(),
            'canSell' => $this->allows('shop.sales.create'),
        ]);
    }

    /**
     * The sale detail page. Its absence was a journey dead end: the receipt
     * number appeared in a flash message that vanished on the next page load, so
     * a customer returning with a query about RCP-0231 could not be answered.
     */
    public function show(Sale $sale): View
    {
        $this->authorizeAccess('shop.sales.view', $sale, 'Sale '.$sale->receipt_no);

        return view('shop.sales.show', [
            'sale' => $sale->load(['items.product', 'farmer', 'cooperative', 'salesOfficer', 'voidedBy']),
            'seesRevenue' => $this->allows('shop.revenue.view'),
            'canVoid' => $this->allows('shop.sales.edit', $sale),
            'deduction' => PendingFarmerDeduction::query()
                ->where('sale_id', $sale->getKey())->first(),
        ]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $this->authorizeAccess('shop.sales.edit', $sale, 'Void '.$sale->receipt_no);

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        $this->sales->void($sale, $validated['void_reason'], $this->currentUser());

        return back()->with('success', $sale->receipt_no.' voided. Stock has been returned.');
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * The sale form renders a fixed set of line rows, and the officer fills as
         * many as the customer is buying. Unused rows post an empty product and an
         * empty quantity.
         *
         * Those blanks used to reach validation, where `items.*.product_id` is
         * required — so a customer buying one bag of feed produced six validation
         * errors and the sale could not be recorded at all unless they happened to
         * buy exactly as many products as there were rows. Dropping the blank rows
         * before validation is what makes a one-item sale possible; the `min:1`
         * rule below still rejects a sale with no lines at all.
         */
        $request->merge([
            'items' => array_values(array_filter(
                (array) $request->input('items', []),
                static fn ($item) => is_array($item)
                    && trim((string) ($item['product_id'] ?? '')) !== ''
                    && trim((string) ($item['quantity'] ?? '')) !== '',
            )),
        ]);

        $validated = $request->validate([
            'customer_type' => ['required', Rule::in(Sale::CUSTOMER_TYPES)],
            'farmer_id' => ['nullable', 'exists:farmers,id', 'required_if:customer_type,farmer'],
            'cooperative_id' => ['nullable', 'exists:cooperatives,id', 'required_if:customer_type,cooperative'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(Sale::PAYMENT_METHODS)],
            'amount_received' => ['nullable', 'string', self::MONEY_FORMAT],
            // BR-27 — required by the service when a line's category demands it.
            'prescription_reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            /*
             * A price is money the shop is owed, so it cannot carry a minus sign.
             * It was validated as a string and nothing else: '-500' reached
             * Money::fromMajor, came back as -50,000 kobo, and a negative line
             * offset the positive ones on the same receipt — see
             * SaleService::guardLinePrices for what that cost downstream.
             *
             * `numeric` is the wrong rule here and always will be: ARCH-6 routes
             * operator money through Money::fromMajor, which accepts the grouping
             * commas an officer types ("4,800"). The format below accepts exactly
             * what that parser accepts and refuses the sign it should never have
             * been given. The service refuses zero and below regardless — this is
             * the layer that gives the officer a field-level error instead.
             */
            'items.*.unit_price' => ['nullable', 'string', self::MONEY_FORMAT],
        ], [
            'items.*.unit_price.regex' => 'A price override must be a positive amount, like 4800 or 4,800.50.',
            'amount_received.regex' => 'The amount received must be a positive amount, like 4800 or 4,800.50.',
        ]);

        $lines = array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (float) $item['quantity'],
            'unit_price_minor' => ($item['unit_price'] ?? null) === null
                ? null
                : Money::fromMajor($item['unit_price']),
        ], $validated['items']);

        $sale = $this->sales->record(
            array_merge($validated, [
                'amount_received_minor' => Money::fromMajor($validated['amount_received'] ?? null) ?? 0,
            ]),
            $lines,
            $this->currentUser(),
        );

        return back()->with('success', sprintf(
            'Sale %s recorded — %s.',
            $sale->receipt_no,
            Money::format((int) $sale->total_minor),
        ));
    }
}
