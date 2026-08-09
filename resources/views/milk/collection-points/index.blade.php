@extends('layouts.app')
@section('title', 'Collection Points')

@section('content')
  <div class="page-head">
    <div>
      <h1>Collection Points</h1>
      <p>Where farmers deliver &mdash; {{ number_format($points->total()) }} within your scope</p>
    </div>
    <div class="page-actions">
      @can('milk.points.create')
        <a href="#modal-new-point" class="btn btn-primary">+ Add Collection Point</a>
      @endcan
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Register</h3><p>Cut-off times default to {{ $defaultCutoff }} and may not be set later than {{ $latestCutoff }}</p></div>
    </div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" /></div>
        <div class="field"><label for="center">Center</label>
          <select id="center" name="center">
            <option value="">All centers</option>
            @foreach ($centers as $center)
              <option value="{{ $center->id }}" @selected(request('center') == $center->id)>{{ $center->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['active' => 'Active', 'idle' => 'Idle', 'suspended' => 'Suspended'] as $value => $label)
              <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('collection-points.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Point</th><th>Community</th><th>Feeds</th><th>Agent</th>
              <th>Cut-off</th><th class="num">Today</th><th>Status</th><th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($points as $point)
              @php($today = $todayByPoint[$point->id] ?? null)
              <tr>
                <td>
                  <div class="font-bold">{{ $point->name }}</div>
                  <div class="cell-sub perm-key">{{ $point->code }}</div>
                </td>
                <td>{{ $point->community?->name }}<div class="cell-sub">{{ $point->lga?->name }}</div></td>
                <td>{{ $point->collectionCenter?->name }}</td>
                <td>{{ $point->agent?->name ?? '—' }}</td>
                {{-- BR-3 — the cut-off actually applied. --}}
                <td>{{ $point->effectiveCutoff() }}</td>
                <td class="num">
                  {{ $today ? \App\Support\Volume::format($today->litres) : '—' }}
                  @if ($today)<div class="cell-sub">{{ $today->deliveries }} deliveries</div>@endif
                </td>
                <td>
                  <span class="badge {{ ['active' => 'success', 'idle' => 'warning', 'suspended' => 'danger'][$point->status] ?? 'muted' }}">
                    {{ ucfirst($point->status) }}
                  </span>
                </td>
                <td class="actions">
                  <a href="{{ route('collection-points.show', $point) }}" class="btn btn-ghost btn-sm">Open</a>
                  @if ($canEdit)
                    <a href="#modal-edit-point-{{ $point->id }}" class="btn btn-ghost btn-sm">Edit</a>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="8">
                @include('partials.empty', [
                  'title' => 'No collection points in your scope',
                  'message' => 'Your data scope is '.auth()->user()->overallScopeDescription().'.',
                  'icon' => '&#9962;',
                ])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $points, 'noun' => 'points'])
  </div>

  @can('milk.points.create')
    <div id="modal-new-point" class="modal @if (old('_modal') === 'modal-new-point') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add Collection Point</h3><p>Points feed exactly one center</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('collection-points.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-point" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-point'])
            <div class="form-grid">
              <div class="field"><label for="np-code">Code <span class="req">*</span></label>
                <input type="text" id="np-code" name="code" value="{{ old('code') }}" required /></div>
              <div class="field"><label for="np-name">Name <span class="req">*</span></label>
                <input type="text" id="np-name" name="name" value="{{ old('name') }}" required /></div>
              <div class="field"><label for="np-community">Community <span class="req">*</span></label>
                <select id="np-community" name="community_id" data-searchable data-combo-placeholder="Search communities…">
                  <option value="">&mdash;</option>
                  @foreach ($communities as $community)
                    <option value="{{ $community->id }}" @selected(old('community_id') == $community->id)>{{ $community->name }} ({{ $community->lga?->name }})</option>
                  @endforeach
                </select>
                {{--
                  A native <details> rather than a second modal: opening another
                  :target modal would close this one and lose everything typed.
                  Collapsed by default, so the form is no busier than before, and
                  it works with no JavaScript at all.
                --}}
                <details class="mt-8" @if (old('new_community_name')) open @endif>
                  <summary class="text-small text-primary">Community not listed? Add it here</summary>
                  <div class="mt-8">
                    <div class="field"><label for="np-new-community">New community name</label>
                      <input type="text" id="np-new-community" name="new_community_name" value="{{ old('new_community_name') }}" /></div>
                    <div class="field"><label for="np-new-community-lga">Its LGA</label>
                      <select id="np-new-community-lga" name="new_community_lga_id" data-searchable data-combo-placeholder="Search LGAs…">
                        <option value="">&mdash;</option>
                        @foreach ($lgas as $lga)
                          <option value="{{ $lga->id }}" @selected(old('new_community_lga_id') == $lga->id)>{{ $lga->name }}</option>
                        @endforeach
                      </select></div>
                    <div class="hint">Fill these in and the picker above can be left empty.</div>
                  </div>
                </details></div>

              <div class="field"><label for="np-center">Feeds center <span class="req">*</span></label>
                <select id="np-center" name="collection_center_id" data-searchable data-combo-placeholder="Search centers…">
                  <option value="">&mdash;</option>
                  @foreach ($centers as $center)
                    <option value="{{ $center->id }}" @selected(old('collection_center_id') == $center->id)>{{ $center->name }}</option>
                  @endforeach
                </select>
                @can('milk.points.create')
                  <details class="mt-8" @if (old('new_center_name')) open @endif>
                    <summary class="text-small text-primary">Center not listed? Add it here</summary>
                    <div class="mt-8">
                      <div class="field"><label for="np-new-center-code">New center code</label>
                        <input type="text" id="np-new-center-code" name="new_center_code" value="{{ old('new_center_code') }}" /></div>
                      <div class="field"><label for="np-new-center">New center name</label>
                        <input type="text" id="np-new-center" name="new_center_name" value="{{ old('new_center_name') }}" /></div>
                      <div class="field"><label for="np-new-center-lga">Its LGA</label>
                        <select id="np-new-center-lga" name="new_center_lga_id" data-searchable data-combo-placeholder="Search LGAs…">
                          <option value="">&mdash;</option>
                          @foreach ($lgas as $lga)
                            <option value="{{ $lga->id }}" @selected(old('new_center_lga_id') == $lga->id)>{{ $lga->name }}</option>
                          @endforeach
                        </select></div>
                      <div class="hint">The center is created active. Set its officer and distance afterwards.</div>
                    </div>
                  </details>
                @endcan</div>
              <div class="field"><label for="np-agent">Collection agent</label>
                <select id="np-agent" name="agent_user_id" data-searchable data-combo-placeholder="Search agents…">
                  <option value="">Unassigned</option>
                  @foreach ($agents as $agent)
                    <option value="{{ $agent->id }}" @selected(old('agent_user_id') == $agent->id)>{{ $agent->name }} &mdash; {{ $agent->email }}</option>
                  @endforeach
                </select>
                <div class="hint">Only staff who can record a delivery are listed. Can be assigned later.</div></div>
              <div class="field"><label for="np-cutoff">Cut-off override</label>
                <input type="time" id="np-cutoff" name="cutoff_time" value="{{ old('cutoff_time') }}" max="{{ $latestCutoff }}" />
                <div class="hint">Leave blank to use the {{ $defaultCutoff }} default. Never later than {{ $latestCutoff }}.</div></div>
              <div class="field"><label for="np-fee">Transport fee (₦)</label>
                <input type="text" id="np-fee" name="transport_fee" value="{{ old('transport_fee') }}" inputmode="decimal" /></div>
              <div class="field"><label for="np-status">Status <span class="req">*</span></label>
                <select id="np-status" name="status" required>
                  <option value="active">Active</option><option value="idle">Idle</option><option value="suspended">Suspended</option>
                </select></div>
              <div class="field"><label for="np-opened">Opened on</label>
                <input type="date" id="np-opened" name="opened_on" value="{{ \App\Support\Wat::today()->toDateString() }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create point</button>
          </div>
        </form>
      </div>
    </div>
  @endcan

  {{--
    The update route has existed from the start with no form posting to it, so a
    point's agent, cut-off, fee and status were fixed at creation. Assigning an
    agent to an existing point was impossible.
  --}}
  @if ($canEdit)
    @foreach ($points as $point)
      <div id="modal-edit-point-{{ $point->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Edit {{ $point->name }}</h3>
              <p>{{ $point->community?->name }} &middot; feeds {{ $point->collectionCenter?->name ?? 'no center' }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('collection-points.update', $point) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-edit-point-{{ $point->id }}" /> @method('PUT')
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-point-'.$point->id.''])
              <div class="form-grid">
                <div class="field"><label for="ep-name-{{ $point->id }}">Name <span class="req">*</span></label>
                  <input type="text" id="ep-name-{{ $point->id }}" name="name" value="{{ $point->name }}" required /></div>
                <div class="field"><label for="ep-agent-{{ $point->id }}">Collection agent</label>
                  <select id="ep-agent-{{ $point->id }}" name="agent_user_id"
                          data-searchable data-combo-placeholder="Search agents…">
                    <option value="">Unassigned</option>
                    @foreach ($agents as $agent)
                      <option value="{{ $agent->id }}" @selected($point->agent_user_id == $agent->id)>{{ $agent->name }} &mdash; {{ $agent->email }}</option>
                    @endforeach
                  </select>
                  <div class="hint">Only staff who can record a delivery are listed.</div></div>
                <div class="field"><label for="ep-cutoff-{{ $point->id }}">Cut-off override</label>
                  <input type="time" id="ep-cutoff-{{ $point->id }}" name="cutoff_time"
                         value="{{ $point->cutoff_time }}" max="{{ $latestCutoff }}" />
                  <div class="hint">Blank uses the {{ $defaultCutoff }} default.</div></div>
                <div class="field"><label for="ep-fee-{{ $point->id }}">Transport fee (&#8358;)</label>
                  <input type="text" id="ep-fee-{{ $point->id }}" name="transport_fee" inputmode="decimal"
                         {{-- ARCH-6 — kobo out through Money, never a float divide by a literal 100. --}}
                         value="{{ $point->transport_fee_minor ? \App\Support\Money::decimal($point->transport_fee_minor) : '' }}" /></div>
                <div class="field"><label for="ep-status-{{ $point->id }}">Status <span class="req">*</span></label>
                  <select id="ep-status-{{ $point->id }}" name="status" required>
                    @foreach (['active' => 'Active', 'idle' => 'Idle', 'suspended' => 'Suspended'] as $value => $label)
                      <option value="{{ $value }}" @selected($point->status === $value)>{{ $label }}</option>
                    @endforeach
                  </select></div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif
@endsection
