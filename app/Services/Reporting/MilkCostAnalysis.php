<?php

namespace App\Services\Reporting;

use App\Models\Batch;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Trip;
use App\Models\User;
use App\Support\Money;
use App\Support\Volume;
use Carbon\Carbon;

/**
 * §15.5 — what a litre costs to put in the factory's tank.
 *
 * Every input existed and nothing joined them. Consignments snapshot the farmer
 * rate, trips carry the transport fee, deliveries carry the rejected volume, and
 * batches record what the factory actually received. Separately each is a fact;
 * together they are the only number that says whether the network is viable.
 *
 * WHAT THIS DELIBERATELY DOES NOT CLAIM. It is a COST, not a margin. Nothing in
 * this system records what the factory pays for a litre — `batches` has
 * `litres_received` and no price — so there is no revenue side to subtract from,
 * and inventing one would be worse than leaving the gap visible. The moment a
 * factory price is modelled, this is where the margin goes.
 *
 * THE DENOMINATOR IS LITRES PAID FOR, and this was got wrong once already. The
 * first version divided by litres the factory RECEIVED in the same date window,
 * which reads as the more meaningful figure and is not a figure at all: milk
 * delivered on the 30th is confirmed on the 31st and batched in the next month,
 * so the numerator and denominator counted different milk. On the real database
 * it reported 338 L "lost" — an artefact of the window, quoted in naira.
 *
 * Losses are measured ALONG THE CHAIN instead, on the same objects: a
 * consignment's declared-less-confirmed, and a batch's own `discrepancy_litres`.
 * Those compare a thing to itself and cannot drift with the calendar.
 *
 * FARMER COST IS GROSS. The savings, levy and social deductions are retained by
 * the cooperative rather than by the network, so whether they are a cost depends
 * on who is asking. Gross is the milk's price to the farmer; the retained
 * portion is reported alongside it rather than netted off, so a reader can take
 * either view without the code having taken one for them.
 */
