{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup, so a dynamic id here would make every
     link to this modal look like a link to a modal nobody defines. --}}
<div class="modal-card">
    <div class="modal-head">
      <h3>{{ $route ? 'Edit '.$route->name : 'Add a route' }}</h3>
      <p>The tariff is what a rider is paid for this leg. A trip snapshots it when
         it is logged, so changing it here never re-prices a trip already made.</p>
    </div>
    <form method="POST"
          action="{{ $route ? route('fleet.routes.update', $route) : route('fleet.routes.store') }}">
      @csrf
      @if ($route)@method('PUT')@endif
      <div class="modal-body">
        <div class="field">
          <label for="{{ $id }}-name">Name</label>
          <input type="text" id="{{ $id }}-name" name="name" required
                 value="{{ old('name', $route?->name) }}" />
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-distance">Distance (km)</label>
            <input type="text" id="{{ $id }}-distance" name="distance_km" inputmode="decimal" required
                   value="{{ old('distance_km', $route?->distance_km) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-tariff">Tariff (₦)</label>
            {{-- ARCH-6 — naira in, kobo stored. --}}
            <input type="text" id="{{ $id }}-tariff" name="tariff" inputmode="decimal" required
                   value="{{ old('tariff', $route ? number_format($route->tariff_minor / 100, 2, '.', '') : '') }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-vtype">Vehicle type</label>
            <input type="text" id="{{ $id }}-vtype" name="vehicle_type"
                   value="{{ old('vehicle_type', $route?->vehicle_type) }}" />
          </div>
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-fromtype">From</label>
            <select id="{{ $id }}-fromtype" name="from_type" required>
              @foreach (['collection_point' => 'A collection point', 'collection_center' => 'A collection centre'] as $value => $label)
                <option value="{{ $value }}" @selected(old('from_type', $route?->from_type) === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="{{ $id }}-from">Which one</label>
            <select id="{{ $id }}-from" name="from_id">
              <option value="">—</option>
              @foreach ($points as $p)
                <option value="{{ $p->id }}" @selected(old('from_id', $route?->from_id) == $p->id)>{{ $p->name }} (point)</option>
              @endforeach
              @foreach ($centers as $c)
                <option value="{{ $c->id }}" @selected(old('from_id', $route?->from_id) == $c->id)>{{ $c->name }} (centre)</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="{{ $id }}-totype">To</label>
            <select id="{{ $id }}-totype" name="to_type" required>
              @foreach (['factory' => 'The factory', 'collection_center' => 'A collection centre', 'collection_point' => 'A collection point'] as $value => $label)
                <option value="{{ $value }}" @selected(old('to_type', $route?->to_type ?? 'factory') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="{{ $id }}-status">Status</label>
            <select id="{{ $id }}-status" name="status" required>
              @foreach (['active', 'inactive'] as $status)
                <option value="{{ $status }}" @selected(old('status', $route?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <a href="#" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $route ? 'Save' : 'Add route' }}</button>
      </div>
    </form>
  </div>
