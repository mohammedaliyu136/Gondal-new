<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\CooperativeEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Community\CooperativeService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * cooperatives.html and cooperative-detail.html.
 *
 * §5.1 — community.coop.savings is SENSITIVE. Balances and the ledger are only
 *   rendered to a holder, and the write route requires the create grant.
 * NG-1 — no loans, no investments. Two accounts only.
 * §15.3 — the manual cooperative forms are outstanding from Muhammad Bello. The
 *   schema here is §6.6 exactly; the screen surfaces that openly rather than
 *   inventing fields.
 */
class CooperativeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CooperativeService $cooperatives,
    ) {}

    public function index(Request $request): View
    {
        $cooperatives = Cooperative::query()
            ->with(['community.lga', 'collectionPoint'])
            ->withCount('members')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)->orWhere('code', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $seesSavings = $this->allows('community.coop.savings.view');

        return view('community.cooperatives.index', [
            'cooperatives' => $cooperatives,
            'seesSavings' => $seesSavings,
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            // §9 — the defaults applied to a new cooperative.
            'defaults' => [
                'savings_pct' => Settings::decimalString('cooperative.default_savings_deduction_pct', '5'),
                'levy_pct' => Settings::decimalString('cooperative.default_levy_pct', '2'),
                'social_minor' => Settings::moneyMinor('cooperative.default_social_contribution_minor', 25_000),
            ],
            'canCreate' => $this->allows('community.cooperatives.create'),
        ]);
    }

    public function show(Cooperative $cooperative): View
    {
        $this->authorizeAccess('community.cooperatives.view', $cooperative, 'Cooperative → '.$cooperative->name);

        $seesSavings = $this->allows('community.coop.savings.view');

        return view('community.cooperatives.show', [
            'cooperative' => $cooperative->load(['community.lga', 'collectionPoint', 'accounts', 'rates.createdBy']),
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            /*
             * Route::has because the PUT is registered separately from this
             * screen; without the guard the page 500s while the two halves are
             * out of step, which is a worse failure than a missing button.
             */
            'canEdit' => $this->allows('community.cooperatives.edit', $cooperative)
                && Route::has('cooperatives.update'),
            'members' => $cooperative->members()->orderBy('name')->paginate(25),
            'seesSavings' => $seesSavings,
            // §5.1 — withheld entirely without the sensitive grant.
            'generalEntries' => $seesSavings
                ? ($cooperative->generalAccount()?->entries()->limit(25)->get() ?? collect())
                : collect(),
            'socialEntries' => $seesSavings
                ? ($cooperative->socialAccount()?->entries()->limit(25)->get() ?? collect())
                : collect(),
            'canPostEntry' => $this->allows('community.coop.savings.create', $cooperative),
            // NG-1 — stated on the screen so nobody looks for the loan book.
            'loansDeferred' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:cooperatives,code'],
            'name' => ['required', 'string', 'max:255'],
            'registered_on' => ['nullable', 'date'],
            'community_id' => ['required', 'exists:communities,id'],
            'collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'chairman_name' => ['nullable', 'string', 'max:255'],
            'secretary_name' => ['nullable', 'string', 'max:255'],
            'treasurer_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'savings_deduction_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'levy_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'social_contribution' => ['nullable', 'string'],
        ]);

        $community = Community::query()->findOrFail($validated['community_id']);

        // ARCH-4 layer 2 — a cooperative belongs to the settlement it registers
        // in, and the enroller must be able to reach that settlement.
        $this->authorizeAccess('community.cooperatives.create', $community, 'Register a cooperative in '.$community->name);

        $cooperative = $this->cooperatives->register($validated, $community, $this->currentUser());

        return redirect()->route('cooperatives.show', $cooperative)
            ->with('success', $cooperative->name.' onboarded with a general and a social fund.');
    }

    /**
     * The register was write-once: committees turn over annually, and the
     * officials, contact phone, collection point and status were all fixed at
     * registration. BR-15's percentages are editable here too — prospectively,
     * which is what both screens already told the user was happening.
     */
    public function update(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $this->authorizeAccess('community.cooperatives.edit', $cooperative, 'Cooperative → '.$cooperative->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'chairman_name' => ['nullable', 'string', 'max:255'],
            'secretary_name' => ['nullable', 'string', 'max:255'],
            'treasurer_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'savings_deduction_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'levy_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'social_contribution' => ['nullable', 'string'],
            /*
             * BR-15 — a percentage change is dated, never retroactive. Refusing a
             * back-date is the whole point: the effective date is what decides
             * which payables a change can reach, so an operator must not be able
             * to move one that has already been calculated.
             */
            'effective_from' => ['nullable', 'date', 'after_or_equal:today'],
            'status' => ['nullable', 'in:active,dormant,inactive'],
        ]);

        $this->cooperatives->update($cooperative, $validated, $this->currentUser());

        return back()->with('success', $cooperative->name.' updated.');
    }

    /**
     * §5.1 sensitive — a fund movement. The running balance is written onto the
     * entry so the statement never has to be recomputed.
     */
    public function storeEntry(Request $request, Cooperative $cooperative): RedirectResponse
    {
        $this->authorizeAccess('community.coop.savings.create', $cooperative, 'Post a fund entry for '.$cooperative->name);

        $validated = $request->validate([
            'kind' => ['required', 'in:general,social'],
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'string'],
        ]);

        $amount = Money::fromMajor($validated['amount']) ?? 0;

        if ($amount <= 0) {
            return back()->withErrors(['amount' => 'Enter an amount greater than zero.']);
        }

        $entry = DB::transaction(function () use ($cooperative, $validated, $amount): CooperativeEntry {
            /** @var CooperativeAccount $account */
            $account = CooperativeAccount::query()
                ->where('cooperative_id', $cooperative->getKey())
                ->where('kind', $validated['kind'])
                ->lockForUpdate()
                ->firstOrFail();

            $signed = $validated['direction'] === CooperativeEntry::DIRECTION_IN ? $amount : -$amount;
            $balance = (int) $account->balance_minor + $signed;

            $account->forceFill(['balance_minor' => $balance])->save();

            return CooperativeEntry::query()->create([
                'cooperative_account_id' => $account->getKey(),
                'entry_date' => $validated['entry_date'],
                'description' => $validated['description'],
                'direction' => $validated['direction'],
                'amount_minor' => $amount,
                'balance_after_minor' => $balance,
            ]);
        });

        $this->audit->created(
            $entry,
            sprintf(
                '%s fund %s %s — %s (balance %s)',
                ucfirst($validated['kind']),
                $validated['direction'] === 'in' ? 'credit' : 'debit',
                Money::format($amount),
                $validated['description'],
                Money::format((int) $entry->balance_after_minor),
            ),
            'Community Engagement',
            ['cooperative' => $cooperative->code, 'sensitive' => 'community.coop.savings'],
            $this->currentUser(),
        );

        return back()->with('success', 'Fund entry posted.');
    }
}
