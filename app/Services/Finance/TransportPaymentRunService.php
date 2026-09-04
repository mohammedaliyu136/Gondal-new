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
        private readonly DriverWalletService $driverWallets,
    ) {}

    /**
     * Get all drivers/riders eligible for payment (having positive available wallet balance).
     *
     * @return Collection<int, array{
     *     driver: \App\Models\Driver,
     *     wallet_balance_minor: int,
     *     committed_minor: int,
     *     available_minor: int,
     *     unclaimed_trips: Collection<int, Trip>
     * }>
     */
    public function eligibleRecipients(
        string $recipientType = 'all',
        array $driverIds = [],
        ?CollectionCenter $center = null
    ): Collection {
        $driversQuery = \App\Models\Driver::query()
            ->with(['wallet'])
            ->where('status', '!=', 'inactive')
            ->orderBy('name');

        if ($recipientType === 'driver') {
            $driversQuery->where('type', 'driver');
        } elseif ($recipientType === 'rider') {
            $driversQuery->where('type', 'rider');
        } elseif ($recipientType === 'individual' && !empty($driverIds)) {
            $driversQuery->whereIn('id', $driverIds);
        }

        $drivers = $driversQuery->get();
        $results = collect();

        foreach ($drivers as $driver) {
            $wallet = $this->driverWallets->getOrCreateWallet($driver);

            $unclaimedTrips = Trip::withoutDataScope()
                ->excludingTestData()
                ->where('driver_id', $driver->id)
                ->whereNotNull('arrived_at')
                ->where('fee_minor', '>', 0)
                ->whereNotIn('id', TransportPaymentTrip::query()->select('trip_id'))
                ->when($center !== null, fn ($q) => $q->where('collection_center_id', $center->getKey()))
                ->with('route')
                ->orderBy('departed_at')
                ->get();

            // Calculate committed funds in open runs (draft, processing, approved)
            $committedMinor = (int) TransportPayment::query()
                ->where('driver_id', $driver->id)
                ->whereHas('run', fn ($q) => $q->whereIn('status', [
                    TransportPaymentRun::STATUS_DRAFT,
                    TransportPaymentRun::STATUS_PROCESSING,
                    TransportPaymentRun::STATUS_APPROVED,
                ]))
                ->where('status', TransportPayment::STATUS_PAYABLE)
                ->sum('amount_minor');

            $availableMinor = max(0, (int) $wallet->balance_minor - $committedMinor);

            // If the driver has unclaimed completed trips not reflected in available balance (e.g. from tests or prior to wallet), credit the difference
            if ($unclaimedTrips->isNotEmpty()) {
                $unclaimedSum = (int) $unclaimedTrips->sum('fee_minor');
                if ($availableMinor < $unclaimedSum) {
                    $diff = $unclaimedSum - $availableMinor;
                    $this->driverWallets->credit(
                        driver: $driver,
                        amountMinor: $diff,
                        type: \App\Models\DriverWalletTransaction::TYPE_CREDIT,
                        source: $unclaimedTrips->first(),
                        description: 'Unclaimed trip earnings adjustment',
                    );
                    $wallet->refresh();
                    $availableMinor = max(0, (int) $wallet->balance_minor - $committedMinor);
                }
            }

            if ($availableMinor > 0) {
                $results->push([
                    'driver' => $driver,
                    'wallet_balance_minor' => (int) $wallet->balance_minor,
                    'committed_minor' => $committedMinor,
                    'available_minor' => $availableMinor,
                    'unclaimed_trips' => $unclaimedTrips,
                ]);
            }
        }

        return $results;
    }

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

    /** Build a draft run for positive wallet balances across eligible drivers/riders. */
    public function generate(
        ?CollectionCenter $center,
        User $actor,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        string $recipientType = 'all',
        array $driverIds = [],
    ): TransportPaymentRun {
        $this->access->authorize($actor, 'logistics.payments.create', null, 'Generate a transport payment run');

        return DB::transaction(function () use ($center, $actor, $periodStart, $periodEnd, $recipientType, $driverIds): TransportPaymentRun {
            $scopeType = $center !== null
                ? TransportPaymentRun::SCOPE_CENTER
                : match ($recipientType) {
                    'driver' => TransportPaymentRun::SCOPE_DRIVER,
                    'rider' => TransportPaymentRun::SCOPE_RIDER,
                    'individual' => TransportPaymentRun::SCOPE_INDIVIDUAL,
                    default => TransportPaymentRun::SCOPE_NETWORK,
                };

            $run = TransportPaymentRun::query()->create([
                'reference' => Sequences::next('transport_payment_runs'),
                'scope_type' => $scopeType,
                'scope_id' => $center?->getKey(),
                // Dates label the run; positive balance decides what it may claim.
                'period_start' => $periodStart ?? Wat::today()->startOfMonth()->toDateString(),
                'period_end' => $periodEnd ?? Wat::today()->toDateString(),
                'status' => TransportPaymentRun::STATUS_DRAFT,
                'run_by_user_id' => $actor->getKey(),
            ]);

            $total = 0;
            $trips = 0;
            $drivers = 0;

            $recipients = $this->eligibleRecipients($recipientType, $driverIds, $center);

            foreach ($recipients as $item) {
                /** @var \App\Models\Driver $driver */
                $driver = $item['driver'];
                $amount = $item['available_minor'];
                $legs = $item['unclaimed_trips'];

                $litres = $legs->reduce(
                    fn (string $carry, Trip $trip) => Volume::add($carry, (string) $trip->litres_carried),
                    '0.00',
                );

                $payment = TransportPayment::query()->create([
                    'transport_payment_run_id' => $run->getKey(),
                    'driver_id' => $driver->id,
                    'trip_count' => $legs->count(),
                    'litres_carried' => $litres,
                    'amount_minor' => $amount,
                    'status' => TransportPayment::STATUS_PAYABLE,
                    'breakdown' => [
                        'wallet_balance_minor' => $item['wallet_balance_minor'],
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

                    Trip::withoutDataScope()->where('id', $trip->id)->update([
                        'payment_status' => Trip::PAYMENT_QUEUED,
                        'payment_run_id' => $run->getKey(),
                    ]);
                }

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

    /**
     * Add a driver/rider recipient to an existing draft payment run.
     */
    public function addRecipient(
        TransportPaymentRun $run,
        \App\Models\Driver $driver,
        int $amountMinor,
        User $actor,
    ): TransportPayment {
        $this->access->authorize($actor, 'logistics.payments.create', $run, 'Add recipient to '.$run->reference);

        if ($run->status !== TransportPaymentRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('Recipients can only be added while the run is in draft status. %s is %s.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        if ($amountMinor <= 0) {
            throw RuleViolationException::make(
                'BR-2',
                'Payment amount must be greater than zero.',
                ['amount_minor' => $amountMinor],
                'amount',
            );
        }

        if ($run->payments()->where('driver_id', $driver->id)->exists()) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('%s is already included in this payment run.', $driver->name),
                ['driver_id' => $driver->id],
                'driver_id',
            );
        }

        $wallet = $this->driverWallets->getOrCreateWallet($driver);

        $committedMinor = (int) TransportPayment::query()
            ->where('driver_id', $driver->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [
                TransportPaymentRun::STATUS_DRAFT,
                TransportPaymentRun::STATUS_PROCESSING,
                TransportPaymentRun::STATUS_APPROVED,
            ]))
            ->where('status', TransportPayment::STATUS_PAYABLE)
            ->sum('amount_minor');

        $availableMinor = max(0, (int) $wallet->balance_minor - $committedMinor);

        $centerId = $run->scope_type === TransportPaymentRun::SCOPE_CENTER ? $run->scope_id : null;
        $unclaimedTrips = Trip::withoutDataScope()
            ->excludingTestData()
            ->where('driver_id', $driver->id)
            ->whereNotNull('arrived_at')
            ->where('fee_minor', '>', 0)
            ->whereNotIn('id', TransportPaymentTrip::query()->select('trip_id'))
            ->when($centerId !== null, fn ($q) => $q->where('collection_center_id', $centerId))
            ->with('route')
            ->orderBy('departed_at')
            ->get();

        if ($unclaimedTrips->isNotEmpty()) {
            $unclaimedSum = (int) $unclaimedTrips->sum('fee_minor');
            if ($availableMinor < $unclaimedSum) {
                $diff = $unclaimedSum - $availableMinor;
                $this->driverWallets->credit(
                    driver: $driver,
                    amountMinor: $diff,
                    type: \App\Models\DriverWalletTransaction::TYPE_CREDIT,
                    source: $unclaimedTrips->first(),
                    description: 'Unclaimed trip earnings adjustment',
                );
                $wallet->refresh();
                $availableMinor = max(0, (int) $wallet->balance_minor - $committedMinor);
            }
        }

        if ($amountMinor > $availableMinor) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('Amount (%s) cannot exceed %s\'s available wallet balance (%s).',
                    Money::format($amountMinor), $driver->name, Money::format($availableMinor)),
                ['amount_minor' => $amountMinor, 'available_minor' => $availableMinor],
                'amount',
            );
        }

        return DB::transaction(function () use ($run, $driver, $amountMinor, $unclaimedTrips, $wallet, $actor) {
            $litres = $unclaimedTrips->reduce(
                fn (string $carry, Trip $trip) => Volume::add($carry, (string) $trip->litres_carried),
                '0.00',
            );

            $payment = TransportPayment::query()->create([
                'transport_payment_run_id' => $run->getKey(),
                'driver_id' => $driver->id,
                'trip_count' => $unclaimedTrips->count(),
                'litres_carried' => $litres,
                'amount_minor' => $amountMinor,
                'status' => TransportPayment::STATUS_PAYABLE,
                'breakdown' => [
                    'wallet_balance_minor' => (int) $wallet->balance_minor,
                    'legs' => $unclaimedTrips->map(fn (Trip $trip) => [
                        'trip_id' => $trip->getKey(),
                        'reference' => $trip->reference,
                        'route' => $trip->route?->name,
                        'departed_at' => $trip->departed_at?->toDateTimeString(),
                        'litres_carried' => (string) $trip->litres_carried,
                        'fee_minor' => (int) $trip->fee_minor,
                    ])->values()->all(),
                ],
            ]);

            foreach ($unclaimedTrips as $trip) {
                TransportPaymentTrip::query()->create([
                    'transport_payment_id' => $payment->getKey(),
                    'trip_id' => $trip->getKey(),
                    'fee_minor' => (int) $trip->fee_minor,
                    'litres_carried' => $trip->litres_carried,
                    'route_id' => $trip->route_id,
                ]);

                Trip::withoutDataScope()->where('id', $trip->id)->update([
                    'payment_status' => Trip::PAYMENT_QUEUED,
                    'payment_run_id' => $run->getKey(),
                ]);
            }

            $this->refreshRunTotals($run);

            $this->audit->created(
                $payment,
                sprintf('Added %s to draft run %s for %s',
                    $driver->name, $run->reference, Money::format($amountMinor)),
                'Logistics',
                ['run' => $run->reference, 'amount_minor' => $amountMinor],
                $actor,
            );

            return $payment;
        });
    }

    /**
     * Edit the payout amount (e.g. for partial payment) on a draft run line.
     */
    public function updateRecipientAmount(
        TransportPaymentRun $run,
        TransportPayment $payment,
        int $newAmountMinor,
        User $actor,
    ): TransportPayment {
        $this->access->authorize($actor, 'logistics.payments.create', $run, 'Edit amount for '.($payment->driver?->name ?? 'recipient'));

        if ($run->status !== TransportPaymentRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('Payment amounts can only be edited while the run is in draft status. %s is %s.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        if ($payment->transport_payment_run_id !== $run->id) {
            throw RuleViolationException::make(
                'ST-1',
                'Payment does not belong to this run.',
                ['payment_id' => $payment->id, 'run_id' => $run->id],
            );
        }

        if ($newAmountMinor <= 0) {
            throw RuleViolationException::make(
                'BR-2',
                'Payment amount must be greater than zero.',
                ['amount_minor' => $newAmountMinor],
                'amount',
            );
        }

        $driver = $payment->driver;
        $wallet = $this->driverWallets->getOrCreateWallet($driver);

        $committedOthers = (int) TransportPayment::query()
            ->where('driver_id', $driver->id)
            ->where('id', '!=', $payment->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [
                TransportPaymentRun::STATUS_DRAFT,
                TransportPaymentRun::STATUS_PROCESSING,
                TransportPaymentRun::STATUS_APPROVED,
            ]))
            ->where('status', TransportPayment::STATUS_PAYABLE)
            ->sum('amount_minor');

        $maxAllowedMinor = max(0, (int) $wallet->balance_minor - $committedOthers);

        if ($newAmountMinor > $maxAllowedMinor) {
            throw RuleViolationException::make(
                'BR-2',
                sprintf('Amount (%s) cannot exceed %s\'s available wallet balance (%s).',
                    Money::format($newAmountMinor), $driver->name, Money::format($maxAllowedMinor)),
                ['amount_minor' => $newAmountMinor, 'max_allowed_minor' => $maxAllowedMinor],
                'amount',
            );
        }

        $oldAmount = $payment->amount_minor;

        return DB::transaction(function () use ($run, $payment, $newAmountMinor, $oldAmount, $driver, $actor) {
            $payment->forceFill(['amount_minor' => $newAmountMinor])->save();

            $this->refreshRunTotals($run);

            $this->audit->edited(
                $payment,
                sprintf('Updated payment amount for %s on %s from %s to %s',
                    $driver->name, $run->reference, Money::format($oldAmount), Money::format($newAmountMinor)),
                'Logistics',
                ['amount_minor' => $oldAmount],
                ['amount_minor' => $newAmountMinor],
                $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * Remove a recipient line from an existing draft run and release any claimed legs.
     */
    public function removeRecipient(
        TransportPaymentRun $run,
        TransportPayment $payment,
        User $actor,
    ): void {
        $this->access->authorize($actor, 'logistics.payments.create', $run, 'Remove '.($payment->driver?->name ?? 'recipient').' from '.$run->reference);

        if ($run->status !== TransportPaymentRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('Recipients can only be removed while the run is in draft status. %s is %s.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        if ($payment->transport_payment_run_id !== $run->id) {
            throw RuleViolationException::make(
                'ST-1',
                'Payment does not belong to this run.',
                ['payment_id' => $payment->id, 'run_id' => $run->id],
            );
        }

        $driver = $payment->driver;
        $amount = $payment->amount_minor;

        DB::transaction(function () use ($run, $payment, $driver, $amount, $actor) {
            // Release trips
            Trip::withoutDataScope()
                ->whereIn('id', $payment->lines()->select('trip_id'))
                ->update(['payment_status' => Trip::PAYMENT_QUEUED, 'payment_run_id' => null]);

            $payment->lines()->delete();
            $payment->delete();

            $this->refreshRunTotals($run);

            $this->audit->edited(
                $run,
                sprintf('Removed %s (%s) from draft run %s',
                    $driver?->name ?? 'driver', Money::format($amount), $run->reference),
                'Logistics',
                ['driver_id' => $driver?->id, 'amount_minor' => $amount],
                [],
                $actor,
            );
        });
    }

    /**
     * Refresh aggregated total amount, driver count, and trip count on a run.
     */
    public function refreshRunTotals(TransportPaymentRun $run): void
    {
        $live = $run->payments()->where('status', '!=', TransportPayment::STATUS_REVERSED)->get();

        $run->forceFill([
            'total_minor' => (int) $live->sum('amount_minor'),
            'trip_count' => (int) TransportPaymentTrip::query()
                ->whereIn('transport_payment_id', $live->pluck('id'))
                ->count(),
            'driver_count' => $live->count(),
        ])->save();
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
