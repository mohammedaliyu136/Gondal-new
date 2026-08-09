@extends('layouts.app')
@section('title', $delivery->reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('deliveries.index') }}">Milk Flow</a><span class="sep">/</span>
    <span class="here">{{ $delivery->reference }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \App\Support\Volume::format($delivery->litres_accepted, false) }}</div>
    <div class="dh-main">
      <h1>{{ $delivery->reference }}</h1>
      <div class="dh-sub">
        {{ $delivery->farmer?->name }} ({{ $delivery->farmer?->code }}) at {{ $delivery->collectionPoint?->name }}
        &middot; {{ \App\Support\Wat::dateTime($delivery->delivered_at) }} WAT
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['accepted' => 'success', 'partial' => 'warning', 'rejected' => 'danger'][$delivery->status] ?? 'muted' }}">
          {{ ucfirst($delivery->status) }}
        </span>
        @if ($delivery->consignment)
          <span class="pill">On {{ $delivery->consignment->reference }}</span>
        @else
          <span class="pill">Awaiting dispatch</span>
        @endif
        @if ($delivery->is_test)<span class="badge muted plain">test record</span>@endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canAdjust)
        <a href="#modal-adjust" class="btn btn-outline">Record adjustment</a>
      @endif
    </div>
  </div>

  @if ($delivery->was_after_cutoff)
    {{-- BR-3 — the override is on the record, permanently. --}}
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>Recorded after the {{ substr((string) $delivery->cutoff_applied, 0, 5) }} cut-off.</strong>
        @if ($delivery->cutoff_override_by_user_id)
          Accepted under a supervisor override by {{ $delivery->cutoffOverriddenBy?->name }}:
          &ldquo;{{ $delivery->cutoff_override_reason }}&rdquo;. The override is in the audit log.
        @else
          Rejected for failure to meet delivery time.
        @endif
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Volume</h3><p>As recorded at the collection point</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-3">
            <div class="meta-item"><div class="meta-label">Presented</div>
              <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_presented) }}</div></div>
            <div class="meta-item"><div class="meta-label">Rejected</div>
              <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_rejected) }}</div>
              <div class="cell-sub">{{ $delivery->rejectionReason?->name ?? 'none' }}</div></div>
            <div class="meta-item"><div class="meta-label">Accepted</div>
              <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_accepted) }}</div></div>
          </div>

          @if ($delivery->isAdjusted() || $delivery->adjustments->isNotEmpty())
            {{--
              BR-12 — the payable volume, beside the accepted one. The screen used
              to list the adjustments and print no result, so an operator could
              deduct a litre, see the row, and have no figure anywhere say what
              the farmer is now owed.
            --}}
            <div class="divider"></div>
            <div class="meta-grid cols-3">
              <div class="meta-item"><div class="meta-label">Adjustments</div>
                <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_adjusted) }}</div></div>
              <div class="meta-item"><div class="meta-label">Payable</div>
                <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_payable) }}</div>
                <div class="cell-sub">accepted &plusmn; adjustments</div></div>
              @if ($delivery->consignment)
                <div class="meta-item"><div class="meta-label">Carried on {{ $delivery->consignment->reference }}</div>
                  <div class="meta-value big">{{ \App\Support\Volume::format($delivery->litres_accepted) }}</div>
                  {{--
                    BR-7 sums litres_accepted, so an adjustment recorded after
                    dispatch moves the farmer's figure and not the consignment's.
                    Whether it should move both is the open question recorded in
                    AdjustmentService::applyToDelivery; until it is answered the
                    screen shows the difference rather than hiding it.
                  --}}
                  <div class="cell-sub">as dispatched</div></div>
              @endif
            </div>

            <div class="divider"></div>
            <h3 class="mb-16">Adjustments</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th class="num">Change</th><th>Reason</th><th>Explanation</th><th>By</th><th>When</th></tr></thead>
                <tbody>
                  @foreach ($delivery->adjustments as $adjustment)
                    <tr>
                      <td class="num font-bold">{{ $adjustment->signedLitres() }}</td>
                      <td>{{ $adjustment->reason?->name }}</td>
                      <td>{{ $adjustment->explanation }}</td>
                      <td>{{ $adjustment->createdBy?->name }}</td>
                      <td>{{ \App\Support\Wat::relative($adjustment->created_at) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Chain</h3><p>Point &rarr; center &rarr; factory</p></div></div>
        <div class="card-body">
          <div class="flow">
            <span class="step done"><span class="step-num">1</span> {{ $delivery->reference }}</span>
            <span class="arrow">&rsaquo;</span>
            @if ($delivery->consignment)
              <span class="step done"><span class="step-num">2</span> {{ $delivery->consignment->reference }}</span>
              <span class="arrow">&rsaquo;</span>
              @if ($delivery->consignment->batch_id)
                <span class="step done"><span class="step-num">3</span>
                  {{ \App\Models\Batch::withoutDataScope()->find($delivery->consignment->batch_id)?->reference ?? 'batched' }}</span>
              @else
                <span class="step"><span class="step-num">3</span> Not batched</span>
              @endif
            @else
              <span class="step"><span class="step-num">2</span> Not dispatched</span>
              <span class="arrow">&rsaquo;</span>
              <span class="step"><span class="step-num">3</span> Not batched</span>
            @endif
          </div>

          @if ($delivery->consignment)
            <div class="divider"></div>
            <div class="meta-grid cols-3">
              <div class="meta-item"><div class="meta-label">Grade</div>
                <div class="meta-value">{{ $delivery->consignment->grade?->name ?? 'not graded' }}</div></div>
              {{-- BR-14 — the snapshotted rate, gated because it is a payment figure. --}}
              @can('milk.grade.view')
                <div class="meta-item"><div class="meta-label">Rate paid</div>
                  <div class="meta-value">{{ \App\Support\Money::format($delivery->consignment->rate_per_litre_minor) }}/L</div>
                  <div class="cell-sub">saved at confirmation</div></div>
              @endcan
              <div class="meta-item"><div class="meta-label">Confirmed</div>
                <div class="meta-value">{{ \App\Support\Wat::dateTime($delivery->consignment->confirmed_at) }}</div></div>
            </div>
          @endif
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Record</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Farmer</div>
              <div class="meta-value">
                {{--
                  Link only when the viewer could actually open the record — a
                  farmer outside their scope renders as a name, not as a link to
                  a refusal.
                --}}
                @if ($delivery->farmer !== null
                    && \Illuminate\Support\Facades\Gate::check('community.farmers.view')
                    && $delivery->farmer->isWithinScopeFor(auth()->user(), 'community.farmers.view'))
                  <a href="{{ route('farmers.show', $delivery->farmer) }}" class="text-primary">{{ $delivery->farmer->name }}</a>
                @else
                  {{ $delivery->farmer?->name ?? '—' }}
                @endif
              </div></div>
            <div class="meta-item"><div class="meta-label">Cooperative</div>
              <div class="meta-value">{{ $delivery->farmer?->cooperative?->name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Point</div>
              <div class="meta-value">{{ $delivery->collectionPoint?->name }}</div></div>
            <div class="meta-item"><div class="meta-label">Center</div>
              <div class="meta-value">{{ $delivery->collectionPoint?->collectionCenter?->name }}</div></div>
            <div class="meta-item"><div class="meta-label">Containers</div>
              <div class="meta-value">{{ $delivery->containers ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Recorded by</div>
              <div class="meta-value">{{ $delivery->recordedBy?->name }}</div></div>
          </div>
          @if ($delivery->notes)
            <div class="divider"></div>
            <div class="text-small">{{ $delivery->notes }}</div>
          @endif
        </div>
        @can('milk.deliveries.edit')
          <form method="POST" action="{{ route('deliveries.update', $delivery) }}">
            @csrf @method('PUT')
            <div class="card-body">
              <div class="field mb-16"><label for="dc-containers">Containers</label>
                <input type="number" id="dc-containers" name="containers" value="{{ $delivery->containers }}" min="0" /></div>
              <div class="field"><label for="dc-notes">Notes</label>
                <textarea id="dc-notes" name="notes" rows="2">{{ $delivery->notes }}</textarea>
                <div class="hint">Litres cannot be edited here. Use Record adjustment to change the payable volume.</div></div>
            </div>
            <div class="modal-foot"><button type="submit" class="btn btn-outline btn-sm">Save detail</button></div>
          </form>
        @endcan
      </div>
    </div>
  </div>

  @if ($canAdjust)
    <div id="modal-adjust" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Record Adjustment</h3><p>A reason and an explanation are both required</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('deliveries.adjust', $delivery) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-adjust" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-adjust'])
            <div class="field mb-16"><label for="adj-delta">Change in litres <span class="req">*</span></label>
              <input type="text" id="adj-delta" name="litres_delta" inputmode="decimal" placeholder="-1.00" required />
              {{-- BR-12 — the floor is quoted, because an over-large deduction is refused. --}}
              <div class="hint">Negative deducts, positive adds. Payable volume is
                {{ \App\Support\Volume::format($delivery->litres_payable) }} and cannot go below zero.</div></div>
            <div class="field mb-16"><label for="adj-reason">Reason <span class="req">*</span></label>
              <select id="adj-reason" name="adjustment_reason_id" required>
                @foreach ($adjustmentReasons as $reason)
                  <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                @endforeach
              </select></div>
            <div class="field"><label for="adj-explanation">Explanation <span class="req">*</span></label>
              <textarea id="adj-explanation" name="explanation" rows="3" required></textarea>
              <div class="hint">Adjustments are never silent — this goes to the audit log.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Record adjustment</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
