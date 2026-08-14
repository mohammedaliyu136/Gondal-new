<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — recording money actually handed to a farmer.
 *
 * This is the weakest link in the whole ERP and the code should say so. Cash at
 * a collection point, counted into a hand at 06:30, is defended by a signed
 * sheet, a photograph and a spot-check — and a collusive payer with an absent
 * farmer defeats all three. What software can do is make every payout
 * ATTRIBUTABLE (who, when, where, against which computed figure) so that a
 * discrepancy is discoverable afterwards. It cannot make one impossible.
 *
 * The real control is organisational: whoever records the milk should not be the
 * person who hands over the cash, which is why `finance.farmer_payments.disburse`
 * is granted to the Collection Officer and deliberately not to the Collection
 * Agent (docs/PLAN-FARMER-PAYMENTS.md §1.3).
 */
class FarmerDisbursementService
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(FarmerPayment $payment, array $data, User $actor): FarmerPaymentDisbursement
    {
        $this->access->authorize(
            $actor,
            'finance.farmer_payments.disburse',
            $payment->run,
            'Record a payout to '.($payment->farmer?->name ?? 'a farmer'),
        );

        $this->guardRunApproved($payment);
        $this->guardNotHeld($payment);

        $amount = (int) ($data['amount_minor'] ?? 0);

        $this->guardAmount($payment, $amount);

        $method = (string) ($data['method'] ?? FarmerPaymentDisbursement::METHOD_CASH);

        if (! in_array($method, FarmerPaymentDisbursement::METHODS, true)) {
            throw RuleViolationException::make(
                'BR-2',
                'That is not a payment method the system knows.',
                ['method' => $method],
                'method',
            );
        }

        /*
         * A payout to somebody other than the farmer needs to say who and on
         * what authority. This will be routed around — an agent in the rain
         * will type 'self' for a son who came instead — but the field being on
         * the record rather than derivable means an audit can at least ask why
         * one point's collections are 100% 'self'.
         */
        $relation = $data['received_by_relation'] ?? 'self';

        if ($relation !== 'self' && trim((string) ($data['proxy_authority_ref'] ?? '')) === '') {
            throw RuleViolationException::make(
                'BR-2',
                'Paying someone other than the farmer needs a written authority reference.',
                ['relation' => $relation],
                'proxy_authority_ref',
            );
        }

        return DB::transaction(function () use ($payment, $data, $actor, $amount, $method, $relation): FarmerPaymentDisbursement {
            $disbursement = FarmerPaymentDisbursement::query()->create(array_merge(
                $this->coordinates($data),
                [
                    'farmer_payment_id' => $payment->getKey(),
                    'method' => $method,
                    'amount_minor' => $amount,
                    'disbursed_at' => Wat::instant($data['disbursed_at'] ?? null) ?? Wat::now(),
                    'paid_by_user_id' => $actor->getKey(),
                    'received_by' => $data['received_by'] ?? $payment->farmer?->name,
                    'received_by_relation' => $relation,
                    'proxy_authority_ref' => $data['proxy_authority_ref'] ?? null,
                    'external_reference' => $data['external_reference'] ?? null,
                    'signature_evidence_id' => $data['signature_evidence_id'] ?? null,
                ],
            ));

            // Fully paid closes the line. A part payment leaves it payable, so
            // the remainder still shows as owed rather than quietly vanishing.
            if ($payment->fresh()->outstandingMinor() === 0) {
                $payment->forceFill(['status' => FarmerPayment::STATUS_PAID])->save();
            }

            $this->closeRunIfSettled($payment->run);

            $this->audit->created(
                $disbursement,
                sprintf('%s paid to %s by %s (%s)',
                    Money::format($amount),
                    $payment->farmer?->name ?? 'farmer',
                    $actor->name,
                    $method,
                ),
                'Finance',
                [
                    'payment_run' => $payment->run?->reference,
                    'method' => $method,
                    'received_by' => $disbursement->received_by,
                    'relation' => $relation,
                ],
                $actor,
            );

            return $disbursement;
        });
    }

    /**
     * What a point still has to hand over, and what it has.
     *
     * The reconciliation an officer is asked for at the end of a payout morning:
     * issued, disbursed, outstanding. It is the only control here with real
     * teeth, and only when the person disbursing is not the person who recorded
     * the milk.
     *
     * @return array{payable: int, disbursed: int, outstanding: int, held: int, farmers: int, paid: int}
     */
    public function reconcile(PaymentRun $run): array
    {
        $payments = $run->payments()->get();

        $disbursed = (int) FarmerPaymentDisbursement::query()
            ->whereIn('farmer_payment_id', $payments->pluck('id'))
            ->sum('amount_minor');

        $payable = (int) $payments->where('status', '!=', FarmerPayment::STATUS_HELD)->sum('net_minor');

        return [
            'payable' => $payable,
            'disbursed' => $disbursed,
            'outstanding' => $payable - $disbursed,
            'held' => (int) $payments->where('status', FarmerPayment::STATUS_HELD)->sum('net_minor'),
            'farmers' => $payments->count(),
            'paid' => $payments->where('status', FarmerPayment::STATUS_PAID)->count(),
        ];
    }

    /* ------------------------------------------------------------------ */

    private function guardRunApproved(FarmerPayment $payment): void
    {
        if (! $payment->run?->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s has not been approved. Nothing may be paid out against it yet.',
                    $payment->run?->reference ?? 'This run'),
                ['status' => $payment->run?->status],
                'payment_run_id',
            );
        }
    }

    /** BR-36 — the money is owed and recorded; it is not payable yet. */
    private function guardNotHeld(FarmerPayment $payment): void
    {
        if ($payment->isHeld()) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf('%s\'s payment is held until their details are revalidated. '
                    .'The money is recorded and stays owed — it is the check that is missing, not the milk.',
                    $payment->farmer?->name ?? 'This farmer'),
                ['hold_reason' => $payment->hold_reason],
                'farmer_payment_id',
            );
        }
    }

    private function guardAmount(FarmerPayment $payment, int $amount): void
    {
        if ($amount <= 0) {
            throw RuleViolationException::make(
                'BR-2',
                'A payout must be more than zero.',
                ['amount_minor' => $amount],
                'amount_minor',
            );
        }

        $outstanding = $payment->outstandingMinor();

        if ($amount > $outstanding) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('That is more than is owed. %s outstanding on this payment.',
                    Money::format($outstanding)),
                ['outstanding_minor' => $outstanding, 'attempted_minor' => $amount],
                'amount_minor',
            );
        }
    }

    /** Every payable line settled closes the run. Held lines do not block it. */
    private function closeRunIfSettled(?PaymentRun $run): void
    {
        if ($run === null || $run->status === PaymentRun::STATUS_PAID) {
            return;
        }

        $unsettled = $run->payments()
            ->where('status', FarmerPayment::STATUS_PAYABLE)
            ->exists();

        if (! $unsettled) {
            $run->forceFill(['status' => PaymentRun::STATUS_PAID, 'paid_at' => Wat::now()])->save();
        }
    }

    /**
     * Where the payout happened, if the device knew.
     *
     * Same shape and same meaning as the field-capture columns: evidence, never
     * a gate. A payout with no fix is still recordable — refusing it would push
     * the payout off the system, which is the opposite of what evidence is for.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function coordinates(array $data): array
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [];
        }

        if (abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return [];
        }

        $accuracy = $data['location_accuracy_m'] ?? null;

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'location_accuracy_m' => is_numeric($accuracy) ? (int) round((float) $accuracy) : null,
            'located_at' => Wat::instant($data['located_at'] ?? null) ?? Wat::now(),
        ];
    }
}
