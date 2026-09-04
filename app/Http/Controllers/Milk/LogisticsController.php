<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Driver;
use App\Models\Route as TransportRoute;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Logistics\TripService;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * logistics.html.
 *
 * USER-1 — riders and drivers are records. They do not sign in; the logistics
 *   officer records on their behalf so they can be paid.
 * BR-2 — the transport fee is the route tariff snapshotted at logging time.
 *   `litres_carried` is an observation the operator records; see Trip's docblock
 *   for the open decision about deriving it instead.
 * §5.1 — logistics.payments is SENSITIVE, so fees and payment status are only
 *   rendered to a holder of that permission.
 * §15.1 — a payment RUN is Phase 7 and blocked; trips are only queued here.
 *
 * The write itself lives in TripService, not here: nothing decides in a
 * controller, and the ARCH-4 scope check belongs where an API surface would
 * reach it too.
 */
class LogisticsController extends Controller
{
    public function __construct(private readonly TripService $trips) {}

    public function index(Request $request): View
    {
        $date = $request->date('date')?->toDateString() ?? Wat::today()->toDateString();

        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $trips = Trip::query()
            ->with(['route', 'collectionPoint', 'collectionCenter', 'vehicle', 'driver', 'loggedBy'])
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('driver'), fn ($query) => $query->where('driver_id', $request->integer('driver')))
            ->latest('departed_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // §5.1 — sensitive. Withheld entirely without logistics.payments.view.
        $seesPayments = $this->allows('logistics.payments.view');

        return view('milk.logistics.index', [
            'trips' => $trips,
            'date' => $date,
            'routes' => TransportRoute::query()->active()->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->active()->orderBy('registration')->get(),
            'drivers' => Driver::query()->active()->orderBy('name')->get(),
            /*
             * SCOPE-1 — the leg's endpoints, so the operator can say which point
             * the rider actually served. Both lists come through the data scope,
             * so the picker offers only places this user may log against and the
             * service's ARCH-4 check agrees with what the screen showed.
             */
            'centers' => CollectionCenter::query()->active()->orderBy('name')->get(['id', 'name']),
            'points' => CollectionPoint::query()->active()->orderBy('name')
                ->get(['id', 'name', 'code', 'collection_center_id']),
            'seesPayments' => $seesPayments,
            'queuedFeeMinor' => $seesPayments
                ? (int) Trip::query()->excludingTestData()->queuedForPayment()->sum('fee_minor')
                : null,
            /*
             * ARCH-9 — dawn runs depart in the small hours and `departed_at` is a
             * UTC instant, so a 00:30 departure was counted against yesterday. This
             * figure sits beside the queued transport fee and is what an officer
             * sanity-checks a driver's fee against, so it disagreeing with the trip
             * list on the same page is visible and undermines both numbers.
             */
            'litresToday' => Volume::fromCentilitres((int) round(100 * (float) Trip::query()
                ->excludingTestData()
                ->where('departed_at', '>=', $dayStart)
                ->where('departed_at', '<', $dayEnd)
                ->sum('litres_carried'))),
            'canLog' => $this->allows('logistics.trips.create'),
        ]);
    }

    public function storeTrip(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'route_id' => ['required', 'exists:routes,id'],
            // SCOPE-1 — the leg's own endpoints. Optional in the request because
            // a route that names its endpoints can supply them; the service
            // refuses a trip that ends up located nowhere.
            'collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'collection_center_id' => ['nullable', 'exists:collection_centers,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'driver_id' => ['required', 'exists:drivers,id'],
            'departed_at' => ['required', 'date'],
            'arrived_at' => ['required', 'date', 'after_or_equal:departed_at'],
            'litres_carried' => ['nullable', 'numeric', 'min:0'],
            'plus_amount' => ['nullable', 'numeric', 'min:0'],
            'plus_reason' => ['nullable', 'string', 'max:255'],
            'minus_amount' => ['nullable', 'numeric', 'min:0'],
            'minus_reason' => ['nullable', 'string', 'max:255'],
        ], [], [
            'driver_id' => 'rider / driver',
            'departed_at' => 'departed at',
            'arrived_at' => 'arrived at',
            'plus_amount' => 'addition amount',
            'minus_amount' => 'deduction amount',
        ]);

        // §9 — a route is reference data with no scope of its own. What the trip
        // may be logged against is decided by its endpoints, in the service.
        $route = TransportRoute::query()->findOrFail($validated['route_id']);

        $trip = $this->trips->log($route, $validated, $this->currentUser());

        return back()->with('success', $trip->reference.' logged.');
    }
}
