@extends('layouts.app')
@section('title', 'Extension Agents')

@section('content')
  <div class="page-head">
    <div>
      <h1>Extension Agents</h1>
      <p>{{ number_format($agents->total()) }} agents &middot; targets for {{ $month->format('F Y') }}</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('field-activities.index') }}" class="btn btn-outline">Field Activities</a>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Agents</h3></div></div>
    <div class="card-body">
      {{--
        The controller has always honoured ?status= and ?q=, but this screen was
        the only index in the system with no filter bar, so neither could be
        reached from the interface.
      --}}
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" /></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['active', 'inactive'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select></div>
        <div class="field"><label>&nbsp;</label>
          <button type="submit" class="btn btn-outline">Filter</button></div>
        @if (request()->hasAny(['q', 'status']))
          <div class="field"><label>&nbsp;</label>
            <a href="{{ route('extension-agents.index') }}" class="btn btn-ghost">Clear</a></div>
        @endif
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Agent</th><th>Code</th><th class="num">Communities</th><th>Reports to</th>
            <th class="num">Visits this month</th><th class="num">Target</th>
            <th class="num">Farmers reached</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($agents as $agent)
              @php($counts = $activityCounts[$agent->id] ?? null)
              @php($done = (int) ($counts->activities ?? 0))
              @php($target = (int) ($agent->visit_target_monthly ?? 0))
              <tr>
                <td><div class="font-bold">{{ $agent->user?->name }}</div>
                  <div class="cell-sub">{{ $agent->user?->department?->name }}</div></td>
                <td class="perm-key">{{ $agent->code }}</td>
                <td class="num">{{ $agent->communities_count }}</td>
                <td>{{ $agent->reportsTo?->name ?? '—' }}</td>
                <td class="num font-bold">{{ $done }}</td>
                <td class="num">{{ $target > 0 ? $target : '—' }}</td>
                <td class="num">{{ number_format((int) ($counts->reached ?? 0)) }}</td>
                <td>
                  @if ($target > 0)
                    <span class="badge {{ $done >= $target ? 'success' : ($done >= $target * 0.6 ? 'warning' : 'danger') }}">
                      {{ $done >= $target ? 'On target' : round($done / max(1, $target) * 100).'%' }}
                    </span>
                  @else
                    <span class="badge {{ $agent->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($agent->status) }}</span>
                  @endif
                </td>
                <td class="actions"><a href="{{ route('extension-agents.show', $agent) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', ['title' => 'No extension agents in your scope', 'icon' => '&#128100;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $agents, 'noun' => 'agents'])
  </div>
@endsection
