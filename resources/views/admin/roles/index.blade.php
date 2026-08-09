@extends('layouts.app')
@section('title', 'Roles & Permissions')

@section('content')
  <div class="page-head">
    <div>
      <h1>Roles &amp; Permissions</h1>
      <p>Roles are named sets of permissions. Edit them here.</p>
    </div>
    <div class="page-actions">
      @can('admin.roles.edit')
        <a href="{{ route('admin.permission-tests.index') }}" class="btn btn-outline">Permission Testing</a>
      @endcan
      @if ($canCreate)<a href="#modal-new-role" class="btn btn-primary">+ Create Role</a>@endif
    </div>
  </div>

  <div class="alert success mb-16">
    <span>&#9989;</span>
    <div>
      <strong>A permission change takes effect on the holder&rsquo;s next page load.</strong>
      Check any change with a test user in
      @can('admin.roles.edit')
        <a href="{{ route('admin.permission-tests.index') }}" class="text-primary">Permission Testing</a>
      @else Permission Testing @endcan
      before it reaches live staff.
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-ic">&#128737;</div><div class="stat-label">Active Roles</div>
      <div class="stat-value">{{ $counts['active'] }}</div>
      <div class="stat-foot">{{ $counts['retired'] }} retired &middot; {{ $counts['draft'] }} draft</div></div>
    <div class="stat green"><div class="stat-ic">&#9989;</div><div class="stat-label">Permissions</div>
      <div class="stat-value">{{ $permissionTotal }}</div>
      <div class="stat-foot">{{ $retiredPermissionCount }} retired, never deleted</div></div>
    <div class="stat amber"><div class="stat-ic">&#128101;</div><div class="stat-label">Role Assignments</div>
      <div class="stat-value">{{ $counts['assignments'] }}</div>
      <div class="stat-foot">each carries its own data scope</div></div>
    <div class="stat red"><div class="stat-ic">&#129514;</div><div class="stat-label">Test Users</div>
      <div class="stat-value">{{ $counts['testUsers'] }}</div>
      <div class="stat-foot">excluded from every report and payroll</div></div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Role List</h3><p>Open a role to edit its permission matrix</p></div>
      <span class="pill green">{{ $counts['active'] }} active &middot; {{ $counts['retired'] }} retired</span>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Role</th><th>Description</th><th>Data scope</th>
            <th class="num">Permissions</th><th class="num">Sensitive</th><th class="num">Users</th>
            <th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @foreach ($roles as $role)
              <tr>
                <td>
                  <div class="flex">
                    <span class="role-dot" style="width:8px;height:8px;border-radius:50%;background:var(--{{ $role->accent ?? 'muted' }})"></span>
                    <div class="font-bold {{ $role->isRetired() ? 'text-muted' : '' }}">
                      {{ $role->name }}
                      @if ($role->isRetired())<span class="badge muted plain">retired</span>@endif
                      @if ($role->is_automatic)<span class="badge info plain">automatic</span>@endif
                    </div>
                  </div>
                  @if ($role->retired_at)
                    <div class="cell-sub">Replaced {{ \App\Support\Wat::date($role->retired_at) }}</div>
                  @endif
                </td>
                <td>{{ $role->description }}</td>
                <td>{{ $role->defaultScopeType()->label() }}</td>
                <td class="num">{{ $role->permissions_count }} / {{ $permissionTotal }}</td>
                <td class="num">
                  @php($sensitive = $sensitiveByRole[$role->id] ?? 0)
                  @if ($sensitive > 0)
                    <span class="badge danger">{{ $sensitive }}</span>
                  @else
                    &mdash;
                  @endif
                </td>
                <td class="num">{{ $role->users_count }}</td>
                <td>
                  <span class="badge {{ [
                    'active' => 'success', 'draft' => 'warning',
                    'disabled' => 'muted', 'retired' => 'muted',
                  ][$role->status] ?? 'muted' }}">{{ ucfirst($role->status) }}</span>
                  @if ($role->hasUnvalidatedChanges())
                    <div class="cell-sub text-danger">untested change</div>
                  @endif
                </td>
                <td class="actions">
                  <a href="{{ route('admin.roles.show', $role) }}" class="btn btn-ghost btn-sm">
                    {{ $role->isRetired() ? 'View' : 'Edit' }}
                  </a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-body">
      <div class="text-small text-muted">
        A role with assigned users can be disabled but never deleted, and retired roles are kept so older
        audit entries still resolve. A draft role has no permissions and cannot be assigned.
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Permission Matrix</h3>
        <p>Every live permission against every active role. <code>&mdash;</code> means the action does not apply to that resource</p></div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="permission-table">
          <thead>
            <tr>
              <th>Module / Functionality</th>
              @foreach ($matrixRoles as $role)
                <th class="perm-check">{{ $role->name }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($matrix as $resourceKey => $permissions)
              @php($first = $permissions->first())
              <tr class="group-row">
                <td colspan="{{ $matrixRoles->count() + 1 }}">
                  {{ $first->label }}
                  <span class="perm-key">{{ $resourceKey }}</span>
                  @if ($first->is_sensitive)<span class="badge danger plain">sensitive</span>@endif
                </td>
              </tr>
              @foreach (\App\Authorization\PermissionKey::ACTIONS as $action)
                @php($permission = $permissions->firstWhere('action', $action))
                <tr>
                  <td>
                    <div class="{{ $permission ? '' : 'text-muted' }}">{{ ucfirst($action) }}</div>
                    @if ($permission)
                      <div class="cell-sub perm-key">{{ $resourceKey }}.{{ $action }}</div>
                    @endif
                  </td>
                  @foreach ($matrixRoles as $role)
                    <td class="perm-check">
                      @if ($permission === null)
                        <span class="text-muted">&mdash;</span>
                      @else
                        <input type="checkbox" disabled
                               @checked($role->permissions->contains('id', $permission->id)) />
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-body">
      <div class="text-small text-muted">
        This matrix is read-only. Open a role to change its permissions &mdash; each change is recorded with
        the grants before and after, and how many users it affects.
      </div>
    </div>
  </div>

  @if ($canCreate)
    <div id="modal-new-role" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Create Role</h3><p>Starts as a draft with no permissions</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.roles.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-role" />
          {{-- roles.html: the form validates inline and names the blocking field. --}}
          @include('partials.auth-errors')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-role'])
            <div class="field mb-16"><label for="nr-name">Role name <span class="req">*</span></label>
              <input type="text" id="nr-name" name="name" value="{{ old('name') }}" required />
              <div class="hint">Must be unique.</div></div>
            <div class="field mb-16"><label for="nr-description">Description</label>
              <textarea id="nr-description" name="description" rows="2">{{ old('description') }}</textarea></div>
            <div class="field"><label for="nr-scope">Default data scope <span class="req">*</span></label>
              <select id="nr-scope" name="scope_type" required>
                @foreach ($scopeTypes as $scopeType)
                  <option value="{{ $scopeType->value }}" @selected(old('scope_type') === $scopeType->value)>
                    {{ $scopeType->label() }}
                  </option>
                @endforeach
              </select>
              <div class="hint">
                Each assignment carries its own scope; this is the default the picker offers.
              </div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create role</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
