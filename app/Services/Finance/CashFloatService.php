<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CashFloat;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\PaymentRun;
use App\Models\TransportPayment;
use App\Models\TransportPaymentDisbursement;
use App\Models\TransportPaymentRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — the cash book.
 *
 * Closes the second leg of a payout. `farmer_payment_disbursements` says what
 * the farmer got; this says what the officer drew and what came back, and the
 * difference between the two is a number with somebody's name on it.
 *
 * THE ARITHMETIC, and it is deliberately simple enough to check by hand:
 *
 *     variance = drawn − disbursed − returned
 *
 * where `disbursed` is what THIS officer recorded handing over against THIS
 * float's purpose, counted by the system rather than by the person holding the
 * bag. A positive variance is money unaccounted for; a negative one means they
 * paid out more than they drew, which is its own kind of problem and is not
 * quietly floored to zero.
 *
 * WHAT IT CANNOT DO. A collusive officer with an absent farmer defeats this the
 * same way they defeat a signed sheet. The claim is narrower and worth making
 * anyway: an unexplained gap becomes discoverable by somebody who was not there.
 */
class CashFloatService
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hand a float out.
     *
     * @param  PaymentRun|TransportPaymentRun|null  $purpose
     */
    public function issue(
        User $holder,
        int $amountMinor,
        User $actor,
        ?Model $purpose = null,
        ?int $collectionCenterId = null,
        ?string $notes = null,
    ): CashFloat {
        $this->access->authorize($actor, 'finance.cash.issue', null, 'Issue a cash float');

        if ($amountMinor <= 0) {
            throw RuleViolationException::make(
                'BR-2', 'A float of zero is not a float.',
                ['amount_minor' => $amountMinor], 'amount_drawn_minor',
            );
        }

        /*
         * Two people, always.
         *
         * A float somebody issues to themselves is a spreadsheet, not a
         * control — the whole value of this record is that the money passed
         * between two names. This is the same principle as BR-18 on approvals
         * and it is enforced here rather than left to the form.
         */
        if ($holder->getKey() === $actor->getKey()) {
            throw RuleViolationException::make(
                'BR-18',
                'A float cannot be issued to yourself. Someone else has to hand it over.',
                ['user_id' => $actor->getKey()],
                'drawn_by_user_id',
            );
        }

        // One open float per person at a time. Two bags with one name on them
        // makes every variance arguable — "that shortfall was on the other one".
        $open = CashFloat::withoutDataScope()
            ->openFloats()
            ->where('drawn_by_user_id', $holder->getKey())
            ->first();

        if ($open !== null) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s still holds %s on %s. That has to be reconciled first.',
                    $holder->name, Money::format((int) $open->amount_drawn_minor), $open->reference),
                ['open_float' => $open->reference],
                'drawn_by_user_id',
            );
        }

        return DB::transaction(function () use ($holder, $amountMinor, $actor, $purpose, $collectionCenterId, $notes): CashFloat {
            $float = CashFloat::query()->create([
                'reference' => Sequences::next('cash_floats'),
                'purpose_type' => $purpose?->getMorphClass(),
                'purpose_id' => $purpose?->getKey(),
                'collection_center_id' => $collectionCenterId,
                'amount_drawn_minor' => $amountMinor,
                'drawn_by_user_id' => $holder->getKey(),
                'issued_by_user_id' => $actor->getKey(),
                'opened_at' => Wat::now(),
                'status' => CashFloat::STATUS_OPEN,
                'notes' => $notes,
            ]);

            $this->audit->created(
                $float,
                sprintf('%s issued to %s by %s%s',
                    Money::format($amountMinor), $holder->name, $actor->name,
                    $purpose !== null ? ' for '.($purpose->reference ?? class_basename($purpose)) : ''),
                'Finance',
                ['float' => $float->reference, 'holder' => $holder->name],
                $actor,
            );

            return $float->refresh();
        });
    }

    /**
     * What this float's holder has actually been recorded handing over.
     *
     * Counted by the system from the disbursement tables, filtered to the
     * holder and — where the float names one — to its purpose. Both farmer and
     * transport payouts count, because at a centre on a payout morning they come
     * out of the same bag.
     */
    public function disbursedMinor(CashFloat $float): int
    {
        $holderId = (int) $float->drawn_by_user_id;
        $since = $float->opened_at;

        $farmer = FarmerPaymentDisbursement::query()
            ->where('paid_by_user_id', $holderId)
            ->when($since !== null, fn ($query) => $query->where('disbursed_at', '>=', $since))
            ->when($float->purpose_type === (new PaymentRun)->getMorphClass(),
                fn ($query) => $query->whereIn('farmer_payment_id',
                    FarmerPayment::query()->where('payment_run_id', $float->purpose_id)->select('id')))
            ->sum('amount_minor');

        $transport = TransportPaymentDisbursement::query()
            ->where('paid_by_user_id', $holderId)
            ->when($since !== null, fn ($query) => $query->where('disbursed_at', '>=', $since))
            ->when($float->purpose_type === (new TransportPaymentRun)->getMorphClass(),
                fn ($query) => $query->whereIn('transport_payment_id',
                    TransportPayment::query()->where('transport_payment_run_id', $float->purpose_id)->select('id')))
            ->sum('amount_minor');

        return (int) $farmer + (int) $transport;
    }

    /**
     * Sign a float back in.
     *
     * The variance is STAMPED, not recomputed on read, for the same reason
     * `cooperative_entries.balance_after_minor` is: a disbursement corrected
     * next month must not silently restate a figure somebody was already asked
     * to explain.
     */
    public function reconcile(
        CashFloat $float,
        int $returnedMinor,
        User $actor,
        ?string $explanation = null,
    ): CashFloat {
        $this->access->authorize($actor, 'finance.cash.reconcile', $float, 'Reconcile '.$float->reference);

        if (! $float->isOpen()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s has already been reconciled.', $float->reference),
                ['status' => $float->status], 'status',
            );
        }

        /*
         * The holder may not sign their own bag back in — the reconciliation is
         * the whole control, and a control the controlled party performs is not
         * one. `finance.cash.reconcile` is already withheld from the Collection
         * Officer; this catches the case where somebody holds it for another
         * reason and happens to be carrying a float.
         */
        if ((int) $float->drawn_by_user_id === $actor->getKey()) {
            throw RuleViolationException::make(
                'BR-18',
                'You cannot reconcile a float you were carrying. Someone else has to receive it.',
                ['float' => $float->reference],
                'received_back_by_user_id',
            );
        }

        if ($returnedMinor < 0) {
            throw RuleViolationException::make(
                'BR-2', 'A negative return is not a return.',
                ['amount_returned_minor' => $returnedMinor], 'amount_returned_minor',
            );
        }

        $disbursed = $this->disbursedMinor($float);
        $variance = (int) $float->amount_drawn_minor - $disbursed - $returnedMinor;

        /*
         * A variance has to be explained in words before it can be filed. NOT
         * because the words are verifiable — they are not — but because
         * "₦4,000 short, no reason given" and "₦4,000 short, note torn, paid
         * from own pocket" are different records to somebody reading fourteen
         * of these looking for a pattern.
         */
        if ($variance !== 0 && trim((string) $explanation) === '') {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('%s is unaccounted for. That has to be explained before the float can be closed.',
                    Money::format(abs($variance))),
                ['variance_minor' => $variance],
                'variance_explanation',
            );
        }

        return DB::transaction(function () use ($float, $returnedMinor, $actor, $explanation, $disbursed, $variance): CashFloat {
            $float->forceFill([
                'amount_returned_minor' => $returnedMinor,
                'received_back_by_user_id' => $actor->getKey(),
                'returned_at' => Wat::now(),
                'disbursed_minor' => $disbursed,
                'variance_minor' => $variance,
                'variance_explanation' => $explanation,
                'status' => CashFloat::STATUS_RECONCILED,
            ])->save();

            $this->audit->edited(
                $float,
                sprintf('%s reconciled — %s drawn, %s disbursed, %s returned, %s %s',
                    $float->reference,
                    Money::format((int) $float->amount_drawn_minor),
                    Money::format($disbursed),
                    Money::format($returnedMinor),
                    Money::format(abs($variance)),
                    $variance === 0 ? 'variance' : ($variance > 0 ? 'UNACCOUNTED FOR' : 'paid over'),
                ),
                'Finance',
                ['status' => CashFloat::STATUS_OPEN],
                [
                    'status' => CashFloat::STATUS_RECONCILED,
                    'variance_minor' => $variance,
                    'explanation' => $explanation,
                    'received_by' => $actor->name,
                ],
                $actor,
            );

            return $float->refresh();
        });
    }

    /**
     * Who is still holding money, and how much of it the system cannot see.
     *
     * The screen this whole table exists for.
     *
     * @return array{floats: int, drawn: int, disbursed: int, unaccounted: int}
     */
    public function outstanding(): array
    {
        $open = CashFloat::query()->openFloats()->get();

        $drawn = (int) $open->sum('amount_drawn_minor');
        $disbursed = $open->sum(fn (CashFloat $float) => $this->disbursedMinor($float));

        return [
            'floats' => $open->count(),
            'drawn' => $drawn,
            'disbursed' => (int) $disbursed,
            'unaccounted' => $drawn - (int) $disbursed,
        ];
    }
}
