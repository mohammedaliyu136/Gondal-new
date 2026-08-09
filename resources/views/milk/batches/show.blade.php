@extends('layouts.app')
@section('title', $batch->reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('batches.index') }}">Batches</a><span class="sep">/</span>
    <span class="here">{{ $batch->reference }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \App\Support\Volume::format($batch->litres_dispatched, false) }}</div>
    <div class="dh-main">
      <h1>{{ $batch->reference }}</h1>
      <div class="dh-sub">
        {{ $batch->collectionCenter?->name }} &rarr; factory &middot;
        dispatched {{ \App\Support\Wat::dateTime($batch->dispatched_at) }} WAT &middot;
        {{ $batch->consignments->count() }} consignments
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['in_transit' => 'info', 'reconciled' => 'success', 'discrepancy' => 'danger', 'released' => 'success'][$batch->status] ?? 'muted' }}">
          {{ \Illuminate\Support\Str::headline($batch->status) }}
        </span>
        @if ($batch->trip)<span class="pill">{{ $batch->trip->reference }}</span>@endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canReconcile && $batch->reconciled_at === null)
        <a href="{{ route('reconciliation.index') }}" class="btn btn-primary">Reconcile at factory</a>
      @endif
      @if ($canRelease && $batch->isReleasable())
        <a href="#modal-release" class="btn btn-primary">Release</a>
      @endif
    </div>
  </div>

  @if ($batch->exceedsTolerance())
    {{-- BR-11 --}}
    <div class="alert danger mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>Variance of {{ $batch->discrepancyPercentage() }}% exceeds the
          {{ $batch->tolerancePercentage() }}% tolerance.</strong>
        A supervisor note is required before this batch can be released.
        @if ($batch->supervisor_notes)
          <div class="mt-16">Note on file: &ldquo;{{ $batch->supervisor_notes }}&rdquo;</div>
        @endif
      </div>
    </div>
  @endif

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Dispatched</div>
      <div class="stat-value">{{ \App\Support\Volume::format($batch->litres_dispatched) }}</div>
      <div class="stat-foot">total confirmed litres</div></div>
    <div class="stat green"><div class="stat-label">Received</div>
      <div class="stat-value">{{ $batch->litres_received === null ? '—' : \App\Support\Volume::format($batch->litres_received) }}</div>
      <div class="stat-foot">{{ $batch->containers_received ?? '—' }} containers</div></div>
    <div class="stat {{ $batch->exceedsTolerance() ? 'red' : 'amber' }}"><div class="stat-label">Variance</div>
      <div class="stat-value">{{ $batch->discrepancy_litres === null ? '—' : $batch->discrepancy_litres.' L' }}</div>
      <div class="stat-foot">{{ $batch->discrepancyPercentage() ?? '0.00' }}% vs {{ $batch->tolerancePercentage() }}% tolerance</div></div>
    <div class="stat red"><div class="stat-label">Rejected at factory</div>
      <div class="stat-value">{{ \App\Support\Volume::format($batch->litres_rejected_at_factory) }}</div>
      <div class="stat-foot">{{ $batch->rejectionReason?->name ?? 'none' }}</div></div>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Consignments in this Batch</h3>
          <p>Each was confirmed and graded before joining</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Consignment</th><th>Point</th><th class="num">Confirmed</th><th>Grade</th><th class="num">Rate</th></tr></thead>
              <tbody>
                @foreach ($batch->consignments as $consignment)
                  <tr>
                    <td class="perm-key">{{ $consignment->reference }}</td>
                    <td>{{ $consignment->collectionPoint?->name }}</td>
                    <td class="num font-bold">{{ \App\Support\Volume::format($consignment->litres_confirmed) }}</td>
                    <td><span class="badge success">{{ $consignment->grade?->name }}</span></td>
                    <td class="num">
                      @can('milk.grade.view')
                        {{ \App\Support\Money::format($consignment->rate_per_litre_minor) }}
                      @else
                        &mdash;
                      @endcan
                    </td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="2">Total</th>
                  <th class="num">{{ \App\Support\Volume::format($batch->litres_dispatched) }}</th>
                  <th colspan="2"></th>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Reconciliation</h3><p>What the factory recorded on intake</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Reconciled by</div>
              <div class="meta-value">{{ $batch->reconciledBy?->name ?? 'not yet' }}</div></div>
            <div class="meta-item"><div class="meta-label">Reconciled at</div>
              <div class="meta-value">{{ \App\Support\Wat::dateTime($batch->reconciled_at) }}</div></div>
            <div class="meta-item"><div class="meta-label">Discrepancy cause</div>
              <div class="meta-value">{{ $batch->discrepancyCause?->name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Released</div>
              <div class="meta-value">{{ \App\Support\Wat::dateTime($batch->released_at) }}
                @if ($batch->releasedBy)<div class="cell-sub">{{ $batch->releasedBy->name }}</div>@endif</div></div>
          </div>
          @if ($batch->supervisor_notes)
            <div class="divider"></div>
            <div class="meta-item"><div class="meta-label">Supervisor note</div>
              <div class="meta-value">{{ $batch->supervisor_notes }}</div></div>
          @endif
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Transport</h3><p>The trip that carried this batch</p></div></div>
        <div class="card-body">
          @if ($batch->trip)
            <div class="meta-grid cols-2">
              <div class="meta-item"><div class="meta-label">Trip</div>
                <div class="meta-value mono">{{ $batch->trip->reference }}</div></div>
              <div class="meta-item"><div class="meta-label">Route</div>
                <div class="meta-value">{{ $batch->trip->route?->name }}</div></div>
              <div class="meta-item"><div class="meta-label">Driver</div>
                <div class="meta-value">{{ $batch->trip->driver?->name ?? '—' }}</div></div>
              <div class="meta-item"><div class="meta-label">Vehicle</div>
                <div class="meta-value">{{ $batch->trip->vehicle?->registration ?? '—' }}</div></div>
              @can('logistics.payments.view')
                <div class="meta-item"><div class="meta-label">Fee</div>
                  <div class="meta-value">{{ \App\Support\Money::format($batch->trip->fee_minor) }}</div>
                  <div class="cell-sub">the route&rsquo;s fee on the day of the trip</div></div>
              @endcan
            </div>
          @else
            @include('partials.empty', ['title' => 'No trip recorded', 'icon' => '&#128666;'])
          @endif
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Progress</h3><p>Where this batch has reached</p></div></div>
        <div class="card-body">
          <div class="flow">
            <span class="step {{ $batch->dispatched_at ? 'done' : '' }}"><span class="step-num">1</span> In transit</span>
            <span class="arrow">&rsaquo;</span>
            <span class="step {{ $batch->reconciled_at ? 'done' : '' }}"><span class="step-num">2</span>
              {{ $batch->status === 'discrepancy' ? 'Discrepancy' : 'Reconciled' }}</span>
            <span class="arrow">&rsaquo;</span>
            <span class="step {{ $batch->released_at ? 'done' : '' }}"><span class="step-num">3</span> Released</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if ($canRelease && $batch->isReleasable())
    <div id="modal-release" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Release {{ $batch->reference }}</h3><p>To production and payment</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('reconciliation.release', $batch) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-release" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-release'])
            @if ($batch->exceedsTolerance())
              <div class="alert warn mb-16">
                <span>&#9888;&#65039;</span>
                <div>The {{ $batch->discrepancyPercentage() }}% variance exceeds tolerance, so a note is
                  required before release.</div>
              </div>
            @endif
            <div class="field">
              <label for="rel-notes">Supervisor note
                @if ($batch->exceedsTolerance())<span class="req">*</span>@endif</label>
              <textarea id="rel-notes" name="supervisor_notes" rows="3"
                        @required($batch->exceedsTolerance())>{{ $batch->supervisor_notes }}</textarea>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Release batch</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
