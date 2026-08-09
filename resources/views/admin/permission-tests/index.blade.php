@extends('layouts.app')
@section('title', 'Permission Testing')

@section('content')
  <div class="page-head">
    <div>
      <h1>Permission Testing</h1>
      <p>Validate a role with a test user before changes reach live staff accounts</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('admin.roles.index') }}" class="btn btn-outline">Roles</a>
      <a href="#modal-new-test" class="btn btn-primary">+ New Test Run</a>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#129514;</span>
    <div>
      <strong>How a test run works.</strong>
      Create a test user, assign the role you are changing, and confirm they reach only the areas you intend.
      A run can target development or staging only, never live data.
    </div>
  </div>

  @if ($current)
    <div class="grid grid-4 mb-16">
      <div class="stat green"><div class="stat-ic">&#9989;</div><div class="stat-label">Checks Passed</div>
        <div class="stat-value">{{ $current->passed_count }}</div>
        <div class="stat-foot">of {{ $current->passed_count + $current->failed_count }} in {{ $current->reference }}</div></div>
      <div class="stat red"><div class="stat-ic">&#10060;</div><div class="stat-label">Checks Failed</div>
        <div class="stat-value">{{ $current->failed_count }}</div>
        <div class="stat-foot">{{ $current->failed_count === 0 ? 'nothing to resolve' : 'must be resolved before going live' }}</div></div>
      <div class="stat blue"><div class="stat-ic">&#128100;</div><div class="stat-label">Test Users</div>
        <div class="stat-value">{{ $testUserCount }}</div>
        <div class="stat-foot">never used on live data</div></div>
      <div class="stat amber"><div class="stat-ic">&#128336;</div><div class="stat-label">Last Run</div>
        <div class="stat-value">{{ \App\Support\Wat::time($current->started_at) }}</div>
        <div class="stat-foot">by {{ $current->runBy?->name }}</div></div>
    </div>

    <div class="card mb-16">
      <div class="card-head">
        <div>
          <h3>Current Test Run &mdash; {{ $current->reference }}</h3>
          <p>Role <strong>{{ $current->role?->name }}</strong> as
             <strong>{{ $current->testUser?->email }}</strong> &middot;
             started {{ \App\Support\Wat::relative($current->started_at) }}</p>
        </div>
        <span class="badge {{ $current->failed_count === 0 && $current->completed_at ? 'success' : ($current->failed_count > 0 ? 'warning' : 'info') }}">
          {{ $current->completed_at === null ? 'Not run yet' : ($current->failed_count === 0 ? 'All checks passed' : $current->failed_count.' issues found') }}
        </span>
      </div>
      <div class="card-body">
        <div class="meta-grid cols-4">
          <div class="meta-item"><div class="meta-label">Test User</div>
            <div class="meta-value">{{ $current->testUser?->name }}</div>
            <div class="cell-sub">{{ $current->testUser?->email }}</div></div>
          <div class="meta-item"><div class="meta-label">Role Under Test</div>
            <div class="meta-value">
              <a href="{{ route('admin.roles.show', $current->role) }}" class="text-primary">{{ $current->role?->name }}</a>
            </div></div>
          <div class="meta-item"><div class="meta-label">Simulated Scope</div>
            <div class="meta-value">{{ \Illuminate\Support\Str::headline((string) $current->scope_type) }}</div>
            @if ($current->scope_target_id)
              <div class="cell-sub">target #{{ $current->scope_target_id }}</div>
            @endif</div>
          <div class="meta-item"><div class="meta-label">Environment</div>
            <div class="meta-value"><span class="badge info">{{ ucfirst($current->environment) }}</span></div>
            <div class="cell-sub">development or staging only</div></div>
        </div>
        <div class="divider"></div>
        <div class="flex flex-wrap">
          <form method="POST" action="{{ route('admin.permission-tests.run', $current) }}">
            @csrf
            <button type="submit" class="btn btn-primary">
              {{ $current->completed_at === null ? 'Run all checks' : 'Re-run all checks' }}
            </button>
          </form>
          @if ($current->hasPassed() && $current->status !== 'approved_for_live')
            <form method="POST" action="{{ route('admin.permission-tests.complete', $current) }}">
              @csrf
              <button type="submit" class="btn btn-outline">Approve for live</button>
            </form>
          @endif
        </div>
        <div class="hint mt-16">
          Running the checks applies the simulated assignment to the test user, replacing anything they held,
          so the run measures this role and nothing else.
        </div>
      </div>
    </div>

    <div class="split">
      <div class="stack">
        <div class="card">
          <div class="card-head">
            <div><h3>Access Verification</h3>
              <p>Expected access compared with what the test user actually reached</p></div>
            <div class="flex">
              <span class="pill green">{{ $current->passed_count }} pass</span>
              <span class="pill" style="background:var(--danger-soft);color:var(--danger)">{{ $current->failed_count }} fail</span>
            </div>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Area</th><th>Expected</th><th>Actual</th><th>Result</th><th>Note</th></tr></thead>
                <tbody>
                  @php($lastModule = null)
                  @forelse ($checks as $check)
                    @if ($check->module !== $lastModule)
                      <tr class="group-row"><td colspan="5">{{ $check->module ?? 'Other' }}</td></tr>
                      @php($lastModule = $check->module)
                    @endif
                    <tr>
                      <td>{{ $check->area }}
                        @if ($check->permission_key)
                          <div class="cell-sub perm-key">{{ $check->permission_key }}</div>
                        @endif
                        @if ($check->is_scope_probe)
                          <div class="cell-sub"><span class="badge info plain">scope probe</span></div>
                        @endif
                      </td>
                      <td>{{ $check->describeExpected() }}</td>
                      <td class="{{ $check->passed === false ? 'text-danger font-bold' : '' }}">{{ $check->describeActual() }}</td>
                      <td>
                        @if ($check->passed === null)
                          <span class="badge muted">Not run</span>
                        @else
                          <span class="badge {{ $check->passed ? 'success' : 'danger' }}">
                            {{ $check->passed ? 'Pass' : 'Fail' }}</span>
                        @endif
                      </td>
                      <td class="text-small text-muted">{{ $check->note ?? '' }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="5">@include('partials.empty', [
                      'title' => 'No checks recorded yet',
                      'message' => 'Run the checks to compare expected access against actual.',
                      'icon' => '&#129514;',
                    ])</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
          @if ($checks instanceof \Illuminate\Contracts\Pagination\Paginator)
            @include('partials.pagination', ['paginator' => $checks, 'noun' => 'checks'])
          @endif
        </div>

        <div class="card">
          <div class="card-head"><div><h3>Previous Test Runs</h3><p>All validation attempts</p></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Run</th><th>Role</th><th>Test user</th><th>Run by</th>
                  <th>Date</th><th class="num">Pass / Fail</th><th>Outcome</th></tr></thead>
                <tbody>
                  @foreach ($runs as $run)
                    <tr>
                      <td class="perm-key">{{ $run->reference }}</td>
                      <td>{{ $run->role?->name }}</td>
                      <td>{{ \Illuminate\Support\Str::before($run->testUser?->email ?? '', '@') }}</td>
                      <td>{{ $run->runBy?->name }}</td>
                      <td>{{ \App\Support\Wat::date($run->started_at) }}</td>
                      <td class="num">{{ $run->passed_count }} / {{ $run->failed_count }}</td>
                      <td><span class="badge {{ [
                        'passed' => 'success', 'approved_for_live' => 'success',
                        'failed' => 'danger', 'rejected' => 'danger',
                        'in_progress' => 'warning', 'abandoned' => 'muted',
                      ][$run->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($run->status) }}</span></td>
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
          <div class="card-head"><div><h3>Navigation the Test User Sees</h3>
            <p>Built from the permissions this role grants</p></div></div>
          <div class="card-body">
            @php($visible = collect($navigationPreview)->pluck('label')->all())
            <div class="chip-group" style="flex-direction:column;align-items:stretch">
              @foreach ($allNavigation as $item)
                @php($isVisible = in_array($item['label'], $visible, true))
                <span class="chip {{ $isVisible ? 'on' : 'off' }}">
                  {{ $item['label'] }}{{ $isVisible ? '' : ' — hidden' }}
                </span>
                @if ($isVisible && ($item['type'] ?? 'link') === 'group')
                  @php($group = collect($navigationPreview)->firstWhere('label', $item['label']))
                  @foreach ($item['children'] as $child)
                    @php($childVisible = collect($group['children'] ?? [])->pluck('label')->contains($child['label']))
                    <span class="chip {{ $childVisible ? 'on' : 'off' }}" style="margin-left:16px">
                      {{ $child['label'] }}{{ $childVisible ? '' : ' — hidden' }}
                    </span>
                  @endforeach
                @endif
              @endforeach
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>Issues to Resolve</h3><p>Before applying to live users</p></div></div>
          <div class="card-body">
            @php($failures = $checksByModule->flatten()->where('passed', false))
            @forelse ($failures->take(8) as $failure)
              <div class="queue-item">
                <div class="qi-ic" style="background:var(--danger-soft);color:var(--danger)">{{ $loop->iteration }}</div>
                <div>
                  <div class="qi-title">{{ $failure->area }}</div>
                  <div class="qi-sub">
                    {{ $failure->note }}
                    @if ($failure->permission_key)
                      <span class="perm-key">{{ $failure->permission_key }}</span>
                    @endif
                  </div>
                </div>
                <div class="qi-right">
                  <a href="{{ route('admin.roles.show', $current->role) }}" class="btn btn-danger btn-sm">Fix</a>
                </div>
              </div>
            @empty
              @include('partials.empty', [
                'title' => 'Nothing to resolve',
                'message' => $current->completed_at ? 'Every check matched the expectation.' : 'Run the checks first.',
                'icon' => '&#9989;',
              ])
            @endforelse
          </div>
        </div>
      </div>
    </div>
  @else
    <div class="card">
      <div class="card-body">
        @include('partials.empty', [
          'title' => 'No test runs yet',
          'message' => 'Start one to compare a role’s intended access against what it actually grants.',
          'icon' => '&#129514;',
        ])
      </div>
    </div>
  @endif

  <div id="modal-new-test" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog">
      <div class="modal-head">
        <div><h3>New Test Run</h3><p>One role per scenario</p></div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('admin.permission-tests.store') }}">
        @csrf
          <input type="hidden" name="_modal" value="modal-new-test" />
        @include('partials.auth-errors')
        <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-test'])
          <div class="form-grid">
            <div class="field"><label for="nt-role">Role under test <span class="req">*</span></label>
              <select id="nt-role" name="role_id" required>
                @foreach ($roles as $role)
                  <option value="{{ $role->id }}">
                    {{ $role->name }} ({{ $role->livePermissions()->count() }} permissions)
                  </option>
                @endforeach
              </select></div>
            <div class="field"><label for="nt-user">Test user <span class="req">*</span></label>
              <select id="nt-user" name="test_user_id" required>
                @forelse ($testUsers as $testUser)
                  <option value="{{ $testUser->id }}">{{ $testUser->name }} — {{ $testUser->email }}</option>
                @empty
                  <option value="">No test accounts exist yet</option>
                @endforelse
              </select>
              <div class="hint">
                Only accounts flagged as test users may be targeted. Create one under Users.
              </div></div>
            <div class="field"><label for="nt-scope">Simulated scope <span class="req">*</span></label>
              <select id="nt-scope" name="scope_type" required>
                @foreach ($scopeTypes as $scopeType)
                  <option value="{{ $scopeType->value }}">{{ $scopeType->label() }}</option>
                @endforeach
              </select></div>
            <div class="field"><label for="nt-target">Scope target</label>
              <select id="nt-target" name="scope_target_id">
                <option value="">None</option>
                @foreach ($centers as $center)
                  <option value="{{ $center->id }}">{{ $center->name }} (center)</option>
                @endforeach
              </select>
              <div class="hint">
                Choosing a center adds two more checks: one inside that scope and one outside it, so the run
                also proves what the role cannot reach.
              </div></div>
            <div class="field"><label for="nt-env">Environment <span class="req">*</span></label>
              <select id="nt-env" name="environment" required>
                @foreach ($environments as $environment)
                  <option value="{{ $environment }}">{{ ucfirst($environment) }}</option>
                @endforeach
              </select>
              <div class="hint">Production is deliberately not on this list.</div></div>
            <div class="field full"><label for="nt-notes">Notes</label>
              <textarea id="nt-notes" name="notes" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary">Start test run</button>
        </div>
      </form>
    </div>
  </div>
@endsection
