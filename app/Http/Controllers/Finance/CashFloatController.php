<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CashFloat;
use App\Models\CollectionCenter;
use App\Models\PaymentRun;
use App\Models\TransportPaymentRun;
use App\Models\User;
use App\Services\Finance\CashFloatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §14 Phase 7 — the cash book.
 *
 * One screen, deliberately: who is holding money right now, and what came back
 * from everyone who was. Splitting it into "issue" and "reconcile" pages would
 * hide the only view that matters, which is the two side by side.
 */
class CashFloatController extends Controller
{
    public function __construct(private readonly CashFloatService $floats) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess('finance.cash.view', null, 'Cash book');

        $floats = CashFloat::query()
            ->with(['drawnBy', 'issuedBy', 'receivedBackBy', 'collectionCenter', 'purpose'])
            ->latest('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('finance.cash.index', [
            'floats' => $floats,
            // Computed per row rather than joined: the disbursed figure spans
            // two disbursement tables and a date window, and a wrong join here
            // would understate what an officer is answerable for.
            'disbursedByFloat' => $floats->getCollection()
                ->mapWithKeys(fn (CashFloat $float) => [$float->id => $this->floats->disbursedMinor($float)])
                ->all(),
            'outstanding' => $this->floats->outstanding(),
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            // Only people who can actually be handed money — holding
            // finance.cash.view is the marker for "works a payout".
            'holders' => User::query()->where('status', 'active')->orderBy('name')->get()
                ->filter(fn (User $user) => $user->hasPermission('finance.farmer_payments.disburse')
                    || $user->hasPermission('logistics.payments.disburse'))
                ->values(),
            'runs' => PaymentRun::query()->open()->latest('id')->limit(25)->get(),
            'transportRuns' => TransportPaymentRun::query()->open()->latest('id')->limit(25)->get(),
            'canIssue' => $this->allows('finance.cash.issue'),
            'canReconcile' => $this->allows('finance.cash.reconcile'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'drawn_by_user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_drawn_minor' => ['required', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:64'],
            'collection_center_id' => ['nullable', 'integer', 'exists:collection_centers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // "farmer:12" / "transport:3" — one select rather than two, because the
        // officer drawing the bag thinks about the morning, not the table.
        $purpose = null;

        if (! empty($validated['purpose']) && str_contains($validated['purpose'], ':')) {
            [$kind, $id] = explode(':', $validated['purpose'], 2);

            $purpose = $kind === 'transport'
                ? TransportPaymentRun::withoutDataScope()->find($id)
                : PaymentRun::withoutDataScope()->find($id);
        }

        $float = $this->floats->issue(
            User::query()->findOrFail($validated['drawn_by_user_id']),
            (int) $validated['amount_drawn_minor'],
            $this->currentUser(),
            $purpose,
            $validated['collection_center_id'] ?? null,
            $validated['notes'] ?? null,
        );

        return back()->with('success', $float->reference.' issued.');
    }

    public function reconcile(Request $request, CashFloat $float): RedirectResponse
    {
        $validated = $request->validate([
            'amount_returned_minor' => ['required', 'integer', 'min:0'],
            'variance_explanation' => ['nullable', 'string', 'max:500'],
        ]);

        $reconciled = $this->floats->reconcile(
            $float,
            (int) $validated['amount_returned_minor'],
            $this->currentUser(),
            $validated['variance_explanation'] ?? null,
        );

        $variance = (int) $reconciled->variance_minor;

        return back()->with('success', $variance === 0
            ? $float->reference.' reconciled — it balances.'
            : sprintf('%s reconciled — %s %s.', $float->reference,
                \App\Support\Money::format(abs($variance)),
                $variance > 0 ? 'unaccounted for' : 'paid out over the float'));
    }
}
