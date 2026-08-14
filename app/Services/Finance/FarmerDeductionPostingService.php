<?php

namespace App\Services\Finance;

use App\Models\CooperativeAccount;
use App\Models\CooperativeEntry;
use App\Models\FarmerPayment;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Community\CooperativeLedgerService;
use App\Support\Money;

/**
 * §14 Phase 7 — sending a farmer's deductions somewhere.
 *
 * THE HOLE THIS CLOSES. The payment run computed a savings percentage, a levy
 * and a social contribution, subtracted all three from the farmer, wrote them on
 * the payment row, and credited nothing. The money came off a household and
 * stopped existing anywhere in the system. `cooperative_entries` was empty while
 * the deductions were real, which meant a farmer asking "where are my savings?"
 * could be shown a number on a payslip and no account holding it.
 *
 * WHERE EACH ONE GOES, and why the three are not one posting:
 *
 *   savings → the SAVINGS account. Members' money, held. Kept off the general
 *             account so a cooperative's shop debt cannot eat it.
 *   levy    → the GENERAL account. This one genuinely is the cooperative's
 *             income — it is what the levy is for.
 *   social  → the SOCIAL account. A bereavement fund is not working capital.
 *
 * WHEN. On APPROVAL of the run, not on generation and not on disbursement.
 * Generation would fill the ledger with entries a cancelled draft then has to
 * unwind. Disbursement would leave the deductions unposted for a farmer whose
 * payment is held under BR-36 — whose milk was still collected and whose savings
 * was still taken. Approval is the point the figures become committed, and this
 * is therefore an ACCRUAL: the pool shows what has been deducted, not what has
 * been banked.
 *
 * A farmer with no cooperative has all three at zero and posts nothing. That is
 * a real case, not an error.
 */
class FarmerDeductionPostingService
{
    public function __construct(
        private readonly CooperativeLedgerService $ledger,
    ) {}

    /**
     * Post every live line's deductions. Safe to call twice.
     *
     * @return int the number of entries written
     */
    public function postForRun(PaymentRun $run, ?User $actor = null): int
    {
        $written = 0;

        foreach ($run->payments()->with('farmer.cooperative.accounts')->get() as $payment) {
            if ($payment->status === FarmerPayment::STATUS_REVERSED) {
                continue;
            }

            $written += $this->postForPayment($payment, $actor);
        }

        return $written;
    }

    /**
     * @return int the number of entries written
     */
    public function postForPayment(FarmerPayment $payment, ?User $actor = null): int
    {
        $cooperative = $payment->farmer?->cooperative;

        if ($cooperative === null) {
            return 0;
        }

        /*
         * Idempotency. syncFromWorkflow can be reached more than once for the
         * same instance — an approver acting twice on a stale page, a retried
         * job — and posting the same savings twice would inflate a pool nobody
         * can reconcile back down. The payment is the source, so its presence
         * in the ledger is the guard.
         */
        if ($this->alreadyPosted($payment)) {
            return 0;
        }

        $name = $payment->farmer?->name ?? 'a farmer';
        $reference = $payment->run?->reference ?? 'a payment run';
        $written = 0;

        $written += $this->credit(
            $cooperative->savingsAccount(),
            (int) $payment->savings_minor,
            sprintf('Savings deducted from %s — %s', $name, $reference),
            $payment,
            $actor,
        );

        $written += $this->credit(
            $cooperative->generalAccount(),
            (int) $payment->levy_minor,
            sprintf('Levy from %s — %s', $name, $reference),
            $payment,
            $actor,
        );

        $written += $this->credit(
            $cooperative->socialAccount(),
            (int) $payment->social_minor,
            sprintf('Social contribution from %s — %s', $name, $reference),
            $payment,
            $actor,
        );

        return $written;
    }

    /**
     * Undo the postings for one payment.
     *
     * Posts the opposite entries rather than deleting the originals: the ledger
     * stamps `balance_after_minor` onto every row, so deleting one would restate
     * what a member was told their balance was. The reversal is a movement of
     * its own, which is also the only honest way to show it happened.
     *
     * @return int the number of entries written
     */
    public function reverseForPayment(FarmerPayment $payment, User $actor, string $reason): int
    {
        $entries = CooperativeEntry::query()
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->getKey())
            ->where('direction', CooperativeEntry::DIRECTION_IN)
            ->get();

        $written = 0;

        foreach ($entries as $entry) {
            // An entry that has already been reversed must not be reversed
            // again — the guard is the presence of an opposite entry citing it.
            $undone = CooperativeEntry::query()
                ->where('source_type', $entry->getMorphClass())
                ->where('source_id', $entry->getKey())
                ->exists();

            if ($undone) {
                continue;
            }

            $this->ledger->reverse($entry, $reason, $actor);
            $written++;
        }

        return $written;
    }

    /** What the cooperative's pools hold, for a screen that wants all three. */
    public function poolsFor(int $cooperativeId): array
    {
        return CooperativeAccount::query()
            ->where('cooperative_id', $cooperativeId)
            ->get()
            ->mapWithKeys(fn (CooperativeAccount $account) => [
                $account->kind => (int) $account->balance_minor,
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */

    private function alreadyPosted(FarmerPayment $payment): bool
    {
        return CooperativeEntry::query()
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->getKey())
            ->where('direction', CooperativeEntry::DIRECTION_IN)
            ->exists();
    }

    private function credit(
        ?CooperativeAccount $account,
        int $amountMinor,
        string $description,
        FarmerPayment $payment,
        ?User $actor,
    ): int {
        // Zero is not an entry, and a cooperative registered before the savings
        // account existed may not have one. Neither is an error worth stopping a
        // payment run for; both are worth not pretending about.
        if ($account === null || $amountMinor <= 0) {
            return 0;
        }

        $this->ledger->post(
            $account,
            CooperativeEntry::DIRECTION_IN,
            $amountMinor,
            $description,
            $payment,
            $actor,
        );

        return 1;
    }

    /** For an audit line that says what a run moved into the pools. */
    public function summaryFor(PaymentRun $run): string
    {
        $payments = $run->payments()->where('status', '!=', FarmerPayment::STATUS_REVERSED)->get();

        return sprintf('savings %s, levy %s, social %s',
            Money::format((int) $payments->sum('savings_minor')),
            Money::format((int) $payments->sum('levy_minor')),
            Money::format((int) $payments->sum('social_minor')),
        );
    }
}
