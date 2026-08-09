@extends('layouts.app')
@section('title', 'Milk Flow')

@section('content')
  <div class="page-head">
    <div>
      <h1>Milk Flow</h1>
      <p>Farmer deliveries at collection points &middot; {{ \App\Support\Wat::longDate($date) }}</p>
    </div>
    <div class="page-actions">
      @can('milk.consignment.confirm.view')
        <a href="{{ route('consignments.index') }}" class="btn btn-outline">Consignments</a>
      @endcan
      @can('milk.batch.dispatch.view')
        <a href="{{ route('batches.index') }}" class="btn btn-outline">Batches</a>
      @endcan
      @if ($canRecord)
        <a href="#modal-record" class="btn btn-primary">+ Record Delivery</a>
      @endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Presented</div>
      <div class="stat-value">{{ \App\Support\Volume::format($totals->presented ?? 0) }}</div>
      <div class="stat-foot">{{ (int) ($totals->deliveries ?? 0) }} deliveries</div></div>
    <div class="stat red"><div class="stat-label">Rejected</div>
      <div class="stat-value">{{ \App\Support\Volume::format($totals->rejected ?? 0) }}</div>
      <div class="stat-foot">not paid for, not carried</div></div>
    <div class="stat green"><div class="stat-label">Accepted</div>
      <div class="stat-value">{{ \App\Support\Volume::format($totals->accepted ?? 0) }}</div>
      <div class="stat-foot">presented &minus; rejected</div></div>
    <div class="stat amber"><div class="stat-label">Awaiting dispatch</div>
      <div class="stat-value">{{ number_format($awaitingDispatch) }}</div>
      <div class="stat-foot">not yet on a consignment</div></div>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>Deliveries</h3><p>Every litre attributable to a named farmer</p></div>
    </div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="date">Date</label>
          <input type="date" id="date" name="date" value="{{ $date }}" /></div>
        <div class="field"><label for="point">Point</label>
          <select id="point" name="point">
            <option value="">All points in scope</option>
            @foreach ($points as $point)
              <option value="{{ $point->id }}" @selected(old('collection_point_id', request('point')) == $point->id)>{{ $point->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['accepted' => 'Accepted', 'partial' => 'Partial', 'rejected' => 'Rejected'] as $value => $label)
              <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Reference or farmer" /></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('deliveries.index') }}" class="btn btn-ghost btn-sm">Today</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Reference</th><th>Farmer</th><th>Point</th><th class="num">Presented</th>
            <th class="num">Rejected</th><th class="num">Accepted</th><th>Reason</th>
            <th>Status</th><th>Consignment</th><th>Time</th>
          </tr></thead>
          <tbody>
            @forelse ($deliveries as $delivery)
              <tr>
                <td><a href="{{ route('deliveries.show', $delivery) }}" class="perm-key">{{ $delivery->reference }}</a>
                  @if ($delivery->is_test)<div class="cell-sub"><span class="badge muted plain">test</span></div>@endif</td>
                <td>{{ $delivery->farmer?->name }}<div class="cell-sub">{{ $delivery->farmer?->code }}</div></td>
                <td>{{ $delivery->collectionPoint?->name }}</td>
                <td class="num">{{ \App\Support\Volume::format($delivery->litres_presented, false) }}</td>
                <td class="num">{{ \App\Support\Volume::format($delivery->litres_rejected, false) }}</td>
                <td class="num font-bold">{{ \App\Support\Volume::format($delivery->litres_accepted, false) }}</td>
                <td>{{ $delivery->rejectionReason?->name ?? '—' }}</td>
                <td>
                  <span class="badge {{ ['accepted' => 'success', 'partial' => 'warning', 'rejected' => 'danger'][$delivery->status] ?? 'muted' }}">
                    {{ ucfirst($delivery->status) }}
                  </span>
                  @if ($delivery->was_after_cutoff)
                    <div class="cell-sub text-danger">after cut-off {{ $delivery->cutoff_applied ? substr($delivery->cutoff_applied, 0, 5) : '' }}</div>
                  @endif
                </td>
                <td>{{ $delivery->consignment?->reference ?? '—' }}</td>
                <td>{{ \App\Support\Wat::time($delivery->delivered_at) }}</td>
              </tr>
            @empty
              <tr><td colspan="10">@include('partials.empty', [
                'title' => 'No deliveries for this filter',
                'message' => 'Your data scope is '.auth()->user()->overallScopeDescription().'.',
                'icon' => '&#127869;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $deliveries, 'noun' => 'deliveries'])
  </div>

  @if ($canRecord)
    <div id="modal-record" class="modal @if (old('_modal') === 'modal-record') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Record Farmer Delivery</h3><p>Litres presented, then any rejection with its reason</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('deliveries.store') }}">
          @csrf
          {{-- Names the modal so a failed submit can reopen it with old() intact. --}}
          <input type="hidden" name="_modal" value="modal-record" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-record'])
            <div class="form-grid">
              <div class="field"><label for="rd-point">Collection point <span class="req">*</span></label>
                <select id="rd-point" data-searchable data-combo-placeholder="Search collection points…" name="collection_point_id" required>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}" @selected(request('point') == $point->id)>
                      {{ $point->name }} (cut-off {{ $point->effectiveCutoff() }})
                    </option>
                  @endforeach
                </select></div>
              <div class="field"><label for="rd-farmer">Farmer <span class="req">*</span></label>
                <select id="rd-farmer" data-searchable data-combo-placeholder="Search farmers by name or code…" name="farmer_id" required>
                  @foreach ($farmers as $farmer)
                    <option value="{{ $farmer->id }}" @selected(old('farmer_id') == $farmer->id)>{{ $farmer->name }} — {{ $farmer->code }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="rd-at">Delivered at</label>
                <input type="datetime-local" id="rd-at" name="delivered_at"
                       value="{{ old('delivered_at', \App\Support\Wat::forInput()) }}" />
                <div class="hint">After the point&rsquo;s cut-off, reject the delivery in full or record a supervisor override below.</div></div>
              <div class="field"><label for="rd-containers">Containers</label>
                <input type="number" id="rd-containers" name="containers" min="0" value="{{ old('containers') }}" /></div>
              <div class="field"><label for="rd-presented">Litres presented <span class="req">*</span></label>
                <input type="text" id="rd-presented" name="litres_presented" inputmode="decimal" value="{{ old('litres_presented') }}" required /></div>
              <div class="field"><label for="rd-rejected">Litres rejected</label>
                <input type="text" id="rd-rejected" name="litres_rejected" inputmode="decimal" value="{{ old('litres_rejected', '0') }}" />
                <div class="hint">Accepted litres are worked out for you as presented &minus; rejected.</div></div>
              <div class="field full"><label for="rd-reason">Rejection reason</label>
                {{-- BR-1 — the configured list for the POINT stage only. --}}
                <select id="rd-reason" name="rejection_reason_id">
                  <option value="">No rejection</option>
                  @foreach ($pointReasons as $reason)
                    <option value="{{ $reason->id }}" @selected(old('rejection_reason_id') == $reason->id)>{{ $reason->name }}@if ($reason->help_text) — {{ $reason->help_text }}@endif</option>
                  @endforeach
                </select>
                <div class="hint">Choose from the configured reasons. Ask an administrator to add one if it is missing.</div></div>
              <div class="field full"><label for="rd-notes">Notes</label>
                <textarea id="rd-notes" name="notes" rows="2">{{ old('notes') }}</textarea></div>
              <div class="field full">
                <label class="check-label" for="rd-override">
                  <input type="checkbox" id="rd-override" name="cutoff_override" value="1" @checked(old('cutoff_override')) />
                  Supervisor override for a late delivery
                </label>
                <input type="text" name="cutoff_override_reason" value="{{ old('cutoff_override_reason') }}" placeholder="Why the override is justified — it is logged" />
                <div class="hint">An override without a written reason will not be accepted.</div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            {{--
              The morning-queue button. Only the clicked submit's name/value is
              posted, so this one flag tells the controller to come straight back
              here with the point still chosen.
            --}}
            <button type="submit" name="add_another" value="1" class="btn btn-outline">Save &amp; add another</button>
            <button type="submit" class="btn btn-primary">Record delivery</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
