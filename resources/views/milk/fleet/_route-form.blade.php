{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup, so a dynamic id here would make every
     link to this modal look like a link to a modal nobody defines. --}}
@php
  $fromType = old('from_type', $route?->from_type ?? 'collection_center');
  $toType = old('to_type', $route?->to_type ?? 'factory');
  $fromId = old('from_id', $route?->from_id);
  $toId = old('to_id', $route?->to_id);

  $currentCategory = 'center_factory';
  if ($fromType === 'collection_point' && $toType === 'collection_center') {
    $currentCategory = 'point_center';
  } elseif ($fromType === 'collection_point' && $toType === 'factory') {
    $currentCategory = 'point_factory';
  } elseif ($fromType === 'collection_center' && $toType === 'factory') {
    $currentCategory = 'center_factory';
  } elseif ($fromType === 'collection_center' && $toType === 'collection_center') {
    $currentCategory = 'center_center';
  }

  $isFromPoint = in_array($currentCategory, ['point_center', 'point_factory'], true);
  $isToFactory = in_array($currentCategory, ['point_factory', 'center_factory'], true);
@endphp

<a href="#" class="modal-overlay"></a>
<div class="modal-dialog">
  <div class="modal-head">
    <div>
      <h3>{{ $route ? 'Edit '.$route->name : 'Add a route' }}</h3>
      <p>The tariff is what a rider is paid for this leg. A trip snapshots it when
         it is logged, so changing it here never re-prices a trip already made.</p>
    </div>
    <a href="#" class="modal-close">&times;</a>
  </div>
  <form method="POST"
        action="{{ $route ? route('fleet.routes.update', $route) : route('fleet.routes.store') }}"
        class="route-form"
        data-has-route="{{ $route ? '1' : '0' }}">
    @csrf
    @if ($route)@method('PUT')@endif
    <div class="modal-body">
      {{-- Route Category --}}
      <div class="field" style="margin-bottom:14px;">
        <label for="{{ $id }}-category"><strong>Route Category</strong></label>
        <select id="{{ $id }}-category" class="route-category-select" style="font-weight:600;">
          <option value="center_factory" @selected($currentCategory === 'center_factory')>Centre → Factory (Collection Centre to Processing Plant)</option>
          <option value="point_center" @selected($currentCategory === 'point_center')>Point → Centre (Collection Point to Collection Centre)</option>
          <option value="point_factory" @selected($currentCategory === 'point_factory')>Point → Factory (Collection Point to Processing Plant)</option>
          <option value="center_center" @selected($currentCategory === 'center_center')>Centre → Centre (Inter-Centre Transfer)</option>
        </select>
        <div class="hint">Select transit category. Origin and destination fields adjust automatically.</div>
      </div>

      {{-- Hidden backend endpoints --}}
      <input type="hidden" id="{{ $id }}-fromtype" name="from_type" class="route-from-type" value="{{ $fromType }}" />
      <input type="hidden" id="{{ $id }}-totype" name="to_type" class="route-to-type" value="{{ $toType }}" />

      {{-- Origin and Destination dynamic dropdowns --}}
      <div class="form-grid" style="margin-bottom:14px;">
        {{-- FROM: Point --}}
        <div id="{{ $id }}-from-point-wrap" class="field from-point-wrap" style="{{ $isFromPoint ? '' : 'display:none;' }}">
          <label for="{{ $id }}-from-point">From (Collection Point) <span style="color:var(--danger, #dc2626);">*</span></label>
          <select id="{{ $id }}-from-point"
                  class="from-point-select"
                  {{ $isFromPoint ? 'name=from_id' : 'disabled' }}>
            <option value="">Select Collection Point...</option>
            @foreach ($points as $p)
              <option value="{{ $p->id }}"
                      data-name="{{ $p->name }}"
                      data-center-id="{{ $p->collection_center_id }}"
                      data-tariff="{{ $p->transport_fee_minor ? number_format($p->transport_fee_minor / 100, 2, '.', '') : '' }}"
                      @selected((string) $fromId === (string) $p->id)>
                {{ $p->name }}
              </option>
            @endforeach
          </select>
        </div>

        {{-- FROM: Centre --}}
        <div id="{{ $id }}-from-center-wrap" class="field from-center-wrap" style="{{ $isFromPoint ? 'display:none;' : '' }}">
          <label for="{{ $id }}-from-center">From (Collection Centre) <span style="color:var(--danger, #dc2626);">*</span></label>
          <select id="{{ $id }}-from-center"
                  class="from-center-select"
                  {{ $isFromPoint ? 'disabled' : 'name=from_id' }}>
            <option value="">Select Collection Centre...</option>
            @foreach ($centers as $c)
              <option value="{{ $c->id }}"
                      data-name="{{ $c->name }}"
                      data-distance="{{ $c->distance_to_factory_km }}"
                      data-tariff="{{ $c->transport_fee_minor ? number_format($c->transport_fee_minor / 100, 2, '.', '') : '' }}"
                      @selected((string) $fromId === (string) $c->id)>
                {{ $c->name }}
              </option>
            @endforeach
          </select>
        </div>

        {{-- TO: Centre --}}
        <div id="{{ $id }}-to-center-wrap" class="field to-center-wrap" style="{{ $isToFactory ? 'display:none;' : '' }}">
          <label for="{{ $id }}-to-center">To (Collection Centre) <span style="color:var(--danger, #dc2626);">*</span></label>
          <select id="{{ $id }}-to-center"
                  class="to-center-select"
                  {{ $isToFactory ? 'disabled' : 'name=to_id' }}>
            <option value="">Select Destination Centre...</option>
            @foreach ($centers as $c)
              <option value="{{ $c->id }}" data-name="{{ $c->name }}" @selected((string) $toId === (string) $c->id)>
                {{ $c->name }}
              </option>
            @endforeach
          </select>
        </div>

        {{-- TO: Factory --}}
        <div id="{{ $id }}-to-factory-wrap" class="field to-factory-wrap" style="{{ $isToFactory ? '' : 'display:none;' }}">
          <label>To Destination</label>
          <input type="text" value="The Factory (Gondal Processing Plant)" readonly style="background:var(--card, #f8fafc); cursor:not-allowed; font-weight:600; color:var(--text-bright, #0f172a);" />
          <input type="hidden" id="{{ $id }}-to-factory-hidden" class="to-factory-hidden" {{ $isToFactory ? 'name=to_id' : 'disabled' }} value="" />
        </div>
      </div>

      {{-- Route Name --}}
      <div class="field" style="margin-bottom:14px;">
        <label for="{{ $id }}-name">Route Name <span style="color:var(--danger, #dc2626);">*</span></label>
        <input type="text" id="{{ $id }}-name" name="name" class="route-name" required
               value="{{ old('name', $route?->name) }}" placeholder="e.g. Kumbotso → Factory" />
        <div class="hint">Generated from origin and destination, or customize as needed.</div>
      </div>

      {{-- Details: Distance, Tariff, Vehicle, Status --}}
      <div class="form-grid">
        <div class="field">
          <label for="{{ $id }}-distance">Distance (km) <span style="color:var(--danger, #dc2626);">*</span></label>
          <input type="text" id="{{ $id }}-distance" name="distance_km" class="route-distance" inputmode="decimal" required
                 value="{{ old('distance_km', $route?->distance_km) }}" placeholder="e.g. 25.5" />
        </div>
        <div class="field">
          <label for="{{ $id }}-tariff">Tariff (₦) <span style="color:var(--danger, #dc2626);">*</span></label>
          {{-- ARCH-6 — naira in, kobo stored. --}}
          <input type="text" id="{{ $id }}-tariff" name="tariff" class="route-tariff" inputmode="decimal" required
                 value="{{ old('tariff', $route ? number_format($route->tariff_minor / 100, 2, '.', '') : '') }}" placeholder="e.g. 1500.00" />
        </div>
        <div class="field">
          <label for="{{ $id }}-status">Status <span style="color:var(--danger, #dc2626);">*</span></label>
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
