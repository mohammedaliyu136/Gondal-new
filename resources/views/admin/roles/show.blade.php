@extends('layouts.app')
@section('title', $role->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('admin.roles.index') }}">Roles &amp; Permissions</a><span class="sep">/</span>
    <span class="here">{{ $role->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($role->name, 0, 2) }}</div>
    <div class="dh-main">
      <h1>{{ $role->name }}</h1>
      <div class="dh-sub">{{ $role->description }}</div>
      <div class="dh-tags">
        <span class="badge {{ ['active' => 'success', 'draft' => 'warning', 'disabled' => 'muted', 'retired' => 'muted'][$role->status] ?? 'muted' }}">
          {{ ucfirst($role->status) }}</span>
        <span class="pill">{{ $role->defaultScopeType()->label() }}</span>
        <span class="pill">{{ $role->permissions->count() }} permissions</span>
        @if ($sensitiveCount > 0)
          <span class="badge danger">{{ $sensitiveCount }} sensitive</span>
        @endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canEdit)<a href="#modal-edit-role" class="btn btn-outline">Role settings</a>@endif
      @if ($canDisable && ! $role->is_automatic && $role->status !== 'disabled')
        <form method="POST" action="{{ route('admin.roles.disable', $role) }}">
          @csrf
          <button type="submit" class="btn btn-ghost text-danger">Disable</button>
        </form>
      @endif
    </div>
  </div>

  @if ($role->isDraft())
    <div class="alert warn mb-16">
      <span>&#10067;</span>
      <div>
        <strong>This role is a draft and cannot be assigned.</strong>
        Grant its permissions and check them with a test run, then activate it.
      </div>
    </div>
  @endif

  @if ($sensitiveCount > 0)
    {{-- PERM-2 --}}
    <div class="alert danger mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>Sensitive Access: {{ $sensitiveCount }} permission(s).</strong>
        This role can see figures that are kept away from operational staff &mdash;
        @foreach ($role->permissions->where('is_sensitive', true)->pluck('resource_key')->unique() as $key)
          <span class="perm-key">{{ $key }}</span>@if (! $loop->last), @endif
        @endforeach.
        Granting any of these should be deliberate.
      </div>
    </div>
  @endif

  @if ($needsTestRun && $liveUserCount > 0)
    {{-- TEST-5 --}}
    <div class="alert warn mb-16">
      <span>&#129514;</span>
      <div>
        <strong>{{ $liveUserCount }} live user(s) hold this role and its current permissions have not passed a test run.</strong>
        Run one in <a href="{{ route('admin.permission-tests.index') }}" class="text-primary">Permission Testing</a>
        before this reaches staff. Saving anyway is allowed, and the override is recorded in the audit log.
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <form method="POST" action="{{ route('admin.roles.permissions', $role) }}">
        @csrf @method('PUT')
        <div class="card">
          <div class="card-head">
            <div><h3>Permission Matrix</h3>
              <p>Tick to grant. <code>&mdash;</code> means the action does not apply to that resource</p></div>
            @if ($canEdit)
              <button type="submit" class="btn btn-primary btn-sm">Save permissions</button>
            @else
              <span class="badge muted">read only</span>
            @endif
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table class="permission-table">
                <thead>
                  <tr>
                    <th>Resource</th>
                    @foreach (\App\Authorization\PermissionKey::ACTIONS as $action)
                      <th class="perm-check">{{ ucfirst($action) }}</th>
                    @endforeach
                  </tr>
                </thead>
                <tbody>
                  @foreach ($matrix as $resourceKey => $permissions)
                    @php($first = $permissions->first())
                    <tr>
                      <td>
                        <div class="font-bold">{{ $first->label }}</div>
                        <div class="cell-sub perm-key">{{ $resourceKey }}</div>
                        @if ($first->is_sensitive)
                          <div class="cell-sub"><span class="badge danger plain">sensitive</span></div>
                        @endif
                        @if ($first->description)
                          <div class="cell-sub">{{ $first->description }}</div>
                        @endif
                      </td>
                      @foreach (\App\Authorization\PermissionKey::ACTIONS as $action)
                        @php($permission = $permissions->firstWhere('action', $action))
                        <td class="perm-check">
                          @if ($permission === null)
                            <span class="text-muted">&mdash;</span>
                          @else
                            <label class="sr-only" for="perm-{{ $permission->id }}"
                                   style="position:absolute;left:-9999px">{{ $resourceKey }}.{{ $action }}</label>
                            <input type="checkbox" id="perm-{{ $permission->id }}"
                                   name="permission_ids[]" value="{{ $permission->id }}"
                                   @checked(in_array($permission->id, $grantedIds, true))
                                   @disabled(! $canEdit) />
                          @endif
                        </td>
                      @endforeach
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>

          @if ($canEdit && $liveUserCount > 0)
            <div class="card-body">
              <div class="field">
                <label for="override">Override reason</label>
                <input type="text" id="override" name="override_reason"
                       placeholder="Only needed if you are saving without a passing test run" />
                <div class="hint">
                  Saving with {{ $liveUserCount }} live user(s) and no passing run is permitted, and the
                  override is written to the audit log with the before and after grant sets.
                </div>
              </div>
            </div>
          @endif

          @if ($canEdit)
            <div class="action-bar">
              <div class="ab-note">
                Changes apply on each holder&rsquo;s next page load. Retired permissions can never be granted.
              </div>
              <button type="submit" class="btn btn-primary">Save permissions</button>
            </div>
          @endif
        </div>
      </form>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Assigned Users</h3><p>Each with their own data scope</p></div></div>
        <div class="card-body">
          @forelse ($assignments as $assignment)
            <div class="queue-item">
              <div class="qi-ic">{{ $assignment->user?->initials() }}</div>
              <div>
                <div class="qi-title">
                  @can('admin.users.view')
                    <a href="{{ route('admin.users.show', $assignment->user) }}">{{ $assignment->user?->name }}</a>
                  @else
                    {{ $assignment->user?->name }}
                  @endcan
                </div>
                <div class="qi-sub">{{ $assignment->describeScope() }}</div>
              </div>
              <div class="qi-right">
                @if ($assignment->user?->is_test)
                  <span class="badge muted">test</span>
                @else
                  <span class="badge success">live</span>
                @endif
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'No users hold this role', 'icon' => '&#128101;'])
          @endforelse
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Test Runs</h3><p>A passing run validates the permissions as they stand</p></div>
          @can('admin.roles.edit')
            <a href="{{ route('admin.permission-tests.index') }}" class="btn btn-ghost btn-sm">Run a test</a>
          @endcan
        </div>
        <div class="card-body">
          @forelse ($recentRuns as $run)
            <div class="queue-item">
              <div class="qi-ic">&#129514;</div>
              <div>
                <div class="qi-title">{{ $run->reference }}</div>
                <div class="qi-sub">
                  {{ $run->testUser?->email }} &middot; {{ $run->environment }} &middot;
                  {{ $run->passed_count }} pass / {{ $run->failed_count }} fail
                </div>
                <div class="tl-time">{{ \App\Support\Wat::relative($run->started_at) }}</div>
              </div>
              <div class="qi-right">
                <span class="badge {{ [
                  'passed' => 'success', 'approved_for_live' => 'success',
                  'failed' => 'danger', 'rejected' => 'danger', 'in_progress' => 'warning',
                ][$run->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($run->status) }}</span>
              </div>
            </div>
          @empty
            @include('partials.empty', [
              'title' => 'Never tested',
              'message' => 'Nothing has validated this role against a test user yet.',
              'icon' => '&#129514;',
            ])
          @endforelse
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Sensitive Permissions</h3><p>Kept away from operational roles</p></div></div>
        <div class="card-body">
          @foreach ($sensitivePermissions->groupBy('resource_key') as $resourceKey => $group)
            <div class="queue-item">
              <div class="qi-ic" style="background:var(--danger-soft);color:var(--danger)">!</div>
              <div>
                <div class="qi-title perm-key">{{ $resourceKey }}</div>
                <div class="qi-sub">{{ $group->first()->label }}</div>
              </div>
              <div class="qi-right">
                @php($held = $group->pluck('id')->intersect($grantedIds)->count())
                <span class="badge {{ $held > 0 ? 'danger' : 'muted' }}">
                  {{ $held > 0 ? $held.' granted' : 'not granted' }}
                </span>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>

  @if ($canEdit)
    <div id="modal-edit-role" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Role Settings</h3><p>{{ $role->name }}</p></div>
          <a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('admin.roles.update', $role) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-role" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-role'])
            <div class="field mb-16"><label for="er-name">Name <span class="req">*</span></label>
              <input type="text" id="er-name" name="name" value="{{ $role->name }}" required /></div>
            <div class="field mb-16"><label for="er-description">Description</label>
              <textarea id="er-description" name="description" rows="2">{{ $role->description }}</textarea></div>
            <div class="field mb-16"><label for="er-scope">Default data scope <span class="req">*</span></label>
              <select id="er-scope" name="scope_type" required>
                @foreach ($scopeTypes as $scopeType)
                  <option value="{{ $scopeType->value }}" @selected($role->scope_type === $scopeType->value)>
                    {{ $scopeType->label() }}
                  </option>
                @endforeach
              </select></div>
            <div class="field"><label for="er-status">Status <span class="req">*</span></label>
              <select id="er-status" name="status" required>
                @foreach (['active' => 'Active', 'draft' => 'Draft', 'disabled' => 'Disabled', 'retired' => 'Retired'] as $value => $label)
                  <option value="{{ $value }}" @selected($role->status === $value)>{{ $label }}</option>
                @endforeach
              </select>
              <div class="hint">A role with no permissions cannot be activated &mdash; it would grant nothing.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save role</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
