@extends('layouts.app')
@section('title', $point->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('collection-points.index') }}">Collection Points</a><span class="sep">/</span>
    <span class="here">{{ $point->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($point->code, -2) }}</div>
    <div class="dh-main">
      <h1>{{ $point->name }}</h1>
      <div class="dh-sub">{{ $point->community?->name }} &middot; {{ $point->lga?->name }} &middot; feeds {{ $point->collectionCenter?->name }}</div>
      <div class="dh-tags">
        <span class="badge {{ $point->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($point->status) }}</span>
        <span class="pill">Cut-off {{ $cutoff }}</span>
        <span class="pill">{{ number_format($farmerCount) }} farmers</span>
      </div>
    </div>
    <div class="dh-actions">
      {{--
        Both of the point's own jobs happen here now. "Record intake" used to
        navigate to the deliveries screen with `?point=`, where the first thing
        the agent did was re-choose the point they had just come from.
      --}}
      @if ($canRecordDelivery)
        <a href="#modal-record-here" class="btn btn-primary">Record intake</a>
      @endif
      @if ($canDispatch)
        <a href="#modal-dispatch-here" class="btn btn-outline">
          Dispatch to {{ $point->collectionCenter?->name ?? 'centre' }}
          @if ($awaitingDispatch->isNotEmpty())
            <span class="badge warning">{{ $awaitingDispatch->count() }}</span>
          @endif
        </a>
      @endif
      @can('milk.points.edit')
        <a href="#modal-edit-point" class="btn btn-outline">Edit</a>
      @endcan
    </div>
  </div>

  @if ($openFollowups->isNotEmpty())
    {{-- BR-5 — automatic follow-ups against this point. --}}
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>{{ $openFollowups->count() }} open quality follow-up(s).</strong>
        @foreach ($openFollowups as $followup)
          {{ $followup->rejectionReason?->name }} &mdash; {{ $followup->trigger_count }} in {{ $followup->window_days }} days.
        @endforeach
        Closing one requires a logged field activity.
      </div>
    </div>
  @endif

  <div class="grid grid-4 mb-16">
    <div class="stat green"><div class="stat-label">Accepted today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($todayTotals->accepted ?? 0) }}</div>
      <div class="stat-foot">{{ (int) ($todayTotals->deliveries ?? 0) }} deliveries</div></div>
    <div class="stat blue"><div class="stat-label">Presented today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($todayTotals->presented ?? 0) }}</div>
      <div class="stat-foot">before rejection</div></div>
    <div class="stat red"><div class="stat-label">Rejected today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($todayTotals->rejected ?? 0) }}</div>
      <div class="stat-foot">not paid for</div></div>
    <div class="stat amber"><div class="stat-label">Awaiting dispatch</div>
      <div class="stat-value">{{ $point->deliveries()->awaitingDispatch()->count() }}</div>
      <div class="stat-foot">deliveries not yet on a consignment</div></div>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Deliveries</h3><p>Most recent first</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Reference</th><th>Farmer</th><th class="num">Presented</th><th class="num">Rejected</th>
                <th class="num">Accepted</th><th>Reason</th><th>Consignment</th><th>Recorded</th>
              </tr></thead>
              <tbody>
                @forelse ($deliveries as $delivery)
                  <tr>
                    <td><a href="{{ route('deliveries.show', $delivery) }}" class="perm-key">{{ $delivery->reference }}</a>
                      @if ($delivery->was_after_cutoff)<div class="cell-sub text-danger">after cut-off</div>@endif</td>
                    <td>{{ $delivery->farmer?->name }}<div class="cell-sub">{{ $delivery->farmer?->code }}</div></td>
                    <td class="num">{{ \App\Support\Volume::format($delivery->litres_presented, false) }}</td>
                    <td class="num">{{ \App\Support\Volume::format($delivery->litres_rejected, false) }}</td>
                    <td class="num font-bold">{{ \App\Support\Volume::format($delivery->litres_accepted, false) }}</td>
                    <td>{{ $delivery->rejectionReason?->name ?? '—' }}</td>
                    <td>{{ $delivery->consignment?->reference ?? '—' }}</td>
                    <td>{{ \App\Support\Wat::relative($delivery->delivered_at) }}
                      <div class="cell-sub">{{ $delivery->recordedBy?->name }}</div></td>
                  </tr>
                @empty
                  <tr><td colspan="8">@include('partials.empty', ['title' => 'No deliveries recorded here yet', 'icon' => '&#127869;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @include('partials.pagination', ['paginator' => $deliveries, 'noun' => 'deliveries'])
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Point Detail</h3><p>Register record</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Code</div><div class="meta-value mono">{{ $point->code }}</div></div>
            <div class="meta-item"><div class="meta-label">Agent</div><div class="meta-value">{{ $point->agent?->name ?? 'Unassigned' }}</div></div>
            <div class="meta-item"><div class="meta-label">Cut-off applied</div><div class="meta-value">{{ $cutoff }}</div></div>
            <div class="meta-item"><div class="meta-label">Opened</div><div class="meta-value">{{ \App\Support\Wat::date($point->opened_on) }}</div></div>
            @can('logistics.payments.view')
              <div class="meta-item"><div class="meta-label">Transport fee</div>
                <div class="meta-value">{{ \App\Support\Money::format($point->transport_fee_minor) }}</div></div>
            @endcan
            <div class="meta-item"><div class="meta-label">Farmers</div><div class="meta-value big">{{ number_format($farmerCount) }}</div></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Farmers</h3><p>Whose milk is brought here</p></div>
          <a href="{{ route('collection-points.farmers', $point) }}" class="btn btn-outline btn-sm">
            View all {{ number_format($farmerCount) }}
          </a>
        </div>
        <div class="card-body">
          <div class="meta-grid cols-2 mb-16">
            <div class="meta-item"><div class="meta-label">Registered here</div>
              <div class="meta-value">{{ number_format($farmerCount) }}</div></div>
            <div class="meta-item"><div class="meta-label">Delivered today</div>
              <div class="meta-value">{{ number_format($farmersDeliveredToday) }}</div></div>
          </div>
          @if ($farmerPreview->isNotEmpty())
            <div class="chip-group">
              @foreach ($farmerPreview as $farmer)
                <span class="chip">{{ $farmer->name }}</span>
              @endforeach
              @if ($farmerCount > $farmerPreview->count())
                <span class="chip muted">+{{ number_format($farmerCount - $farmerPreview->count()) }} more</span>
              @endif
            </div>
          @else
            <div class="text-muted text-small">No farmers are registered at this point yet.</div>
          @endif
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Recent Consignments</h3><p>Dispatched to {{ $point->collectionCenter?->name }}</p></div>
          {{--
            Where the milk goes next. The point screen knew its centre's name
            and gave no way to reach it, so following one consignment meant
            going back to the register and finding the centre by hand.
          --}}
          @if ($point->collectionCenter)
            <a href="{{ route('collection-centers.show', $point->collectionCenter) }}" class="btn btn-outline btn-sm">
              Centre activity &rarr;
            </a>
          @endif
        </div>
        <div class="card-body">
          @if ($awaitingDispatch->isNotEmpty())
            {{-- The thing an agent most needs to see on this card: milk that
                 has been recorded and has not left. --}}
            <div class="alert warn mb-16">
              <strong>{{ $awaitingDispatch->count() }} delivery(ies) awaiting dispatch</strong> &mdash;
              {{ \App\Support\Volume::format($awaitingDispatch->sum('litres_accepted')) }} accepted, still at this point.
            </div>
          @endif
          @forelse ($consignments as $consignment)
            <div class="queue-item">
              <div class="qi-ic">&#128666;</div>
              <div>
                <div class="qi-title perm-key">{{ $consignment->reference }}</div>
                <div class="qi-sub">{{ \App\Support\Volume::format($consignment->litres_dispatched) }} &middot;
                  {{ \App\Support\Wat::relative($consignment->dispatched_at) }}</div>
              </div>
              <div class="qi-right">
                <span class="badge {{ $consignment->status === 'awaiting_confirmation' ? 'warning' : 'success' }}">
                  {{ \Illuminate\Support\Str::headline($consignment->status) }}
                </span>
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'Nothing dispatched yet', 'icon' => '&#128666;'])
          @endforelse
        </div>
      </div>
    </div>
  </div>

  @if ($canRecordDelivery)
    {{--
      The same form the deliveries screen carries, minus the point picker —
      there is only one point here and it is the one in the URL. It posts to the
      same route, so DeliveryService applies BR-1, BR-3 and BR-6 identically;
      nothing about the rules is re-implemented on this screen.
    --}}
    <div id="modal-record-here" class="modal @if (old('_modal') === 'modal-record-here') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Record intake at {{ $point->name }}</h3>
            <p>Litres presented, then any rejection with its reason &middot; cut-off {{ $cutoff }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('deliveries.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-record-here" />
          {{-- The point is the page, not a choice. --}}
          <input type="hidden" name="collection_point_id" value="{{ $point->id }}" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-record-here'])
            <div class="form-grid">
              <div class="field full"><label for="rh-farmer">Farmer <span class="req">*</span></label>
                <select id="rh-farmer" name="farmer_id" required data-searchable
                        data-combo-placeholder="Search this point&rsquo;s farmers…">
                  <option value="">—</option>
                  @forelse ($pointFarmers as $farmer)
                    <option value="{{ $farmer->id }}" @selected(old('farmer_id') == $farmer->id)>
                      {{ $farmer->name }} — {{ $farmer->code }}</option>
                  @empty
                    <option value="" disabled>No farmers assigned to this point yet</option>
                  @endforelse
                </select>
                <div class="hint">Only farmers assigned here. Use
                  <a href="{{ route('collection-points.farmers', $point) }}">Farmers</a> to add one.</div></div>

              <div class="field"><label for="rh-presented">Litres presented <span class="req">*</span></label>
                <input type="text" id="rh-presented" name="litres_presented" inputmode="decimal"
                       value="{{ old('litres_presented') }}" required /></div>
              <div class="field"><label for="rh-rejected">Litres rejected</label>
                <input type="text" id="rh-rejected" name="litres_rejected" inputmode="decimal"
                       value="{{ old('litres_rejected', '0') }}" />
                <div class="hint">Accepted is worked out for you as presented &minus; rejected.</div></div>

              <div class="field full"><label for="rh-reason">Rejection reason</label>
                {{-- BR-1 — the configured list for the POINT stage only. --}}
                <select id="rh-reason" name="rejection_reason_id">
                  <option value="">No rejection</option>
                  @foreach ($pointReasons as $reason)
                    <option value="{{ $reason->id }}" @selected(old('rejection_reason_id') == $reason->id)>
                      {{ $reason->name }}@if ($reason->help_text) — {{ $reason->help_text }}@endif</option>
                  @endforeach
                </select></div>

              <div class="field"><label for="rh-at">Delivered at</label>
                <input type="datetime-local" id="rh-at" name="delivered_at"
                       value="{{ old('delivered_at', \App\Support\Wat::forInput()) }}" />
                <div class="hint">After {{ $cutoff }}, reject in full for late delivery.</div></div>
              <div class="field"><label for="rh-containers">Containers</label>
                <input type="number" id="rh-containers" name="containers" min="0" value="{{ old('containers') }}" /></div>

              <div class="field full"><label for="rh-notes">Notes</label>
                <textarea id="rh-notes" name="notes" rows="2">{{ old('notes') }}</textarea></div>

              @if ($canOverrideCutoff)
                {{--
                  BR-3 — shown only to somebody who actually holds
                  milk.deliveries.cutoff_override. Rendering it for an agent who
                  does not would be offering them a control that refuses them.
                --}}
                <div class="field full">
                  <label class="check-label" for="rh-override">
                    <input type="checkbox" id="rh-override" name="cutoff_override" value="1"
                           @checked(old('cutoff_override')) />
                    Supervisor override for a late delivery
                  </label>
                  <input type="text" name="cutoff_override_reason" value="{{ old('cutoff_override_reason') }}"
                         placeholder="Why the override is justified — it is logged" />
                  <div class="hint">An override without a written reason will not be accepted.</div>
                </div>
              @endif
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            {{-- The morning queue is several farmers back to back. --}}
            <button type="submit" name="add_another" value="1" class="btn btn-outline">Save &amp; add another</button>
            <button type="submit" class="btn btn-primary">Record delivery</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canDispatch)
    {{-- Push this point's accepted milk to the centre. --}}
    <div id="modal-dispatch-here" class="modal @if (old('_modal') === 'modal-dispatch-here') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Dispatch to {{ $point->collectionCenter?->name }}</h3>
            <p>Tick what is going. The consignment volume is their accepted litres.</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('consignments.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-dispatch-here" />
          <input type="hidden" name="collection_point_id" value="{{ $point->id }}" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-dispatch-here'])

            @if ($awaitingDispatch->isEmpty())
              @include('partials.empty', [
                'title' => 'Nothing awaiting dispatch',
                'message' => 'Every delivery recorded here is already on a consignment.',
                'icon' => '&#128666;',
              ])
            @else
              <div class="field mb-16">
                <label>Deliveries awaiting dispatch</label>
                <div class="stack" style="gap:8px;margin-top:6px">
                  @foreach ($awaitingDispatch as $delivery)
                    <label class="check-label">
                      {{-- Ticked by default: the ordinary act is "send this
                           morning's milk", and making the agent tick forty boxes
                           to do the normal thing invites them to miss one. --}}
                      <input type="checkbox" name="delivery_ids[]" value="{{ $delivery->id }}" checked />
                      <span class="perm-key">{{ $delivery->reference }}</span>
                      &middot; {{ $delivery->farmer?->name }}
                      &middot; {{ \App\Support\Volume::format($delivery->litres_accepted) }} accepted
                    </label>
                  @endforeach
                </div>
                <div class="hint">
                  {{ \App\Support\Volume::format($awaitingDispatch->sum('litres_accepted')) }} accepted in total.
                  A delivery can only ever be on one consignment.
                </div>
              </div>

              <div class="form-grid">
                <div class="field"><label for="dh-containers">Containers</label>
                  <input type="number" id="dh-containers" name="containers" min="0" value="{{ old('containers') }}" /></div>
                <div class="field"><label for="dh-at">Dispatched at</label>
                  <input type="datetime-local" id="dh-at" name="dispatched_at"
                         value="{{ old('dispatched_at', \App\Support\Wat::forInput()) }}" /></div>
              </div>
            @endif
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            @if ($awaitingDispatch->isNotEmpty())
              <button type="submit" class="btn btn-primary">Dispatch to centre</button>
            @endif
          </div>
        </form>
      </div>
    </div>
  @endif

  @can('milk.points.edit')
    <div id="modal-edit-point" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Edit Point</h3><p>{{ $point->code }}</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('collection-points.update', $point) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-point" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-point'])
            <div class="field mb-16"><label for="ep-name">Name <span class="req">*</span></label>
              <input type="text" id="ep-name" name="name" value="{{ $point->name }}" required /></div>
            <div class="field mb-16"><label for="ep-agent">Collection agent</label>
              <select id="ep-agent" name="agent_user_id" data-searchable data-combo-placeholder="Search agents…">
                <option value="">Unassigned</option>
                @foreach ($agents as $candidate)
                  <option value="{{ $candidate->id }}" @selected($point->agent_user_id == $candidate->id)>{{ $candidate->name }} &mdash; {{ $candidate->email }}</option>
                @endforeach
              </select>
              <div class="hint">Only staff who can record a delivery are listed.</div></div>
            <div class="field mb-16"><label for="ep-cutoff">Cut-off override</label>
              <input type="time" id="ep-cutoff" name="cutoff_time" value="{{ $point->cutoff_time ? substr($point->cutoff_time, 0, 5) : '' }}" />
              <div class="hint">Leave blank to use the default from Settings. It cannot be set later than the limit in Settings.</div></div>
            <div class="field mb-16"><label for="ep-fee">Transport fee (₦)</label>
              <input type="text" id="ep-fee" name="transport_fee" value="{{ \App\Support\Money::decimal($point->transport_fee_minor) === '—' ? '' : \App\Support\Money::decimal($point->transport_fee_minor) }}" /></div>
            <div class="field"><label for="ep-status">Status <span class="req">*</span></label>
              <select id="ep-status" name="status" required>
                @foreach (['active', 'idle', 'suspended'] as $status)
                  <option value="{{ $status }}" @selected($point->status === $status)>{{ ucfirst($status) }}</option>
                @endforeach
              </select></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save point</button>
          </div>
        </form>
      </div>
    </div>
  @endcan

@endsection
