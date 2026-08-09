@extends('layouts.app')
@section('title', 'Logistics & Transport')

@section('content')
  <div class="page-head">
    <div>
      <h1>Logistics &amp; Transport</h1>
      <p>Trips, riders and vehicles &middot; {{ \App\Support\Wat::longDate($date) }}</p>
    </div>
    <div class="page-actions">
      @if ($canLog)
        <a href="#modal-trip" class="btn btn-primary">+ Log Trip</a>
      @endif
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#128666;</span>
    <div>
      <strong>Riders and drivers do not sign in.</strong>
      Log their trips here on their behalf so their work is on record and can be paid.
      @unless ($seesPayments)
        Transport fees are hidden from you. Ask your supervisor if you need to see them.
      @endunless
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Litres carried today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($litresToday) }}</div>
      <div class="stat-foot">accepted volume only</div></div>
    <div class="stat green"><div class="stat-label">Active riders &amp; drivers</div>
      <div class="stat-value">{{ $drivers->count() }}</div>
      <div class="stat-foot">{{ $vehicles->count() }} vehicles</div></div>
    <div class="stat amber"><div class="stat-label">Routes configured</div>
      <div class="stat-value">{{ $routes->count() }}</div>
      <div class="stat-foot">tariffs are set in Settings</div></div>
    @if ($seesPayments)
      <div class="stat red"><div class="stat-label">Fees queued for payment</div>
        <div class="stat-value">{{ \App\Support\Money::compact($queuedFeeMinor) }}</div>
        <div class="stat-foot">not yet payable from here</div></div>
    @else
      <div class="stat"><div class="stat-label">Transport fees</div>
        <div class="stat-value">&mdash;</div>
        <div class="stat-foot">hidden from you</div></div>
    @endif
  </div>

  {{-- §15.1 — the blocked decision, stated on the screen that would consume it. --}}
  <div class="alert warn mb-16">
    <span>&#128274;</span>
    <div>
      <strong>Transport payment runs are not available yet.</strong>
      Each trip is queued with its fee saved against it, but nothing is paid out from this screen.
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Trips</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="driver">Rider / driver</label>
          <select id="driver" name="driver">
            <option value="">All</option>
            @foreach ($drivers as $driver)
              <option value="{{ $driver->id }}" @selected(request('driver') == $driver->id)>{{ $driver->name }}</option>
            @endforeach
          </select></div>
        @if ($seesPayments)
          <div class="field"><label for="payment_status">Payment</label>
            <select id="payment_status" name="payment_status">
              <option value="">All</option>
              @foreach (['queued' => 'Queued', 'approved' => 'Approved', 'paid' => 'Paid'] as $value => $label)
                <option value="{{ $value }}" @selected(request('payment_status') === $value)>{{ $label }}</option>
              @endforeach
            </select></div>
        @endif
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('logistics.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Trip</th><th>Route</th><th>Leg served</th><th>Rider / driver</th><th>Vehicle</th>
            <th class="num">Litres</th>
            @if ($seesPayments)<th class="num">Fee</th><th>Payment</th>@endif
            <th>Departed</th>
          </tr></thead>
          <tbody>
            @forelse ($trips as $trip)
              <tr>
                <td class="perm-key">{{ $trip->reference }}</td>
                <td>{{ $trip->route?->name }}
                  @if ($trip->route?->distance_km)<div class="cell-sub">{{ $trip->route->distance_km }} km</div>@endif</td>
                {{-- SCOPE-1 — the trip's own endpoints, which are what put it on this list. --}}
                <td>{{ $trip->collectionPoint?->name ?? $trip->collectionCenter?->name ?? '—' }}
                  @if ($trip->collectionPoint && $trip->collectionCenter)
                    <div class="cell-sub">to {{ $trip->collectionCenter->name }}</div>
                  @endif</td>
                <td>{{ $trip->driver?->name ?? '—' }}</td>
                <td>{{ $trip->vehicle?->registration ?? '—' }}</td>
                <td class="num">{{ \App\Support\Volume::format($trip->litres_carried, false) }}</td>
                @if ($seesPayments)
                  <td class="num">{{ \App\Support\Money::format($trip->fee_minor) }}</td>
                  <td><span class="badge {{ ['queued' => 'warning', 'approved' => 'info', 'paid' => 'success'][$trip->payment_status] ?? 'muted' }}">
                    {{ ucfirst($trip->payment_status) }}</span></td>
                @endif
                <td>{{ \App\Support\Wat::relative($trip->departed_at) }}</td>
              </tr>
            @empty
              <tr><td colspan="{{ $seesPayments ? 9 : 7 }}">
                @include('partials.empty', ['title' => 'No trips logged', 'icon' => '&#128666;'])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $trips, 'noun' => 'trips'])
  </div>

  @if ($canLog)
    <div id="modal-trip" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Log a Trip</h3><p>The trip keeps the route&rsquo;s fee as it stands today</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('logistics.trips.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-trip" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-trip'])
            <div class="form-grid">
              <div class="field"><label for="tr-route">Route <span class="req">*</span></label>
                <select id="tr-route" name="route_id" required>
                  @foreach ($routes as $route)
                    <option value="{{ $route->id }}">
                      {{ $route->name }}@if ($seesPayments) — {{ $route->formattedTariff() }}@endif
                    </option>
                  @endforeach
                </select>
                <div class="hint">Route tariffs are edited in Settings.</div></div>
              {{--
                SCOPE-1 — the route is a tariff template and most of them name no
                places at all, so the leg's endpoints are recorded here. Without
                them a trip belongs to nobody: it shows on no point-, centre- or
                LGA-scoped list, and its cost cannot be set against the point's
                transport fee.
              --}}
              <div class="field"><label for="tr-center">Collection center <span class="req">*</span></label>
                <select id="tr-center" name="collection_center_id" required>
                  <option value="">&mdash;</option>
                  @foreach ($centers as $center)
                    <option value="{{ $center->id }}" @selected(old('collection_center_id') == $center->id)>{{ $center->name }}</option>
                  @endforeach
                </select>
                <div class="hint">The centre this leg served, whether it collected from it or delivered to it.</div></div>
              <div class="field"><label for="tr-point">Collection point</label>
                <select id="tr-point" name="collection_point_id" data-searchable data-combo-placeholder="Search points…">
                  <option value="">None — this leg ran from the center</option>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}" @selected(old('collection_point_id') == $point->id)>{{ $point->name }} &mdash; {{ $point->code }}</option>
                  @endforeach
                </select>
                <div class="hint">Name the point on a point&rarr;center run so its transport cost is attributable.</div></div>
              <div class="field"><label for="tr-driver">Rider / driver</label>
                <select id="tr-driver" name="driver_id">
                  <option value="">Unassigned</option>
                  @foreach ($drivers as $driver)
                    <option value="{{ $driver->id }}">{{ $driver->name }} ({{ $driver->type }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="tr-vehicle">Vehicle</label>
                <select id="tr-vehicle" name="vehicle_id">
                  <option value="">Unassigned</option>
                  @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}">{{ $vehicle->registration }} ({{ $vehicle->type }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="tr-litres">Litres carried</label>
                <input type="text" id="tr-litres" name="litres_carried" inputmode="decimal" value="0" />
                <div class="hint">Count accepted litres only. Rejected milk is not carried and is not paid for.</div></div>
              <div class="field"><label for="tr-departed">Departed at</label>
                <input type="datetime-local" id="tr-departed" name="departed_at"
                       value="{{ \App\Support\Wat::forInput() }}" /></div>
              <div class="field"><label for="tr-arrived">Arrived at</label>
                <input type="datetime-local" id="tr-arrived" name="arrived_at" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Log trip</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
