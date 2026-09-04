<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Driver;
use App\Models\Route as TransportRoute;
use App\Models\Vehicle;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\BankService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §9 — the fleet and the routes it runs.
 *
 * The register these three tables make up had no screen at all. `/logistics`
 * renders a "Log a Trip" modal whose route, vehicle and rider selects are
 * populated from them and whose route select is `required`, and nothing anywhere
 * could create a row in any of the three: they were seeded only by
 * DemoDataSeeder, which NFR-12 keeps off by default. So on a production install
 * every picker was empty and no trip could be logged — which also meant
 * `trips.fee_minor`, the figure §15.1's transport payment run will settle from,
 * was never captured at all. Buying a motorcycle, hiring a rider or re-tariffing
 * a leg needed a developer with database access.
 *
 * USER-1 — a rider or a commercial driver is a RECORD, not an account. There is
 * no credential here and there never will be; they are named on a trip so they
 * can be paid.
 *
 * A route's tariff is money and is effective immediately, which is a real
 * difference from BR-13's effective-dated grade rates: a trip snapshots its fee
 * at the moment it is logged (see TripService), so re-tariffing a leg cannot
 * rewrite what a rider was already owed. That is why this screen may edit a
 * tariff in place where the grade-rate screen may not.
 */
class FleetController extends Controller
{
    /** REF-1 — a reference row is retired, never deleted. */
    private const STATUSES = ['active', 'inactive'];

    /** §6.3's vocabulary for a leg's ends. The factory is not a row anywhere. */
    private const ENDPOINTS = ['collection_point', 'collection_center', 'factory'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BankService $bankService,
    ) {}

    public function index(): View
    {
        $this->authorizeAccess('logistics.trips.view', null, 'Fleet and routes');

        return view('milk.fleet.index', [
            'routes' => TransportRoute::query()->orderBy('name')->get(),
            'vehicles' => Vehicle::query()->orderBy('registration')->get(),
            'drivers' => Driver::query()->with(['wallet.transactions'])->orderBy('name')->get(),
            'centers' => CollectionCenter::query()->active()->orderBy('name')->get(['id', 'name', 'distance_to_factory_km', 'transport_fee_minor']),
            'points' => CollectionPoint::query()->active()->orderBy('name')->get(['id', 'name', 'collection_center_id', 'transport_fee_minor']),
            'banks' => $this->bankService->getBanks(),
            'canEdit' => $this->allows('logistics.trips.edit'),
        ]);
    }

    /* ------------------------------- Routes ------------------------------ */

    public function storeRoute(Request $request): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Add a transport route');

        $route = TransportRoute::query()->create($this->routeAttributes($request));

        $this->audit->created(
            $route,
            sprintf('Route %s added — %s, %s', $route->name, $route->distance_km.' km', Money::format($route->tariff_minor)),
            'Logistics',
            ['tariff_minor' => $route->tariff_minor],
            $this->currentUser(),
        );

