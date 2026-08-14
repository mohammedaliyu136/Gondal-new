<?php

namespace App\Services\Finance;

use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FarmerPaymentDelivery;
use App\Models\PendingFarmerDeduction;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Volume;
use Illuminate\Support\Collection;

/**
 * §14 Phase 7 — what a farmer is owed, and exactly how that figure was reached.
 *
 * READ-ONLY. Nothing here writes a row or moves money. It is deliberately the
 * first thing built (docs/PLAN-FARMER-PAYMENTS.md §8, increment 1) so the
 * cooperative can check the arithmetic against their own books before anyone
 * trusts the system with a payment run.
 *
 * DELIVERY-ANCHORED. The unit is the DELIVERY, priced at the rate snapshotted on
 * the consignment it rode in — not the consignment as a whole. `litres_payable`
 * is the column AdjustmentService already calls "the FARMER's payment unit"
 * (BR-12), so an adjustment made at the centre reaches the farmer's money
 * without this class knowing anything about adjustments.
 *
 * WHAT IS UNPAID is defined by absence from the claim ledger — a delivery with
 * no `farmer_payment_deliveries` row — and never by a date window. That is what
 * makes a consignment confirmed after its month closed simply appear in the next
 * run rather than falling down a gap between two periods.
 *
 * BR-16 / BR-2 — rejected volume is valued at zero and excluded, which needs no
 * code here: it is already absent from `litres_accepted`, hence from
 * `litres_payable`.
 *
 * BR-35 — test activity is excluded through `deliveries.is_test`. It cannot be
 * excluded through the farmer, because `farmers` has no such column.
 *
 * ROUNDING is stated at every step and happens PER DELIVERY, because rates
 * differ per consignment and there is no single rate to apply to a farmer's
 * monthly total. The cost is that two farmers with identical litres can differ
 * by a kobo; the breakdown is what makes that arguable rather than mysterious.
 */
class FarmerPaymentCalculator
{
    /**
     * The levy base — decision §1.4 of the plan, and NOT a settled question.
     *
     * Taking the levy on gross-less-savings is what payroll does for its
     * sequential deductions, which is the only reason it is the default here.
     * Nobody has confirmed the cooperative's bye-laws say so. On 100,000 naira
     * gross the two answers differ by 100 naira. It is a Settings row rather
     * than a constant precisely so answering the question is an edit, not a
     * release.
     */
    private const LEVY_BASE_SETTING = 'cooperative.levy_on_net_of_savings';

    /**
     * Everything a farmer is owed but has not been paid for.
     *
     * @return Collection<int, Delivery>
     */
    public function unpaidDeliveriesFor(Farmer $farmer): Collection
    {
        return Delivery::withoutDataScope()
            ->excludingTestData()
            ->where('farmer_id', $farmer->getKey())
            // Only milk that has reached a confirmed, priced consignment. An
            // un-dispatched delivery is real milk that nobody has valued yet;
            // paying it would be paying a rate that does not exist.
            ->whereHas('consignment', fn ($query) => $query->whereNotNull('rate_per_litre_minor'))
            ->whereNotIn('id', FarmerPaymentDelivery::query()->select('delivery_id'))
            ->with(['consignment.grade'])
            ->orderBy('delivered_at')
            ->get();
    }

