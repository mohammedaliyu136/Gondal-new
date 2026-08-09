<?php

namespace App\Services\Milk;

use App\Exceptions\RuleViolationException;
use App\Models\Adjustment;
use App\Models\AdjustmentReason;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * BR-12 — "Every adjustment requires a reason and an explanation. Adjustments are
 * never silent."
 *
 * Both are enforced here and by NOT NULL columns, and every adjustment writes an
 * audit entry (AUDIT-2). There is no code path that changes a stored volume
 * without producing one of these rows.
 *
 * "Never silent" has two halves and only the first was built. An adjustment was
 * loud in the audit log and mute in the arithmetic: for a DELIVERY it moved no
 * litres, so §17's DEL-0009 — 28 L accepted, −1 L adjustment, 27 L payable —
 * could not be produced by any query the application makes, while the delivery
 * screen told the operator that Record adjustment was how a volume is changed. A
 * control that records nothing is worse than no control, so the delivery's
 * payable volume is now written here, in the same transaction as the row that
 * explains it.
 */
class AdjustmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $litresDelta  signed: "-1.00" deducts, "+1.00" adds
     */
    public function record(
        Model $subject,
        string $litresDelta,
        int $reasonId,
        string $explanation,
        User $actor,
    ): Adjustment {
        $explanation = trim($explanation);

        if ($explanation === '') {
            throw RuleViolationException::make(
                'BR-12',
                'An adjustment needs an explanation. Adjustments are never silent.',
                [],
                'explanation',
            );
        }

        if (Volume::toCentilitres($litresDelta) === 0) {
            throw RuleViolationException::make(
                'BR-12',
                'An adjustment of zero litres is not an adjustment.',
                [],
                'litres_delta',
            );
        }

        /*
         * A consignment's confirmed volume is computed once, at confirmation
         * (BR-8), and confirmation is one-shot (NFR-4). An adjustment recorded
         * after that would sit on the record changing nothing — worse than an
         * error, because it LOOKS recorded. Refuse it and say why.
         */
        if ($subject instanceof Consignment && $subject->isConfirmed()) {
            throw RuleViolationException::make(
                'BR-12',
                sprintf(
                    '%s was already confirmed, so its volume can no longer be adjusted. Ask a supervisor about correcting a confirmed consignment.',
                    $subject->reference,
                ),
                ['consignment' => $subject->reference],
                'litres_delta',
            );
        }

        $reason = AdjustmentReason::query()->find($reasonId);
        $appliesTo = $this->subjectKind($subject);

        if ($reason === null || $reason->status !== 'active') {
            throw RuleViolationException::make(
                'BR-12',
                'Choose a reason from the configured list.',
                [],
                'adjustment_reason_id',
            );
        }

        if (! in_array($reason->applies_to, [$appliesTo, 'any'], true)) {
            throw RuleViolationException::make(
                'BR-12',
                "The reason '{$reason->name}' does not apply to a {$appliesTo}.",
                ['reason' => $reason->code, 'applies_to' => $reason->applies_to],
                'adjustment_reason_id',
            );
        }

        $delta = Volume::fromCentilitres(Volume::toCentilitres($litresDelta));

        $this->guardMagnitude($subject, $delta);

        $adjustment = DB::transaction(function () use ($subject, $reason, $delta, $explanation): Adjustment {
            $adjustment = Adjustment::query()->create([
                'adjustable_type' => $subject->getMorphClass(),
                'adjustable_id' => $subject->getKey(),
                'adjustment_reason_id' => $reason->getKey(),
                'litres_delta' => $delta,
                'explanation' => $explanation,
            ]);

            /*
             * Same transaction as the row that explains it, so there is no
             * instant in which the volume has moved and the reason for it has
             * not — nor the reverse, which is the state the system was in for
             * every delivery adjustment ever recorded.
             */
            if ($subject instanceof Delivery) {
                $this->applyToDelivery($subject, $delta);
            }

            return $adjustment;
        });

        $this->audit->created(
            $adjustment,
            sprintf(
                '%s L adjustment on %s %s — %s',
                $adjustment->signedLitres(),
                class_basename($subject),
                $subject->reference ?? ('#'.$subject->getKey()),
                $reason->name,
            ),
            'Milk Collection',
            [
                'rule' => 'BR-12',
                'reason' => $reason->code,
                'explanation' => $explanation,
                'subject' => class_basename($subject).'#'.$subject->getKey(),
                /*
                 * The resulting figure, not just the delta. An auditor reading
                 * this entry should not have to re-derive what the farmer is now
                 * owed, and `already_dispatched` is what tells them the
                 * consignment's own litres no longer agree with the sum of its
                 * deliveries' payable volumes — see applyToDelivery.
                 */
                ...($subject instanceof Delivery ? [
                    'litres_payable' => $subject->litres_payable,
                    'already_dispatched' => $subject->consignment_id !== null,
                ] : []),
            ],
            $actor,
        );

        return $adjustment;
    }

    /**
     * BR-12 — the delivery is the FARMER's payment unit, so this is the figure a
     * delivery adjustment moves.
     *
     * DM-1's CHECK pins `litres_accepted` to `presented − rejected`, which is
     * still true and stays enforced; the correction lands beside it in
     * `litres_adjusted`, and `litres_payable` carries the result so no reader has
     * to remember the formula. `forceFill` because these three are the
     * arithmetic, not operator input — nothing outside this service may set them.
     *
     * -----------------------------------------------------------------------
     * OPEN — BUSINESS DECISION, NOT GUESSED HERE.
     *
     * BR-7 computes a consignment's `litres_dispatched` as Σ `litres_accepted`.
     * It is deliberately left reading `litres_accepted`, so in §17's own scenario
     * the litre lost decanting into the centre can is deducted from Zainab and
     * still counted in the 28 L the consignment carried. Whether that is right
     * depends on a question nobody has answered: does a delivery adjustment
     * follow only the farmer's payment, or does it also correct the volume the
     * centre is credited with having received? Making BR-7 sum `litres_payable`
     * is a one-line change in ConsignmentService::dispatch once the answer is in;
     * inventing the answer here would silently move a network production total.
     * -----------------------------------------------------------------------
     */
    private function applyToDelivery(Delivery $delivery, string $delta): void
    {
        $adjusted = Volume::add($delivery->litres_adjusted, $delta);

        $delivery->forceFill([
            'litres_adjusted' => $adjusted,
            'litres_payable' => Volume::add($delivery->litres_accepted, $adjusted),
        ])->save();
    }

    /**
     * BR-12 — an adjustment corrects a volume; it cannot invent a negative one.
     *
     * A 28 L delivery accepted a −100 L adjustment, which under the payable
     * formula makes a farmer who owes the cooperative ₦18,000 for handing over
     * milk. For a consignment the same mistake was caught late and fatally: BR-8
     * refuses the confirmation, and since `isBatchable()` needs confirmation the
     * milk could never join a batch, reach the factory or become payable — a
     * mistyped digit stranding real litres with no route back except a
     * compensating adjustment nothing tells the operator about. Both are cheaper
     * to refuse at the keyboard, with the figure that made it impossible quoted.
     */
    private function guardMagnitude(Model $subject, string $delta): void
    {
        [$base, $baseLabel] = match (true) {
            $subject instanceof Delivery => [$subject->litres_payable, 'accepted volume and its earlier adjustments'],
            $subject instanceof Consignment => [
                Volume::add($subject->litres_dispatched, $subject->adjustmentTotal()),
                'dispatched volume and its earlier adjustments',
            ],
            default => [null, ''],
        };

        if ($base === null) {
            return;
        }

        $resulting = Volume::add($base, $delta);

        if (! Volume::isNegative($resulting)) {
            return;
        }

        throw RuleViolationException::make(
            'BR-12',
            sprintf(
                'That adjustment would take %s to %s. The %s is %s.',
                $subject->reference ?? 'this record',
                Volume::format($resulting),
                $baseLabel,
                Volume::format($base),
            ),
            [
                'available' => $base,
                'requested' => $delta,
                'resulting' => $resulting,
            ],
            'litres_delta',
        );
    }

    private function subjectKind(Model $subject): string
    {
        return match (class_basename($subject)) {
            'Delivery' => 'delivery',
            'Consignment' => 'consignment',
            'Batch' => 'batch',
            'Product' => 'stock',
            default => 'any',
        };
    }
}
