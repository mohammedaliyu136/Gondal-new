<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionCenter;
use App\Models\TransportPayment;
use App\Models\TransportPaymentRun;
use App\Models\TransportPaymentTrip;
use App\Models\Trip;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — generating, approving and closing a transport payment run.
 *
 * The riders and drivers side of the same story the farmer run tells. Every leg
 * logged since the network opened has carried a `fee_minor` and a
 * `payment_status` stuck on `queued`, because nothing in the system could move
 * it. This is what moves it.
 *
 * WHAT IS UNPAID is defined by absence from the claim ledger — a trip with no
 * `transport_payment_trips` row — and never by a date window. Same reasoning as
 * the farmer side: a leg logged late is swept into the next run rather than
 * falling into the gap between two sheets.
 *
 * BR-35 — test trips are excluded through `trips.is_test`.
 *
 * BR-2's transport half is NOT decided here. `fee_minor` is the route tariff
 * snapshotted when the trip was logged; whether a per-litre tariff should exist
 * is the open decision recorded on the Trip model, and this service pays what
 * the trip says it cost.
 */
class TransportPaymentRunService
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every unpaid trip in a scope, grouped into one line per driver.
     *
     * @return Collection<int, Trip>
     */
    public function unpaidTrips(?CollectionCenter $center): Collection
    {
        return Trip::withoutDataScope()
            ->excludingTestData()
            // A leg still on the road is not a debt yet. `arrived_at` is what
            // makes it one, and paying an unarrived trip would pay for work
            // that may still fail.
            ->whereNotNull('arrived_at')
            ->whereNotNull('driver_id')
            ->where('fee_minor', '>', 0)
            ->when($center !== null, fn ($query) => $query->where('collection_center_id', $center->getKey()))
            ->whereNotIn('id', TransportPaymentTrip::query()->select('trip_id'))
            ->with(['driver', 'route'])
            ->orderBy('departed_at')
            ->get();
    }

    /** Build a draft run for everything unpaid in a scope. */
    public function generate(
        ?CollectionCenter $center,
        User $actor,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): TransportPaymentRun {
        $this->access->authorize($actor, 'logistics.payments.create', null, 'Generate a transport payment run');

        return DB::transaction(function () use ($center, $actor, $periodStart, $periodEnd): TransportPaymentRun {
            $run = TransportPaymentRun::query()->create([
                'reference' => Sequences::next('transport_payment_runs'),
                'scope_type' => $center === null
                    ? TransportPaymentRun::SCOPE_NETWORK
                    : TransportPaymentRun::SCOPE_CENTER,
                'scope_id' => $center?->getKey(),
                // Dates label the run; they do not decide what it may claim.
                'period_start' => $periodStart ?? Wat::today()->startOfMonth()->toDateString(),
                'period_end' => $periodEnd ?? Wat::today()->toDateString(),
                'status' => TransportPaymentRun::STATUS_DRAFT,
                'run_by_user_id' => $actor->getKey(),
            ]);

            $total = 0;
            $trips = 0;
            $drivers = 0;

            foreach ($this->unpaidTrips($center)->groupBy('driver_id') as $driverId => $legs) {
                $amount = (int) $legs->sum('fee_minor');
                $litres = $legs->reduce(
                    fn (string $carry, Trip $trip) => Volume::add($carry, (string) $trip->litres_carried),
                    '0.00',
                );

                $payment = TransportPayment::query()->create([
                    'transport_payment_run_id' => $run->getKey(),
                    'driver_id' => $driverId,
                    'trip_count' => $legs->count(),
                    'litres_carried' => $litres,
                    'amount_minor' => $amount,
                    'status' => TransportPayment::STATUS_PAYABLE,
                    // The legs, so a rider disputing the total at a centre can be
                    // shown which trips it is made of rather than a bare figure.
                    'breakdown' => [
                        'legs' => $legs->map(fn (Trip $trip) => [
                            'trip_id' => $trip->getKey(),
                            'reference' => $trip->reference,
                            'route' => $trip->route?->name,
                            'departed_at' => $trip->departed_at?->toDateTimeString(),
                            'litres_carried' => (string) $trip->litres_carried,
                            'fee_minor' => (int) $trip->fee_minor,
                        ])->values()->all(),
                    ],
                ]);

                /*
                 * The claim. This insert can fail on the UNIQUE, and failing is
                 * correct: it means another run took the trip first, and the
                 * whole transaction rolls back rather than paying a leg twice.
                 */
                foreach ($legs as $trip) {
                    TransportPaymentTrip::query()->create([
                        'transport_payment_id' => $payment->getKey(),
                        'trip_id' => $trip->getKey(),
                        'fee_minor' => (int) $trip->fee_minor,
                        'litres_carried' => $trip->litres_carried,
                        'route_id' => $trip->route_id,
                    ]);
                }

                // The logistics screen filters on these two columns, so they are
                // kept in step. The CONSTRAINT is the claim table above; these
                // are a convenience for a screen that already existed.
                Trip::withoutDataScope()->whereIn('id', $legs->pluck('id'))->update([
                    'payment_status' => Trip::PAYMENT_QUEUED,
                    'payment_run_id' => $run->getKey(),
                ]);

                $total += $amount;
                $trips += $legs->count();
                $drivers++;
            }

            $run->forceFill([
                'total_minor' => $total,
                'trip_count' => $trips,
                'driver_count' => $drivers,
            ])->save();

            $this->audit->created(
                $run,
                sprintf('%s generated — %d driver(s), %d trip(s), %s',
                    $run->reference, $drivers, $trips, Money::format($total)),
                'Logistics',
                ['scope' => $run->scope_type.':'.($run->scope_id ?? 'all')],
                $actor,
            );

            return $run->refresh();
        });
    }

    /** BR-18 lives in the engine: the preparer cannot approve their own run. */
    public function submitForApproval(TransportPaymentRun $run, User $actor): TransportPaymentRun
    {
        if ($run->status !== TransportPaymentRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('Only a draft run can be submitted. %s is %s.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        if ((int) $run->driver_count === 0) {
            throw RuleViolationException::make(
                'ST-1',
                'There is nothing to pay in this run.',
                ['reference' => $run->reference],
            );
        }

        $instance = $this->workflow->start(
            Workflow::APPLIES_TRANSPORT_PAYMENT_RUN,
            $run,
            $actor,
            (int) $run->total_minor,
        );

        $run->forceFill([
            'workflow_instance_id' => $instance->getKey(),
            'status' => TransportPaymentRun::STATUS_PROCESSING,
        ])->save();

        return $run->refresh();
    }

    public function syncFromWorkflow(TransportPaymentRun $run): TransportPaymentRun
    {
        $instance = $run->workflowInstance;

        if ($instance === null) {
            return $run;
        }

        if ($instance->status === WorkflowInstance::STATUS_APPROVED && ! $run->isApproved()) {
            $run->forceFill([
                'status' => TransportPaymentRun::STATUS_APPROVED,
                'approved_at' => $instance->completed_at,
            ])->save();

            // The trips move to `approved` with the run that carries them. This
            // is the first time in the system's life that column has held
            // anything other than `queued`.
            Trip::withoutDataScope()
                ->whereIn('id', TransportPaymentTrip::query()
                    ->whereIn('transport_payment_id', $run->payments()->select('id'))
                    ->select('trip_id'))
                ->update(['payment_status' => Trip::PAYMENT_APPROVED]);

            $this->audit->approval(
                $run,
                sprintf('%s approved for disbursement — %s', $run->reference, Money::format((int) $run->total_minor)),
                ['drivers' => $run->driver_count, 'trips' => $run->trip_count],
                null,
                'Logistics',
            );
        }

        // A rejected run returns to draft so it can be corrected and resubmitted
        // (BR-20). The claims stay: the trips are still this run's.
        if ($instance->status === WorkflowInstance::STATUS_REJECTED) {
            $run->forceFill(['status' => TransportPaymentRun::STATUS_DRAFT])->save();
        }

        return $run->refresh();
    }

    /**
     * Throw the run away and release every trip it claimed.
     *
     * The claim rows are DELETED, because the UNIQUE on trip_id is what lets a
     * later run pick the leg up again.
     */
    public function cancel(TransportPaymentRun $run, User $actor, string $reason): TransportPaymentRun
    {
        if ($run->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s is %s. An approved run is reversed, not cancelled.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        DB::transaction(function () use ($run, $actor, $reason): void {
            foreach ($run->payments()->with('lines')->get() as $payment) {
                Trip::withoutDataScope()
                    ->whereIn('id', $payment->lines()->select('trip_id'))
                    ->update(['payment_status' => Trip::PAYMENT_QUEUED, 'payment_run_id' => null]);

                $payment->lines()->delete();
                $payment->delete();
            }

            $run->forceFill(['status' => TransportPaymentRun::STATUS_CANCELLED])->save();

            $this->audit->edited(
                $run,
                sprintf('%s cancelled — %s', $run->reference, $reason),
                'Logistics',
                ['status' => TransportPaymentRun::STATUS_DRAFT],
                ['status' => TransportPaymentRun::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );
        });

        return $run->refresh();
    }
}
