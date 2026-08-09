@extends('layouts.app')
@section('title', $agent->user?->name ?? $agent->code)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('extension-agents.index') }}">Extension Agents</a><span class="sep">/</span>
    <span class="here">{{ $agent->user?->name ?? $agent->code }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ $agent->user?->initials() }}</div>
    <div class="dh-main">
      <h1>{{ $agent->user?->name }}</h1>
      <div class="dh-sub">
        {{ $agent->code }} &middot; {{ $agent->communities->count() }} communities
        @if ($agent->reportsTo) &middot; reports to {{ $agent->reportsTo->name }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ $agent->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($agent->status) }}</span>
        <span class="pill">{{ $monthActivities }} activities in {{ $month->format('M Y') }}</span>
        <span class="pill">{{ number_format($monthReached) }} farmers reached</span>
      </div>
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Communities covered</div>
      <div class="stat-value">{{ $agent->communities->count() }}</div>
      <div class="stat-foot">assigned to this agent</div></div>
    <div class="stat green"><div class="stat-label">Activities this month</div>
      <div class="stat-value">{{ $monthActivities }}</div>
      <div class="stat-foot">target {{ $agent->visit_target_monthly ?? '—' }}</div></div>
    <div class="stat amber"><div class="stat-label">Farmers reached</div>
      <div class="stat-value">{{ number_format($monthReached) }}</div>
      <div class="stat-foot">this month</div></div>
    <div class="stat"><div class="stat-label">Enrolment target</div>
      <div class="stat-value">{{ $agent->enrolment_target_monthly ?? '—' }}</div>
      <div class="stat-foot">per month</div></div>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Recent Activities</h3></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Reference</th><th>Type</th><th>Community</th><th>Farmer</th>
                <th class="num">Reached</th><th>Topic</th><th>Date</th></tr></thead>
              <tbody>
                @forelse ($activities as $activity)
                  <tr>
                    <td class="perm-key">{{ $activity->reference }}</td>
                    <td>{{ $activity->activityType?->name }}
                      @if ($activity->closes_followup_id)
                        <div class="cell-sub text-primary">closed a follow-up</div>
                      @endif</td>
                    <td>{{ $activity->community?->name }}</td>
                    <td>{{ $activity->farmer?->name ?? '—' }}</td>
                    <td class="num">{{ $activity->farmers_reached }}</td>
                    <td>{{ $activity->topic ?? '—' }}</td>
                    <td>{{ \App\Support\Wat::date($activity->activity_date) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="7">@include('partials.empty', ['title' => 'No activities logged', 'icon' => '&#128203;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Communities</h3><p>The communities this agent covers</p></div></div>
        <div class="card-body">
          <div class="chip-group">
            @forelse ($agent->communities as $community)
              <span class="chip on">{{ $community->name }} <span class="text-muted">&middot; {{ $community->lga?->name }}</span></span>
            @empty
              <span class="text-muted text-small">No communities assigned &mdash; the agent can see nothing until one is.</span>
            @endforelse
          </div>
          <div class="hint mt-16">
            This agent can only see farmers and activities in the communities listed here.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Account</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Email</div><div class="meta-value">{{ $agent->user?->email }}</div></div>
            <div class="meta-item"><div class="meta-label">Department</div><div class="meta-value">{{ $agent->user?->department?->name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Visit target</div><div class="meta-value">{{ $agent->visit_target_monthly ?? '—' }}/month</div></div>
            <div class="meta-item"><div class="meta-label">Enrolment target</div><div class="meta-value">{{ $agent->enrolment_target_monthly ?? '—' }}/month</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection
