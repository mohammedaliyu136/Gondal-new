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
      <p>Configure dynamic approval journeys, roles, and authorization stages across all modules</p>
    </div>
    <div class="page-actions">
      <a href="#modal-new-workflow" class="btn btn-primary">+ New Workflow</a>
      <a href="{{ route('admin.settings') }}" class="btn btn-outline">Back to Settings</a>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Stages point at roles, not people.</strong>
      A stage assigns approval to a Role (e.g. &ldquo;Internal Audit&rdquo; or &ldquo;Factory Supervisor&rdquo;); whoever holds that role sees actionable items in
      <a href="{{ route('approvals.index') }}" class="text-primary">My Approvals</a>.
      Reassigning staff never breaks a workflow.
    </div>
  </div>

  <div class="tabs">
    <a href="{{ route('admin.settings') }}" class="tab">Milk &amp; Quality</a>
    <a href="{{ route('admin.settings') }}#locations" class="tab">Locations &amp; Routes</a>
    <a href="{{ route('admin.settings') }}#cooperatives" class="tab">Cooperatives</a>
    <a href="{{ route('admin.settings') }}#shop" class="tab">Shop &amp; Inventory</a>
    <a href="{{ route('admin.settings') }}#payments" class="tab">Payment Gateways</a>
    <span class="tab active">Approval Workflows</span>
    <a href="{{ route('admin.settings') }}#numbering" class="tab">Numbering</a>
  </div>

  {{-- Workflows Registry Table --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Workflows Registry</h3>
        <p>{{ $workflows->where('status', 'active')->count() }} active &middot;
           {{ $workflows->where('status', 'disabled')->count() }} disabled &mdash; click any row to configure its stages and options</p>
      </div>
      <a href="#modal-new-workflow" class="btn btn-ghost btn-sm">+ Create Workflow</a>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Workflow</th>
            <th>Applies to</th>
            <th class="num">Stages</th>
            <th>Conditions / Bands</th>
            <th class="num">In flight</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @foreach ($workflows as $workflow)
              @php($isSelected = $selected && $selected->id === $workflow->id)
              <tr style="{{ $isSelected ? 'background: #f0f7ff; font-weight: 500;' : '' }}">
                <td class="font-bold {{ $workflow->status === 'disabled' ? 'text-muted' : '' }}">
                  <a href="{{ route('admin.settings.workflows', ['workflow' => $workflow->id]) }}" class="text-primary" style="text-decoration:none">
                    {{ $workflow->name }}
                  </a>
                  <div class="cell-sub perm-key">{{ $workflow->code }}</div>
                </td>
                <td>
                  <span class="badge info plain">{{ \Illuminate\Support\Str::headline($workflow->applies_to) }}</span>
                  <div class="cell-sub">{{ $workflow->description ?: 'No description' }}</div>
                </td>
                <td class="num font-bold">{{ $workflow->stages->count() }}</td>
                <td>
                  @php($conditional = $workflow->stages->where('condition_type', '!=', 'always'))
                  @if ($workflow->bands->isNotEmpty())
                    <span class="badge warning">{{ $workflow->bands->count() }} amount band(s)</span>
                  @elseif ($conditional->isNotEmpty())
                    <span class="badge info">{{ $conditional->count() }} conditional stage(s)</span>
                  @else
                    <span class="text-muted text-small">Linear sequence</span>
                  @endif
                </td>
                <td class="num font-bold">{{ $inFlight[$workflow->id] ?? 0 }}</td>
                <td>
                  <span class="badge {{ $workflow->status === 'active' ? 'success' : 'muted' }}">
                    {{ ucfirst($workflow->status) }}
                  </span>
                </td>
                <td class="actions">
                  <a href="{{ route('admin.settings.workflows', ['workflow' => $workflow->id]) }}"
                     class="btn {{ $isSelected ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                    {{ $isSelected ? 'Selected' : 'Configure' }}
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
        Select any workflow above to view its configured route, add stages, assign approving roles, and adjust SLA options.
      </div>
    </div>
  </div>

  @if ($selected)
    <div class="split">
      <div class="stack">
        {{-- Stages Card --}}
        <div class="card">
          <div class="card-head">
            <div>
              <h3>{{ $selected->name }} &mdash; Approval Stages</h3>
              <p>{{ $selected->code }} &middot; the route every <code>{{ $selected->applies_to }}</code> follows in order</p>
            </div>
            <div class="flex" style="gap:8px;align-items:center">
              <span class="badge info">{{ $selected->stages->count() }} stages</span>
              <a href="#modal-add-stage" class="btn btn-primary btn-sm">+ Add Stage</a>
            </div>
          </div>

          @if ($selected->stages->isNotEmpty())
            <div class="card-body">
              <div class="flow mb-16">
                @foreach ($selected->stages as $stage)
                  <span class="step done">
                    <span class="step-num">{{ $stage->position }}</span>
                    {{ $stage->name }}
                    <span class="text-muted" style="font-size:0.75rem">({{ $stage->approvingRole?->name ?? 'Requester' }})</span>
                  </span>
                  @if (! $loop->last)<span class="arrow">&rsaquo;</span>@endif
                @endforeach
              </div>
            </div>
          @endif

          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr>
                  <th class="num">#</th>
                  <th>Stage Name</th>
                  <th>Approving Role</th>
                  <th>Applies When</th>
                  <th class="num">SLA</th>
                  <th class="perm-check">Can Reject</th>
                  <th class="actions">Actions</th>
                </tr></thead>
                <tbody>
                  @forelse ($selected->stages as $stage)
                    <tr>
                      <td class="num font-bold">{{ $stage->position }}</td>
                      <td>
                        <span class="font-bold">{{ $stage->name }}</span>
                        @if ($stage->hasStageAction() && $stage->stageActionHandler())
                          <div class="cell-sub"><span class="badge warning plain">&#9889; {{ $stage->stageActionHandler()->label() }}</span></div>
                        @endif
                        @if ($stage->is_submission)
                          <div class="cell-sub"><span class="badge info plain">Satisfied by submission</span></div>
                        @endif
                        @if ($stage->required_permission)
                          <div class="cell-sub perm-key">{{ $stage->required_permission }}</div>
                        @endif
                      </td>
                      <td>
                        @if ($stage->approvingRole)
                          <span class="badge success plain">{{ $stage->approvingRole->name }}</span>
                        @else
                          <span class="badge muted plain">Requester (Submitter)</span>
                        @endif
                      </td>
                      <td>{{ $stage->describeCondition() }}</td>
                      <td class="num">{{ $stage->sla_hours ? $stage->sla_hours.'h' : '—' }}</td>
                      <td class="perm-check">
                        @if ($stage->is_submission)
                          <span class="text-muted">&mdash;</span>
                        @else
                          <input type="checkbox" disabled @checked($stage->can_reject) />
                        @endif
                      </td>
                      <td class="actions">
                        <div class="flex" style="gap:4px;justify-content:flex-end">
                          <a href="#modal-edit-stage-{{ $stage->id }}" class="btn btn-ghost btn-sm">Edit</a>
                          <form method="POST" action="{{ route('admin.settings.workflows.stages.destroy', [$selected, $stage]) }}"
                                onsubmit="return confirm('Delete stage {{ $stage->name }}?');" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-danger" style="color:var(--danger)">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="7">
                        <div class="text-muted text-small" style="padding:20px;text-align:center">
                          No stages configured yet. Click "+ Add Stage" above to define the first approval step.
                        </div>
                      </td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {{-- Amount Bands Card --}}
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Amount Bands</h3>
              <p>Optional thresholds to route high or low value items through different approval stages</p>
            </div>
            <a href="#modal-add-band" class="btn btn-ghost btn-sm">+ Add Amount Band</a>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr>
                  <th>Band Name</th>
                  <th>Threshold Range</th>
                  <th>Routed Stages</th>
                  <th class="num">Stages Count</th>
                  <th class="actions">Actions</th>
                </tr></thead>
                <tbody>
                  @forelse ($selected->bands as $band)
                    <tr>
                      <td class="font-bold">{{ $band->name }}</td>
                      <td>{{ $band->describeRange() }}</td>
                      <td>{{ $band->stages->pluck('name')->implode(' → ') ?: 'All Stages' }}</td>
                      <td class="num font-bold">{{ $band->stages->count() }}</td>
                      <td class="actions">
                        <div class="flex" style="gap:4px;justify-content:flex-end">
                          <a href="#modal-edit-band-{{ $band->id }}" class="btn btn-ghost btn-sm">Edit</a>
                          <form method="POST" action="{{ route('admin.settings.workflows.bands.destroy', [$selected, $band]) }}"
                                onsubmit="return confirm('Delete band {{ $band->name }}?');" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-danger" style="color:var(--danger)">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="5">
                        <div class="text-muted text-small" style="padding:16px">
                          No amount bands configured. Every stage applies to all items unless a stage condition filters it.
                        </div>
                      </td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="stack">
        {{-- Workflow Behavior Options --}}
        <form method="POST" action="{{ route('admin.settings.workflows.update', $selected) }}">
          @csrf @method('PUT')
          <div class="card">
            <div class="card-head">
              <div>
                <h3>Workflow Options</h3>
                <p>Rules &amp; SLA configuration for {{ $selected->code }}</p>
              </div>
            </div>
            <div class="card-body">
              <div class="field mb-16">
                <label for="wf-name">Workflow Name <span class="req">*</span></label>
                <input type="text" id="wf-name" name="name" value="{{ $selected->name }}" required />
              </div>
              <div class="field mb-16">
                <label for="wf-desc">Description</label>
                <textarea id="wf-desc" name="description" rows="2">{{ $selected->description }}</textarea>
              </div>
              <div class="divider"></div>
              <div class="stack" style="gap:12px;margin-bottom:16px">
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
            <div class="modal-foot">
              <button type="submit" class="btn btn-primary">Save Workflow Options</button>
            </div>
          </div>
        </form>

        {{-- Who Holds Each Stage --}}
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Who Holds Each Stage</h3>
              <p>Active role holders who will receive approval items in /approvals</p>
            </div>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-ghost btn-sm">Manage Roles</a>
          </div>
          <div class="card-body">
            <div class="kpi-list">
              @foreach ($stageHolders as $holder)
                <div class="kpi-row">
                  <div class="kpi-ic" style="background:var(--primary-soft);color:var(--primary-dark)">
                    {{ $holder['stage']->position }}
                  </div>
                  <div class="grow">
                    <div class="kpi-name">{{ $holder['stage']->approvingRole?->name ?? 'Requester' }}</div>
                    <div class="text-muted text-small">
                      {{ $holder['users']->count() }} active user(s)
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
          </div>
        </div>
      </div>
    </div>
  @endif

  {{-- Modal: Create Workflow --}}
  <div id="modal-new-workflow" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog narrow">
      <div class="modal-head">
        <div><h3>Create Approval Workflow</h3><p>Define a new approval journey for any system module</p></div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('admin.settings.workflows.store') }}">
        @csrf
        <div class="modal-body">
          @include('partials.modal-errors', ['modal' => 'modal-new-workflow'])
          <div class="field mb-16">
            <label for="nw-code">Workflow Code <span class="req">*</span></label>
            <input type="text" id="nw-code" name="code" placeholder="e.g. WF-007 or MC-PAY" required maxlength="16" />
            <div class="hint">Unique identifier code for this workflow.</div>
          </div>
          <div class="field mb-16">
            <label for="nw-name">Workflow Name <span class="req">*</span></label>
            <input type="text" id="nw-name" name="name" placeholder="e.g. Milk Collection Payment Approval" required />
          </div>
          <div class="field mb-16">
            <label for="nw-applies">Applies To Module Key <span class="req">*</span></label>
            <input type="text" id="nw-applies" name="applies_to" placeholder="e.g. milk_collection_payment, farmer_payment, requisition" required maxlength="32" />
            <div class="hint">The key used by the calling module when calling WorkflowEngine::start().</div>
          </div>
          <div class="field mb-16">
            <label for="nw-desc">Description</label>
            <textarea id="nw-desc" name="description" rows="2" placeholder="Brief description of what this workflow approves"></textarea>
          </div>
          <div class="field mb-16">
            <label for="nw-status">Status <span class="req">*</span></label>
            <select id="nw-status" name="status" required>
              <option value="active" selected>Active</option>
              <option value="disabled">Disabled</option>
            </select>
          </div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary">Create Workflow</button>
        </div>
      </form>
    </div>
  </div>

  @if ($selected)
    {{-- Modal: Add Stage --}}
    <div id="modal-add-stage" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add Approval Stage</h3><p>{{ $selected->name }} ({{ $selected->code }})</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.settings.workflows.stages.store', $selected) }}">
          @csrf
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-add-stage'])
            <div class="form-grid">
              <div class="field">
                <label for="as-pos">Stage Position (#) <span class="req">*</span></label>
                <input type="number" id="as-pos" name="position" value="{{ ($selected->stages->max('position') ?? 0) + 1 }}" min="1" required />
                <div class="hint">Execution order (1, 2, 3...)</div>
              </div>
              <div class="field">
                <label for="as-name">Stage Name <span class="req">*</span></label>
                <input type="text" id="as-name" name="name" placeholder="e.g. Finance Review, Quality Inspection" required />
              </div>
              <div class="field full">
                <label for="as-role">Approving Role <span class="req">*</span></label>
                <select id="as-role" name="approving_role_id">
                  <option value="">-- Select Approving Role --</option>
                  @foreach ($roles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }} ({{ $role->users()->count() }} users)</option>
                  @endforeach
                </select>
                <div class="hint">Users assigned this role will see the item in /approvals when this stage is reached.</div>
              </div>
              <div class="field">
                <label for="as-cond-type">Condition Type <span class="req">*</span></label>
                <select id="as-cond-type" name="condition_type" required>
                  <option value="always">Always Applies</option>
                  <option value="amount_above">Amount Over (₦)</option>
                  <option value="department">Department Specific</option>
                  <option value="category">Category Specific</option>
                </select>
              </div>
              <div class="field">
                <label for="as-cond-val">Condition Value / Amount (₦)</label>
                <input type="text" id="as-cond-val" name="condition_value" placeholder="e.g. 500000 or Dept ID" />
              </div>
              <div class="field">
                <label for="as-sla">SLA (Hours)</label>
                <input type="number" id="as-sla" name="sla_hours" placeholder="e.g. 24 or 48" min="1" max="720" />
                <div class="hint">Target completion time in hours.</div>
              </div>
              <div class="field">
                <label for="as-perm">Optional Permission Key</label>
                <input type="text" id="as-perm" name="required_permission" placeholder="e.g. purchase.approve.audit" />
              </div>
              <div class="field full">
                <label for="as-stage-action">Stage Action / Sub-Event Trigger</label>
                <select id="as-stage-action" name="stage_action" style="width:100%; max-width:100%;"
                        onchange="updateStageActionCard(this, 'as-action-info-box', 'as-action-title', 'as-action-desc', 'as-action-applies')">
                  <option value="" data-description="Standard approval step without custom sub-events." data-applies="All Modules">
                    -- Standard Approval (No Sub-Event) --
                  </option>
                  @foreach ($availableActions as $actionHandler)
                    <option value="{{ $actionHandler->key() }}"
                            data-title="{{ $actionHandler->label() }}"
                            data-description="{{ $actionHandler->description() }}"
                            data-applies="{{ implode(', ', array_map(fn($t) => \Illuminate\Support\Str::headline($t), $actionHandler->appliesTo())) }}">
                      {{ $actionHandler->label() }}
                    </option>
                  @endforeach
                </select>
                <div id="as-action-info-box" style="display:none; margin-top:8px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 12px;">
                  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <strong id="as-action-title" style="color:#1e40af; font-size:12.5px;"></strong>
                    <span id="as-action-applies" class="badge info plain" style="font-size:11px;"></span>
                  </div>
                  <div id="as-action-desc" style="font-size:12px; color:#334155; line-height:1.4;"></div>
                </div>
              </div>
              <div class="field full">
                <div class="stack" style="gap:8px">
                  <label class="check-label">
                    <input type="checkbox" name="can_reject" value="1" checked />
                    Approver at this stage can reject the request
                  </label>
                  <label class="check-label">
                    <input type="checkbox" name="is_submission" value="1" />
                    This is a submission stage (satisfied automatically upon raising)
                  </label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Add Stage</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modals: Edit Stage --}}
    @foreach ($selected->stages as $stage)
      <div id="modal-edit-stage-{{ $stage->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Edit Stage: {{ $stage->name }}</h3><p>Position {{ $stage->position }} &middot; {{ $selected->name }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('admin.settings.workflows.stages.update', [$selected, $stage]) }}">
            @csrf @method('PUT')
            <div class="modal-body">
              @include('partials.modal-errors', ['modal' => 'modal-edit-stage-'.$stage->id])
              <div class="form-grid">
                <div class="field">
                  <label for="es-{{ $stage->id }}-pos">Stage Position (#) <span class="req">*</span></label>
                  <input type="number" id="es-{{ $stage->id }}-pos" name="position" value="{{ $stage->position }}" min="1" required />
                </div>
                <div class="field">
                  <label for="es-{{ $stage->id }}-name">Stage Name <span class="req">*</span></label>
                  <input type="text" id="es-{{ $stage->id }}-name" name="name" value="{{ $stage->name }}" required />
                </div>
                <div class="field full">
                  <label for="es-{{ $stage->id }}-role">Approving Role</label>
                  <select id="es-{{ $stage->id }}-role" name="approving_role_id">
                    <option value="">-- None / Submitter Stage --</option>
                    @foreach ($roles as $role)
                      <option value="{{ $role->id }}" @selected($stage->approving_role_id === $role->id)>
                        {{ $role->name }} ({{ $role->users()->count() }} users)
                      </option>
                    @endforeach
                  </select>
                </div>
                <div class="field">
                  <label for="es-{{ $stage->id }}-cond-type">Condition Type <span class="req">*</span></label>
                  <select id="es-{{ $stage->id }}-cond-type" name="condition_type" required>
                    <option value="always" @selected($stage->condition_type === 'always')>Always Applies</option>
                    <option value="amount_above" @selected($stage->condition_type === 'amount_above')>Amount Over (₦)</option>
                    <option value="department" @selected($stage->condition_type === 'department')>Department Specific</option>
                    <option value="category" @selected($stage->condition_type === 'category')>Category Specific</option>
                  </select>
                </div>
                <div class="field">
                  <label for="es-{{ $stage->id }}-cond-val">Condition Value</label>
                  <input type="text" id="es-{{ $stage->id }}-cond-val" name="condition_value"
                         value="{{ $stage->condition_type === 'amount_above' && $stage->condition_value ? \App\Support\Money::decimal((int)$stage->condition_value) : $stage->condition_value }}" />
                </div>
                <div class="field">
                  <label for="es-{{ $stage->id }}-sla">SLA (Hours)</label>
                  <input type="number" id="es-{{ $stage->id }}-sla" name="sla_hours" value="{{ $stage->sla_hours }}" min="1" max="720" />
                </div>
                <div class="field">
                  <label for="es-{{ $stage->id }}-perm">Optional Permission Key</label>
                  <input type="text" id="es-{{ $stage->id }}-perm" name="required_permission" value="{{ $stage->required_permission }}" />
                </div>
                <div class="field full">
                  <label for="es-{{ $stage->id }}-action">Stage Action / Sub-Event Trigger</label>
                  <select id="es-{{ $stage->id }}-action" name="stage_action" style="width:100%; max-width:100%;"
                          onchange="updateStageActionCard(this, 'es-{{ $stage->id }}-info-box', 'es-{{ $stage->id }}-title', 'es-{{ $stage->id }}-desc', 'es-{{ $stage->id }}-applies')">
                    <option value="" data-description="Standard approval step without custom sub-events." data-applies="All Modules">
                      -- Standard Approval (No Sub-Event) --
                    </option>
                    @foreach ($availableActions as $actionHandler)
                      <option value="{{ $actionHandler->key() }}"
                              data-title="{{ $actionHandler->label() }}"
                              data-description="{{ $actionHandler->description() }}"
                              data-applies="{{ implode(', ', array_map(fn($t) => \Illuminate\Support\Str::headline($t), $actionHandler->appliesTo())) }}"
                              @selected($stage->stage_action === $actionHandler->key())>
                        {{ $actionHandler->label() }}
                      </option>
                    @endforeach
                  </select>
                  @php($activeAction = $stage->stageActionHandler())
                  <div id="es-{{ $stage->id }}-info-box" style="{{ $activeAction ? 'display:block;' : 'display:none;' }} margin-top:8px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                      <strong id="es-{{ $stage->id }}-title" style="color:#1e40af; font-size:12.5px;">{{ $activeAction?->label() }}</strong>
                      <span id="es-{{ $stage->id }}-applies" class="badge info plain" style="font-size:11px;">
                        @if ($activeAction) Applies to: {{ implode(', ', array_map(fn($t) => \Illuminate\Support\Str::headline($t), $activeAction->appliesTo())) }} @endif
                      </span>
                    </div>
                    <div id="es-{{ $stage->id }}-desc" style="font-size:12px; color:#334155; line-height:1.4;">{{ $activeAction?->description() }}</div>
                  </div>
                </div>
                <div class="field full">
                  <div class="stack" style="gap:8px">
                    <label class="check-label">
                      <input type="checkbox" name="can_reject" value="1" @checked($stage->can_reject) />
                      Approver at this stage can reject the request
                    </label>
                    <label class="check-label">
                      <input type="checkbox" name="is_submission" value="1" @checked($stage->is_submission) />
                      This is a submission stage
                    </label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save Stage</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach

    {{-- Modal: Add Band --}}
    <div id="modal-add-band" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add Amount Band</h3><p>{{ $selected->name }} ({{ $selected->code }})</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.settings.workflows.bands.store', $selected) }}">
          @csrf
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-add-band'])
            <div class="field mb-16">
              <label for="ab-name">Band Name <span class="req">*</span></label>
              <input type="text" id="ab-name" name="name" placeholder="e.g. Standard Threshold, High Value Band" required />
            </div>
            <div class="form-grid mb-16">
              <div class="field">
                <label for="ab-from">Amount From (₦) <span class="req">*</span></label>
                <input type="text" id="ab-from" name="amount_from" placeholder="0.00" inputmode="decimal" required />
              </div>
              <div class="field">
                <label for="ab-to">Amount To (₦)</label>
                <input type="text" id="ab-to" name="amount_to" placeholder="Leave blank for unlimited" inputmode="decimal" />
              </div>
            </div>
            <div class="field">
              <label>Stages Included in this Band</label>
              <div class="stack mt-16" style="gap:8px">
                @foreach ($selected->stages as $stage)
                  <label class="check-label">
                    <input type="checkbox" name="stage_ids[]" value="{{ $stage->id }}" checked />
                    Stage {{ $stage->position }}: {{ $stage->name }} ({{ $stage->approvingRole?->name ?? 'Requester' }})
                  </label>
                @endforeach
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Band</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modals: Edit Band --}}
    @foreach ($selected->bands as $band)
      <div id="modal-edit-band-{{ $band->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Edit Amount Band</h3><p>{{ $band->name }} &middot; {{ $selected->name }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('admin.settings.workflows.bands.update', [$selected, $band]) }}">
            @csrf @method('PUT')
            <div class="modal-body">
              @include('partials.modal-errors', ['modal' => 'modal-edit-band-'.$band->id])
              <div class="field mb-16">
                <label for="eb-{{ $band->id }}-name">Band Name <span class="req">*</span></label>
                <input type="text" id="eb-{{ $band->id }}-name" name="name" value="{{ $band->name }}" required />
              </div>
              <div class="form-grid mb-16">
                <div class="field">
                  <label for="eb-{{ $band->id }}-from">Amount From (₦) <span class="req">*</span></label>
                  <input type="text" id="eb-{{ $band->id }}-from" name="amount_from"
                         value="{{ \App\Support\Money::decimal($band->amount_from_minor) }}" inputmode="decimal" required />
                </div>
                <div class="field">
                  <label for="eb-{{ $band->id }}-to">Amount To (₦)</label>
                  <input type="text" id="eb-{{ $band->id }}-to" name="amount_to"
                         value="{{ $band->amount_to_minor !== null ? \App\Support\Money::decimal($band->amount_to_minor) : '' }}" inputmode="decimal" />
                </div>
              </div>
              <div class="field">
                <label>Stages Included in this Band</label>
                <div class="stack mt-16" style="gap:8px">
                  @php($bandStageIds = $band->stages->pluck('id')->all())
                  @foreach ($selected->stages as $stage)
                    <label class="check-label">
                      <input type="checkbox" name="stage_ids[]" value="{{ $stage->id }}" @checked(in_array($stage->id, $bandStageIds, true)) />
                      Stage {{ $stage->position }}: {{ $stage->name }} ({{ $stage->approvingRole?->name ?? 'Requester' }})
                    </label>
                  @endforeach
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save Band</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  <script>
    window.updateStageActionCard = function(selectEl, boxId, titleId, descId, appliesId) {
      const box = document.getElementById(boxId);
      const titleEl = document.getElementById(titleId);
      const descEl = document.getElementById(descId);
      const appliesEl = document.getElementById(appliesId);
      if (!selectEl || !box) return;

      const opt = selectEl.options[selectEl.selectedIndex];
      if (!opt || !selectEl.value) {
        box.style.display = 'none';
        return;
      }

      if (titleEl) titleEl.textContent = opt.getAttribute('data-title') || opt.text;
      if (descEl) descEl.textContent = opt.getAttribute('data-description') || '';
      if (appliesEl) {
        const applies = opt.getAttribute('data-applies');
        appliesEl.textContent = applies ? 'Applies to: ' + applies : '';
        appliesEl.style.display = applies ? 'inline-flex' : 'none';
      }
      box.style.display = 'block';
    };
  </script>
@endsection
