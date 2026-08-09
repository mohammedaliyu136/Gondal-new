@extends('layouts.app')
@section('title', 'Personas')

@section('content')
  <div class="page-head">
    <div>
      <h1>Personas</h1>
      <p>Who actually uses the system, what they do in it, and what they must never see</p>
    </div>
    <div class="page-actions">
      @can('admin.users.view')<a href="{{ route('admin.users.index') }}" class="btn btn-outline">Users</a>@endcan
      <a href="{{ route('admin.roles.index') }}" class="btn btn-outline">Roles</a>
      @can('admin.roles.edit')
        <a href="{{ route('admin.permission-tests.index') }}" class="btn btn-primary">Test a Persona</a>
      @endcan
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>How to read this.</strong>
      A <strong>persona</strong> is a job. A <strong>role</strong> is the set of permissions that job needs.
      A <strong>user</strong> is one person holding one or more roles. Personas are what you review with the
      business; roles are what the system enforces.
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-ic">&#128100;</div><div class="stat-label">Personas Defined</div>
      <div class="stat-value">{{ $counts['personas'] }}</div>
      <div class="stat-foot">covering {{ $counts['roles'] }} live roles</div></div>
    <div class="stat green"><div class="stat-ic">&#128101;</div><div class="stat-label">Users Behind Them</div>
      <div class="stat-value">{{ number_format($counts['users']) }}</div>
      <div class="stat-foot">active accounts</div></div>
    <div class="stat amber"><div class="stat-ic">&#127806;</div><div class="stat-label">Farmers &mdash; Not Users</div>
      <div class="stat-value">{{ number_format($counts['farmers']) }}</div>
      <div class="stat-foot">records, no sign-in</div></div>
    <div class="stat red"><div class="stat-ic">&#10067;</div><div class="stat-label">Undefined</div>
      <div class="stat-value">{{ $counts['undefined'] }}</div>
      <div class="stat-foot">draft roles, cannot be assigned</div></div>
  </div>

  <div class="alert warn mb-16">
    <span>&#9888;&#65039;</span>
    <div>
      <strong>Farmers and cooperative members are not system users.</strong>
      They never sign in, and there is no farmer portal. They are records that agents and extension staff
      create and maintain on their behalf. Anything a farmer needs to see &mdash; volumes, grades, payments
      &mdash; reaches them on paper or through their agent.
    </div>
  </div>

  @foreach ($personas as $group => $items)
    <div class="card mb-16">
      <div class="card-head">
        <div><h3>{{ $group }}</h3><p>{{ $items->count() }} persona(s)</p></div>
      </div>
      <div class="card-body">
        <div class="grid grid-2">
          @foreach ($items as $persona)
            <div class="card">
              <div class="card-body">
                <div class="persona-head">
                  <div class="avatar-md">{{ \Illuminate\Support\Str::of($persona['name'])->explode(' ')->take(2)->map(fn ($w) => \Illuminate\Support\Str::substr($w, 0, 1))->implode('') }}</div>
                  <div>
                    <div class="p-name">{{ $persona['name'] }}</div>
                    <div class="p-title">
                      {{ $persona['role'] }} &middot; scope: {{ $persona['scope'] }}
                    </div>
                  </div>
                </div>

                <div class="chip-group mb-16">
                  <span class="chip on">{{ $persona['permission_count'] }} permissions</span>
                  <span class="chip {{ $persona['user_count'] > 0 ? 'on' : '' }}">
                    {{ $persona['user_count'] }} user(s)
                  </span>
                  <span class="chip">Lands on: {{ $persona['lands_on'] }}</span>
                </div>

                <div class="p-label">Their day</div>
                <ul class="p-list">
                  @foreach ($persona['day'] as $line)
                    <li>{{ $line }}</li>
                  @endforeach
                </ul>

                <div class="p-label mt-16">Cannot see</div>
                <ul class="p-list">
                  @foreach ($persona['cannot'] as $line)
                    <li>{{ $line }}</li>
                  @endforeach
                </ul>

                <div class="divider"></div>
                <div class="text-small">
                  <strong>Key restriction:</strong> {{ $persona['restriction'] }}
                </div>

                @if ($persona['role_model'])
                  <div class="flex flex-wrap mt-16">
                    <a href="{{ route('admin.roles.show', $persona['role_model']) }}" class="btn btn-ghost btn-sm">Open role</a>
                  </div>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  @endforeach

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>All Roles at a Glance</h3>
          <p>Every role, its users, its default scope and its permission count</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Role</th><th>Default scope</th>
                <th class="num">Users</th><th class="num">Permissions</th><th>Status</th></tr></thead>
              <tbody>
                @foreach ($roles as $role)
                  <tr>
                    <td>
                      <a href="{{ route('admin.roles.show', $role) }}" class="font-bold">{{ $role->name }}</a>
                      <div class="cell-sub">{{ $role->description }}</div>
                    </td>
                    <td>{{ $role->defaultScopeType()->label() }}</td>
                    <td class="num">{{ $role->users_count }}</td>
                    <td class="num">{{ $role->livePermissions()->count() }}</td>
                    <td><span class="badge {{ [
                      'active' => 'success', 'draft' => 'warning',
                      'disabled' => 'muted', 'retired' => 'muted',
                    ][$role->status] ?? 'muted' }}">{{ ucfirst($role->status) }}</span></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Who Is Deliberately Not a User</h3>
          <p>People the system serves without giving them an account</p></div></div>
        <div class="card-body">
          <div class="queue-item">
            <div class="qi-ic">&#127806;</div>
            <div>
              <div class="qi-title">Farmers &middot; {{ number_format($counts['farmers']) }} records</div>
              <div class="qi-sub">Agents record on their behalf. Volumes, grades and payments reach them via
                the agent or on paper.</div>
            </div>
            <div class="qi-right"><span class="badge muted">Records</span></div>
          </div>
          <div class="queue-item">
            <div class="qi-ic">&#127974;</div>
            <div>
              <div class="qi-title">Cooperative officials</div>
              <div class="qi-sub">Chairman, secretary and treasurer are contacts on the cooperative record,
                not accounts.</div>
            </div>
            <div class="qi-right"><span class="badge muted">Records</span></div>
          </div>
          <div class="queue-item">
            <div class="qi-ic">&#128666;</div>
            <div>
              <div class="qi-title">Riders &amp; commercial drivers</div>
              <div class="qi-sub">Recorded on trips by the logistics officer so they can be paid. No sign-in.</div>
            </div>
            <div class="qi-right"><span class="badge muted">Records</span></div>
          </div>
          <div class="queue-item">
            <div class="qi-ic">&#127981;</div>
            <div>
              <div class="qi-title">Vendors &amp; suppliers</div>
              <div class="qi-sub">Named on requisitions and stock batches. There is no vendor registry to
                maintain them in.</div>
            </div>
            <div class="qi-right"><span class="badge warning">Gap</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection
