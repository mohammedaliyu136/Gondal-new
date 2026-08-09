@extends('layouts.app')
@section('title', 'Employees')

@section('content')
  <div class="page-head">
    <div>
      <h1>Employees</h1>
      <p>{{ number_format($employees->total()) }} records &middot; {{ number_format($headcount) }} on payroll</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('departments.index') }}" class="btn btn-outline">Departments</a>
      <a href="{{ route('positions.index') }}" class="btn btn-outline">Open positions</a>
      @if ($canCreate)
        <a href="#modal-employee" class="btn btn-primary">+ Add employee</a>
      @endif
    </div>
  </div>

  @unless ($seesSalaries)
    <div class="alert info mb-16">
      <span>&#128274;</span>
      <div>
        <strong>Salary figures are hidden from you.</strong>
        You can see employee records without their pay. Ask your supervisor if you need the figures.
      </div>
    </div>
  @endunless

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Headcount</div>
      <div class="stat-value">{{ number_format($headcount) }}</div>
      <div class="stat-foot">on payroll</div></div>
    <div class="stat green"><div class="stat-label">Departments</div>
      <div class="stat-value">{{ $departments->count() }}</div>
      <div class="stat-foot">active</div></div>
    @if ($seesSalaries)
      <div class="stat amber"><div class="stat-label">Monthly gross</div>
        <div class="stat-value">{{ \App\Support\Money::compact($payrollTotalMinor) }}</div>
        <div class="stat-foot">before deductions</div></div>
    @else
      <div class="stat"><div class="stat-label">Monthly gross</div>
        <div class="stat-value">&mdash;</div><div class="stat-foot">hidden from you</div></div>
    @endif
    <div class="stat"><div class="stat-label">Confirmed</div>
      <div class="stat-value">{{ number_format($confirmedCount) }}</div>
      <div class="stat-foot">past probation</div></div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Staff Register</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" /></div>
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
            @foreach (['probation', 'confirmed', 'on_leave', 'exited'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('employees.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Employee</th><th>Department</th><th>Position</th><th>Grade</th>
            <th>Line manager</th><th>Joined</th>
            @if ($seesSalaries)<th class="num">Gross monthly</th>@endif
            <th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($employees as $employee)
              <tr>
                <td><div class="font-bold">{{ $employee->name }}</div><div class="cell-sub perm-key">{{ $employee->code }}</div></td>
                <td>{{ $employee->department?->name ?? '—' }}</td>
                <td>{{ $employee->position ?? '—' }}</td>
                <td>{{ $employee->grade_level ?? '—' }}</td>
                <td>{{ $employee->lineManager?->name ?? '—' }}</td>
                <td>{{ \App\Support\Wat::date($employee->joined_on) }}</td>
                @if ($seesSalaries)
                  <td class="num font-bold">{{ \App\Support\Money::format($employee->gross_monthly_minor) }}</td>
                @endif
                <td><span class="badge {{ [
                  'confirmed' => 'success', 'probation' => 'warning',
                  'on_leave' => 'info', 'exited' => 'muted',
                ][$employee->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($employee->status) }}</span></td>
                <td class="actions"><a href="{{ route('employees.show', $employee) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="{{ $seesSalaries ? 9 : 8 }}">
                @include('partials.empty', ['title' => 'No employees in your scope', 'icon' => '&#128188;'])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $employees, 'noun' => 'employees'])
  </div>

  @if ($canCreate)
    <div id="modal-employee" class="modal @if (old('_modal') === 'modal-employee') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <div><h3>Add employee</h3><p>The register the payroll run reads from</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('employees.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-employee" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-employee'])
            <div class="form-grid">
              <div class="field"><label for="emp-code">Staff number <span class="req">*</span></label>
                <input type="text" id="emp-code" name="code" value="{{ old('code', $suggestedCode) }}" required />
                <div class="hint">Suggested from the last one issued. Change it if you number differently.</div></div>
              <div class="field"><label for="emp-name">Full name <span class="req">*</span></label>
                <input type="text" id="emp-name" name="name" value="{{ old('name') }}" required /></div>
              <div class="field"><label for="emp-phone">Phone</label>
                <input type="text" id="emp-phone" name="phone" inputmode="tel" value="{{ old('phone') }}" /></div>
              <div class="field"><label for="emp-email">Email</label>
                <input type="email" id="emp-email" name="email" value="{{ old('email') }}" /></div>
              <div class="field"><label for="emp-dept">Department</label>
                <select id="emp-dept" data-searchable data-combo-placeholder="Search departments…" name="department_id">
                  <option value="">&mdash;</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="emp-position">Position</label>
                <input type="text" id="emp-position" name="position" value="{{ old('position') }}" /></div>
              <div class="field"><label for="emp-type">Employment type</label>
                <select id="emp-type" name="employment_type">
                  @foreach (['permanent' => 'Permanent', 'contract' => 'Contract', 'casual' => 'Casual'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('employment_type') === $value)>{{ $label }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="emp-grade">Grade level</label>
                <input type="text" id="emp-grade" name="grade_level" value="{{ old('grade_level') }}" /></div>
              <div class="field"><label for="emp-station">Duty station</label>
                <input type="text" id="emp-station" name="duty_station" value="{{ old('duty_station') }}" /></div>
              <div class="field"><label for="emp-manager">Reports to</label>
                <select id="emp-manager" data-searchable data-combo-placeholder="Search staff by name or number…" name="line_manager_id">
                  <option value="">&mdash;</option>
                  @foreach ($managers as $manager)
                    <option value="{{ $manager->id }}" @selected(old('line_manager_id') == $manager->id)>{{ $manager->name }} &mdash; {{ $manager->code }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="emp-joined">Joined on</label>
                <input type="date" id="emp-joined" name="joined_on" value="{{ old('joined_on') }}" /></div>
              <div class="field"><label for="emp-confirmed">Confirmed on</label>
                <input type="date" id="emp-confirmed" name="confirmed_on" value="{{ old('confirmed_on') }}" /></div>
              <div class="field"><label for="emp-status">Status</label>
                <select id="emp-status" name="status">
                  @foreach (['probation' => 'Probation', 'confirmed' => 'Confirmed', 'on_leave' => 'On leave', 'exited' => 'Exited'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', 'probation') === $value)>{{ $label }}</option>
                  @endforeach
                </select>
                <div class="hint">Probation, confirmed and on-leave staff are included in the payroll run.</div></div>

              @if ($seesSalaries)
                <div class="field"><label for="emp-gross">Gross monthly (&#8358;)</label>
                  <input type="text" id="emp-gross" name="gross_monthly" inputmode="decimal" value="{{ old('gross_monthly') }}" />
                  <div class="hint">What the payroll run will use. Leave blank if they are not on payroll.</div></div>
                <div class="field"><label for="emp-bank">Bank</label>
                  <input type="text" id="emp-bank" name="bank_name" value="{{ old('bank_name') }}" /></div>
                <div class="field"><label for="emp-account">Account number</label>
                  <input type="text" id="emp-account" name="bank_account" inputmode="numeric" value="{{ old('bank_account') }}" />
                  <div class="hint">Only the last four digits are kept.</div></div>
              @endif

              <div class="field"><label for="emp-kin">Next of kin</label>
                <input type="text" id="emp-kin" name="next_of_kin_name" value="{{ old('next_of_kin_name') }}" /></div>
              <div class="field"><label for="emp-kin-phone">Next of kin phone</label>
                <input type="text" id="emp-kin-phone" name="next_of_kin_phone" inputmode="tel" value="{{ old('next_of_kin_phone') }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Add employee</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
