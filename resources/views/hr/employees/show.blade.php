@extends('layouts.app')
@section('title', $employee->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('employees.index') }}">Employees</a><span class="sep">/</span>
    <span class="here">{{ $employee->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($employee->name, 0, 2) }}</div>
    <div class="dh-main">
      <h1>{{ $employee->name }}</h1>
      <div class="dh-sub">
        {{ $employee->code }} &middot; {{ $employee->position ?? 'position not set' }}
        @if ($employee->department) &middot; {{ $employee->department->name }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['confirmed' => 'success', 'probation' => 'warning', 'on_leave' => 'info', 'exited' => 'muted'][$employee->status] ?? 'muted' }}">
          {{ \Illuminate\Support\Str::headline($employee->status) }}</span>
        @if ($employee->grade_level)<span class="pill">{{ $employee->grade_level }}</span>@endif
        @if ($employee->user)<span class="pill">has a system account</span>@endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canEdit)
        <a href="#modal-edit-employee" class="btn btn-primary">Edit record</a>
      @endif
    </div>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Employment</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-3">
            <div class="meta-item"><div class="meta-label">Employment type</div>
              <div class="meta-value">{{ $employee->employment_type ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Duty station</div>
              <div class="meta-value">{{ $employee->duty_station ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Line manager</div>
              <div class="meta-value">{{ $employee->lineManager?->name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Joined</div>
              <div class="meta-value">{{ \App\Support\Wat::date($employee->joined_on) }}</div></div>
            <div class="meta-item"><div class="meta-label">Confirmed</div>
              <div class="meta-value">{{ \App\Support\Wat::date($employee->confirmed_on) }}</div></div>
            <div class="meta-item"><div class="meta-label">Direct reports</div>
              <div class="meta-value">{{ $employee->reports->count() }}</div></div>
          </div>

          @if ($seesSalaries)
            <div class="divider"></div>
            <div class="meta-grid cols-3">
              <div class="meta-item"><div class="meta-label">Gross monthly</div>
                <div class="meta-value big">{{ \App\Support\Money::format($employee->gross_monthly_minor) }}</div></div>
              <div class="meta-item"><div class="meta-label">Bank</div>
                <div class="meta-value">{{ $employee->bank_name ?? '—' }}</div></div>
              <div class="meta-item"><div class="meta-label">Account</div>
                <div class="meta-value mono">{{ $employee->bank_account_masked ?? '—' }}</div>
                <div class="cell-sub">only the last digits are shown</div></div>
            </div>
          @else
            <div class="divider"></div>
            <div class="alert info">
              <span>&#128274;</span>
              <div>You do not have permission to see pay and bank details. Ask your supervisor.</div>
            </div>
          @endif
        </div>
      </div>

      {{--
        The entitlement is annual and nothing showed the year's position, so the
        first anyone knew that a request would not fit was the refusal. Shown
        even when nothing has been taken — a full balance is the answer to
        "how many days do I have left", not an absence of information.
      --}}
      @if ($leaveBalances !== [])
        <div class="card">
          <div class="card-head"><div><h3>Leave balance</h3><p>{{ $leaveYear }} entitlement year</p></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Type</th><th class="num">Entitlement</th>
                  <th class="num">Booked</th><th class="num">Remaining</th></tr></thead>
                <tbody>
                  @foreach ($leaveBalances as $balance)
                    <tr>
                      <td>{{ $balance['type']->name }}</td>
                      <td class="num">{{ $balance['entitlement'] > 0 ? $balance['entitlement'].' days' : 'no annual limit' }}</td>
                      <td class="num">{{ $balance['taken'] }}</td>
                      <td class="num font-bold">{{ $balance['entitlement'] > 0 ? $balance['remaining'] : '—' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
          <div class="card-body">
            <div class="cell-sub">Approved days and days awaiting a decision both count against the year.</div>
          </div>
        </div>
      @endif

      @if ($leave->isNotEmpty())
        <div class="card">
          <div class="card-head"><div><h3>Leave</h3></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Type</th><th>From</th><th>To</th><th class="num">Days</th><th>Status</th></tr></thead>
                <tbody>
                  @foreach ($leave as $request)
                    <tr>
                      <td>{{ $request->leaveType?->name }}</td>
                      <td>{{ \App\Support\Wat::date($request->starts_on) }}</td>
                      <td>{{ \App\Support\Wat::date($request->ends_on) }}</td>
                      <td class="num">{{ $request->days }}</td>
                      <td><span class="badge {{ [
                        'approved' => 'success', 'in_review' => 'warning',
                        'rejected' => 'danger', 'draft' => 'muted', 'cancelled' => 'muted',
                      ][$request->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($request->status) }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @endif

      @if ($payslips->isNotEmpty())
        <div class="card">
          <div class="card-head"><div><h3>Payslips</h3><p>Confidential pay records</p></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Reference</th><th>Period</th><th class="num">Gross</th>
                  <th class="num">Deductions</th><th class="num">Net</th><th class="actions"></th></tr></thead>
                <tbody>
                  @foreach ($payslips as $payslip)
                    <tr>
                      <td class="perm-key">{{ $payslip->reference }}</td>
                      <td>{{ $payslip->payrollRun?->periodLabel() }}</td>
                      <td class="num">{{ \App\Support\Money::format($payslip->gross_minor) }}</td>
                      <td class="num">{{ \App\Support\Money::format($payslip->deductions_minor) }}</td>
                      <td class="num font-bold">{{ \App\Support\Money::format($payslip->net_minor) }}</td>
                      <td class="actions">
                        <a href="{{ route('payroll.payslips.show', $payslip) }}" class="btn btn-ghost btn-sm">Open</a>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @endif
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Contact</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Phone</div><div class="meta-value">{{ $employee->phone ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Email</div><div class="meta-value">{{ $employee->email ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Next of kin</div><div class="meta-value">{{ $employee->next_of_kin_name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Kin phone</div><div class="meta-value">{{ $employee->next_of_kin_phone ?? '—' }}</div></div>
          </div>
        </div>
      </div>

      @if ($employee->user)
        <div class="card">
          <div class="card-head"><div><h3>System Account</h3></div></div>
          <div class="card-body">
            <div class="meta-grid cols-2 mb-16">
              <div class="meta-item"><div class="meta-label">Email</div><div class="meta-value">{{ $employee->user->email }}</div></div>
              <div class="meta-item"><div class="meta-label">Status</div>
                <div class="meta-value">{{ ucfirst($employee->user->status) }}</div></div>
            </div>
            <div class="chip-group">
              @foreach ($employee->user->roles as $role)
                <span class="chip on">{{ $role->name }}</span>
              @endforeach
            </div>
            @can('admin.users.view')
              <div class="divider"></div>
              <a href="{{ route('admin.users.show', $employee->user) }}" class="btn btn-ghost btn-sm">Open account</a>
            @endcan
          </div>
        </div>
      @endif
    </div>
  </div>

  {{--
    A promotion, a salary review, a bank change or a departure had no path
    through the application at all: `employees.update` existed and nothing posted
    to it, so the only correction was a database UPDATE, which also skipped the
    before/after audit entry EmployeeService writes. gross_monthly_minor is what
    every payslip is built from, so an uncorrectable one is a wrong payslip every
    month until somebody edits the table by hand.
  --}}
  @if ($canEdit)
    <div id="modal-edit-employee" class="modal @if (old('_modal') === 'modal-edit-employee') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <div><h3>Edit {{ $employee->name }}</h3><p>{{ $employee->code }} &middot; the register the payroll run reads from</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('employees.update', $employee) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-employee" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-employee'])
            <div class="form-grid">
              <div class="field"><label for="ee-code">Staff number <span class="req">*</span></label>
                <input type="text" id="ee-code" name="code" value="{{ old('code', $employee->code) }}" required /></div>
              <div class="field"><label for="ee-name">Full name <span class="req">*</span></label>
                <input type="text" id="ee-name" name="name" value="{{ old('name', $employee->name) }}" required /></div>
              <div class="field"><label for="ee-phone">Phone</label>
                <input type="text" id="ee-phone" name="phone" inputmode="tel" value="{{ old('phone', $employee->phone) }}" /></div>
              <div class="field"><label for="ee-email">Email</label>
                <input type="email" id="ee-email" name="email" value="{{ old('email', $employee->email) }}" /></div>
              <div class="field"><label for="ee-dept">Department</label>
                <select id="ee-dept" data-searchable data-combo-placeholder="Search departments…" name="department_id">
                  <option value="">&mdash;</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id', $employee->department_id) == $department->id)>{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ee-position">Position</label>
                <input type="text" id="ee-position" name="position" value="{{ old('position', $employee->position) }}" /></div>
              <div class="field"><label for="ee-type">Employment type</label>
                <select id="ee-type" name="employment_type">
                  @foreach (['permanent' => 'Permanent', 'contract' => 'Contract', 'casual' => 'Casual'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('employment_type', $employee->employment_type) === $value)>{{ $label }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ee-grade">Grade level</label>
                <input type="text" id="ee-grade" name="grade_level" value="{{ old('grade_level', $employee->grade_level) }}" /></div>
              <div class="field"><label for="ee-station">Duty station</label>
                <input type="text" id="ee-station" name="duty_station" value="{{ old('duty_station', $employee->duty_station) }}" /></div>
              <div class="field"><label for="ee-manager">Reports to</label>
                <select id="ee-manager" data-searchable data-combo-placeholder="Search staff by name or number…" name="line_manager_id">
                  <option value="">&mdash;</option>
                  @foreach ($managers as $manager)
                    <option value="{{ $manager->id }}" @selected(old('line_manager_id', $employee->line_manager_id) == $manager->id)>{{ $manager->name }} &mdash; {{ $manager->code }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ee-joined">Joined on</label>
                <input type="date" id="ee-joined" name="joined_on" value="{{ old('joined_on', $employee->joined_on?->toDateString()) }}" /></div>
              <div class="field"><label for="ee-confirmed">Confirmed on</label>
                <input type="date" id="ee-confirmed" name="confirmed_on" value="{{ old('confirmed_on', $employee->confirmed_on?->toDateString()) }}" /></div>
              <div class="field"><label for="ee-status">Status</label>
                <select id="ee-status" name="status">
                  @foreach (['probation' => 'Probation', 'confirmed' => 'Confirmed', 'on_leave' => 'On leave', 'exited' => 'Exited'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $employee->status) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
                {{-- BR-32's shape: a leaver is marked, never deleted. --}}
                <div class="hint">Mark a leaver Exited rather than deleting them &mdash; payroll history still names them. Exited staff drop off the next run.</div></div>

              {{-- G-6 — pay and bank stay behind hr.payroll.view, as on the add form. --}}
              @if ($seesSalaries)
                <div class="field"><label for="ee-gross">Gross monthly (&#8358;)</label>
                  <input type="text" id="ee-gross" name="gross_monthly" inputmode="decimal"
                         value="{{ old('gross_monthly', \App\Support\Money::decimal((int) $employee->gross_monthly_minor)) }}" />
                  <div class="hint">A change here is named in the audit log and applies from the next payroll run.</div></div>
                <div class="field"><label for="ee-bank">Bank</label>
                  <input type="text" id="ee-bank" name="bank_name" value="{{ old('bank_name', $employee->bank_name) }}" /></div>
                <div class="field"><label for="ee-account">Account number</label>
                  <input type="text" id="ee-account" name="bank_account" inputmode="numeric" value="{{ old('bank_account') }}" />
                  <div class="hint">Currently {{ $employee->bank_account_masked ?? 'not set' }}. Leave blank to keep it; only the last four digits are kept.</div></div>
              @endif

              <div class="field"><label for="ee-kin">Next of kin</label>
                <input type="text" id="ee-kin" name="next_of_kin_name" value="{{ old('next_of_kin_name', $employee->next_of_kin_name) }}" /></div>
              <div class="field"><label for="ee-kin-phone">Next of kin phone</label>
                <input type="text" id="ee-kin-phone" name="next_of_kin_phone" inputmode="tel" value="{{ old('next_of_kin_phone', $employee->next_of_kin_phone) }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
