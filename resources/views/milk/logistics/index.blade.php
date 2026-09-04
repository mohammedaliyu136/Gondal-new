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
                  <td class="num">
                    <div style="font-weight:700;">{{ \App\Support\Money::format($trip->fee_minor) }}</div>
                    @if ($trip->plus_amount_minor > 0 || $trip->minus_amount_minor > 0)
                      @php
                        $feeBreakdownTitle = 'Base: ' . $trip->formattedBaseTariff();
                        if ($trip->plus_amount_minor) {
                          $feeBreakdownTitle .= ' | +' . $trip->formattedPlusAmount() . ($trip->plus_reason ? ' (' . $trip->plus_reason . ')' : '');
                        }
                        if ($trip->minus_amount_minor) {
                          $feeBreakdownTitle .= ' | -' . $trip->formattedMinusAmount() . ($trip->minus_reason ? ' (' . $trip->minus_reason . ')' : '');
                        }
                      @endphp
                      <div class="cell-sub" style="font-size:0.75rem; white-space:nowrap;" title="{{ $feeBreakdownTitle }}">
                        Base {{ $trip->formattedBaseTariff() }}
                        @if ($trip->plus_amount_minor) <span style="color:#0b7d54; font-weight:600;">+{{ $trip->formattedPlusAmount() }}</span>@endif
                        @if ($trip->minus_amount_minor) <span style="color:#dc2626; font-weight:600;">-{{ $trip->formattedMinusAmount() }}</span>@endif
                      </div>
                    @endif
                  </td>
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
      <div class="modal-dialog" style="max-width:680px; width:95%;">
        <div class="modal-head">
          <div>
            <h3>Log a Trip</h3>
            <p>Record a transport run, apply adjustments, and credit driver wallet</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('logistics.trips.store') }}" id="trip-log-form">
          @csrf
          <input type="hidden" name="_modal" value="modal-trip" />
          {{-- Hidden endpoints auto-derived from selected route --}}
          <input type="hidden" id="tr-center" name="collection_center_id" value="{{ old('collection_center_id') }}" />
          <input type="hidden" id="tr-point" name="collection_point_id" value="{{ old('collection_point_id') }}" />

          <div class="modal-body" style="padding:16px 20px;">
            @include('partials.modal-errors', ['modal' => 'modal-trip'])

            {{-- 1. Route Selection (Searchable & Filterable) --}}
            <div class="field" style="margin-bottom:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <label for="tr-route" style="margin:0;"><strong>Route</strong> <span class="req">*</span></label>
                <span class="hint" style="margin:0;">Endpoints are bound automatically from the route</span>
              </div>

              {{-- Quick Category Filter Chips --}}
              <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;" id="tr-route-filters" data-combo-ignore>
                <button type="button" class="btn btn-sm btn-primary active-route-filter" data-filter="all" style="font-size:0.75rem; padding:3px 10px;">All</button>
                <button type="button" class="btn btn-sm btn-ghost" data-filter="center_factory" style="font-size:0.75rem; padding:3px 10px;">Centre → Factory</button>
                <button type="button" class="btn btn-sm btn-ghost" data-filter="point_center" style="font-size:0.75rem; padding:3px 10px;">Point → Centre</button>
                <button type="button" class="btn btn-sm btn-ghost" data-filter="point_factory" style="font-size:0.75rem; padding:3px 10px;">Point → Factory</button>
                <button type="button" class="btn btn-sm btn-ghost" data-filter="center_center" style="font-size:0.75rem; padding:3px 10px;">Centre → Centre</button>
              </div>

              <select id="tr-route" name="route_id" required
                      data-searchable data-min-options="0"
                      data-combo-placeholder="Search routes by origin, destination, or name…"
                      style="font-weight:600;">
                <option value="">Select transit route...</option>
                @foreach ($routes as $route)
                  @php
                    $cat = 'other';
                    if ($route->from_type === 'collection_center' && $route->to_type === 'factory') $cat = 'center_factory';
                    elseif ($route->from_type === 'collection_point' && $route->to_type === 'collection_center') $cat = 'point_center';
                    elseif ($route->from_type === 'collection_point' && $route->to_type === 'factory') $cat = 'point_factory';
                    elseif ($route->from_type === 'collection_center' && $route->to_type === 'collection_center') $cat = 'center_center';
                  @endphp
                  <option value="{{ $route->id }}"
                          data-category="{{ $cat }}"
                          data-tariff="{{ number_format($route->tariff_minor / 100, 2, '.', '') }}"
                          data-formatted-tariff="{{ $route->formattedTariff() }}"
                          data-distance="{{ $route->distance_km ?? '' }}"
                          data-from-type="{{ $route->from_type }}"
                          data-from-id="{{ $route->from_id }}"
                          data-to-type="{{ $route->to_type }}"
                          data-to-id="{{ $route->to_id }}"
                          data-name="{{ $route->name }}"
                          @selected(old('route_id') == $route->id)>
                    {{ $route->name }} — {{ $route->formattedTariff() }}@if($route->distance_km) ({{ $route->distance_km }} km)@endif
                  </option>
                @endforeach
              </select>

              {{-- Visual Route Summary Card --}}
              <div id="tr-route-summary" style="display:none; margin-top:10px; padding:10px 14px; background:var(--card, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; font-size:0.85rem;">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                  <div>
                    <span style="color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; font-weight:600;">Route / Leg</span>
                    <div id="tr-summary-leg" style="font-weight:700; color:var(--text-bright); font-size:0.95rem;">—</div>
                  </div>
                  <div style="text-align:right;">
                    <span style="color:var(--text-muted); font-size:0.75rem; text-transform:uppercase; font-weight:600;">Base Tariff</span>
                    <div id="tr-summary-tariff" style="font-weight:800; color:#0b7d54; font-size:1.1rem;">—</div>
                  </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; padding-top:6px; border-top:1px dashed var(--border, #e2e8f0); font-size:0.8rem; color:var(--text-muted);">
                  <span id="tr-summary-endpoints">—</span>
                  <span id="tr-summary-distance">—</span>
                </div>
              </div>
            </div>

            {{-- 2. Rider / Driver & Vehicle --}}
            <div class="form-grid" style="margin-bottom:14px;">
              <div class="field">
                <label for="tr-driver">Rider / driver <span class="req">*</span></label>
                <select id="tr-driver" name="driver_id" required>
                  <option value="">Select rider or driver...</option>
                  @foreach ($drivers as $driver)
                    <option value="{{ $driver->id }}" @selected(old('driver_id') == $driver->id)>
                      {{ $driver->name }} ({{ ucfirst($driver->type) }}) &bull; Bal: {{ $driver->formattedWalletBalance() }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="tr-vehicle">Vehicle</label>
                <select id="tr-vehicle" name="vehicle_id">
                  <option value="">Unassigned</option>
                  @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>
                      {{ $vehicle->registration }} ({{ $vehicle->type }})
                    </option>
                  @endforeach
                </select>
              </div>
            </div>

            {{-- 3. Operations: Litres, Departed, Arrived --}}
            <div class="form-grid" style="margin-bottom:16px;">
              <div class="field">
                <label for="tr-litres">Litres carried</label>
                <input type="text" id="tr-litres" name="litres_carried" inputmode="decimal" value="{{ old('litres_carried', '0') }}" />
                <div class="hint">Count accepted litres only.</div>
              </div>
              <div class="field">
                <label for="tr-departed">Departed at <span class="req">*</span></label>
                <input type="datetime-local" id="tr-departed" name="departed_at"
                       value="{{ old('departed_at', \App\Support\Wat::forInput()) }}" required />
              </div>
              <div class="field">
                <label for="tr-arrived">Arrived at <span class="req">*</span></label>
                <input type="datetime-local" id="tr-arrived" name="arrived_at"
                       value="{{ old('arrived_at') }}" required />
              </div>
            </div>

            {{-- 4. Tariff Adjustments (Plus / Minus / Both) --}}
            <div style="background:var(--card, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:14px 16px; margin-bottom:14px;">
              <div style="font-weight:700; font-size:0.92rem; color:var(--text-bright); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                <span>&#9878;</span> Trip Adjustments &amp; Allowances
              </div>
              <p style="margin:0 0 12px 0; font-size:0.8rem; color:var(--text-muted);">
                Add bonus/hardship for extra detours or subtract penalties for delayed dispatch / milk spoilage.
              </p>

              {{-- Positive Adjustment --}}
              <div class="form-grid" style="margin-bottom:10px;">
                <div class="field">
                  <label for="tr-plus-amount" style="color:#0b7d54;">(+) Addition / Bonus (₦)</label>
                  <input type="number" step="0.01" min="0" id="tr-plus-amount" name="plus_amount"
                         placeholder="0.00" value="{{ old('plus_amount') }}" style="border-color:#a7f3d0;" />
                </div>
                <div class="field">
                  <label for="tr-plus-reason">Addition reason</label>
                  <input type="text" id="tr-plus-reason" name="plus_reason"
                         placeholder="e.g. Extra collection route detour, emergency pickup" value="{{ old('plus_reason') }}" />
                </div>
              </div>

              {{-- Negative Adjustment --}}
              <div class="form-grid" style="margin-bottom:12px;">
                <div class="field">
                  <label for="tr-minus-amount" style="color:#dc2626;">(-) Deduction / Penalty (₦)</label>
                  <input type="number" step="0.01" min="0" id="tr-minus-amount" name="minus_amount"
                         placeholder="0.00" value="{{ old('minus_amount') }}" style="border-color:#fecaca;" />
                </div>
                <div class="field">
                  <label for="tr-minus-reason">Deduction reason</label>
                  <input type="text" id="tr-minus-reason" name="minus_reason"
                         placeholder="e.g. Rider delayed dispatch causing spoilage" value="{{ old('minus_reason') }}" />
                </div>
              </div>

              {{-- Live Calculated Net Payable Box --}}
              <div style="background:#fff; border:1px solid var(--border, #e2e8f0); border-radius:6px; padding:12px 14px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                  <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Net Payable Trip Fee</span>
                  <div id="tr-calc-breakdown" style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Base ₦0.00</div>
                </div>
                <div id="tr-calc-total" style="font-size:1.35rem; font-weight:800; color:#0b7d54;">₦0.00</div>
              </div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:6px; display:flex; align-items:center; gap:4px;">
                <span>&#128181;</span> Credited automatically to the rider/driver's electronic wallet upon logging.
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Log trip</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const routeSelect = document.getElementById('tr-route');
        const centerInput = document.getElementById('tr-center');
        const pointInput = document.getElementById('tr-point');
        const summaryBox = document.getElementById('tr-route-summary');
        const summaryLeg = document.getElementById('tr-summary-leg');
        const summaryTariff = document.getElementById('tr-summary-tariff');
        const summaryEndpoints = document.getElementById('tr-summary-endpoints');
        const summaryDistance = document.getElementById('tr-summary-distance');

        const plusAmountInput = document.getElementById('tr-plus-amount');
        const minusAmountInput = document.getElementById('tr-minus-amount');
        const calcBreakdown = document.getElementById('tr-calc-breakdown');
        const calcTotal = document.getElementById('tr-calc-total');

        const pointToCenterMap = {
          @foreach ($points as $p)
            '{{ $p->id }}': '{{ $p->collection_center_id }}',
          @endforeach
        };

        function formatNaira(amount) {
          return '₦' + Number(amount).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Category filter chips for fast search
        const filterBtns = document.querySelectorAll('#tr-route-filters button');
        filterBtns.forEach(btn => {
          btn.addEventListener('mousedown', function(e) {
            e.preventDefault(); // Keep focus; prevent blur from closing combobox
          });

          btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            filterBtns.forEach(b => {
              b.classList.remove('btn-primary');
              b.classList.add('btn-ghost');
            });
            this.classList.remove('btn-ghost');
            this.classList.add('btn-primary');

            const filter = this.dataset.filter;
            const comboInput = routeSelect.parentElement ? routeSelect.parentElement.querySelector('.combo-input') : null;

            Array.from(routeSelect.options).forEach(opt => {
              if (!opt.value) return;
              const matches = filter === 'all' || opt.dataset.category === filter;
              opt.hidden = !matches;
            });

            // If currently selected route does not match filter, reset selection
            const currentOpt = routeSelect.options[routeSelect.selectedIndex];
            if (currentOpt && currentOpt.value && currentOpt.hidden) {
              routeSelect.value = '';
              updateRouteDetails();
            }

            if (comboInput) {
              comboInput.value = '';
            }

            // Immediately pop open dropdown showing all routes matching this category
            routeSelect.dispatchEvent(new CustomEvent('combo:open'));
          });
        });

        function updateRouteDetails() {
          const opt = routeSelect.options[routeSelect.selectedIndex];
          if (!opt || !opt.value) {
            if (summaryBox) summaryBox.style.display = 'none';
            centerInput.value = '';
            pointInput.value = '';
            updateCalculations(0);
            return;
          }

          const fromType = opt.dataset.fromType;
          const fromId = opt.dataset.fromId;
          const toType = opt.dataset.toType;
          const toId = opt.dataset.toId;
          const baseTariff = parseFloat(opt.dataset.tariff) || 0;

          // Auto-resolve endpoints
          let resolvedCenterId = '';
          let resolvedPointId = '';

          if (fromType === 'collection_point' && fromId) {
            resolvedPointId = fromId;
            resolvedCenterId = pointToCenterMap[fromId] || (toType === 'collection_center' ? toId : '');
          } else if (fromType === 'collection_center' && fromId) {
            resolvedCenterId = fromId;
          } else if (toType === 'collection_center' && toId) {
            resolvedCenterId = toId;
          }

          centerInput.value = resolvedCenterId;
          pointInput.value = resolvedPointId;

          if (summaryBox) {
            summaryBox.style.display = 'block';
            summaryLeg.textContent = opt.dataset.name || opt.text;
            summaryTariff.textContent = opt.dataset.formattedTariff || formatNaira(baseTariff);
            if (summaryEndpoints) {
              const fromLabel = fromType ? fromType.replace('collection_', '').replace('_', ' ') : 'Origin';
              const toLabel = toType ? toType.replace('collection_', '').replace('_', ' ') : 'Destination';
              summaryEndpoints.textContent = `Transit: ${fromLabel.toUpperCase()} → ${toLabel.toUpperCase()}`;
            }
            if (summaryDistance) {
              summaryDistance.textContent = opt.dataset.distance ? `${opt.dataset.distance} km` : '';
            }
          }

          updateCalculations(baseTariff);
        }

        function updateCalculations(base) {
          if (typeof base === 'undefined') {
            const opt = routeSelect.options[routeSelect.selectedIndex];
            base = opt && opt.value ? (parseFloat(opt.dataset.tariff) || 0) : 0;
          }

          const plus = parseFloat(plusAmountInput.value) || 0;
          const minus = parseFloat(minusAmountInput.value) || 0;
          const net = Math.max(0, base + plus - minus);

          let breakdownParts = ['Base ' + formatNaira(base)];
          if (plus > 0) breakdownParts.push('+' + formatNaira(plus));
          if (minus > 0) breakdownParts.push('-' + formatNaira(minus));

          calcBreakdown.textContent = breakdownParts.join(' ');
          calcTotal.textContent = formatNaira(net);
        }

        if (routeSelect) {
          routeSelect.addEventListener('change', updateRouteDetails);
        }
        if (plusAmountInput) {
          plusAmountInput.addEventListener('input', () => updateCalculations());
        }
        if (minusAmountInput) {
          minusAmountInput.addEventListener('input', () => updateCalculations());
        }

        // Initialize state on load
        if (routeSelect && routeSelect.value) {
          updateRouteDetails();
        }
      });
    </script>
  @endif
@endsection

