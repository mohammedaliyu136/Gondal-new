<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\FarmerPayment;
use App\Models\PaymentRun;
use App\Models\PendingFarmerDeduction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — undoing a payment that was wrong.
 *
 * Two situations that look alike and are not:
 *
 *   NOTHING WAS PAID YET. The line is released: its claims on deliveries are
 *   deleted, so the milk becomes payable again on the next run, and any shop
 *   debt it settled goes back to pending. Nothing is owed by anybody. This is
 *   an erasure, and it is safe.
 *
 *   MONEY ALREADY LEFT. It cannot be un-handed. The line is marked reversed and
 *   the amount already disbursed becomes a DEBT the farmer carries, recovered
 *   from later milk — which means an administrative error takes food off
 *   somebody's table next fortnight. That is a real harm the software creates by
 *   being correct, so the recovery is capped (§1.6) and the reason is recorded
 *   in words a farmer could be read.
 *
 * The claims are DELETED rather than flagged in both cases, because the UNIQUE
 * on farmer_payment_deliveries.delivery_id is what lets a later run pick the
 * milk up again. Leaving a tombstone row would make the milk unpayable forever.
 */
class FarmerPaymentReversalService
{
    /**
     * §1.6 of the plan — how much of a farmer's gross may be taken in one cycle
     * to recover a debt.
     *
     * Without a cap, a farmer with a large clawback receives zero for several
     * fortnights running while the balance grows, and there is no channel to
     * warn them: they find out standing at a collection point. A row rather than
     * a constant, because the right number is the cooperative's to choose.
     */
    private const RECOVERY_CAP_SETTING = 'cooperative.max_debt_recovery_pct';

    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
        private readonly FarmerDeductionPostingService $postings,
    ) {}

    /**
     * Reverse one farmer's payment.
     *
     * @param  string  $reason  shown to whoever asks why, including the farmer
     */
    public function reversePayment(FarmerPayment $payment, User $actor, string $reason): FarmerPayment
    {
        $this->access->authorize(
            $actor,
            'finance.farmer_payments.reverse',
            $payment->run,
            'Reverse the payment to '.($payment->farmer?->name ?? 'a farmer'),
        );

        if ($payment->status === FarmerPayment::STATUS_REVERSED) {
            throw RuleViolationException::make(
                'ST-1',
                'That payment has already been reversed.',
                ['farmer_payment_id' => $payment->getKey()],
                'status',
            );
        }

        $disbursed = (int) $payment->disbursements()->sum('amount_minor');

        return DB::transaction(function () use ($payment, $actor, $reason, $disbursed): FarmerPayment {
            // Release the milk. Deleting the claims is what makes it payable
            // again — see the note on the UNIQUE in the class docblock.
            $payment->lines()->delete();

            // A shop debt this payment settled was only settled because the
            // payment happened. It did not.
            $settled = collect($payment->breakdown['settled_deduction_ids'] ?? []);

            if ($settled->isNotEmpty()) {
                PendingFarmerDeduction::query()->whereIn('id', $settled->all())->update([
                    'status' => PendingFarmerDeduction::STATUS_PENDING,
                    'settled_at' => null,
                ]);
            }

            /*
             * Money that already left becomes a debt. This is the only place in
             * the system that creates a deduction the farmer did not agree to,
             * so it says plainly what it is and which run it came from.
             */
            if ($disbursed > 0) {
                PendingFarmerDeduction::query()->create([
                    'farmer_id' => $payment->farmer_id,
                    'amount_minor' => $disbursed,
                    'description' => sprintf('Overpayment on %s — %s',
                        $payment->run?->reference ?? 'a payment run', $reason),
                    'status' => PendingFarmerDeduction::STATUS_PENDING,
                    'created_by_user_id' => $actor->getKey(),
                ]);
            }

            /*
             * The cooperative's pools gave up their share too. Posted as
             * opposite entries rather than deleted, because the ledger stamps
             * `balance_after_minor` onto every row and removing one would
             * restate what a member was told their balance was last month.
             */
            $this->postings->reverseForPayment($payment, $actor, sprintf(
                'Payment to %s reversed — %s', $payment->farmer?->name ?? 'farmer', $reason,
            ));

            $payment->forceFill([
                'status' => FarmerPayment::STATUS_REVERSED,
                'hold_reason' => null,
            ])->save();

            $this->audit->edited(
                $payment,
                sprintf('Payment to %s reversed — %s%s',
                    $payment->farmer?->name ?? 'farmer',
                    $reason,
                    $disbursed > 0
                        ? sprintf('. %s already paid is now recoverable from future milk.', Money::format($disbursed))
                        : '. Nothing had been paid out.',
                ),
                'Finance',
                ['status' => $payment->getOriginal('status'), 'net_minor' => $payment->net_minor],
                ['status' => FarmerPayment::STATUS_REVERSED, 'clawback_minor' => $disbursed, 'reason' => $reason],
                $actor,
            );

            $this->refreshRunTotals($payment->run);

            return $payment->refresh();
        });
    }

    /**
     * Reverse an entire run — every line, one by one.
     *
     * Deliberately not a bulk UPDATE: each line's clawback and released debts
     * are individual facts, and a farmer whose payment is reversed as part of a
     * batch deserves the same per-farmer audit entry as one reversed alone.
     *
     * @return array{reversed: int, clawback_minor: int}
     */
    public function reverseRun(PaymentRun $run, User $actor, string $reason): array
    {
        $this->access->authorize($actor, 'finance.farmer_payments.reverse', $run, 'Reverse '.$run->reference);

        if (! $run->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s is %s. An unapproved run is cancelled, not reversed.', $run->reference, $run->status),
                ['status' => $run->status],
                'status',
            );
        }

        $reversed = 0;
        $clawback = 0;

        foreach ($run->payments()->where('status', '!=', FarmerPayment::STATUS_REVERSED)->get() as $payment) {
            $clawback += (int) $payment->disbursements()->sum('amount_minor');
            $this->reversePayment($payment, $actor, $reason);
            $reversed++;
        }

        $run->forceFill(['status' => PaymentRun::STATUS_CANCELLED])->save();

        $this->audit->edited(
            $run,
            sprintf('%s reversed — %d payment(s), %s recoverable', $run->reference, $reversed, Money::format($clawback)),
            'Finance',
            ['status' => PaymentRun::STATUS_APPROVED],
            ['status' => PaymentRun::STATUS_CANCELLED, 'reason' => $reason],
            $actor,
        );

        return ['reversed' => $reversed, 'clawback_minor' => $clawback];
    }

    /**
     * The most a debt may take from one payment.
     *
     * Returns the full gross when no cap is configured, which is the behaviour
     * that existed before this setting — recover everything. Setting it to, say,
     * 50 means a farmer always takes home half their milk money however much
     * they owe.
     */
    public function recoveryCeilingMinor(int $grossMinor): int
    {
        $pct = Settings::get(self::RECOVERY_CAP_SETTING);

        if ($pct === null || (float) $pct <= 0 || (float) $pct >= 100) {
            return $grossMinor;
        }

        return Money::percentageOf($grossMinor, $pct);
    }

    /** Totals drift once a line is reversed; recompute rather than decrement. */
    private function refreshRunTotals(?PaymentRun $run): void
    {
        if ($run === null) {
            return;
        }

        $live = $run->payments()->where('status', '!=', FarmerPayment::STATUS_REVERSED)->get();
        $held = $live->where('status', FarmerPayment::STATUS_HELD);

        $run->forceFill([
            'gross_total_minor' => (int) $live->sum('gross_minor'),
            'deductions_total_minor' => (int) $live->sum('gross_minor') - (int) $live->sum('net_minor'),
            'net_total_minor' => (int) $live->sum('net_minor'),
            'held_net_minor' => (int) $held->sum('net_minor'),
            'cash_required_minor' => (int) $live->sum('net_minor') - (int) $held->sum('net_minor'),
            'farmer_count' => $live->count(),
            'held_count' => $held->count(),
        ])->save();
    }

    /** For the reversal screen: what reversing this would cost the farmer. */
    public function clawbackPreviewMinor(FarmerPayment $payment): int
    {
        return (int) $payment->disbursements()->sum('amount_minor');
    }

    public function reversedAt(FarmerPayment $payment): ?string
    {
        return $payment->status === FarmerPayment::STATUS_REVERSED
            ? Wat::dateTime($payment->updated_at)
            : null;
    }
}
