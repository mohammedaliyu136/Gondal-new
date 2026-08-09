@extends('layouts.app')
@section('title', 'Collection Centers')

@section('content')
  <div class="page-head">
    <div>
      <h1>Collection Centers</h1>
      <p>Points feed centers; centers dispatch batches to the factory</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('collection-points.index') }}" class="btn btn-outline">Collection points</a>
      @if ($canCreate)
        <a href="#modal-new-center" class="btn btn-primary">+ Add Collection Center</a>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>Centers</h3><p>{{ number_format($centers->total()) }} within your scope</p></div>
      <form method="GET" class="flex">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <option value="active" @selected(request('status') === 'active')>Active</option>
          <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
        </select>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Center</th><th>LGA</th><th class="num">Points</th><th>Officer</th>
            <th>Logistics</th><th class="num">Confirmed today</th><th class="num">Cold storage</th>
            <th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($centers as $center)
              @php($today = $todayByCenter[$center->id] ?? null)
              <tr>
                <td><div class="font-bold">{{ $center->name }}</div><div class="cell-sub perm-key">{{ $center->code }}</div></td>
                <td>{{ $center->lga?->name }}</td>
                <td class="num">{{ $center->collection_points_count }}</td>
                <td>{{ $center->officer?->name ?? '—' }}</td>
                <td>{{ $center->logisticsOfficer?->name ?? '—' }}</td>
                <td class="num font-bold">
                  {{ $today ? \App\Support\Volume::format($today->litres) : '—' }}
                  @if ($today)<div class="cell-sub">{{ $today->consignments }} consignments</div>@endif
                </td>
                <td class="num">{{ $center->cold_storage_litres ? \App\Support\Volume::format($center->cold_storage_litres) : '—' }}</td>
                <td><span class="badge {{ $center->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($center->status) }}</span></td>
                <td class="actions">
                  {{-- §4 — the detail screen needs milk.consignment.confirm.view. --}}
                  @can('milk.consignment.confirm.view')
                    <a href="{{ route('collection-centers.show', $center) }}" class="btn btn-ghost btn-sm">Open</a>
                  @else
                    <span class="text-muted text-small">view only</span>
                  @endcan
                  {{--
                    The edit form lives on the detail screen rather than being
                    repeated per row: a centre carries nine editable fields and
                    twenty-five copies of that modal is most of the page weight.
                  --}}
                  @if ($canEdit)
                    <a href="{{ route('collection-centers.show', $center) }}#modal-edit-center" class="btn btn-outline btn-sm">Edit</a>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', [
                'title' => 'No centers in your scope',
                'message' => 'Your data scope is '.auth()->user()->overallScopeDescription().'.',
                'icon' => '&#127981;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $centers, 'noun' => 'centers'])
  </div>

  @if ($canCreate)
    <div id="modal-new-center" class="modal @if (old('_modal') === 'modal-new-center') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add collection center</h3>
            <p>Points feed a center; the center dispatches batches to the factory</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('collection-centers.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-center" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-center'])
            <div class="form-grid">
              <div class="field"><label for="cc-code">Code <span class="req">*</span></label>
                <input type="text" id="cc-code" name="code" value="{{ old('code') }}" required />
                <div class="hint">Short reference used on consignments and batches.</div></div>
              <div class="field"><label for="cc-name">Name <span class="req">*</span></label>
                <input type="text" id="cc-name" name="name" value="{{ old('name') }}" required /></div>
              <div class="field"><label for="cc-lga">LGA <span class="req">*</span></label>
                <select id="cc-lga" name="lga_id" data-searchable data-combo-placeholder="Search LGAs…" required>
                  <option value="">&mdash;</option>
                  @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}" @selected(old('lga_id') == $lga->id)>{{ $lga->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="cc-status">Status</label>
                <select id="cc-status" name="status">
                  @foreach (['active' => 'Active', 'suspended' => 'Suspended'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="cc-officer">Collection officer</label>
                <select id="cc-officer" name="officer_user_id" data-searchable data-combo-placeholder="Search staff…">
                  <option value="">&mdash;</option>
                  @foreach ($staff as $person)
                    <option value="{{ $person->id }}" @selected(old('officer_user_id') == $person->id)>{{ $person->name }} &mdash; {{ $person->email }}</option>
                  @endforeach
                </select>
                <div class="hint">Confirms consignments arriving at this center.</div></div>
              <div class="field"><label for="cc-logistics">Logistics officer</label>
                <select id="cc-logistics" name="logistics_user_id" data-searchable data-combo-placeholder="Search staff…">
                  <option value="">&mdash;</option>
                  @foreach ($staff as $person)
                    <option value="{{ $person->id }}" @selected(old('logistics_user_id') == $person->id)>{{ $person->name }} &mdash; {{ $person->email }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="cc-cold">Cold storage (L)</label>
                <input type="text" id="cc-cold" name="cold_storage_litres" inputmode="decimal" value="{{ old('cold_storage_litres') }}" /></div>
              <div class="field"><label for="cc-distance">Distance to factory (km)</label>
                <input type="text" id="cc-distance" name="distance_to_factory_km" inputmode="decimal" value="{{ old('distance_to_factory_km') }}" />
                <div class="hint">Used for the transport tariff on trips from this center.</div></div>
              <div class="field"><label for="cc-fee">Transport fee (&#8358;)</label>
                <input type="text" id="cc-fee" name="transport_fee" inputmode="decimal" value="{{ old('transport_fee') }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create center</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
