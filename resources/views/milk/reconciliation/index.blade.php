@extends('layouts.app')
@section('title', 'Factory Reconciliation')

@section('content')
  <div class="page-head">
    <div>
      <h1>Factory Reconciliation</h1>
      <p>Received against dispatched &middot; {{ \App\Support\Wat::longDate($date) }}</p>
    </div>
    <div class="page-actions">
      @can('milk.batch.dispatch.view')
        <a href="{{ route('batches.index') }}" class="btn btn-outline">All batches</a>
      @endcan
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Tolerance is {{ $tolerance }}%</strong>, set in
      {{--
        Keep a non-word character immediately before @endcan. Blade will not parse
        a directive that directly follows a word character — the guard that stops
        an email address being read as a directive — so "Settings@endcan" leaves
        the @can unclosed and the whole view fails to compile.
      --}}
      @can('admin.settings.edit')<a href="{{ route('admin.settings') }}" class="text-primary">Settings</a>@else<span>Settings</span>@endcan.
      Beyond it, a supervisor note is required before a batch can be released.
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">In transit</div>
      <div class="stat-value">{{ $inTransit->count() }}</div>
      <div class="stat-foot">{{ \App\Support\Volume::format(\App\Support\Volume::sum($inTransit->pluck('litres_dispatched')->all())) }} expected</div></div>
    <div class="stat green"><div class="stat-label">Received today</div>
      <div class="stat-value">{{ \App\Support\Volume::format($litresReceivedToday) }}</div>
      <div class="stat-foot">against {{ \App\Support\Volume::format($litresDispatchedToday) }} dispatched</div></div>
    <div class="stat amber"><div class="stat-label">Variance today</div>
      <div class="stat-value">{{ \App\Support\Volume::subtract($litresReceivedToday, $litresDispatchedToday) }} L</div>
      <div class="stat-foot">received &minus; dispatched</div></div>
    <div class="stat red"><div class="stat-label">Awaiting release</div>
      <div class="stat-value">{{ $awaitingRelease->count() }}</div>
      <div class="stat-foot">{{ $awaitingRelease->filter->exceedsTolerance()->count() }} need a note</div></div>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head">
          <div><h3>Arriving Batches</h3><p>Record what the factory actually received</p></div>
          @unless ($canReconcile)<span class="badge muted">read only</span>@endunless
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Batch</th><th>Center</th><th class="num">Dispatched</th><th class="num">Containers</th>
                <th>Driver</th><th>Dispatched at</th><th class="actions">Actions</th>
              </tr></thead>
              <tbody>
                @forelse ($inTransit as $batch)
                  <tr>
                    <td><a href="{{ route('batches.show', $batch) }}" class="perm-key">{{ $batch->reference }}</a></td>
                    <td>{{ $batch->collectionCenter?->name }}</td>
                    <td class="num font-bold">{{ \App\Support\Volume::format($batch->litres_dispatched) }}</td>
                    <td class="num">{{ $batch->containers ?? '—' }}</td>
                    <td>{{ $batch->trip?->driver?->name ?? '—' }}</td>
                    <td>{{ \App\Support\Wat::relative($batch->dispatched_at) }}</td>
                    <td class="actions">
                      @if ($canReconcile)
                        <a href="#modal-reconcile-{{ $batch->id }}" class="btn btn-primary btn-sm">Reconcile</a>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="7">@include('partials.empty', [
                    'title' => 'Nothing in transit',
                    'message' => 'Every dispatched batch has reached the factory.',
                    'icon' => '&#128666;',
                  ])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Reconciled Today</h3><p>Variance is received &minus; dispatched</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Batch</th><th>Center</th><th class="num">Dispatched</th><th class="num">Received</th>
                <th class="num">Variance</th><th>Cause</th><th>Status</th><th class="actions">Actions</th>
              </tr></thead>
              <tbody>
                @forelse ($reconciledToday as $batch)
                  <tr>
                    <td><a href="{{ route('batches.show', $batch) }}" class="perm-key">{{ $batch->reference }}</a></td>
                    <td>{{ $batch->collectionCenter?->name }}</td>
                    <td class="num">{{ \App\Support\Volume::format($batch->litres_dispatched, false) }}</td>
                    <td class="num">{{ \App\Support\Volume::format($batch->litres_received, false) }}</td>
                    <td class="num {{ $batch->exceedsTolerance() ? 'text-danger font-bold' : '' }}">
                      {{ $batch->discrepancy_litres }} L
                      <div class="cell-sub">{{ $batch->discrepancyPercentage() }}%</div>
                    </td>
                    <td>{{ $batch->discrepancyCause?->name ?? '—' }}</td>
                    <td><span class="badge {{ $batch->status === 'discrepancy' ? 'danger' : ($batch->status === 'released' ? 'success' : 'info') }}">
                      {{ \Illuminate\Support\Str::headline($batch->status) }}</span></td>
                    <td class="actions">
                      @if ($canRelease && $batch->isReleasable())
                        <a href="{{ route('batches.show', $batch) }}#modal-release" class="btn btn-outline btn-sm">Release</a>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="8">@include('partials.empty', ['title' => 'Nothing reconciled today', 'icon' => '&#127981;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Awaiting Release</h3><p>Reconciled but not yet released</p></div></div>
        <div class="card-body">
          @forelse ($awaitingRelease as $batch)
            <div class="queue-item">
              <div class="qi-ic">{!! $batch->exceedsTolerance() ? '&#9888;&#65039;' : '&#9989;' !!}</div>
              <div>
                <div class="qi-title"><a href="{{ route('batches.show', $batch) }}">{{ $batch->reference }}</a></div>
                <div class="qi-sub">
                  {{ $batch->collectionCenter?->name }} &middot;
                  {{ $batch->discrepancyPercentage() }}% variance
                  @if ($batch->exceedsTolerance() && ! $batch->supervisor_notes) &middot; note required @endif
                </div>
              </div>
              <div class="qi-right">
                <span class="badge {{ $batch->exceedsTolerance() ? 'danger' : 'success' }}">
                  {{ $batch->exceedsTolerance() ? 'Beyond tolerance' : 'Within tolerance' }}
                </span>
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'Nothing awaiting release', 'icon' => '&#9989;'])
          @endforelse
        </div>
      </div>
    </div>
  </div>

  @if ($canReconcile)
    @foreach ($inTransit as $batch)
      <div id="modal-reconcile-{{ $batch->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Reconcile {{ $batch->reference }}</h3>
              <p>{{ $batch->collectionCenter?->name }} &middot;
                 {{ \App\Support\Volume::format($batch->litres_dispatched) }} dispatched</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('reconciliation.store', $batch) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-reconcile-{{ $batch->id }}" />
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-reconcile-'.$batch->id.''])
              <div class="form-grid">
                <div class="field"><label for="rc-{{ $batch->id }}-received">Litres received <span class="req">*</span></label>
                  <input type="text" id="rc-{{ $batch->id }}-received" name="litres_received" inputmode="decimal" required />
                  <div class="hint">The variance against the dispatched volume is calculated for you.</div></div>
                <div class="field"><label for="rc-{{ $batch->id }}-containers">Containers received</label>
                  <input type="number" id="rc-{{ $batch->id }}-containers" name="containers_received" min="0" /></div>
                <div class="field"><label for="rc-{{ $batch->id }}-cause">Discrepancy cause</label>
                  <select id="rc-{{ $batch->id }}-cause" name="discrepancy_cause_id">
                    <option value="">No discrepancy</option>
                    @foreach ($causes as $cause)
                      <option value="{{ $cause->id }}">{{ $cause->name }}</option>
                    @endforeach
                  </select>
                  <div class="hint">Required when the variance exceeds {{ $tolerance }}%.</div></div>
                <div class="field"><label for="rc-{{ $batch->id }}-rejected">Rejected at factory (L)</label>
                  <input type="text" id="rc-{{ $batch->id }}-rejected" name="litres_rejected_at_factory" inputmode="decimal" value="0" /></div>
                <div class="field"><label for="rc-{{ $batch->id }}-reason">Factory rejection reason</label>
                  {{-- BR-1 — enabled at the factory stage only. --}}
                  <select id="rc-{{ $batch->id }}-reason" name="rejection_reason_id">
                    <option value="">No rejection</option>
                    @foreach ($factoryReasons as $reason)
                      <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                    @endforeach
                  </select></div>
                <div class="field"><label for="rc-{{ $batch->id }}-at">Reconciled at</label>
                  <input type="datetime-local" id="rc-{{ $batch->id }}-at" name="reconciled_at"
                         value="{{ \App\Support\Wat::forInput() }}" /></div>
                <div class="field full"><label for="rc-{{ $batch->id }}-notes">Supervisor note</label>
                  <textarea id="rc-{{ $batch->id }}-notes" name="supervisor_notes" rows="2"></textarea>
                  <div class="hint">Required before release when the variance exceeds tolerance.</div></div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Reconcile</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif
@endsection