        return back()->with('success', $route->name.' added.');
    }

    public function updateRoute(Request $request, TransportRoute $route): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Edit route '.$route->name);

        $before = $route->only(['name', 'distance_km', 'tariff_minor', 'status']);

        $route->forceFill($this->routeAttributes($request, $route))->save();

        /*
         * The tariff is the reason this is audited rather than just saved. It is
         * money, and a trip logged tomorrow will be paid at whatever it says —
         * so who changed it and when is the answer to "why did this month cost
         * more than last".
         */
        $this->audit->edited(
            $route,
            'Route '.$route->name.' updated',
            'Logistics',
            $before,
            $route->only(['name', 'distance_km', 'tariff_minor', 'status']),
            $this->currentUser(),
        );

        return back()->with('success', $route->name.' updated.');
    }

    /**
     * Creates the centre→factory leg for every active centre that has none.
     *
     * A fresh install has centres (an administrator creates them) and no routes,
     * and until one exists no trip can be logged. The data is already there and
     * correct: §6.1 puts `distance_to_factory_km` and `transport_fee_minor` on
     * the centre itself, so deriving the route from it invents nothing — it
     * copies the figures the administrator already entered, once, into the shape
     * the trip form needs.
     *
     * Idempotent, and it never touches a route that exists: re-running it after
     * adding a seventh centre creates one route, not seven.
     */
    public function generateCentreRoutes(): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Generate centre routes');

        $existing = TransportRoute::query()
            ->where('from_type', 'collection_center')
            ->whereNotNull('from_id')
            ->pluck('from_id')
            ->all();

        $created = 0;

        foreach (CollectionCenter::query()->active()->get() as $centre) {
            if (in_array($centre->getKey(), $existing, true)) {
                continue;
            }

            $route = TransportRoute::query()->create([
                'name' => $centre->name.' → Factory',
                'from_type' => 'collection_center',
                'from_id' => $centre->getKey(),
                'to_type' => 'factory',
                'to_id' => null,
                'distance_km' => $centre->distance_to_factory_km ?? 0,
                'tariff_minor' => (int) ($centre->transport_fee_minor ?? 0),
                'vehicle_type' => null,
                'status' => 'active',
            ]);

            $this->audit->created(
                $route,
                sprintf('Route %s generated from the centre record', $route->name),
                'Logistics',
                ['derived_from_center' => $centre->code, 'tariff_minor' => $route->tariff_minor],
                $this->currentUser(),
            );

            $created++;
        }

        return back()->with(
            'success',
            $created === 0
                ? 'Every active centre already has a route to the factory.'
                : $created.' route(s) created from the centre records.',
        );
    }

    /* ------------------------------ Vehicles ----------------------------- */

    public function storeVehicle(Request $request): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Add a vehicle');

        $vehicle = Vehicle::query()->create($request->validate([
            'registration' => ['required', 'string', 'max:32', 'unique:vehicles,registration'],
            'type' => ['required', 'string', 'max:32'],
            'capacity_litres' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]));

        return $this->recorded($vehicle, 'Vehicle '.$vehicle->registration.' added', $vehicle->registration.' added.');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Edit vehicle '.$vehicle->registration);

        $before = $vehicle->only(['registration', 'type', 'capacity_litres', 'status']);

        $vehicle->forceFill($request->validate([
            'registration' => ['required', 'string', 'max:32', Rule::unique('vehicles', 'registration')->ignore($vehicle->getKey())],
            'type' => ['required', 'string', 'max:32'],
            'capacity_litres' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]))->save();

        $this->audit->edited(
            $vehicle, 'Vehicle '.$vehicle->registration.' updated', 'Logistics',
            $before, $vehicle->only(['registration', 'type', 'capacity_litres', 'status']), $this->currentUser(),
        );

        return back()->with('success', $vehicle->registration.' updated.');
    }

    /* ------------------------------- Riders ------------------------------ */

    public function verifyBank(Request $request): JsonResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Verify driver bank account');

        $validated = $request->validate([
            'account_number' => ['required', 'string'],
            'bank_code' => ['required', 'string'],
        ]);

        $result = $this->bankService->verifyAccount($validated['account_number'], $validated['bank_code']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Add a rider or driver');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'licence_no' => ['nullable', 'string', 'max:64'],
            'type' => ['required', Rule::in(['rider', 'driver'])],
            'status' => ['required', Rule::in(self::STATUSES)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:32'],
            'bank_account' => ['nullable', 'string', 'max:32'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('drivers', 'public');
        }

        $driver = Driver::query()->create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'licence_no' => $validated['licence_no'] ?? null,
            'type' => $validated['type'],
            'status' => $validated['status'],
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'bank_account' => $validated['bank_account'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
            'image' => $imagePath,
            'created_by_user_id' => $this->currentUser()->id,
        ]);

        return $this->recorded($driver, 'Rider '.$driver->name.' added', $driver->name.' added.');
    }

    public function updateDriver(Request $request, Driver $driver): RedirectResponse
    {
        $this->authorizeAccess('logistics.trips.edit', null, 'Edit rider '.$driver->name);

        $before = $driver->only(['name', 'phone', 'licence_no', 'type', 'status', 'bank_name', 'bank_code', 'bank_account', 'account_name', 'image']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'licence_no' => ['nullable', 'string', 'max:64'],
            'type' => ['required', Rule::in(['rider', 'driver'])],
            'status' => ['required', Rule::in(self::STATUSES)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:32'],
            'bank_account' => ['nullable', 'string', 'max:32'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('image')) {
            if ($driver->image && Storage::disk('public')->exists($driver->image)) {
                Storage::disk('public')->delete($driver->image);
            }
            $driver->image = $request->file('image')->store('drivers', 'public');
        }

        $driver->forceFill([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'licence_no' => $validated['licence_no'] ?? null,
            'type' => $validated['type'],
            'status' => $validated['status'],
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'bank_account' => $validated['bank_account'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
        ])->save();

        $this->audit->edited(
            $driver, 'Rider '.$driver->name.' updated', 'Logistics',
            $before, $driver->only(['name', 'phone', 'licence_no', 'type', 'status', 'bank_name', 'bank_code', 'bank_account', 'account_name', 'image']), $this->currentUser(),
        );

        return back()->with('success', $driver->name.' updated.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function routeAttributes(Request $request, ?TransportRoute $route = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /*
             * §6.3 makes both ENDS required and both IDS nullable, which is
             * exactly right: every leg runs from somewhere to somewhere, but the
             * factory is not a row in any table, so `to_type = factory` has no
             * `to_id` to carry. A leg with no stated ends also falls outside
             * every geography filter, which is how the seeded point→centre
             * tariffs ended up invisible to the trip screen.
             */
            'from_type' => ['required', Rule::in(self::ENDPOINTS)],
            'from_id' => ['nullable', 'integer'],
            'to_type' => ['required', Rule::in(self::ENDPOINTS)],
            'to_id' => ['nullable', 'integer'],
            'distance_km' => ['required', 'numeric', 'min:0'],
            // ARCH-6 — the operator types naira; the column is kobo.
            'tariff' => ['required', 'numeric', 'min:0'],
            'vehicle_type' => ['nullable', 'string', 'max:32'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        return [
            'name' => $validated['name'],
            'from_type' => $validated['from_type'],
            'from_id' => $validated['from_id'] ?? null,
            'to_type' => $validated['to_type'],
            'to_id' => $validated['to_id'] ?? null,
            'distance_km' => $validated['distance_km'],
            'tariff_minor' => Money::fromMajor($validated['tariff']) ?? 0,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'status' => $validated['status'],
        ];
    }

    private function recorded(Model $subject, string $summary, string $message): RedirectResponse
    {
        $this->audit->created($subject, $summary, 'Logistics', [], $this->currentUser());

        return back()->with('success', $message);
    }
}
