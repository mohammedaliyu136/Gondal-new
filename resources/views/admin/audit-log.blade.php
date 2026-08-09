@extends('layouts.app')
@section('title', 'Audit Log')

@section('content')
  <div class="page-head">
    <div>
      <h1>Audit Log</h1>
      <p>{{ number_format($counts['total']) }} entries &middot; append-only, retained at least {{ $retentionMonths }} months</p>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#128220;</span>
    <div>
      <strong>This log cannot be edited or deleted, by anyone, through any screen.</strong>
      Every permission change, data change and blocked access attempt is written here and stays here.
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat red"><div class="stat-label">Blocked access (30d)</div>
      <div class="stat-value">{{ number_format($counts['blocked']) }}</div>
      <div class="stat-foot">each with the missing permission</div></div>
    <div class="stat amber"><div class="stat-label">Permission changes (30d)</div>
      <div class="stat-value">{{ number_format($counts['permission_changes']) }}</div>
      <div class="stat-foot">with before/after grant sets</div></div>
    <div class="stat blue"><div class="stat-label">Failed sign-ins (30d)</div>
      <div class="stat-value">{{ number_format($counts['failed_signins']) }}</div>
      <div class="stat-foot">throttled per account and per IP</div></div>
    <div class="stat green"><div class="stat-label">Total entries</div>
      <div class="stat-value">{{ number_format($counts['total']) }}</div>
      <div class="stat-foot">nothing has ever been removed</div></div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Entries</h3><p>Newest first</p></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="event">Event</label>
          <select id="event" name="event">
            <option value="">All events</option>
            @foreach ($eventTypes as $eventType)
              <option value="{{ $eventType }}" @selected(request('event') === $eventType)>
                {{ \Illuminate\Support\Str::headline($eventType) }}
              </option>
            @endforeach
          </select></div>
        <div class="field"><label for="module">Module</label>
          <select id="module" name="module">
            <option value="">All modules</option>
            @foreach ($modules as $module)
              <option value="{{ $module }}" @selected(request('module') === $module)>{{ $module }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="actor">Actor</label>
          <select id="actor" name="actor">
            <option value="">Anyone</option>
            @foreach ($actors as $actor)
              <option value="{{ $actor->id }}" @selected(request('actor') == $actor->id)>{{ $actor->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="reference">Reference</label>
          <input type="text" id="reference" name="reference" value="{{ request('reference') }}" placeholder="DENY-0001" /></div>
        <div class="field"><label for="from">From</label>
          <input type="date" id="from" name="from" value="{{ request('from') }}" /></div>
        <div class="field"><label for="to">To</label>
          <input type="date" id="to" name="to" value="{{ request('to') }}" /></div>
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Summary text or a reference like DENY-0004" /></div>
        <div class="field"><label class="check-label" for="include_test">
          <input type="checkbox" id="include_test" name="include_test" value="1" @checked(request()->boolean('include_test')) />
          Include test activity</label></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('admin.audit-log') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>When (WAT)</th><th>Event</th><th>Actor</th><th>Module</th>
            <th>Summary</th><th>Detail</th><th>Source</th>
          </tr></thead>
          <tbody>
            @forelse ($entries as $entry)
              <tr>
                <td>{{ \App\Support\Wat::dateTime($entry->occurred_at) }}
                  @if ($entry->reference)<div class="cell-sub perm-key">{{ $entry->reference }}</div>@endif</td>
                <td>
                  <span class="badge {{ match ($entry->event_type) {
                    'blocked_access', 'failed_signin', 'rejection', 'data_delete' => 'danger',
                    'permission_change', 'role_change', 'test_run' => 'warning',
                    'approval', 'signin' => 'success',
                    default => 'info',
                  } }}">{{ \Illuminate\Support\Str::headline($entry->event_type) }}</span>
                  @if ($entry->is_test)<div class="cell-sub"><span class="badge muted plain">test</span></div>@endif
                </td>
                <td>
                  @if ($entry->actor_user_id && \Illuminate\Support\Facades\Gate::check('admin.users.view'))
                    <a href="{{ route('admin.users.show', $entry->actor_user_id) }}" class="text-primary">{{ $entry->actor_label }}</a>
                  @else
                    {{ $entry->actor_label }}
                  @endif
                  @if ($entry->actor_role_label)<div class="cell-sub">{{ $entry->actor_role_label }}</div>@endif</td>
                <td>{{ $entry->module ?? '—' }}</td>
                <td>{{ $entry->summary }}</td>
                <td>
                  @if ($entry->missing_permission)
                    {{--
                      The roles the user actually held at the moment of refusal are
                      captured on the entry; showing them is what turns "quote the
                      reference to your administrator" into something the
                      administrator can act on without reconstructing it by hand.
                    --}}
                    <div class="text-small">missing <span class="perm-key">{{ $entry->missing_permission }}</span></div>
                    <div class="cell-sub">{{ $entry->deny_reason === 'scope' ? 'out of data scope' : 'permission not granted' }}</div>
                    @if (! empty($entry->detail['user_roles']))
                      <div class="cell-sub">held: {{ implode(', ', $entry->detail['user_roles']) }}</div>
                    @endif
                  @elseif (($entry->detail['affected_users'] ?? null) !== null)
                    {{-- AUDIT-3 --}}
                    <div class="text-small">{{ $entry->detail['affected_users'] }} user(s) affected</div>
                    @if (! empty($entry->detail['granted']))
                      <div class="cell-sub">+{{ count($entry->detail['granted']) }} granted</div>
                    @endif
                    @if (! empty($entry->detail['revoked']))
                      <div class="cell-sub">&minus;{{ count($entry->detail['revoked']) }} revoked</div>
                    @endif
                    @if (! empty($entry->detail['sensitive_granted']))
                      <div class="cell-sub text-danger">
                        sensitive: {{ implode(', ', $entry->detail['sensitive_granted']) }}
                      </div>
                    @endif
                    @if (! empty($entry->detail['test_run_override_reason']))
                      <div class="cell-sub text-danger">
                        test-run override: {{ $entry->detail['test_run_override_reason'] }}
                      </div>
                    @endif
                  @elseif (($entry->detail['rules'] ?? $entry->detail['rule'] ?? null) !== null)
                    <div class="text-small">
                      @foreach ((array) ($entry->detail['rules'] ?? $entry->detail['rule']) as $rule)
                        <span class="perm-key">{{ $rule }}</span>
                      @endforeach
                    </div>
                  @else
                    <span class="text-muted">&mdash;</span>
                  @endif
                  @if ($entry->attempted_route)
                    <div class="cell-sub mono">{{ $entry->attempted_route }}</div>
                  @endif
                </td>
                <td><span class="badge {{ $entry->source === 'api' ? 'info' : 'muted' }}">{{ strtoupper($entry->source) }}</span>
                  @if ($entry->ip)<div class="cell-sub mono">{{ $entry->ip }}</div>@endif</td>
              </tr>
            @empty
              <tr><td colspan="7">@include('partials.empty', ['title' => 'No entries for this filter', 'icon' => '&#128220;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $entries, 'noun' => 'entries'])
  </div>
@endsection
