@extends('layouts.app')
@section('title', 'Field Activities')

@section('content')
  <div class="page-head">
    <div>
      <h1>Field Activities</h1>
      <p>{{ number_format($monthCount) }} this month &middot; {{ number_format($monthReached) }} farmers reached</p>
    </div>
    <div class="page-actions">
      @can('community.extension.view')
        <a href="{{ route('extension-agents.index') }}" class="btn btn-outline">Agents</a>
      @endcan
      @if ($canLog)<a href="#modal-log" class="btn btn-primary">+ Log Activity</a>@endif
    </div>
  </div>

  @if ($openFollowups->isNotEmpty())
    {{-- BR-5 + Phase 5 acceptance --}}
    <div class="card mb-16">
      <div class="card-head">
        <div><h3>Open Quality Follow-ups</h3>
          <p>Opened automatically by repeat rejections. Closing one requires a logged activity.</p></div>
        <span class="badge warning">{{ $openFollowups->count() }} open</span>
      </div>
      <div class="card-body flush">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Subject</th><th>Reason</th><th class="num">Occurrences</th>
              <th class="num">Threshold</th><th>Opened</th><th class="actions">Close</th></tr></thead>
            <tbody>
              @foreach ($openFollowups as $followup)
                <tr>
                  <td>
                    @if ($followup->subject instanceof \App\Models\Farmer)
                      <a href="{{ route('farmers.show', $followup->subject) }}">{{ $followup->subject->name }}</a>
                      <div class="cell-sub">{{ $followup->subject->code }}</div>
                    @else
                      {{ $followup->subject?->name ?? class_basename($followup->subject_type) }}
                    @endif
                  </td>
                  <td>{{ $followup->rejectionReason?->name }}</td>
                  <td class="num font-bold">{{ $followup->trigger_count }}</td>
                  <td class="num">{{ $followup->threshold }} in {{ $followup->window_days }} days</td>
                  <td>{{ \App\Support\Wat::relative($followup->opened_at) }}</td>
                  <td class="actions">
                    @if ($canLog)
                      <a href="#modal-log" class="btn btn-outline btn-sm">Log a visit</a>
                    @else
                      <span class="text-muted text-small">&mdash;</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>Activity Log</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="agent">Agent</label>
          <select id="agent" name="agent">
            <option value="">All</option>
            @foreach ($agents as $agent)
              <option value="{{ $agent->id }}" @selected(request('agent') == $agent->id)>{{ $agent->user?->name ?? $agent->code }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="type">Type</label>
          <select id="type" name="type">
            <option value="">All</option>
            @foreach ($types as $type)
              <option value="{{ $type->id }}" @selected(request('type') == $type->id)>{{ $type->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="community">Community</label>
          <select id="community" name="community">
            <option value="">All</option>
            @foreach ($communities as $community)
              <option value="{{ $community->id }}" @selected(request('community') == $community->id)>{{ $community->name }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('field-activities.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Reference</th><th>Type</th><th>Agent</th><th>Community</th><th>Farmer</th>
            <th class="num">Reached</th><th>Topic</th><th>Date</th><th>Source</th></tr></thead>
          <tbody>
            @forelse ($activities as $activity)
              <tr>
                <td class="perm-key">{{ $activity->reference }}</td>
                <td>{{ $activity->activityType?->name }}
                  @if ($activity->closes_followup_id)
                    <div class="cell-sub text-primary">closed {{ $activity->closesFollowup?->rejectionReason?->name }} follow-up</div>
                  @endif</td>
                <td>{{ $activity->extensionAgent?->user?->name }}</td>
                <td>{{ $activity->community?->name }}</td>
                <td>{{ $activity->farmer?->name ?? '—' }}</td>
                <td class="num">{{ $activity->farmers_reached }}</td>
                <td>{{ $activity->topic ?? '—' }}</td>
                <td>{{ \App\Support\Wat::date($activity->activity_date) }}</td>
                <td><span class="badge {{ $activity->source === 'api' ? 'info' : 'muted' }}">{{ strtoupper($activity->source) }}</span></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', ['title' => 'No activities for this filter', 'icon' => '&#128203;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $activities, 'noun' => 'activities'])
  </div>

  @if ($canLog)
    <div id="modal-log" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Log Field Activity</h3>
            <p>Selecting a follow-up below closes it once this activity is logged</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('field-activities.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-log" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-log'])
            <div class="form-grid">
              <div class="field"><label for="la-agent">Agent <span class="req">*</span></label>
                <select id="la-agent" name="extension_agent_id" required>
                  @foreach ($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->user?->name ?? $agent->code }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="la-type">Activity type <span class="req">*</span></label>
                <select id="la-type" name="activity_type_id" required>
                  @foreach ($types as $type)
                    <option value="{{ $type->id }}">
                      {{ $type->name }}@if ($type->closes_quality_followup) — can close a follow-up @endif
                    </option>
                  @endforeach
                </select></div>
              <div class="field"><label for="la-community">Community <span class="req">*</span></label>
                <select id="la-community" name="community_id" required>
                  @foreach ($communities as $community)
                    <option value="{{ $community->id }}">{{ $community->name }} ({{ $community->lga?->name }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="la-farmer">Farmer</label>
                <select id="la-farmer" name="farmer_id">
                  <option value="">Not farmer-specific</option>
                  @foreach ($farmers as $farmer)
                    <option value="{{ $farmer->id }}">{{ $farmer->name }} — {{ $farmer->code }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="la-date">Activity date <span class="req">*</span></label>
                <input type="date" id="la-date" name="activity_date"
                       value="{{ \App\Support\Wat::today()->toDateString() }}" required /></div>
              <div class="field"><label for="la-reached">Farmers reached</label>
                <input type="number" id="la-reached" name="farmers_reached" min="0" value="0" /></div>
              <div class="field full"><label for="la-topic">Topic</label>
                <input type="text" id="la-topic" name="topic" /></div>
              <div class="field full"><label for="la-followup">Closes quality follow-up</label>
                <select id="la-followup" name="closes_followup_id">
                  <option value="">None</option>
                  @foreach ($openFollowups as $followup)
                    <option value="{{ $followup->id }}">
                      {{ $followup->subject?->name ?? 'subject' }} —
                      {{ $followup->rejectionReason?->name }}
                      ({{ $followup->trigger_count }} in {{ $followup->window_days }} days)
                    </option>
                  @endforeach
                </select>
                <div class="hint">
                  Only activity types an administrator has marked as closing a follow-up will be accepted.
                </div></div>
              <div class="field full"><label for="la-findings">Findings</label>
                <textarea id="la-findings" name="findings" rows="3"></textarea></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Log activity</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
