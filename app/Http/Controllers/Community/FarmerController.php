<?php

namespace App\Http\Controllers\Community;

use App\Authorization\ScopeType;
use App\Http\Controllers\Controller;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\Lga;
use App\Services\Audit\AuditLogger;
use App\Services\Milk\QualityFollowupService;
use App\Services\Payment\BankService;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * farmers.html and farmer-detail.html.
 *
 * USER-1 / USER-2 — a farmer is a record. There is no invite, no portal link and
 * no credential field anywhere on these screens.
 *
 * The persona note matters here: an Extension Agent holds community.farmers but
 * NOT milk.deliveries, so the delivery history section is permission-gated rather
 * than assumed.
 */
class FarmerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QualityFollowupService $followups,
        private readonly BankService $bankService,
    ) {}

    public function index(Request $request): View
    {
        $farmers = Farmer::query()
            ->with(['community.lga', 'cooperative', 'defaultCollectionPoint'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('lga'), fn ($query) => $query->where('lga_id', $request->integer('lga')))
            ->when($request->filled('cooperative'), fn ($query) => $query->where('cooperative_id', $request->integer('cooperative')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('community.farmers.index', [
            'farmers' => $farmers,
            'lgas' => Lga::query()->orderBy('name')->get(),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'cooperatives' => Cooperative::query()->orderBy('name')->get(),
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            'banks' => $this->bankService->getBanks(),
            'activeCount' => Farmer::query()->active()->count(),
            'canCreate' => $this->allows('community.farmers.create'),
        ]);
    }

    public function show(Farmer $farmer): View
    {
        $this->authorizeAccess('community.farmers.view', $farmer, 'Farmer → '.$farmer->name);

        // The persona boundary: an Extension Agent sees the farmer but not their
        // volumes (§16 — "No volumes or payment figures").
        $seesVolumes = $this->allows('milk.deliveries.view');

        $deliveries = $seesVolumes
            ? $farmer->deliveries()
                ->with(['collectionPoint', 'rejectionReason', 'consignment.grade'])
                ->latest('delivered_at')
                ->limit(25)
                ->get()
            : collect();

        return view('community.farmers.show', [
            'farmer' => $farmer->load(['community.lga', 'cooperative', 'defaultCollectionPoint.collectionCenter', 'enrolledBy']),
            'banks' => $this->bankService->getBanks(),
            'seesVolumes' => $seesVolumes,
            'deliveries' => $deliveries,
            'thirtyDayLitres' => $seesVolumes
                ? Volume::fromCentilitres((int) round(100 * (float) Delivery::query()
                    ->excludingTestData()
                    ->where('farmer_id', $farmer->getKey())
                    ->where('delivered_at', '>=', Wat::now()->subDays(30))
                    ->sum('litres_accepted')))
                : null,
            'openFollowups' => $this->followups->openFor($farmer),
            'activities' => $farmer->fieldActivities()->with(['activityType', 'extensionAgent.user'])->latest('activity_date')->limit(10)->get(),
            // BR-30 — deductions awaiting the farmer's next payment. Phase 7
            // settles these on a payment run; until one is generated they sit
            // here, which is what this list is for.
            'pendingDeductions' => $this->allows('shop.sales.view')
                ? $farmer->pendingDeductions()->pending()->with('sale')->get()
                : collect(),
            'wallet' => $farmer->getOrCreateWallet(),
            'walletTransactions' => $farmer->walletTransactions()->with(['recordedBy'])->limit(20)->get(),
            'seesFinances' => $this->allows('finance.farmer_payments.view') || $this->allows('community.farmers.view'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:farmers,code'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:16'],
            'year_of_birth' => ['nullable', 'integer', 'min:1900', 'max:'.(int) Wat::local()->format('Y')],
            'phone' => ['nullable', 'string', 'max:32'],
            'community_id' => ['required', 'exists:communities,id'],
            'cooperative_id' => ['nullable', 'exists:cooperatives,id'],
            'cooperative_member_no' => ['nullable', 'string', 'max:32'],
            'default_collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'herd_size' => ['nullable', 'integer', 'min:0'],
            'lactating_count' => ['nullable', 'integer', 'min:0'],
            'payout_method' => ['nullable', 'string', 'in:bank,cash,mobile_money,via_cooperative'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:16'],
            'bank_account' => ['nullable', 'string', 'max:32'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'mobile_money_number' => ['nullable', 'string', 'max:32'],
        ]);

        $community = Community::query()->findOrFail($validated['community_id']);

        $this->authorizeAccess('community.farmers.create', $community, 'Enrol a farmer in '.$community->name);

        $points = $this->currentUser()?->scopeSetFor('community.farmers.view')
            ->targetIdsFor(ScopeType::Point) ?? [];

        if (($validated['default_collection_point_id'] ?? null) === null && $points !== []) {
            if (count($points) === 1) {
                $validated['default_collection_point_id'] = $points[0];
            } else {
                return back()->withInput()->withErrors([
                    'default_collection_point_id' => 'Choose the collection point this farmer delivers to. Your access covers several, '
                        .'and without one the farmer would not appear in your own register.',
                ]);
            }
        }

        $bankAccount = !empty($validated['bank_account']) ? trim($validated['bank_account']) : null;
        $bankAccountMasked = $bankAccount ? (strlen($bankAccount) >= 6 ? substr($bankAccount, 0, 3) . '***' . substr($bankAccount, -3) : $bankAccount) : null;
        $payoutMethod = $validated['payout_method'] ?? ($bankAccount ? 'bank' : 'cash');

        $farmer = Farmer::query()->create(array_merge($validated, [
            'lga_id' => $community->lga_id,
            'payout_method' => $payoutMethod,
            'bank_account' => $bankAccount,
            'bank_account_masked' => $bankAccountMasked,
            'enrolled_by_user_id' => $this->currentUser()?->getKey(),
            'enrolled_on' => Wat::today()->toDateString(),
            'status' => 'active',
        ]));

        $this->audit->created(
            $farmer,
            sprintf('Farmer %s (%s) enrolled in %s', $farmer->name, $farmer->code, $community->name),
            'Community Engagement',
            ['herd_size' => $farmer->herd_size, 'rule' => 'USER-1', 'bank_name' => $farmer->bank_name, 'bank_account' => $bankAccountMasked],
            $this->currentUser(),
        );

        return redirect()->route('farmers.show', $farmer)->with('success', $farmer->name.' enrolled.');
    }

    public function update(Request $request, Farmer $farmer): RedirectResponse
    {
        $this->authorizeAccess('community.farmers.edit', $farmer, 'Farmer → '.$farmer->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'cooperative_id' => ['nullable', 'exists:cooperatives,id'],
            'cooperative_member_no' => ['nullable', 'string', 'max:32'],
            'default_collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'herd_size' => ['nullable', 'integer', 'min:0'],
            'lactating_count' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,dormant,exited'],
            'payout_method' => ['nullable', 'string', 'in:bank,cash,mobile_money,via_cooperative'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:16'],
            'bank_account' => ['nullable', 'string', 'max:32'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'mobile_money_number' => ['nullable', 'string', 'max:32'],
        ]);

        $before = $farmer->only(array_keys($validated));

        if (array_key_exists('bank_account', $validated)) {
            $bankAccount = !empty($validated['bank_account']) ? trim($validated['bank_account']) : null;
            $validated['bank_account'] = $bankAccount;
            $validated['bank_account_masked'] = $bankAccount ? (strlen($bankAccount) >= 6 ? substr($bankAccount, 0, 3) . '***' . substr($bankAccount, -3) : $bankAccount) : null;
            if (empty($validated['payout_method']) && $bankAccount) {
                $validated['payout_method'] = 'bank';
            }
        }

        $farmer->fill($validated)->save();

        $this->audit->edited(
            $farmer,
            $farmer->name.' record updated',
            'Community Engagement',
            $before,
            $farmer->only(array_keys($validated)),
            $this->currentUser(),
        );

        return back()->with('success', 'Farmer record updated.');
    }

    /**
     * AJAX endpoint to verify bank account details in real-time.
     */
    public function verifyBank(Request $request): JsonResponse
    {
        $this->authorizeAnyAccess(
            ['community.farmers.create', 'community.farmers.edit', 'finance.farmer_payments.create'],
            null,
            'Verify Farmer Bank Account'
        );

        $validated = $request->validate([
            'account_number' => ['required', 'string'],
            'bank_code' => ['required', 'string'],
        ]);

        $result = $this->bankService->verifyAccount($validated['account_number'], $validated['bank_code']);

        return response()->json(array_merge($result, [
            'status' => $result['success'],
        ]), $result['success'] ? 200 : 422);
    }

    /**
     * AJAX endpoint to fetch standard bank list.
     */
    public function banks(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'banks' => $this->bankService->getBanks(),
        ]);
    }
}
