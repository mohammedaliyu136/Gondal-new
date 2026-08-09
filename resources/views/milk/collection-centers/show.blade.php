@extends('layouts.app')
@section('title', $center->name.' Center')

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('collection-centers.index') }}">Collection Centers</a><span class="sep">/</span>
    <span class="here">{{ $center->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($center->code, -2) }}</div>
    <div class="dh-main">
      <h1>{{ $center->name }} Center</h1>
      <div class="dh-sub">
        {{ $center->lga?->name }} &middot; {{ $center->collectionPoints->count() }} points feed in &middot;
        {{ $center->distance_to_factory_km ? $center->distance_to_factory_km.' km to factory' : 'distance not set' }}
      </div>
      <div class="dh-tags">
        <span class="badge {{ $center->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($center->status) }}</span>
        <span class="pill">Officer: {{ $center->officer?->name ?? 'unassigned' }}</span>
      </div>
    </div>
    <div class="dh-actions">
      @if ($canEdit)
        <a href="#modal-edit-center" class="btn btn-outline">Edit center</a>
      @endif
      @if ($batchable->isNotEmpty())
        @can('milk.batch.dispatch.create')
          <a href="#modal-batch" class="btn btn-primary">Dispatch batch ({{ $batchable->count() }})</a>
        @endcan
      @endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat green"><div class="stat-label">Confirmed today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($litresConfirmedToday) }}</div>
      <div class="stat-foot">{{ $confirmedToday->count() }} consignments</div></div>
    @if ($canSeeQueue)
      <div class="stat amber"><div class="stat-label">Awaiting confirmation</div>
        <div class="stat-value">{{ $awaiting->count() }}</div>
        <div class="stat-foot">{{ \App\Support\Volume::format(\App\Support\Volume::sum($awaiting->pluck('litres_dispatched')->all())) }} dispatched</div></div>
    @else
      <div class="stat blue"><div class="stat-label">Collection points</div>
        <div class="stat-value">{{ $center->collectionPoints->count() }}</div>
        <div class="stat-foot">feeding this center</div></div>
    @endif
    <div class="stat blue"><div class="stat-label">Adjustments today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($litresAdjustedToday) }}</div>
      <div class="stat-foot">each one carries a recorded reason</div></div>
    <div class="stat red"><div class="stat-label">Rejected here today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($litresRejectedToday) }}</div>
      <div class="stat-foot">excluded from payment</div></div>
  </div>

  <div class="split">
    <div class="stack">
      @if ($canSeeQueue)
      <div class="card">
        <div class="card-head">
          <div><h3>Awaiting Confirmation</h3><p>Confirm the litre count each agent reported</p></div>
          @unless ($canConfirm)
            <span class="badge muted">read only</span>
          @endunless
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Consignment</th><th>Point</th><th class="num">Dispatched</th><th class="num">Deliveries</th>
                <th>Dispatched at</th><th class="actions">Actions</th>
              </tr></thead>
              <tbody>
                @forelse ($awaiting as $consignment)
                  <tr>
                    <td class="perm-key">{{ $consignment->reference }}</td>
                    <td>{{ $consignment->collectionPoint?->name }}</td>
                    <td class="num font-bold">{{ \App\Support\Volume::format($consignment->litres_dispatched) }}</td>
                    <td class="num">{{ $consignment->deliveries->count() }}</td>
                    <td>{{ \App\Support\Wat::relative($consignment->dispatched_at) }}</td>
                    <td class="actions">
                      @if ($canConfirm)
                        <a href="#modal-confirm-{{ $consignment->id }}" class="btn btn-primary btn-sm">Confirm</a>
                      @endif
                      @if ($canAdjust ?? false)
                        <a href="#modal-adjust-{{ $consignment->id }}" class="btn btn-ghost btn-sm">Adjust</a>
                      @endif
                      @unless ($canConfirm || ($canAdjust ?? false))
                        <span class="text-muted text-small">&mdash;</span>
                      @endunless
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="6">@include('partials.empty', [
                    'title' => 'Nothing awaiting confirmation',
                    'message' => 'Every consignment dispatched to this center has been confirmed.',
                    'icon' => '&#9989;',
                  ])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
      @endif

      <div class="card">
        <div class="card-head"><div><h3>Confirmed Today</h3><p>Graded and ready to batch</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Consignment</th><th>Point</th><th class="num">Confirmed</th><th class="num">Rejected</th>
                <th>Grade</th><th class="num">Rate paid</th><th>Batch</th>
              </tr></thead>
              <tbody>
                @forelse ($confirmedToday as $consignment)
                  <tr>
                    <td class="perm-key">{{ $consignment->reference }}</td>
                    <td>{{ $consignment->collectionPoint?->name }}</td>
                    <td class="num font-bold">{{ \App\Support\Volume::format($consignment->litres_confirmed) }}</td>
                    <td class="num">{{ \App\Support\Volume::format($consignment->litres_rejected_at_center, false) }}
                      @if ($consignment->rejectionReason)<div class="cell-sub">{{ $consignment->rejectionReason->name }}</div>@endif</td>
                    <td>
                      @if ($consignment->grade)
                        <span class="badge {{ $consignment->grade->is_rejection ? 'danger' : 'success' }}">{{ $consignment->grade->name }}</span>
                      @elseif ($canGrade && $consignment->batch_id === null)
                        <a href="#modal-grade-{{ $consignment->id }}" class="btn btn-outline btn-sm">Assign grade</a>
                      @else
                        <span class="badge muted">Ungraded</span>
                      @endif
                    </td>
                    {{-- BR-14 — the snapshot, not a live join. --}}
                    <td class="num">{{ \App\Support\Money::format($consignment->rate_per_litre_minor) }}</td>
                    <td>{{ $consignment->batch_id ? ($consignment->batch?->reference ?? 'batched') : '—' }}</td>
                  </tr>
                @empty
                  <tr><td colspan="7">@include('partials.empty', ['title' => 'Nothing confirmed yet today', 'icon' => '&#127869;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head">
          <div><h3>Center Detail</h3></div>
          @if ($canEdit)
            <a href="#modal-edit-center" class="btn btn-ghost btn-sm">Edit</a>
          @endif
        </div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Code</div><div class="meta-value mono">{{ $center->code }}</div></div>
            <div class="meta-item"><div class="meta-label">LGA</div><div class="meta-value">{{ $center->lga?->name }}</div></div>
            <div class="meta-item"><div class="meta-label">Cold storage</div>
              <div class="meta-value">{{ $center->cold_storage_litres ? \App\Support\Volume::format($center->cold_storage_litres) : '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">To factory</div>
              <div class="meta-value">{{ $center->distance_to_factory_km ? $center->distance_to_factory_km.' km' : '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Collection officer</div>
              <div class="meta-value">{{ $center->officer?->name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Logistics officer</div>
              <div class="meta-value">{{ $center->logisticsOfficer?->name ?? '—' }}</div></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Points Feeding In</h3><p>{{ $center->collectionPoints->count() }} points</p></div></div>
        <div class="card-body">
          @foreach ($center->collectionPoints as $point)
            <div class="queue-item">
              <div class="qi-ic">&#9962;</div>
              <div>
                <div class="qi-title"><a href="{{ route('collection-points.show', $point) }}">{{ $point->name }}</a></div>
                <div class="qi-sub">{{ $point->code }} &middot; cut-off {{ $point->effectiveCutoff() }}</div>
              </div>
              <div class="qi-right">
                <span class="badge {{ $point->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($point->status) }}</span>
              </div>
            </div>
          @endforeach
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Ready to Batch</h3>
          <p>Confirmed and graded consignments only</p></div></div>
        <div class="card-body">
          @forelse ($batchable as $consignment)
            <div class="queue-item">
              <div class="qi-ic">&#128230;</div>
              <div>
                <div class="qi-title perm-key">{{ $consignment->reference }}</div>
                <div class="qi-sub">{{ $consignment->collectionPoint?->name }} &middot;
                  {{ \App\Support\Volume::format($consignment->litres_confirmed) }}</div>
              </div>
              <div class="qi-right"><span class="badge success">Ready</span></div>
            </div>
          @empty
            @include('partials.empty', [
              'title' => 'Nothing ready to batch',
              'message' => 'A consignment has to be confirmed and graded before it can join a batch.',
              'icon' => '&#128230;',
            ])
          @endforelse
        </div>
      </div>
    </div>
  </div>

  {{--
    The register was create-only: `collection-centers.update` was routed behind
    milk.points.edit and no screen posted to it. Reassigning the officer when
    someone leaves, correcting the transport fee or suspending a centre all meant
    a direct database write, which REF-1 never sees — and `scope_type = center`
    resolves through officer_user_id, so a stale assignment is an access problem
    as much as a data one. Mirrors the collection-point edit modal.
  --}}
  @if ($canEdit)
    <div id="modal-edit-center" class="modal @if (old('_modal') === 'modal-edit-center') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Edit Center</h3><p>{{ $center->code }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('collection-centers.update', $center) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-center" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-center'])
            <div class="form-grid">
              <div class="field"><label for="ec-code">Code <span class="req">*</span></label>
                <input type="text" id="ec-code" name="code" value="{{ old('code', $center->code) }}" required />
                <div class="hint">Appears on every consignment and batch this center handles.</div></div>
              <div class="field"><label for="ec-name">Name <span class="req">*</span></label>
                <input type="text" id="ec-name" name="name" value="{{ old('name', $center->name) }}" required /></div>
              <div class="field"><label for="ec-lga">LGA <span class="req">*</span></label>
                <select id="ec-lga" name="lga_id" data-searchable data-combo-placeholder="Search LGAs…" required>
                  @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}" @selected(old('lga_id', $center->lga_id) == $lga->id)>{{ $lga->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ec-status">Status</label>
                <select id="ec-status" name="status">
                  @foreach (['active' => 'Active', 'suspended' => 'Suspended'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $center->status) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
                <div class="hint">Suspending a center does not move the points feeding it.</div></div>
              <div class="field"><label for="ec-officer">Collection officer</label>
                <select id="ec-officer" name="officer_user_id" data-searchable data-combo-placeholder="Search staff…">
                  <option value="">&mdash;</option>
                  @foreach ($staff as $person)
                    <option value="{{ $person->id }}" @selected(old('officer_user_id', $center->officer_user_id) == $person->id)>{{ $person->name }} &mdash; {{ $person->email }}</option>
                  @endforeach
                </select>
                <div class="hint">Confirms consignments arriving here, and is who <em>center</em> scope resolves to.</div></div>
              <div class="field"><label for="ec-logistics">Logistics officer</label>
                <select id="ec-logistics" name="logistics_user_id" data-searchable data-combo-placeholder="Search staff…">
                  <option value="">&mdash;</option>
                  @foreach ($staff as $person)
                    <option value="{{ $person->id }}" @selected(old('logistics_user_id', $center->logistics_user_id) == $person->id)>{{ $person->name }} &mdash; {{ $person->email }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ec-cold">Cold storage (L)</label>
                <input type="text" id="ec-cold" name="cold_storage_litres" inputmode="decimal"
                       value="{{ old('cold_storage_litres', $center->cold_storage_litres) }}" /></div>
              <div class="field"><label for="ec-distance">Distance to factory (km)</label>
                <input type="text" id="ec-distance" name="distance_to_factory_km" inputmode="decimal"
                       value="{{ old('distance_to_factory_km', $center->distance_to_factory_km) }}" /></div>
              <div class="field"><label for="ec-fee">Transport fee (&#8358;)</label>
                {{-- ARCH-6 — kobo out through Money, naira back in through Money::fromMajor. --}}
                <input type="text" id="ec-fee" name="transport_fee" inputmode="decimal"
                       value="{{ old('transport_fee', $center->transport_fee_minor ? \App\Support\Money::decimal($center->transport_fee_minor) : '') }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save center</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canAdjust ?? false)
    @foreach ($awaiting as $consignment)
      <div id="modal-adjust-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Adjust {{ $consignment->reference }}</h3>
              <p>{{ \App\Support\Volume::format($consignment->litres_dispatched) }} dispatched from {{ $consignment->collectionPoint?->name }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.adjust', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-adjust-{{ $consignment->id }}" />
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-adjust-'.$consignment->id.''])
              <div class="form-grid">
                <div class="field">
                  <label for="cadj-{{ $consignment->id }}-delta">Litres (+/&minus;) <span class="req">*</span></label>
                  <input type="text" id="cadj-{{ $consignment->id }}-delta" name="litres_delta" inputmode="decimal" placeholder="-2.00" required />
                  <div class="hint">Use a minus sign for a shortfall. Applied when the consignment is confirmed.</div>
                </div>
                <div class="field">
                  <label for="cadj-{{ $consignment->id }}-reason">Reason <span class="req">*</span></label>
                  <select id="cadj-{{ $consignment->id }}-reason" name="adjustment_reason_id" required>
                    @foreach ($adjustmentReasons as $reason)
                      <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="field full">
                  <label for="cadj-{{ $consignment->id }}-why">Explanation <span class="req">*</span></label>
                  <textarea id="cadj-{{ $consignment->id }}-why" name="explanation" rows="2" required></textarea>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Record adjustment</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  @if ($canGrade)
    @foreach ($confirmedToday->filter(fn ($c) => $c->grade_id === null && $c->batch_id === null) as $consignment)
      <div id="modal-grade-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Grade {{ $consignment->reference }}</h3>
              <p>Confirmed {{ \App\Support\Wat::date($consignment->confirmed_at) }} &middot; the rate applied is the one in force that day</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.grade', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-grade-{{ $consignment->id }}" />
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-grade-'.$consignment->id.''])
              <div class="field">
                <label for="cgr-{{ $consignment->id }}-grade">Grade <span class="req">*</span></label>
                <select id="cgr-{{ $consignment->id }}-grade" name="grade_id" required>
                  @foreach ($grades as $grade)
                    <option value="{{ $grade->id }}">
                      {{ $grade->name }}
                      @php($rate = $grade->rateOn($consignment->confirmed_at))
                      @if ($rate) &mdash; {{ \App\Support\Money::format($rate->rate_per_litre_minor) }}/L @endif
                    </option>
                  @endforeach
                </select>
                <div class="hint">Every required quality test must be recorded first.</div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Assign grade</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  @if ($canConfirm)
    @foreach ($awaiting as $consignment)
      <div id="modal-confirm-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Confirm {{ $consignment->reference }}</h3>
              <p>From {{ $consignment->collectionPoint?->name }} &middot;
                 {{ \App\Support\Volume::format($consignment->litres_dispatched) }} dispatched</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.confirm', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-confirm-{{ $consignment->id }}" />
            @include('partials.confirm-form', [
              'consignment' => $consignment,
              'grades' => $grades,
              'centerReasons' => $centerReasons,
              'qualityTests' => $qualityTests,
              'canGrade' => $canGrade,
            ])
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Confirm consignment</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  @can('milk.batch.dispatch.create')
    <div id="modal-batch" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Dispatch Batch to Factory</h3>
            <p>Only confirmed and graded consignments can join</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('batches.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-batch" />
          <input type="hidden" name="collection_center_id" value="{{ $center->id }}" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-batch'])
            <div class="stack" style="gap:8px">
              @foreach ($batchable as $consignment)
                <label class="check-label">
                  <input type="checkbox" name="consignment_ids[]" value="{{ $consignment->id }}" checked />
                  <span class="perm-key">{{ $consignment->reference }}</span>
                  &middot; {{ $consignment->collectionPoint?->name }}
                  &middot; {{ \App\Support\Volume::format($consignment->litres_confirmed) }}
                  &middot; {{ $consignment->grade?->name }}
                </label>
              @endforeach
            </div>
            <div class="divider"></div>
            <div class="form-grid">
              <div class="field"><label for="batch-containers">Containers</label>
                <input type="number" id="batch-containers" name="containers" min="0" /></div>
              <div class="field"><label for="batch-dispatched">Dispatched at</label>
                <input type="datetime-local" id="batch-dispatched" name="dispatched_at"
                       value="{{ \App\Support\Wat::forInput() }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Dispatch batch</button>
          </div>
        </form>
      </div>
    </div>
  @endcan
@endsection
