@extends('layouts.app')
@section('title', 'Approval Workflows')

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('admin.settings') }}">Settings</a><span class="sep">/</span>
    <span class="here">Approval Workflows</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Approval Workflows</h1>
      <p>Who approves what, in what order</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('admin.settings') }}" class="btn btn-outline">Back to Settings</a>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Stages point at roles, not people.</strong>
      A stage says &ldquo;Internal Audit approves&rdquo;; whoever holds that role sees it in
      <a href="{{ route('approvals.index') }}" class="text-primary">My Approvals</a>.
      Reassigning staff therefore never breaks a workflow.
    </div>
  </div>

  <div class="tabs">
    <a href="{{ route('admin.settings') }}" class="tab">Milk &amp; Quality</a>
    <a href="{{ route('admin.settings') }}#locations" class="tab">Locations &amp; Routes</a>
    <a href="{{ route('admin.settings') }}#cooperatives" class="tab">Cooperatives</a>
    <a href="{{ route('admin.settings') }}#shop" class="tab">Shop &amp; Inventory</a>
    <span class="tab active">Approval Workflows</span>
    <a href="{{ route('admin.settings') }}#numbering" class="tab">Numbering</a>
  </div>

  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Workflows</h3>
        <p>{{ $workflows->where('status', 'active')->count() }} active &middot;
           {{ $workflows->where('status', 'disabled')->count() }} disabled</p></div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Workflow</th><th>Applies to</th><th class="num">Stages</th>
            <th>Conditions</th><th class="num">In flight</th><th>Status</th></tr></thead>
          <tbody>
            @foreach ($workflows as $workflow)
              <tr>
                <td class="font-bold {{ $workflow->status === 'disabled' ? 'text-muted' : '' }}">
                  {{ $workflow->name }}
                  <div class="cell-sub perm-key">{{ $workflow->code }}</div>
                </td>
                <td>{{ \Illuminate\Support\Str::headline($workflow->applies_to) }}
                  <div class="cell-sub">{{ $workflow->description }}</div></td>
                <td class="num">{{ $workflow->stages->count() }}</td>
                <td>
                  @php($conditional = $workflow->stages->where('condition_type', '!=', 'always'))
                  @if ($workflow->bands->isNotEmpty())
                    {{ $workflow->bands->count() }} amount band(s)
                  @elseif ($conditional->isNotEmpty())
                    {{ $conditional->count() }} conditional stage(s)
                  @else
                    None
                  @endif
                </td>
                <td class="num">{{ $inFlight[$workflow->id] ?? 0 }}</td>
                <td><span class="badge {{ $workflow->status === 'active' ? 'success' : 'muted' }}">
                  {{ ucfirst($workflow->status) }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-body">
      <div class="text-small text-muted">
        A workflow that is no longer used is disabled rather than deleted, so items it already approved keep
        the route they followed.
      </div>
    </div>
  </div>

  @if ($selected)
    <div class="split">
      <div class="stack">
        <div class="card">
          <div class="card-head">
            <div><h3>{{ $selected->name }} &mdash; Stages</h3>
              <p>{{ $selected->code }} &middot; the route every {{ $selected->applies_to }} follows, in order</p></div>
            <span class="badge info">{{ $selected->stages->count() }} stages</span>
          </div>
          <div class="card-body">
            <div class="flow mb-16">
              @foreach ($selected->stages as $stage)
                <span class="step done"><span class="step-num">{{ $stage->position }}</span> {{ $stage->name }}</span>
                @if (! $loop->last)<span class="arrow">&rsaquo;</span>@endif
              @endforeach
            </div>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th class="num">#</th><th>Stage</th><th>Approving role</th>
                  <th>Applies when</th><th class="num">SLA</th><th class="perm-check">Can reject</th>
                  <th>Permission</th></tr></thead>
                <tbody>
                  @foreach ($selected->stages as $stage)
                    <tr>
                      <td class="num font-bold">{{ $stage->position }}</td>
                      <td>{{ $stage->name }}
                        @if ($stage->is_submission)
                          <div class="cell-sub">satisfied by submitting</div>
                        @endif</td>
                      <td>{{ $stage->approvingRole?->name ?? 'Requester' }}</td>
                      <td>{{ $stage->describeCondition() }}</td>
                      <td class="num">{{ $stage->sla_hours ? $stage->sla_hours.'h' : '—' }}</td>
                      <td class="perm-check">
                        @if ($stage->is_submission)
                          <span class="text-muted">&mdash;</span>
                        @else
                          <input type="checkbox" disabled @checked($stage->can_reject) />
                        @endif
                      </td>
                      <td class="perm-key">{{ $stage->required_permission ?? '—' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <div><h3>Amount Bands</h3><p>The band a request falls into decides which stages apply</p></div>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Band</th><th>Amount</th><th>Route</th><th class="num">Stages</th></tr></thead>
                <tbody>
                  @forelse ($selected->bands as $band)
                    <tr>
                      <td class="font-bold">{{ $band->name }}</td>
                      <td>{{ $band->describeRange() }}</td>
                      <td>{{ $band->stages->pluck('name')->implode(' → ') }}</td>
                      <td class="num">{{ $band->stages->count() }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="4"><div class="text-muted text-small">
                      No bands: every stage applies, subject to its own condition.
                    </div></td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
          <div class="card-body">
            <div class="alert info">
              <span>&#8505;&#65039;</span>
              <div>
                A requisition&rsquo;s total picks its band at submission. Editing a band affects newly raised
                items only &mdash; anything in flight keeps the route it started on.
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="stack">
        <form method="POST" action="{{ route('admin.settings.workflows.update', $selected) }}">
          @csrf @method('PUT')
          <div class="card">
            <div class="card-head"><div><h3>Workflow Options</h3><p>Behaviour for {{ $selected->code }}</p></div></div>
            <div class="card-body">
              <div class="stack" style="gap:12px">
                <label class="check-label"><input type="checkbox" name="options[strict_sequence]" value="1"
                  @checked($selected->option('strict_sequence', true)) /> Stages run in strict sequence</label>
                <label class="check-label"><input type="checkbox" name="options[rejection_returns_to_requester]" value="1"
                  @checked($selected->option('rejection_returns_to_requester', true)) /> A rejection returns the item to the requester</label>
                <label class="check-label"><input type="checkbox" name="options[approver_may_reduce_amount]" value="1"
                  @checked($selected->option('approver_may_reduce_amount', true)) /> Approvers may reduce the approved amount</label>
                <label class="check-label"><input type="checkbox" name="options[allow_request_info]" value="1"
                  @checked($selected->option('allow_request_info', true)) /> Approvers may request more information</label>
                <label class="check-label"><input type="checkbox" name="options[allow_delegation]" value="1"
                  @checked($selected->option('allow_delegation', true)) /> Allow delegation while an approver is away</label>
                <label class="check-label"><input type="checkbox" name="options[auto_escalate_on_sla]" value="1"
                  @checked($selected->option('auto_escalate_on_sla', false)) /> Auto-escalate when the SLA lapses</label>
                {{-- BR-18 is a rule, not an option. --}}
                <label class="check-label"><input type="checkbox" checked disabled />
                  The requester may not approve their own request
                  <span class="text-muted">&mdash; always enforced</span></label>
              </div>
              <div class="divider"></div>
              <div class="field">
                <label for="wf-reminder">Overdue reminder <span class="req">*</span></label>
                <select id="wf-reminder" name="overdue_reminder" required>
                  @foreach ([
                    'daily' => 'Daily after the SLA lapses',
                    'twelve_hourly' => 'Every 12 hours',
                    'once' => 'Once, at the SLA',
                    'never' => 'Never',
                  ] as $value => $label)
                    <option value="{{ $value }}" @selected($selected->option('overdue_reminder', 'daily') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
                <div class="hint">Reminders start once the stage SLA has lapsed.</div>
              </div>
              <div class="field mt-16">
                <label for="wf-status">Status <span class="req">*</span></label>
                <select id="wf-status" name="status" required>
                  <option value="active" @selected($selected->status === 'active')>Active</option>
                  <option value="disabled" @selected($selected->status === 'disabled')>Disabled</option>
                </select>
              </div>
            </div>
            <div class="modal-foot"><button type="submit" class="btn btn-primary">Save options</button></div>
          </div>
        </form>

        <div class="card">
          <div class="card-head">
            <div><h3>Who Holds Each Stage</h3><p>Taken from who holds each role today</p></div>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost btn-sm">Roles</a>
          </div>
          <div class="card-body">
            <div class="kpi-list">
              @foreach ($stageHolders as $holder)
                <div class="kpi-row">
                  <div class="kpi-ic" style="background:var(--primary-soft);color:var(--primary-dark)">
                    {{ $holder['stage']->position }}
                  </div>
                  <div class="grow">
                    <div class="kpi-name">{{ $holder['stage']->approvingRole?->name }}</div>
                    <div class="text-muted text-small">
                      {{ $holder['users']->count() }} user(s)
                      @if ($holder['delegations']->isNotEmpty())
                        &middot; {{ $holder['delegations']->count() }} delegation(s) active
                      @endif
                      @if ($holder['users']->isNotEmpty())
                        &middot; {{ $holder['users']->pluck('name')->take(2)->implode(', ') }}
                      @endif
                    </div>
                  </div>
                  @if ($holder['users']->isEmpty())
                    <span class="badge danger">Unstaffed</span>
                  @elseif ($holder['users']->count() === 1 && $holder['delegations']->isEmpty())
                    <span class="badge warning">Single point</span>
                  @else
                    <span class="badge success">Staffed</span>
                  @endif
                </div>
              @endforeach
            </div>

            @php($single = $stageHolders->filter(fn ($h) => $h['users']->count() === 1 && $h['delegations']->isEmpty()))
            @if ($single->isNotEmpty())
              <div class="alert warn mt-16">
                <span>&#9888;&#65039;</span>
                <div>
                  {{ $single->count() }} stage(s) have exactly one holder and no delegate &mdash;
                  approvals stall when they are away. Name a delegate to cover them.
                </div>
              </div>
            @endif

            @php($unstaffed = $stageHolders->filter(fn ($h) => $h['users']->isEmpty()))
            @if ($unstaffed->isNotEmpty())
              <div class="alert danger mt-16">
                <span>&#10060;</span>
                <div>
                  {{ $unstaffed->count() }} stage(s) have nobody holding the role, so anything reaching them
                  cannot proceed. Assign the role before this workflow is used.
                </div>
              </div>
            @endif
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <div><h3>Recent Settings Changes</h3><p>From the audit log</p></div>
            @can('admin.audit.view')
              <a href="{{ route('admin.audit-log', ['module' => 'Settings']) }}" class="btn btn-ghost btn-sm">Audit log</a>
            @endcan
          </div>
          <div class="card-body">
            <div class="timeline">
              @forelse ($recentChanges as $change)
                <div class="tl-item">
                  <div class="tl-title">{{ $change->summary }}</div>
                  <div class="tl-sub">{{ $change->actor_label }}</div>
                  <div class="tl-time">{{ \App\Support\Wat::relative($change->occurred_at) }}</div>
                </div>
              @empty
                <div class="text-muted text-small">No settings changes recorded yet.</div>
              @endforelse
            </div>
          </div>
        </div>
      </div>
    </div>
  @endif
@endsection
