@extends('layouts.app')
@section('title', 'Users')

@section('content')
  <div class="page-head">
    <div>
      <h1>Users</h1>
      <p>{{ number_format($counts['active']) }} active staff &middot; {{ $counts['test'] }} test &middot;
         {{ $counts['deactivated'] }} deactivated</p>
    </div>
    <div class="page-actions">
      @can('admin.roles.view')
        <a href="{{ route('admin.roles.index') }}" class="btn btn-outline">Roles</a>
      @endcan
      @if ($canCreate)<a href="#modal-new-user" class="btn btn-primary">+ Create User</a>@endif
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#128274;</span>
    <div>
      <strong>You never see or set a user&rsquo;s password.</strong>
      Creating an account emails an activation code and the user chooses their own password.
      Farmers, cooperative officials, riders and vendors do not sign in, so they are not accounts here.
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Active staff</div>
      <div class="stat-value">{{ number_format($counts['active']) }}</div>
      <div class="stat-foot">excluding test accounts</div></div>
    <div class="stat amber"><div class="stat-label">Role assignments</div>
      <div class="stat-value">{{ number_format($counts['assignments']) }}</div>
      <div class="stat-foot">each with its own scope</div></div>
    <div class="stat red"><div class="stat-label">Test accounts</div>
      <div class="stat-value">{{ $counts['test'] }}</div>
      <div class="stat-foot">never in a report or payroll</div></div>
    <div class="stat"><div class="stat-label">Deactivated</div>
      <div class="stat-value">{{ $counts['deactivated'] }}</div>
      <div class="stat-foot">their records keep their name</div></div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Accounts</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or email" /></div>
        <div class="field"><label for="role">Role</label>
          <select id="role" name="role">
            <option value="">All</option>
            @foreach ($roles as $role)
              <option value="{{ $role->id }}" @selected(request('role') == $role->id)>{{ $role->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="department">Department</label>
          <select id="department" name="department">
            <option value="">All</option>
            @foreach ($departments as $department)
              <option value="{{ $department->id }}" @selected(request('department') == $department->id)>{{ $department->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="deactivated" @selected(request('status') === 'deactivated')>Deactivated</option>
          </select></div>
        <div class="field"><label class="check-label" for="test_only">
          <input type="checkbox" id="test_only" name="test_only" value="1" @checked(request()->boolean('test_only')) />
          Test accounts only</label></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>User</th><th>Department</th><th>Position</th><th>Roles</th>
            <th>2FA</th><th>Last sign-in</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($users as $user)
              <tr>
                <td>
                  <div class="flex">
                    <div class="avatar">{{ $user->initials() }}</div>
                    <div>
                      <div class="font-bold">{{ $user->name }}
                        @if ($user->is_test)<span class="badge muted plain">test</span>@endif
                      </div>
                      <div class="cell-sub">{{ $user->email }}</div>
                    </div>
                  </div>
                </td>
                <td>{{ $user->department?->name ?? '—' }}</td>
                <td>{{ $user->position ?? '—' }}</td>
                <td>
                  <div class="chip-group">
                    @foreach ($user->roles->reject->is_automatic->take(3) as $role)
                      <span class="chip on">{{ $role->name }}</span>
                    @endforeach
                    @if ($user->roles->reject->is_automatic->count() > 3)
                      <span class="chip">+{{ $user->roles->reject->is_automatic->count() - 3 }}</span>
                    @endif
                  </div>
                </td>
                <td><span class="badge {{ $user->two_factor_enabled ? 'success' : 'warning' }}">
                  {{ $user->two_factor_enabled ? 'Required' : 'Off' }}</span></td>
                <td>{{ \App\Support\Wat::relative($user->last_signed_in_at) }}</td>
                <td>
                  <span class="badge {{ $user->isActive() ? 'success' : 'muted' }}">
                    {{ $user->isActive() ? 'Active' : 'Deactivated' }}</span>
                  @if ($user->isLocked())<div class="cell-sub text-danger">locked</div>@endif
                </td>
                <td class="actions"><a href="{{ route('admin.users.show', $user) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="8">@include('partials.empty', ['title' => 'No accounts for this filter', 'icon' => '&#128100;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $users, 'noun' => 'users'])
  </div>

  @if ($canCreate)
    <div id="modal-new-user" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Create User</h3><p>An activation code is emailed &mdash; you never set the password</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.users.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-user" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-user'])
            <div class="form-grid">
              <div class="field"><label for="nu-name">Full name <span class="req">*</span></label>
                <input type="text" id="nu-name" name="name" required /></div>
              <div class="field"><label for="nu-email">Email <span class="req">*</span></label>
                <input type="email" id="nu-email" name="email" required /></div>
              <div class="field"><label for="nu-phone">Phone</label>
                <input type="text" id="nu-phone" name="phone" /></div>
              <div class="field"><label for="nu-department">Department</label>
                <select id="nu-department" name="department_id">
                  <option value="">None</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="nu-position">Position</label>
                <input type="text" id="nu-position" name="position" /></div>
              <div class="field"><label for="nu-employee">Employee record</label>
                <select id="nu-employee" name="employee_id">
                  <option value="">Not linked</option>
                  @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->name }} — {{ $employee->code }}</option>
                  @endforeach
                </select>
                <div class="hint">Linking lets them see their own leave and payslips.</div></div>
              <div class="field full">
                <label>Account options</label>
                <div class="stack" style="gap:10px;margin-top:6px">
                  {{-- Unticked by default. An emailed code is a locked door for
                       an agent with no inbox; it stays available for office
                       staff who want it. See the 001400 migration. --}}
                  <label class="check-label"><input type="checkbox" name="two_factor_enabled" value="1" />
                    Require the emailed sign-in code
                    <span class="hint">Off by default &mdash; field staff often have no inbox.</span></label>
                  <label class="check-label"><input type="checkbox" name="is_test" value="1" />
                    This is a test account &mdash; excluded from every report, aggregate and payroll</label>
                </div>
              </div>
            </div>
            <div class="alert info mt-16">
              <span>&#128274;</span>
              <div>The user sets their own password from the activation email.</div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create and send activation</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
