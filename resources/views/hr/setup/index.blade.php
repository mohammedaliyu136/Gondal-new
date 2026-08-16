@extends('layouts.app')
@section('title', 'HR Setup Hub & Policies')

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('employees.index') }}">Human Resources</a><span class="sep">/</span>
    <span class="here">HR Setup Hub</span>
  </div>

  <div class="page-head">
    <div>
      <h1>HR Master Setup &amp; Policy Hub</h1>
      <p>Configure company loan schemes, performance commission milestones, leave entitlement policies, allowances, and departments.</p>
    </div>
    <div class="page-actions">
      @if ($canEdit)
        @if ($activeTab === 'leave_types')
          <a href="#modal-add-leave-type" class="btn btn-primary">
            <span>+</span> Add Leave Policy Type
          </a>
        @elseif ($activeTab === 'departments')
          <a href="{{ route('departments.index') }}" class="btn btn-primary">
            <span>+</span> Manage Departments
          </a>
        @else
          <a href="#modal-add-type" class="btn btn-primary">
            <span>+</span> Add Scheme / Master Type
          </a>
        @endif
      @endif
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Company HR Master Policies &amp; Schemes.</strong>
      These reference schemes define loan tenor rules, performance commission milestones, leave entitlement quotas, and allowance calculations across all employee profiles and payroll runs.
    </div>
  </div>

  {{-- Master Tab Bar Navigation (consistent with admin/settings) --}}
  <div class="tabs">
    <a href="{{ route('hr.setup.index', ['tab' => 'loans']) }}" class="tab @if ($activeTab === 'loans' || $activeTab === 'loan') active @endif">
      Staff Loan Schemes ({{ $types->get('loan', collect())->count() }})
    </a>
    <a href="{{ route('hr.setup.index', ['tab' => 'commissions']) }}" class="tab @if ($activeTab === 'commissions' || $activeTab === 'commission') active @endif">
      Commission Milestones ({{ $types->get('commission', collect())->count() }})
    </a>
    <a href="{{ route('hr.setup.index', ['tab' => 'allowances']) }}" class="tab @if ($activeTab === 'allowances' || $activeTab === 'allowance') active @endif">
      Allowances &amp; Overtime ({{ $types->get('allowance', collect())->count() + $types->get('overtime', collect())->count() }})
    </a>
    <a href="{{ route('hr.setup.index', ['tab' => 'leave_types']) }}" class="tab @if ($activeTab === 'leave_types') active @endif">
      Leave Entitlements ({{ $leaveTypes->count() }})
    </a>
    <a href="{{ route('hr.setup.index', ['tab' => 'deductions']) }}" class="tab @if ($activeTab === 'deductions' || $activeTab === 'deduction') active @endif">
      Deduction Schemes ({{ $types->get('deduction', collect())->count() }})
    </a>
    <a href="{{ route('hr.setup.index', ['tab' => 'departments']) }}" class="tab @if ($activeTab === 'departments') active @endif">
      Departments ({{ $departments->count() }})
    </a>
  </div>

  {{-- Tab Content 1: Leave Policies & Entitlements --}}
  @if ($activeTab === 'leave_types')
    <div class="card flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Leave Scheme / Policy</th>
              <th class="num">Annual Entitlement</th>
              <th>Documentation Rule</th>
              <th>Status</th>
              @if ($canEdit)
                <th class="actions">Actions</th>
              @endif
            </tr>
          </thead>
          <tbody>
            @forelse ($leaveTypes as $lt)
              <tr>
                <td class="perm-key font-bold">
                  <span class="badge info plain" style="font-size:0.7rem;padding:2px 6px">LEAVE</span>
                  {{ $lt->code }}
                </td>
                <td>
                  <strong>{{ $lt->name }}</strong>
                  <div class="cell-sub">Company standard absence entitlement</div>
                </td>
                <td class="num font-bold">
                  @if ($lt->annual_entitlement_days > 0)
                    <span style="color:var(--primary-dark)">{{ $lt->annual_entitlement_days }} days / yr</span>
                  @else
                    <span class="text-muted">Unlimited / Case-by-case</span>
                  @endif
                </td>
                <td>
                  @if ($lt->requires_document)
                    <span class="badge warning plain">Medical/Proof Required</span>
                  @else
                    <span class="badge success plain">Self-service</span>
                  @endif
                </td>
                <td>
                  @if ($lt->status === 'active')
                    <span class="badge success">Active</span>
                  @else
                    <span class="badge muted">Inactive</span>
                  @endif
                </td>
                @if ($canEdit)
                  <td class="actions">
                    <a href="#modal-edit-leave-{{ $lt->id }}" class="btn btn-ghost btn-sm">Edit Policy</a>
                  </td>
                @endif
              </tr>
            @empty
              <tr>
                <td colspan="6" class="empty">No leave policy types configured. Click "+ Add Leave Policy Type" above.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

  {{-- Tab Content 2: Departments --}}
  @elseif ($activeTab === 'departments')
    <div class="card flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Department Name</th>
              <th class="num">Staff Headcount</th>
              <th>Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($departments as $dept)
              <tr>
                <td class="perm-key font-bold">{{ $dept->code }}</td>
                <td>
                  <strong>{{ $dept->name }}</strong>
                </td>
                <td class="num font-bold">
                  <span class="badge info plain">{{ $dept->employees_count }} employees</span>
                </td>
                <td>
                  @if ($dept->is_active ?? true)
                    <span class="badge success">Active</span>
                  @else
                    <span class="badge muted">Inactive</span>
                  @endif
                </td>
                <td class="actions">
                  <a href="{{ route('departments.index') }}" class="btn btn-ghost btn-sm">Manage in Registry &rarr;</a>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="empty">No departments configured yet.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

  {{-- Tab Content 3: Compensation Types (Loans, Commissions, Allowances, Deductions) --}}
  @else
    @php
      $currentCategory = in_array($activeTab, ['loans', 'loan']) ? 'loan' :
                        (in_array($activeTab, ['commissions', 'commission']) ? 'commission' :
                        (in_array($activeTab, ['deductions', 'deduction']) ? 'deduction' : 'allowance'));

      $items = ($currentCategory === 'allowance')
        ? $types->get('allowance', collect())->concat($types->get('overtime', collect()))
        : $types->get($currentCategory, collect());

      $categoryLabel = [
        'loan' => 'Staff Loan & Cash Advance Scheme',
        'commission' => 'Performance Commission Milestone',
        'allowance' => 'Regular Allowance / Overtime Shift',
        'deduction' => 'Deduction Scheme',
      ][$currentCategory] ?? 'Compensation Scheme';
    @endphp

    <div class="card flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name &amp; Scope</th>
              <th>Description &amp; Rules</th>
              <th>Tax Treatment</th>
              <th>Status</th>
              @if ($canEdit)
                <th class="actions">Actions</th>
              @endif
            </tr>
          </thead>
          <tbody>
            @forelse ($items as $type)
              <tr>
                <td class="perm-key font-bold">
                  <span class="badge {{ ['loan' => 'amber', 'commission' => 'info', 'allowance' => 'success', 'overtime' => 'primary', 'deduction' => 'danger'][$type->category] ?? 'muted' }} plain" style="font-size:0.7rem;padding:2px 6px">
                    {{ strtoupper($type->category) }}
                  </span>
                  {{ $type->code }}
                </td>
                <td>
                  <strong>{{ $type->name }}</strong>
                  <div class="cell-sub">{{ ucfirst($type->category) }}</div>
                </td>
                <td>{{ $type->description ?? '—' }}</td>
                <td>
                  @if ($type->is_taxable)
                    <span class="badge warning plain">Taxable (PAYE)</span>
                  @else
                    <span class="badge success plain">Tax Exempt</span>
                  @endif
                </td>
                <td>
                  @if ($type->is_active)
                    <span class="badge success">Active</span>
                  @else
                    <span class="badge muted">Inactive</span>
                  @endif
                </td>
                @if ($canEdit)
                  <td class="actions">
                    <a href="#modal-edit-{{ $type->id }}" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" action="{{ route('hr.setup.destroy', $type) }}" onsubmit="return confirm('Delete this {{ $type->category }} scheme?')" style="display:inline">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-ghost btn-sm text-danger">&times; Delete</button>
                    </form>
                  </td>
                @endif
              </tr>
            @empty
              <tr>
                <td colspan="6" class="empty">No {{ strtolower($categoryLabel) }}s configured yet. Click "+ Add Scheme / Master Type" above to create one.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  @endif

  {{-- Modal: Add Compensation Type (Loans, Commissions, Allowances, Deductions) --}}
  @if ($canEdit)
    <div id="modal-add-type" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Add HR Master Scheme / Type</h3>
            <p>Define a new loan scheme, performance milestone, allowance, or deduction</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('hr.setup.store') }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field full">
                <label for="f-cat">Scheme Category <span class="req">*</span></label>
                <select id="f-cat" name="category" required>
                  <option value="loan" @selected($activeTab === 'loans' || $activeTab === 'loan')>Staff Loan / Cash Advance Scheme</option>
                  <option value="commission" @selected($activeTab === 'commissions' || $activeTab === 'commission')>Performance Commission Milestone</option>
                  <option value="allowance" @selected($activeTab === 'allowances' || $activeTab === 'allowance')>Fixed Allowance (Regular Earnings)</option>
                  <option value="overtime">Overtime Shift Type</option>
                  <option value="deduction" @selected($activeTab === 'deductions' || $activeTab === 'deduction')>Voluntary / Statutory Deduction</option>
                </select>
              </div>

              <div class="field">
                <label for="f-code">Short Code <span class="req">*</span></label>
                <input type="text" id="f-code" name="code" placeholder="e.g. LOAN-EMG or COM-MLK" required />
              </div>

              <div class="field">
                <label for="f-name">Scheme / Type Name <span class="req">*</span></label>
                <input type="text" id="f-name" name="name" placeholder="e.g. Milk Collection Milestone" required />
              </div>

              <div class="field full">
                <label for="f-desc">Description / Policy Rules</label>
                <input type="text" id="f-desc" name="description" placeholder="Brief explanation of eligibility, tenor, or payment criteria" />
              </div>

              <div class="field full">
                <label class="check-label">
                  <input type="checkbox" name="is_taxable" value="1" checked />
                  Subject to PAYE Income Tax Calculation
                </label>
              </div>

              <div class="field full">
                <label class="check-label">
                  <input type="checkbox" name="is_active" value="1" checked />
                  Active (Available for recording and employee assignment)
                </label>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Scheme &rarr;</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modals: Edit Compensation Types --}}
    @foreach ($types->flatten() as $type)
      <div id="modal-edit-{{ $type->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div>
              <h3>Edit {{ $type->name }}</h3>
              <p>{{ $type->code }} &middot; {{ ucfirst($type->category) }}</p>
            </div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('hr.setup.update', $type) }}">
            @csrf
            @method('PUT')
            <div class="modal-body">
              <div class="form-grid">
                <div class="field full">
                  <label for="f-name-{{ $type->id }}">Name <span class="req">*</span></label>
                  <input type="text" id="f-name-{{ $type->id }}" name="name" value="{{ $type->name }}" required />
                </div>

                <div class="field full">
                  <label for="f-desc-{{ $type->id }}">Description</label>
                  <input type="text" id="f-desc-{{ $type->id }}" name="description" value="{{ $type->description }}" />
                </div>

                <div class="field full">
                  <label class="check-label">
                    <input type="checkbox" name="is_taxable" value="1" @checked($type->is_taxable) />
                    Subject to PAYE Income Tax
                  </label>
                </div>

                <div class="field full">
                  <label class="check-label">
                    <input type="checkbox" name="is_active" value="1" @checked($type->is_active) />
                    Active
                  </label>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-outline">Cancel</a>
              <button type="submit" class="btn btn-primary">Update Scheme &rarr;</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach

    {{-- Modal: Add Leave Type --}}
    <div id="modal-add-leave-type" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Add Leave Policy / Entitlement Type</h3>
            <p>Define a new leave scheme for the annual employee entitlement registry</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('hr.setup.leave-types.store') }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field">
                <label for="lt-code">Short Code <span class="req">*</span></label>
                <input type="text" id="lt-code" name="code" placeholder="e.g. PATERNITY" required />
              </div>

              <div class="field">
                <label for="lt-name">Leave Name <span class="req">*</span></label>
                <input type="text" id="lt-name" name="name" placeholder="e.g. Paternity Leave" required />
              </div>

              <div class="field">
                <label for="lt-days">Annual Days Entitlement <span class="req">*</span></label>
                <input type="number" id="lt-days" name="annual_entitlement_days" placeholder="e.g. 14 (0 for unlimited)" min="0" max="365" required />
                <div class="hint">Enter 0 if without annual quota limit.</div>
              </div>

              <div class="field">
                <label for="lt-status">Status <span class="req">*</span></label>
                <select id="lt-status" name="status" required>
                  <option value="active" selected>Active (Available for booking)</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

              <div class="field full">
                <label class="check-label">
                  <input type="checkbox" name="requires_document" value="1" />
                  Mandatory Medical Note / Attachment Required on Application
                </label>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Leave Policy &rarr;</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modals: Edit Leave Types --}}
    @foreach ($leaveTypes as $lt)
      <div id="modal-edit-leave-{{ $lt->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div>
              <h3>Edit {{ $lt->name }}</h3>
              <p>{{ $lt->code }} &middot; Leave Policy</p>
            </div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('hr.setup.leave-types.update', $lt) }}">
            @csrf
            @method('PUT')
            <div class="modal-body">
              <div class="form-grid">
                <div class="field full">
                  <label for="lt-name-{{ $lt->id }}">Leave Policy Name <span class="req">*</span></label>
                  <input type="text" id="lt-name-{{ $lt->id }}" name="name" value="{{ $lt->name }}" required />
                </div>

                <div class="field">
                  <label for="lt-days-{{ $lt->id }}">Annual Days Entitlement <span class="req">*</span></label>
                  <input type="number" id="lt-days-{{ $lt->id }}" name="annual_entitlement_days" value="{{ $lt->annual_entitlement_days }}" min="0" max="365" required />
                </div>

                <div class="field">
                  <label for="lt-status-{{ $lt->id }}">Status <span class="req">*</span></label>
                  <select id="lt-status-{{ $lt->id }}" name="status" required>
                    <option value="active" @selected($lt->status === 'active')>Active</option>
                    <option value="inactive" @selected($lt->status === 'inactive')>Inactive</option>
                  </select>
                </div>

                <div class="field full">
                  <label class="check-label">
                    <input type="checkbox" name="requires_document" value="1" @checked($lt->requires_document) />
                    Mandatory Medical Note / Attachment Required
                  </label>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-outline">Cancel</a>
              <button type="submit" class="btn btn-primary">Update Policy &rarr;</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif
@endsection