class MilkCostAnalysis
{
    /**
     * @return array{
     *     litres: array<string, string>, farmer_gross_minor: int, transport_minor: int,
     *     total_minor: int, cost_per_litre_minor: ?int, shrinkage_litres: string,
     *     shrinkage_minor: int, trips: int, unpriced_litres: string
     * }
     */
    public function forPeriod(Carbon $start, Carbon $end, ?int $collectionCenterId = null): array
    {
        /*
         * 0. Can the caller see a delivery AT ALL?
         *
         * SCOPE-4 sends every aggregate through the model's global scope, and a
         * role holding no `milk.deliveries` scope resolves to an empty set
         * rather than to an error. That is how this report answered "₦0.00 per
         * litre" to the Accounts role — confidently, with no empty state and
         * nothing in the logs. The grant was the fix; this is the alarm, so the
         * next role in the same position is told rather than misled.
         */
        $blind = ! auth()->user() instanceof User
            || auth()->user()->scopeSetFor('milk.deliveries.view')->isEmpty();

        /* 1. What farmers handed over, and what was refused at the point. */
        $deliveries = Delivery::query()
            ->excludingTestData()
            ->where('deliveries.delivered_at', '>=', $start)
            ->where('deliveries.delivered_at', '<', $end)
            ->when($collectionCenterId !== null, fn ($query) => $query->whereIn(
                'deliveries.collection_point_id',
                \App\Models\CollectionPoint::withoutDataScope()
                    ->where('collection_center_id', $collectionCenterId)->select('id'),
            ))
            ->with('consignment')
            ->get();

        $presented = '0.00';
        $rejectedAtPoint = '0.00';
        $payable = '0.00';
        $unpriced = '0.00';
        $farmerGross = 0;

        foreach ($deliveries as $delivery) {
            $presented = Volume::add($presented, (string) $delivery->litres_presented);
            $rejectedAtPoint = Volume::add($rejectedAtPoint, (string) $delivery->litres_rejected);

            $rate = $delivery->consignment?->rate_per_litre_minor;

            if ($rate === null) {
                // Real milk that nobody has valued yet. Counted separately so
                // the cost figure is not quietly built on a partial period.
                $unpriced = Volume::add($unpriced, (string) $delivery->litres_payable);

                continue;
            }

            $payable = Volume::add($payable, (string) $delivery->litres_payable);
            $farmerGross += Money::valueVolume((string) $delivery->litres_payable, (int) $rate);
        }

        /* 2. What the centres confirmed, and what they refused. */
        $consignments = Consignment::query()
            ->excludingTestData()
            ->where('consignments.confirmed_at', '>=', $start)
            ->where('consignments.confirmed_at', '<', $end)
            ->when($collectionCenterId !== null,
                fn ($query) => $query->where('consignments.collection_center_id', $collectionCenterId))
            ->get();

        $confirmed = $consignments->reduce(
            fn (string $carry, Consignment $c) => Volume::add($carry, (string) $c->litres_confirmed),
            '0.00',
        );
        $rejectedAtCenter = $consignments->reduce(
            fn (string $carry, Consignment $c) => Volume::add($carry, (string) $c->litres_rejected_at_center),
            '0.00',
        );

        /*
         * 3. Losses along the chain, each measured on the object it happened to.
         *
         * A consignment's declared-less-confirmed is BR-10's discrepancy, and a
         * batch carries its own. Neither compares one date window to another, so
         * neither moves when the period boundary does.
         */
        $lostToCentre = $consignments->reduce(
            fn (string $carry, Consignment $c) => Volume::add(
                $carry,
                max(0, (float) $c->litres_dispatched - (float) ($c->litres_confirmed ?? $c->litres_dispatched)),
            ),
            '0.00',
        );

        $batches = Batch::query()
            ->excludingTestData()
            ->whereNotNull('batches.reconciled_at')
            ->where('batches.reconciled_at', '>=', $start)
            ->where('batches.reconciled_at', '<', $end)
            ->when($collectionCenterId !== null,
                fn ($query) => $query->where('batches.collection_center_id', $collectionCenterId))
            ->get();

        $received = $batches->reduce(
            fn (string $carry, Batch $b) => Volume::add($carry, (string) ($b->litres_received ?? '0.00')),
            '0.00',
        );
        $rejectedAtFactory = $batches->reduce(
            fn (string $carry, Batch $b) => Volume::add($carry, (string) $b->litres_rejected_at_factory),
            '0.00',
        );
        $lostToFactory = $batches->reduce(
            fn (string $carry, Batch $b) => Volume::add($carry, max(0, (float) ($b->discrepancy_litres ?? 0))),
            '0.00',
        );

        /* 4. What moving it cost. */
        $trips = Trip::query()
            ->excludingTestData()
            ->whereNotNull('trips.arrived_at')
            ->where('trips.departed_at', '>=', $start)
            ->where('trips.departed_at', '<', $end)
            ->when($collectionCenterId !== null,
                fn ($query) => $query->where('trips.collection_center_id', $collectionCenterId))
            ->get();

        $transport = (int) $trips->sum('fee_minor');
        $total = $farmerGross + $transport;

        /*
         * 5. Shrinkage — milk the network bought and lost, as opposed to milk it
         * refused. Kept apart from rejection because rejected milk is valued at
         * zero (BR-16) and never cost anything, whereas this was paid for. Added
         * together, nobody could tell a quality problem from a leaking can.
         */
        $accepted = Volume::subtract($presented, $rejectedAtPoint);
        $shrinkage = Volume::add($lostToCentre, $lostToFactory);

        // Valued at the period's own average rate rather than a guess.
        $averageRate = $this->averageRateMinor($farmerGross, $payable);
        $shrinkageMinor = $averageRate === null
            ? 0
            : max(0, Money::valueVolume($shrinkage, $averageRate));

        return [
            'litres' => [
                'presented' => $presented,
                'rejected_at_point' => $rejectedAtPoint,
                'accepted' => $accepted,
                'confirmed_at_center' => $confirmed,
                'rejected_at_center' => $rejectedAtCenter,
                'received_at_factory' => $received,
                'rejected_at_factory' => $rejectedAtFactory,
                'lost_point_to_centre' => $lostToCentre,
                'lost_centre_to_factory' => $lostToFactory,
                'priced' => $payable,
            ],
            'farmer_gross_minor' => $farmerGross,
            'transport_minor' => $transport,
            'total_minor' => $total,
            // Divided by litres PAID FOR — see the note in the class docblock
            // about the version that divided by litres received. Null rather
            // than zero when there are none: a cost per litre with no litres is
            // not a figure, and a zero would be quoted as one.
            'cost_per_litre_minor' => $this->perLitre($total, $payable),
            'farmer_cost_per_litre_minor' => $this->perLitre($farmerGross, $payable),
            'transport_cost_per_litre_minor' => $this->perLitre($transport, $payable),
            'shrinkage_litres' => $shrinkage,
            'shrinkage_minor' => $shrinkageMinor,
            'trips' => $trips->count(),
            'unpriced_litres' => $unpriced,
            // Not a figure — a warning the caller must surface. Every number
            // above is zero for a reason that has nothing to do with the milk.
            'scope_blind' => $blind,
        ];
    }

    /**
     * Cost divided by litres, in kobo per litre, half-up.
     *
     * Money::valueVolume works in centilitres and multiplies; this divides, so
     * the rounding is done here rather than borrowed from a helper that means
     * something else.
     */
    private function perLitre(int $costMinor, string $litres): ?int
    {
        $centilitres = Volume::toCentilitres($litres);

        if ($centilitres <= 0) {
            return null;
        }

        return (int) round($costMinor * 100 / $centilitres);
    }

    private function averageRateMinor(int $grossMinor, string $litres): ?int
    {
        return $this->perLitre($grossMinor, $litres);
    }
}
