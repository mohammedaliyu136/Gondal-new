@extends('layouts.app')
@section('title', 'Fleet & Routes')

@section('content')
  <div class="page-head">
    <div>
      <h1>Fleet &amp; Routes</h1>
      <p>{{ $routes->count() }} route(s) &middot; {{ $vehicles->count() }} vehicle(s) &middot;
         {{ $drivers->count() }} rider(s)</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('logistics.index') }}" class="btn btn-outline">Trips</a>
    </div>
  </div>

  @if ($routes->where('status', 'active')->isEmpty())
    {{-- The state a fresh install starts in, and the reason this screen exists:
         the trip form's route select is required, so with no routes no trip can
         be logged and no transport fee is ever captured. --}}
    <div class="card mb-16">
      <div class="empty">
        <h3>No active routes</h3>
        <p>A trip cannot be logged until at least one route exists — the fee a rider
           is paid comes from the route's tariff.</p>
      </div>
    </div>
  @endif

  {{-- ------------------------------ Routes ------------------------------ --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Routes</h3><p>A leg and what it pays.</p></div>
      <div>
        @if ($canEdit)
          {{-- The figures are already on the centre records; this copies them
               into the shape the trip form needs rather than asking for them
               a second time. --}}
          <form method="POST" action="{{ route('fleet.routes.generate') }}" class="inline">
            @csrf
            <button type="submit" class="btn btn-outline">Generate centre → factory routes</button>
          </form>
          <a href="#modal-route" class="btn btn-primary">+ Add route</a>
        @endif
      </div>
    </div>
    @if ($routes->isEmpty())
      <div class="empty"><h3>No routes yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Route</th><th>Distance</th><th>Tariff</th><th>Vehicle type</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($routes as $r)
              <tr>
                <td>{{ $r->name }}</td>
                <td>{{ $r->distance_km }} km</td>
                <td>{{ $r->formattedTariff() }}</td>
                <td>{{ $r->vehicle_type ?? '—' }}</td>
                <td><span class="badge {{ $r->status === 'active' ? '' : 'muted' }}">{{ $r->status }}</span></td>
                <td class="row-actions">
                  @if ($canEdit)<a href="#modal-route-{{ $r->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  {{-- ----------------------------- Vehicles ----------------------------- --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Vehicles</h3></div>
      @if ($canEdit)<a href="#modal-vehicle" class="btn btn-primary">+ Add vehicle</a>@endif
    </div>
    @if ($vehicles->isEmpty())
      <div class="empty"><h3>No vehicles yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Registration</th><th>Type</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($vehicles as $v)
              <tr>
                <td>{{ $v->registration }}</td>
                <td>{{ $v->type }}</td>
                <td>{{ $v->capacity_litres ? $v->capacity_litres.' L' : '—' }}</td>
                <td><span class="badge {{ $v->status === 'active' ? '' : 'muted' }}">{{ $v->status }}</span></td>
                <td class="row-actions">
                  @if ($canEdit)<a href="#modal-vehicle-{{ $v->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  {{-- ------------------------- Riders and drivers ------------------------ --}}
  <div class="card">
    <div class="card-head">
      <div>
        <h3>Riders &amp; drivers</h3>
        {{-- USER-1, said on the screen so nobody looks for a login for them. --}}
        <p>Records, not accounts. Named on a trip so they can be paid.</p>
      </div>
      @if ($canEdit)<a href="#modal-driver" class="btn btn-primary">+ Add rider</a>@endif
    </div>
    @if ($drivers->isEmpty())
      <div class="empty"><h3>No riders yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Name</th><th>Phone</th><th>Licence</th><th>Type</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($drivers as $d)
              <tr>
                <td>{{ $d->name }}</td>
                <td>{{ $d->phone ?? '—' }}</td>
                <td>{{ $d->licence_no ?? '—' }}</td>
                <td>{{ $d->type }}</td>
                <td><span class="badge {{ $d->status === 'active' ? '' : 'muted' }}">{{ $d->status }}</span></td>
                <td class="row-actions">
                  @if ($canEdit)<a href="#modal-driver-{{ $d->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if ($canEdit)
    <div id="modal-route" class="modal">
      @include('milk.fleet._route-form', ['id' => 'modal-route', 'route' => null, 'centers' => $centers, 'points' => $points])
    </div>
    @foreach ($routes as $r)
      <div id="modal-route-{{ $r->id }}" class="modal">
        @include('milk.fleet._route-form', ['id' => 'modal-route-'.$r->id, 'route' => $r, 'centers' => $centers, 'points' => $points])
      </div>
    @endforeach

    <div id="modal-vehicle" class="modal">
      @include('milk.fleet._vehicle-form', ['id' => 'modal-vehicle', 'vehicle' => null])
    </div>
    @foreach ($vehicles as $v)
      <div id="modal-vehicle-{{ $v->id }}" class="modal">
        @include('milk.fleet._vehicle-form', ['id' => 'modal-vehicle-'.$v->id, 'vehicle' => $v])
      </div>
    @endforeach

    <div id="modal-driver" class="modal">
      @include('milk.fleet._driver-form', ['id' => 'modal-driver', 'driver' => null])
    </div>
    @foreach ($drivers as $d)
      <div id="modal-driver-{{ $d->id }}" class="modal">
        @include('milk.fleet._driver-form', ['id' => 'modal-driver-'.$d->id, 'driver' => $d])
      </div>
    @endforeach
  @endif
@endsection
