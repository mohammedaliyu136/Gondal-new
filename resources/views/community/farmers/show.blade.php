@extends('layouts.app')
@section('title', $farmer->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('farmers.index') }}">Farmers</a><span class="sep">/</span>
    <span class="here">{{ $farmer->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($farmer->name, 0, 2) }}</div>
    <div class="dh-main">
      <h1>{{ $farmer->name }}</h1>
      <div class="dh-sub">
        {{ $farmer->code }} &middot; {{ $farmer->community?->name }}, {{ $farmer->community?->lga?->name }}
        @if ($farmer->cooperative) &middot; {{ $farmer->cooperative->name }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['active' => 'success', 'dormant' => 'warning', 'exited' => 'muted'][$farmer->status] ?? 'muted' }}">
          {{ ucfirst($farmer->status) }}</span>
        @if ($farmer->herd_size)<span class="pill">{{ $farmer->herd_size }} cattle</span>@endif
        @if ($farmer->lactating_count)<span class="pill">{{ $farmer->lactating_count }} lactating</span>@endif
        <span class="badge muted plain">record, not an account</span>
      </div>
    </div>
    <div class="dh-actions">
      @can('finance.farmer_payments.view')
        {{-- USER-2 — a farmer has no login, so the statement is something an
             officer prints and hands over. --}}
        <a href="{{ route('farmers.statement', $farmer) }}" class="btn btn-outline">Statement</a>
      @endcan
      @can('community.farmers.edit')
        <a href="#modal-edit-farmer" class="btn btn-outline">Edit</a>
      @endcan
    </div>
  </div>

  @if ($openFollowups->isNotEmpty())
    {{-- BR-5 --}}
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>{{ $openFollowups->count() }} open quality follow-up(s), opened automatically.</strong>
        @foreach ($openFollowups as $followup)
          {{ $followup->rejectionReason?->name }}: {{ $followup->trigger_count }} rejections in
          {{ $followup->window_days }} days (threshold {{ $followup->threshold }}).
        @endforeach
        @can('community.extension.create')
          Closing one requires a logged
          <a href="{{ route('field-activities.index') }}" class="text-primary">field activity</a>.
        @endcan
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      @if ($seesVolumes)
        <div class="card">
          <div class="card-head">
            <div><h3>Delivery History</h3><p>Last 25 deliveries</p></div>
            <span class="pill green">30-day accepted: {{ \App\Support\Volume::format($thirtyDayLitres) }}</span>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Reference</th><th>Point</th><th class="num">Presented</th><th class="num">Rejected</th>
                  <th class="num">Accepted</th><th>Reason</th><th>Grade</th><th>When</th></tr></thead>
                <tbody>
                  @forelse ($deliveries as $delivery)
                    <tr>
                      <td><a href="{{ route('deliveries.show', $delivery) }}" class="perm-key">{{ $delivery->reference }}</a></td>
                      <td>{{ $delivery->collectionPoint?->name }}</td>
                      <td class="num">{{ \App\Support\Volume::format($delivery->litres_presented, false) }}</td>
                      <td class="num">{{ \App\Support\Volume::format($delivery->litres_rejected, false) }}</td>
                      <td class="num font-bold">{{ \App\Support\Volume::format($delivery->litres_accepted, false) }}</td>
                      <td>{{ $delivery->rejectionReason?->name ?? '—' }}</td>
                      <td>{{ $delivery->consignment?->grade?->name ?? '—' }}</td>
                      <td>{{ \App\Support\Wat::relative($delivery->delivered_at) }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="8">@include('partials.empty', ['title' => 'No deliveries recorded', 'icon' => '&#127869;'])</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @else
        {{-- §16 — the Extension Agent persona: "No volumes or payment figures". --}}
        <div class="card">
          <div class="card-head"><div><h3>Delivery History</h3><p>Not available to your role</p></div></div>
          <div class="card-body">
            @include('partials.empty', [
              'title' => 'Volumes are outside your role',
              'message' => 'Milk volumes are not shown to your role. You can see the farmer record itself.',
              'icon' => '&#128274;',
            ])
          </div>
        </div>
      @endif

      <div class="card">
        <div class="card-head"><div><h3>Extension Activity</h3><p>Visits, training and follow-ups</p></div></div>
        <div class="card-body">
          @forelse ($activities as $activity)
            <div class="queue-item">
              <div class="qi-ic">&#128100;</div>
              <div>
                <div class="qi-title">{{ $activity->activityType?->name }}
                  <span class="perm-key">{{ $activity->reference }}</span></div>
                <div class="qi-sub">
                  {{ $activity->extensionAgent?->user?->name }}
                  @if ($activity->topic) &middot; {{ $activity->topic }} @endif
                  @if ($activity->closes_followup_id) &middot; closed a quality follow-up @endif
                </div>
                <div class="tl-time">{{ \App\Support\Wat::date($activity->activity_date) }}</div>
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'No activity logged for this farmer', 'icon' => '&#128203;'])
          @endforelse
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Record</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Code</div><div class="meta-value mono">{{ $farmer->code }}</div></div>
            <div class="meta-item"><div class="meta-label">Phone</div><div class="meta-value">{{ $farmer->phone ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Gender</div><div class="meta-value">{{ $farmer->gender ? ucfirst($farmer->gender) : '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Age</div><div class="meta-value">{{ $farmer->age() ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Default point</div>
              <div class="meta-value">{{ $farmer->defaultCollectionPoint?->name ?? '—' }}</div>
              <div class="cell-sub">{{ $farmer->defaultCollectionPoint?->collectionCenter?->name }}</div></div>
            <div class="meta-item"><div class="meta-label">Enrolled</div>
              <div class="meta-value">{{ \App\Support\Wat::date($farmer->enrolled_on) }}</div>
              <div class="cell-sub">by {{ $farmer->enrolledBy?->name ?? 'unknown' }}</div></div>
          </div>
        </div>
      </div>

      @if ($pendingDeductions->isNotEmpty())
        <div class="card">
          <div class="card-head"><div><h3>Pending Deductions</h3>
            <p>Shop purchases to be taken from the next milk payment</p></div></div>
          <div class="card-body">
            @foreach ($pendingDeductions as $deduction)
              <div class="queue-item">
                <div class="qi-ic">&#128722;</div>
                <div>
                  <div class="qi-title">{{ \App\Support\Money::format($deduction->amount_minor) }}</div>
                  <div class="qi-sub">{{ $deduction->description }}</div>
                </div>
                <div class="qi-right"><span class="badge warning">Pending</span></div>
              </div>
            @endforeach
            {{-- §15.1 --}}
          </div>
        </div>
      @endif
    </div>
  </div>

  @can('community.farmers.edit')
    <div id="modal-edit-farmer" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head"><div><h3>Edit Farmer</h3><p>{{ $farmer->code }}</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('farmers.update', $farmer) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-farmer" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-farmer'])
            <div class="form-grid">
              <div class="field"><label for="uf-name">Name <span class="req">*</span></label>
                <input type="text" id="uf-name" name="name" value="{{ $farmer->name }}" required /></div>
              <div class="field"><label for="uf-phone">Phone</label>
                <input type="text" id="uf-phone" name="phone" value="{{ $farmer->phone }}" /></div>
              <div class="field"><label for="uf-memberno">Cooperative member no.</label>
                <input type="text" id="uf-memberno" name="cooperative_member_no" value="{{ $farmer->cooperative_member_no }}" /></div>
              <div class="field"><label for="uf-herd">Herd size</label>
                <input type="number" id="uf-herd" name="herd_size" value="{{ $farmer->herd_size }}" min="0" /></div>
              <div class="field"><label for="uf-lact">Lactating cows</label>
                <input type="number" id="uf-lact" name="lactating_count" value="{{ $farmer->lactating_count }}" min="0" /></div>
              <div class="field"><label for="uf-status">Status <span class="req">*</span></label>
                <select id="uf-status" name="status" required>
                  @foreach (['active', 'dormant', 'exited'] as $status)
                    <option value="{{ $status }}" @selected($farmer->status === $status)>{{ ucfirst($status) }}</option>
                  @endforeach
                </select></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save farmer</button>
          </div>
        </form>
      </div>
    </div>
  @endcan
@endsection
