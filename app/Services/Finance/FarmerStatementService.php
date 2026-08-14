<?php

namespace App\Services\Finance;

use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\PendingFarmerDeduction;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Collection;

/**
 * §14 Phase 7, increment 5 — one farmer's money history, on one page.
 *
 * USER-2 governs the whole shape of this. A farmer is a record, not an account:
 * there is no login, no portal, no notification. A statement is something an
 * officer PRINTS FOR a farmer and hands over — often to somebody who will have it
 * read to them — which is why it is a single flat page of dated lines rather
 * than a dashboard, and why every figure is spelled out in naira instead of
 * being summarised into a balance.
 *
 * The three questions it has to answer, in the order a farmer asks them:
 *
 *   1. What am I owed right now, for milk nobody has paid me for yet?
 *   2. What have I been paid, and for which milk?
 *   3. What is being taken off, and why?
 *
 * NOT A LEDGER. Nothing here is stored or reconciled; every figure is recomputed
 * from the same rows the payment run reads. A stored statement would be a second
 * source of truth that drifts from the first — see the note on `owedMinor()`.
 */
class FarmerStatementService
{
    public function __construct(
        private readonly FarmerPaymentCalculator $calculator,
    ) {}

    /**
     * @return array{
     *     farmer: Farmer, generated_at: string, outstanding: array<string, mixed>,
     *     payments: Collection<int, FarmerPayment>, disbursements: Collection<int, FarmerPaymentDisbursement>,
     *     deductions: Collection<int, PendingFarmerDeduction>,
     *     totals: array<string, mixed>
     * }
     */
    public function build(Farmer $farmer, ?string $from = null, ?string $to = null): array
    {
        /*
         * REVERSED payments are shown, not hidden.
         *
         * A farmer who was paid and then had it reversed carries a debt for it,
         * and the only honest statement is one where that reversal is visible
         * next to the debt it created. Dropping them would produce a page where
         * money is owed for no reason anyone can point at.
         */
        $payments = FarmerPayment::query()
            ->excludingTestData()
            ->where('farmer_id', $farmer->getKey())
            ->when($from !== null, fn ($query) => $query->whereHas('run',
                fn ($run) => $run->whereDate('created_at', '>=', $from)))
            ->when($to !== null, fn ($query) => $query->whereHas('run',
                fn ($run) => $run->whereDate('created_at', '<=', $to)))
            ->with(['run', 'disbursements.paidBy'])
            ->get()
            ->sortByDesc(fn (FarmerPayment $payment) => $payment->run?->created_at ?? $payment->created_at)
            ->values();

        $disbursements = $payments
            ->flatMap(fn (FarmerPayment $payment) => $payment->disbursements)
            ->sortByDesc('disbursed_at')
            ->values();

        // Both pending and settled, because "why was 1,680 taken off in June?"
        // is the single most common question a statement exists to answer.
        $deductions = PendingFarmerDeduction::query()
            ->excludingTestData()
            ->where('farmer_id', $farmer->getKey())
            ->with('sale')
            ->latest('created_at')
            ->get();

        // Question 1 — milk already delivered that no run has claimed yet. This
        // is the figure a farmer disputes at a collection point, so it is
        // computed the same way the next run will compute it, not estimated.
        $outstanding = $this->calculator->value($farmer);

        $live = $payments->where('status', '!=', FarmerPayment::STATUS_REVERSED);

        return [
            'farmer' => $farmer,
            'generated_at' => Wat::dateTime(Wat::now()),
            'outstanding' => $outstanding,
            'payments' => $payments,
            'disbursements' => $disbursements,
            'deductions' => $deductions,
            'totals' => [
                'litres_paid' => $live->reduce(
                    fn (string $carry, FarmerPayment $payment) => Volume::add($carry, (string) $payment->litres_paid),
                    '0.00',
                ),
                'gross_minor' => (int) $live->sum('gross_minor'),
                'savings_minor' => (int) $live->sum('savings_minor'),
                'levy_minor' => (int) $live->sum('levy_minor'),
                'social_minor' => (int) $live->sum('social_minor'),
                'shop_minor' => (int) $live->sum('shop_deduction_minor'),
                'net_minor' => (int) $live->sum('net_minor'),
                'received_minor' => (int) $disbursements->sum('amount_minor'),
                // What the network still has to hand over on ALREADY-APPROVED
                // runs. Distinct from `outstanding` above, which is unclaimed
                // milk — conflating the two is how a farmer gets told they are
                // owed the same money twice.
                'unpaid_on_runs_minor' => (int) $live->sum('net_minor') - (int) $disbursements->sum('amount_minor'),
                'debt_outstanding_minor' => (int) $deductions
                    ->where('status', PendingFarmerDeduction::STATUS_PENDING)
                    ->sum('amount_minor'),
            ],
        ];
    }
}