    /**
     * Value a farmer's unpaid milk.
     *
     * Returns every intermediate figure, not just the answer, because the answer
     * on its own cannot be argued with at a collection point.
     *
     * @return array{
     *     litres: string, gross_minor: int, savings_minor: int, levy_minor: int,
     *     social_minor: int, shop_deduction_minor: int, net_minor: int,
     *     held: bool, hold_reason: ?string, lines: array<int, array<string, mixed>>,
     *     snapshots: array<string, mixed>, deliveries: Collection<int, Delivery>
     * }
     */
    public function value(Farmer $farmer, ?Collection $deliveries = null, bool $chargeSocial = true): array
    {
        $deliveries ??= $this->unpaidDeliveriesFor($farmer);
        $cooperative = $farmer->cooperative;

        /* 1. Each delivery valued at its own consignment's snapshotted rate,
              rounded half-up to the kobo PER DELIVERY. */
        $lines = [];
        $gross = 0;
        $litres = '0.00';

        foreach ($deliveries as $delivery) {
            $rate = (int) $delivery->consignment->rate_per_litre_minor;
            $payable = (string) $delivery->litres_payable;
            $lineGross = Money::valueVolume($payable, $rate);

            $gross += $lineGross;
            $litres = Volume::add($litres, $payable);

            $lines[] = [
                'delivery_id' => $delivery->getKey(),
                'reference' => $delivery->reference,
                'litres_payable' => $payable,
                'rate_per_litre_minor' => $rate,
                // Recorded so "why was I paid B rates?" has an answer. This is
                // the whole instrumentation of the pooled-grade decision (§1.1).
                'grade_id' => $delivery->consignment->grade_id,
                'grade' => $delivery->consignment->grade?->name,
                'consignment_id' => $delivery->consignment_id,
                'consignment' => $delivery->consignment->reference,
                'line_gross_minor' => $lineGross,
            ];
        }

        /* 2..5. Deductions. A farmer with no cooperative has no percentages —
                 a real case, not an error, and one that means they take home
                 more than a member for identical milk. Flagged in the plan. */
        $savingsPct = $cooperative?->savings_deduction_pct;
        $levyPct = $cooperative?->levy_pct;
        $socialMinor = (int) ($cooperative?->social_contribution_minor ?? 0);

        $savings = $savingsPct === null ? 0 : Money::percentageOf($gross, $savingsPct);

        $levyBase = Settings::boolean(self::LEVY_BASE_SETTING, true)
            ? $gross - $savings
            : $gross;

        $levy = $levyPct === null ? 0 : Money::percentageOf($levyBase, $levyPct);

        // Charged once per run, not once per delivery. Whether a run is a month
        // or a fortnight is the caller's business.
        $social = $chargeSocial ? $socialMinor : 0;

        /* 6. BR-30 — shop credit taken from the next milk payment, oldest first,
              whole-debt-or-skip so a large old debt is not half-recovered.

              Capped by §1.6 of the plan. Without a ceiling a farmer with a big
              debt receives ZERO several fortnights running while the balance
              grows, and there is no channel to warn them — they find out
              standing at a collection point on payout day. The cap is a
              settings row because the right number is the cooperative's to
              choose; with none set the behaviour is "recover everything", which
              is what the code did before the setting existed. */
        $ceiling = min(
            max(0, $gross - $savings - $levy - $social),
            $this->recoveryCeilingMinor($gross),
        );

        $available = $ceiling;
        $shop = 0;
        $settled = [];

        foreach ($this->pendingDeductionsFor($farmer) as $deduction) {
            if ($deduction->amount_minor > $available) {
                continue;
            }

            $shop += (int) $deduction->amount_minor;
            $available -= (int) $deduction->amount_minor;
            $settled[] = $deduction->getKey();
        }

        /* 7. Floor at zero. A shortfall stays a pending deduction and carries
              forward — it is never turned into a negative payment. */
        $net = max(0, $gross - $savings - $levy - $social - $shop);

        // BR-36 — computed and owed, and not payable until somebody verifies the
        // farmer. Held, never excluded: excluding it would make the debt invisible.
        $held = $farmer->paymentIsHeldPendingValidation();

        return [
            'litres' => $litres,
            'gross_minor' => $gross,
            'savings_minor' => $savings,
            'levy_minor' => $levy,
            'social_minor' => $social,
            'shop_deduction_minor' => $shop,
            'net_minor' => $net,
            'held' => $held,
            'hold_reason' => $held ? 'unvalidated' : null,
            'lines' => $lines,
            'settled_deduction_ids' => $settled,
            // BR-15 — what the percentages WERE, saved with the figure they made.
            'snapshots' => [
                'savings_pct' => $savingsPct,
                'levy_pct' => $levyPct,
                'social_minor' => $socialMinor,
                'levy_base' => Settings::boolean(self::LEVY_BASE_SETTING, true) ? 'gross_less_savings' : 'gross',
            ],
            'deliveries' => $deliveries,
        ];
    }

    /**
     * §1.6 — the most one payment may give up to old debt.
     *
     * Lives here rather than only on the reversal service because every debt is
     * recovered through this calculation, whether it came from a shop purchase
     * (BR-30) or from clawing back an overpayment.
     */
    public function recoveryCeilingMinor(int $grossMinor): int
    {
        $pct = Settings::get('cooperative.max_debt_recovery_pct');

        if ($pct === null || (float) $pct <= 0 || (float) $pct >= 100) {
            return $grossMinor;
        }

        return Money::percentageOf($grossMinor, $pct);
    }

    /**
     * @return Collection<int, PendingFarmerDeduction>
     */
    public function pendingDeductionsFor(Farmer $farmer): Collection
    {
        return PendingFarmerDeduction::query()
            ->excludingTestData()
            ->where('farmer_id', $farmer->getKey())
            ->where('status', PendingFarmerDeduction::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The headline figure: what the network owes this farmer right now.
     *
     * Derived from unclaimed deliveries every time it is asked, NOT stored. A
     * stored balance is a second source of truth that drifts from the first, and
     * this number is cheap enough to compute that there is no reason to have one.
     */
    public function owedMinor(Farmer $farmer): int
    {
        return $this->value($farmer)['net_minor'];
    }
}
