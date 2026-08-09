<?php

namespace App\Services\Logistics;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Volume;
use App\Support\Wat;

/**
 * §6.3 — logging a transport leg.
 *
 * The whole write used to sit in LogisticsController, which is why it was the
 * one milk-chain write that never asked ARCH-4's second question: the route
 * carried `permission:logistics.trips.create`, so layer 1 held, and a logistics
 * user scoped to a single centre could log a trip anywhere in the network and
 * have its fee counted into the queued transport total.
 *
 * ARCH-4 — the scope check lives HERE rather than in the controller, so the
 *   API surface this module will grow cannot bypass what the screen enforces.
 *   Same reasoning as ConsignmentService::guardGrading().
 * SCOPE-1 — a trip is anchored by its own endpoints, and the scope question is
 *   asked against the most specific one it has.
 * BR-2 / BR-14 in spirit — the route tariff is SNAPSHOTTED onto the trip, so
 *   re-tariffing a route in Settings never moves a logged trip's fee.
 * §15.1 — a payment RUN is Phase 7 and blocked; a trip is only queued here.
 */
class TripService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Access $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function log(Route $route, array $data, User $actor): Trip
    {
        [$point, $center] = $this->resolveEndpoints($route, $data);

        /*
         * ARCH-4, layer 2 — with the record's geography in hand.
         *
         * The point is the narrower anchor when the leg has one: a centre-scoped
         * user naming a point outside their centre is refused by
         * CollectionPoint's own `center` constraint, and a point-scoped user is
         * held to their point. Only a centre→factory leg, which starts at no
         * point, falls back to the centre.
         */
        $this->access->authorize(
            $actor,
            'logistics.trips.create',
            $point ?? $center,
            'Log a trip on '.$route->name,
        );

        $trip = Trip::query()->create([
            'reference' => Sequences::next('trips'),
            'route_id' => $route->getKey(),
            'collection_point_id' => $point?->getKey(),
            'collection_center_id' => $center?->getKey(),
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'logged_by_user_id' => $actor->getKey(),
            'departed_at' => Wat::instant($data['departed_at'] ?? null) ?? Wat::now(),
            'arrived_at' => Wat::instant($data['arrived_at'] ?? null),
            // See Trip's docblock: an observation, not a derived figure, and the
            // choice between the two is an open business decision.
            'litres_carried' => $data['litres_carried'] ?? 0,
            'fee_minor' => (int) $route->tariff_minor,
            'route_tariff_minor_snapshot' => (int) $route->tariff_minor,
            'payment_status' => Trip::PAYMENT_QUEUED,
        ]);

        $this->audit->created(
            $trip,
            sprintf(
                'Trip %s logged on %s (%s) — %s, fee %s',
                $trip->reference,
                $route->name,
                $this->describeLeg($point, $center),
                Volume::format($trip->litres_carried),
                Money::format((int) $trip->fee_minor),
            ),
            'Logistics',
            [
                'route_tariff_snapshot_minor' => (int) $route->tariff_minor,
                'collection_point_id' => $point?->getKey(),
                'collection_center_id' => $center?->getKey(),
            ],
            $actor,
        );

        return $trip;
    }

    /**
     * Where the leg actually ran.
     *
     * Taken from the operator first and from the route only as a fallback,
     * because a route is a tariff template: the generic point→center rows name
     * no ids at all, which is how trips came to have no location. Looked up
     * WITHOUT the data scope on purpose — an out-of-scope id has to reach
     * `authorize()` and produce AUDIT-5's quotable 403, not vanish into a 404
     * and silently fall back to the route's endpoint.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?CollectionPoint, 1: ?CollectionCenter}
     */
    private function resolveEndpoints(Route $route, array $data): array
    {
        $pointId = $data['collection_point_id'] ?? $this->routeEndpoint($route, Route::ENDPOINT_POINT);
        $centerId = $data['collection_center_id'] ?? $this->routeEndpoint($route, Route::ENDPOINT_CENTER);

        $point = $pointId === null ? null : CollectionPoint::withoutDataScope()->find($pointId);
        $center = $centerId === null ? null : CollectionCenter::withoutDataScope()->find($centerId);

        // A point knows its own centre, so a half-filled leg completes itself
        // rather than being stored half-located.
        $center ??= $point?->collectionCenter;

        if ($point === null && $center === null) {
            throw RuleViolationException::make(
                'SCOPE-1',
                'Say where this trip ran. Choose the collection center it served — '
                    .'a trip with no location belongs to nobody and appears on no scoped list.',
                ['route' => $route->name],
                'collection_center_id',
            );
        }

        return [$point, $center];
    }

    private function routeEndpoint(Route $route, string $type): ?int
    {
        if ($route->from_type === $type && $route->from_id !== null) {
            return (int) $route->from_id;
        }

        if ($route->to_type === $type && $route->to_id !== null) {
            return (int) $route->to_id;
        }

        return null;
    }

    private function describeLeg(?CollectionPoint $point, ?CollectionCenter $center): string
    {
        return implode(' → ', array_filter([$point?->name, $center?->name]));
    }
}
