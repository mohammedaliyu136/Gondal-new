<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\TransportPayment;
use App\Models\TransportPaymentDisbursement;
use App\Models\TransportPaymentRun;
use App\Models\TransportPaymentTrip;
use App\Models\Trip;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — recording money actually handed to a rider or driver.
 *
 * Weaker than the farmer side in one specific way, and the code should say so: a
 * farmer's payout is checked against a computed figure the farmer can dispute,
 * whereas a rider's fee was set by whoever logged the trip. The control that
 * matters here is therefore upstream — `logistics.trips.create` and
 * `logistics.payments.disburse` should not sit on the same person, so that
 * logging a leg and paying for it are two signatures rather than one.
 *
 * Simpler than the farmer side in another: there is no held state. A rider has
 * no revalidation to fail, so an approved line is always payable.
 */
class TransportDisbursementService
{
    /** @var array<int, string> */
    public const METHODS = ['cash', 'bank', 'mobile_money'];

    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
        private readonly DriverWalletService $driverWallets,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(TransportPayment $payment, array $data, User $actor): TransportPaymentDisbursement
    {
        $this->access->authorize(
            $actor,
            'logistics.payments.disburse',
            $payment->run,
            'Record a payout to '.($payment->driver?->name ?? 'a driver'),
        );

        if (! $payment->run?->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s has not been approved. Nothing may be paid out against it yet.',
                    $payment->run?->reference ?? 'This run'),
                ['status' => $payment->run?->status],
                'transport_payment_run_id',
            );
        }

        if ($payment->status === TransportPayment::STATUS_REVERSED) {
            throw RuleViolationException::make(
                'ST-1',
                'That payment has been reversed. Nothing may be paid against it.',
                ['transport_payment_id' => $payment->getKey()],
                'status',
            );
        }

        $amount = (int) ($data['amount_minor'] ?? 0);

        if ($amount <= 0) {
            throw RuleViolationException::make(
                'BR-2', 'A payout must be more than zero.',
                ['amount_minor' => $amount], 'amount_minor',
            );
        }

        $outstanding = $payment->outstandingMinor();

        if ($amount > $outstanding) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('That is more than is owed. %s outstanding on this payment.', Money::format($outstanding)),
                ['outstanding_minor' => $outstanding, 'attempted_minor' => $amount],
                'amount_minor',
            );
        }

        $method = (string) ($data['method'] ?? 'cash');

        if (! in_array($method, self::METHODS, true)) {
            throw RuleViolationException::make(
                'BR-2', 'That is not a payment method the system knows.',
                ['method' => $method], 'method',
            );
        }

        return DB::transaction(function () use ($payment, $data, $actor, $amount, $method): TransportPaymentDisbursement {
            $disbursement = TransportPaymentDisbursement::query()->create([
                'transport_payment_id' => $payment->getKey(),
                'amount_minor' => $amount,
                'method' => $method,
                'external_reference' => $data['external_reference'] ?? null,
                'paid_by_user_id' => $actor->getKey(),
                'received_by' => $data['received_by'] ?? $payment->driver?->name,
                'disbursed_at' => Wat::instant($data['disbursed_at'] ?? null) ?? Wat::now(),
            ]);

            // Fully paid closes the line and, with it, the legs. A part payment
            // leaves both open, so the remainder still reads as owed.
            if ($payment->fresh()->outstandingMinor() === 0) {
                $payment->forceFill(['status' => TransportPayment::STATUS_PAID])->save();

                Trip::withoutDataScope()
                    ->whereIn('id', $payment->lines()->select('trip_id'))
                    ->update(['payment_status' => Trip::PAYMENT_PAID]);
            }

            // Debit the driver's electronic wallet for the payout
            if ($payment->driver) {
                $this->driverWallets->debit(
                    driver: $payment->driver,
                    amountMinor: $amount,
                    type: \App\Models\DriverWalletTransaction::TYPE_DEBIT,
                    source: $disbursement,
                    description: sprintf('Transport payout (%s)', $payment->run?->reference ?? 'Payout'),
                    actor: $actor,
                    meta: [
                        'transport_payment_id' => $payment->id,
                        'transport_payment_run_id' => $payment->transport_payment_run_id,
                        'disbursement_id' => $disbursement->id,
                        'method' => $method,
                        'received_by' => $disbursement->received_by,
                    ]
                );
            }

            $this->closeRunIfSettled($payment->run);

            $this->audit->created(
                $disbursement,
                sprintf('%s paid to %s by %s (%s)',
                    Money::format($amount), $payment->driver?->name ?? 'driver', $actor->name, $method),
                'Logistics',
                [
                    'run' => $payment->run?->reference,
                    'method' => $method,
                    'received_by' => $disbursement->received_by,
                    'trips' => $payment->trip_count,
                ],
                $actor,
            );

            return $disbursement;
        });
    }

    /**
     * What the run still has to hand over, and what it has.
     *
     * @return array{payable: int, disbursed: int, outstanding: int, drivers: int, paid: int, trips: int}
     */
    public function reconcile(TransportPaymentRun $run): array
    {
        $payments = $run->payments()->get();
        $live = $payments->where('status', '!=', TransportPayment::STATUS_REVERSED);

        $disbursed = (int) TransportPaymentDisbursement::query()
            ->whereIn('transport_payment_id', $payments->pluck('id'))
            ->sum('amount_minor');

        $payable = (int) $live->sum('amount_minor');

        return [
            'payable' => $payable,
            'disbursed' => $disbursed,
            'outstanding' => $payable - $disbursed,
            'drivers' => $live->count(),
            'paid' => $live->where('status', TransportPayment::STATUS_PAID)->count(),
            'trips' => (int) $live->sum('trip_count'),
        ];
    }

    /**
     * Undo one driver's payment.
     *
     * Same two shapes as the farmer side. Nothing paid yet: the claims are
     * deleted and the legs go back to unpaid, so the next run picks them up.
     * Money already handed over cannot be un-handed — the line is marked
     * reversed, the legs are STILL released, and the overpayment is a fact the
     * audit entry records rather than a debt the system can recover, because
     * there is no rider ledger to carry one.
     */
    public function reverse(TransportPayment $payment, User $actor, string $reason): TransportPayment
    {
        $this->access->authorize(
            $actor,
            'logistics.payments.reverse',
            $payment->run,
            'Reverse the payment to '.($payment->driver?->name ?? 'a driver'),
        );

        if ($payment->status === TransportPayment::STATUS_REVERSED) {
            throw RuleViolationException::make(
                'ST-1', 'That payment has already been reversed.',
                ['transport_payment_id' => $payment->getKey()], 'status',
            );
        }

        $disbursed = (int) $payment->disbursements()->sum('amount_minor');

        return DB::transaction(function () use ($payment, $actor, $reason, $disbursed): TransportPayment {
            Trip::withoutDataScope()
                ->whereIn('id', $payment->lines()->select('trip_id'))
                ->update(['payment_status' => Trip::PAYMENT_QUEUED, 'payment_run_id' => null]);

            $payment->lines()->delete();
            $payment->forceFill(['status' => TransportPayment::STATUS_REVERSED])->save();

            $this->audit->edited(
                $payment,
                sprintf('Payment to %s reversed — %s%s',
                    $payment->driver?->name ?? 'driver',
                    $reason,
                    $disbursed > 0
                        // Said plainly because there is nowhere else for it to
                        // go: no rider ledger exists to carry the balance, so
                        // recovering it is a conversation, not a database row.
                        ? sprintf('. %s had already been handed over and is NOT recovered by this reversal.',
                            Money::format($disbursed))
                        : '. Nothing had been paid out.',
                ),
                'Logistics',
                ['status' => $payment->getOriginal('status'), 'amount_minor' => $payment->amount_minor],
                ['status' => TransportPayment::STATUS_REVERSED, 'unrecovered_minor' => $disbursed, 'reason' => $reason],
                $actor,
            );

            $this->refreshRunTotals($payment->run);

            return $payment->refresh();
        });
    }

    /** @return array{reversed: int, unrecovered_minor: int} */
    public function reverseRun(TransportPaymentRun $run, User $actor, string $reason): array
    {
        $this->access->authorize($actor, 'logistics.payments.reverse', $run, 'Reverse '.$run->reference);

        if (! $run->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s is %s. An unapproved run is cancelled, not reversed.', $run->reference, $run->status),
                ['status' => $run->status], 'status',
            );
        }

        $reversed = 0;
        $unrecovered = 0;

        foreach ($run->payments()->where('status', '!=', TransportPayment::STATUS_REVERSED)->get() as $payment) {
            $unrecovered += (int) $payment->disbursements()->sum('amount_minor');
            $this->reverse($payment, $actor, $reason);
            $reversed++;
        }

        $run->forceFill(['status' => TransportPaymentRun::STATUS_CANCELLED])->save();

        $this->audit->edited(
            $run,
            sprintf('%s reversed — %d payment(s), %s already handed over',
                $run->reference, $reversed, Money::format($unrecovered)),
            'Logistics',
            ['status' => TransportPaymentRun::STATUS_APPROVED],
            ['status' => TransportPaymentRun::STATUS_CANCELLED, 'reason' => $reason],
            $actor,
        );

        return ['reversed' => $reversed, 'unrecovered_minor' => $unrecovered];
    }

    /* ------------------------------------------------------------------ */

    private function closeRunIfSettled(?TransportPaymentRun $run): void
    {
        if ($run === null || $run->status === TransportPaymentRun::STATUS_PAID) {
            return;
        }

        $unsettled = $run->payments()
            ->where('status', TransportPayment::STATUS_PAYABLE)
            ->exists();

        if (! $unsettled) {
            $run->forceFill(['status' => TransportPaymentRun::STATUS_PAID, 'paid_at' => Wat::now()])->save();
        }
    }

    /** Totals drift once a line is reversed; recompute rather than decrement. */
    private function refreshRunTotals(?TransportPaymentRun $run): void
    {
        if ($run === null) {
            return;
        }

        $live = $run->payments()->where('status', '!=', TransportPayment::STATUS_REVERSED)->get();

        $run->forceFill([
            'total_minor' => (int) $live->sum('amount_minor'),
            'trip_count' => (int) $live->sum('trip_count'),
            'driver_count' => $live->count(),
        ])->save();
    }

    /** Legs still unpaid across the whole network — the "should I run this?" figure. */
    public function unclaimedTripCount(): int
    {
        return Trip::withoutDataScope()
            ->excludingTestData()
            ->whereNotNull('arrived_at')
            ->whereNotNull('driver_id')
            ->where('fee_minor', '>', 0)
            ->whereNotIn('id', TransportPaymentTrip::query()->select('trip_id'))
            ->count();
    }
}
