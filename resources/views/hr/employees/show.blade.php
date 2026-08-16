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
        <div class="card-head">
          <div><h3>Employment &amp; Compensation</h3></div>
          @if ($seesSalaries && $canSetSalary)
            <a href="{{ route('employees.salary.edit', $employee) }}" class="btn btn-ghost btn-sm">Configure Salary &rarr;</a>
          @endif
        </div>
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

        </div>
      </div>

      {{-- Dedicated Detailed Salary & Compensation Card --}}
      @if ($seesSalaries)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Salary &amp; Compensation Structure</h3>
              <p>
                @if ($employee->salaryProfile?->effective_date)
                  Effective from {{ \App\Support\Wat::date($employee->salaryProfile->effective_date) }} &middot;
                @endif
                Monthly compensation schedule &amp; payroll breakdown
              </p>
            </div>
            @if ($canSetSalary)
              <a href="{{ route('employees.salary.edit', $employee) }}" class="btn btn-outline btn-sm">
                <span>&#128181;</span> Manage Salary Structure &rarr;
              </a>
            @endif
          </div>

          @php
            $prof = $employee->salaryProfile;
            $basicMinor = $prof ? (int) $prof->basic_salary_minor : (int) round($employee->gross_monthly_minor * 0.50);
            $housingMinor = $prof ? (int) $prof->housing_allowance_minor : (int) round($employee->gross_monthly_minor * 0.30);
            $transportMinor = $prof ? (int) $prof->transport_allowance_minor : (int) round($employee->gross_monthly_minor * 0.20);
            $utilityMinor = $prof ? (int) $prof->utility_allowance_minor : 0;
            $medicalMinor = $prof ? (int) $prof->medical_allowance_minor : 0;
            $otherAllowMinor = $prof ? (int) $prof->other_allowance_minor : 0;

            $grossMinor = $prof ? $prof->computeGrossMinor() : (int) $employee->gross_monthly_minor;
            $pensionMinor = $prof ? $prof->computePensionMinor($grossMinor) : (int) round(($grossMinor * 8) / 100);
            $taxMinor = $prof ? $prof->computeTaxMinor($grossMinor - $pensionMinor) : (int) round((($grossMinor - $pensionMinor) * 7) / 100);
            $nhisMinor = $prof ? (int) $prof->nhis_minor : 0;
            $unionMinor = $prof ? (int) $prof->union_dues_minor : 0;
            $otherDedMinor = $prof ? (int) $prof->other_deduction_minor : 0;

            $totalDedMinor = $pensionMinor + $taxMinor + $nhisMinor + $unionMinor + $otherDedMinor;
            $baseNetMinor = max(0, $grossMinor - $totalDedMinor);

            $activeLoanMonthly = (int) $employee->activeLoans->sum('monthly_installment_minor');
            $unbilledComms = (int) $employee->commissions->where('status', 'approved')->whereNull('payslip_id')->sum('amount_minor');
            $unbilledOvertimes = (int) $employee->overtimes->where('status', 'approved')->whereNull('payslip_id')->sum('total_amount_minor');
            $variableTotal = $unbilledComms + $unbilledOvertimes;
            $currentMonthEstNet = max(0, ($grossMinor + $variableTotal) - ($totalDedMinor + $activeLoanMonthly));
            $hasDynamicItems = ($activeLoanMonthly > 0 || $variableTotal > 0);
          @endphp

          <div class="card-body">
            {{-- Summary KPI Stats --}}
            <div class="grid grid-3 mb-16">
              <div class="stat blue" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px">
                <div class="stat-label" style="font-size:0.8125rem;color:#0369a1;font-weight:600;text-transform:uppercase;letter-spacing:0.03em">Base Gross Earnings</div>
                <div class="stat-value font-bold" style="font-size:1.4rem;color:#0c4a6e;margin-top:4px">
                  {{ \App\Support\Money::format($grossMinor) }}
                </div>
                <div class="stat-foot" style="font-size:0.75rem;color:#0284c7;margin-top:4px">Basic + Fixed Allowances</div>
              </div>

              <div class="stat amber" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px">
                <div class="stat-label" style="font-size:0.8125rem;color:#b91c1c;font-weight:600;text-transform:uppercase;letter-spacing:0.03em">Regular Deductions</div>
                <div class="stat-value font-bold" style="font-size:1.4rem;color:#991b1b;margin-top:4px">
                  -{{ \App\Support\Money::format($totalDedMinor) }}
                </div>
                <div class="stat-foot" style="font-size:0.75rem;color:#dc2626;margin-top:4px">Statutory Pension &amp; PAYE Tax</div>
              </div>

              <div class="stat green" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px">
                <div class="stat-label" style="font-size:0.8125rem;color:#15803d;font-weight:600;text-transform:uppercase;letter-spacing:0.03em">Base Net Take-Home</div>
                <div class="stat-value font-bold" style="font-size:1.4rem;color:#14532d;margin-top:4px">
                  {{ \App\Support\Money::format($baseNetMinor) }}
                </div>
                <div class="stat-foot" style="font-size:0.75rem;color:#16a34a;margin-top:4px">Contractual fixed take-home</div>
              </div>
            </div>

            @if ($hasDynamicItems)
              <div class="alert info mb-16" style="display:flex;align-items:center;justify-content:space-between;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;padding:10px 14px;border-radius:6px">
                <div style="display:flex;align-items:center;gap:10px">
                  <span style="font-size:1.2rem">&#128179;</span>
                  <div>
                    <strong>Estimated {{ date('F Y') }} Payout: {{ \App\Support\Money::format($currentMonthEstNet) }}</strong>
                    <div style="font-size:0.8rem;color:#6d28d9;margin-top:2px">
                      Includes 
                      @if ($variableTotal > 0)<span class="font-bold">+{{ \App\Support\Money::format($variableTotal) }} commissions &amp; overtime</span>@endif
                      @if ($variableTotal > 0 && $activeLoanMonthly > 0) and @endif
                      @if ($activeLoanMonthly > 0)<span class="font-bold text-danger">-{{ \App\Support\Money::format($activeLoanMonthly) }} active loan repayment</span>@endif
                    </div>
                  </div>
                </div>
                @if ($canSetSalary)
                  <a href="{{ route('employees.salary.edit', $employee) }}" class="btn btn-ghost btn-sm" style="color:#6d28d9">View Full Breakdown &rarr;</a>
                @endif
              </div>
            @endif

            {{-- 2-Column Itemized Breakdown Tables --}}
            <div class="split" style="gap:16px;align-items:flex-start">
              {{-- Earnings Column --}}
              <div class="card flush" style="flex:1;border:1px solid #e2e8f0;background:#fff">
                <div class="card-head" style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0">
                  <strong style="font-size:0.875rem;color:#1e293b">Earnings &amp; Allowances</strong>
                </div>
                <div class="table-wrap">
                  <table style="margin:0">
                    <thead>
                      <tr>
                        <th style="font-size:0.8rem">Component</th>
                        <th class="num" style="font-size:0.8rem">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <strong>Basic Salary</strong>
                          <div class="cell-sub">Base wage</div>
                        </td>
                        <td class="num font-bold">{{ \App\Support\Money::format($basicMinor) }}</td>
                      </tr>
                      <tr>
                        <td>Housing Allowance</td>
                        <td class="num">{{ \App\Support\Money::format($housingMinor) }}</td>
                      </tr>
                      <tr>
                        <td>Transport Allowance</td>
                        <td class="num">{{ \App\Support\Money::format($transportMinor) }}</td>
                      </tr>
                      @if ($prof && $prof->utility_allowance_minor > 0)
                        <tr>
                          <td>Utility &amp; Meal Allowance</td>
                          <td class="num">{{ \App\Support\Money::format($prof->utility_allowance_minor) }}</td>
                        </tr>
                      @endif
                      @if ($prof && $prof->medical_allowance_minor > 0)
                        <tr>
                          <td>Medical Allowance</td>
                          <td class="num">{{ \App\Support\Money::format($prof->medical_allowance_minor) }}</td>
                        </tr>
                      @endif
                      @if ($prof && $prof->other_allowance_minor > 0)
                        <tr>
                          <td>Other Fixed Allowance</td>
                          <td class="num">{{ \App\Support\Money::format($prof->other_allowance_minor) }}</td>
                        </tr>
                      @endif
                    </tbody>
                    <tfoot style="background:#f8fafc;font-weight:bold;border-top:1px solid #e2e8f0">
                      <tr>
                        <td>Total Base Monthly Gross</td>
                        <td class="num font-bold" style="color:var(--primary-dark)">{{ \App\Support\Money::format($grossMinor) }}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              {{-- Deductions Column --}}
              <div class="card flush" style="flex:1;border:1px solid #e2e8f0;background:#fff">
                <div class="card-head" style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0">
                  <strong style="font-size:0.875rem;color:#1e293b">Statutory &amp; Regular Deductions</strong>
                </div>
                <div class="table-wrap">
                  <table style="margin:0">
                    <thead>
                      <tr>
                        <th style="font-size:0.8rem">Deduction Scheme</th>
                        <th class="num" style="font-size:0.8rem">Monthly</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          Pension Contribution
                          @if ($prof?->is_pension_exempt)
                            <span class="badge warning plain" style="font-size:0.7rem;padding:2px 6px">Exempt</span>
                          @else
                            <span class="badge info plain" style="font-size:0.7rem;padding:2px 6px">{{ number_format($prof?->pension_rate_pct ?: 8.0, 1) }}%</span>
                          @endif
                        </td>
                        <td class="num" style="color:var(--danger)">
                          {{ $pensionMinor > 0 ? '-' . \App\Support\Money::format($pensionMinor) : '₦ 0.00' }}
                        </td>
                      </tr>
                      <tr>
                        <td>
                          PAYE Tax (Income Tax)
                          @if ($prof?->is_tax_exempt)
                            <span class="badge warning plain" style="font-size:0.7rem;padding:2px 6px">Exempt</span>
                          @else
                            <span class="badge info plain" style="font-size:0.7rem;padding:2px 6px">{{ number_format($prof?->tax_rate_pct ?: 7.0, 1) }}%</span>
                          @endif
                        </td>
                        <td class="num" style="color:var(--danger)">
                          {{ $taxMinor > 0 ? '-' . \App\Support\Money::format($taxMinor) : '₦ 0.00' }}
                        </td>
                      </tr>
                      @if ($prof && $prof->nhis_minor > 0)
                        <tr>
                          <td>Health Insurance (NHIS)</td>
                          <td class="num" style="color:var(--danger)">-{{ \App\Support\Money::format($prof->nhis_minor) }}</td>
                        </tr>
                      @endif
                      @if ($prof && $prof->union_dues_minor > 0)
                        <tr>
                          <td>Union / Cooperative Dues</td>
                          <td class="num" style="color:var(--danger)">-{{ \App\Support\Money::format($prof->union_dues_minor) }}</td>
                        </tr>
                      @endif
                      @if ($prof && $prof->other_deduction_minor > 0)
                        <tr>
                          <td>Other Regular Deductions</td>
                          <td class="num" style="color:var(--danger)">-{{ \App\Support\Money::format($prof->other_deduction_minor) }}</td>
                        </tr>
                      @endif
                    </tbody>
                    <tfoot style="background:#f8fafc;font-weight:bold;border-top:1px solid #e2e8f0">
                      <tr>
                        <td>Total Regular Deductions</td>
                        <td class="num font-bold" style="color:var(--danger)">-{{ \App\Support\Money::format($totalDedMinor) }}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>

            {{-- Bank & Disbursement Destination Footer --}}
            <div class="divider"></div>
            <div class="meta-grid cols-3" style="font-size:0.875rem">
              <div class="meta-item">
                <div class="meta-label">Payment Destination</div>
                <div class="meta-value font-bold">{{ $employee->bank_name ?? 'Commercial Bank' }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Account Number</div>
                <div class="meta-value mono font-bold">{{ $employee->bank_account_masked ?? '—' }}</div>
                <div class="cell-sub">Verified payroll settlement destination</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Disbursement Method</div>
                <div class="meta-value">
                  <span class="badge info plain">Electronic Bank Transfer</span>
                </div>
              </div>
            </div>

            @if ($prof?->notes)
              <div class="alert info mt-16" style="margin-top:12px;margin-bottom:0">
                <span>&#128221;</span>
                <div><strong>HR Compensation Notes:</strong> {{ $prof->notes }}</div>
              </div>
            @endif
          </div>
        </div>
      @else
        <div class="card">
          <div class="card-body">
            <div class="alert info" style="margin-bottom:0">
              <span>&#128274;</span>
              <div>You do not have permission to see pay and bank details. Ask your supervisor.</div>
            </div>
          </div>
        </div>
      @endif

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

      @if ($seesSalaries)
        <div class="card">
          <div class="card-head flex-between">
            <div>
              <h3>Banking &amp; Settlement</h3>
              <p>Verified bank payout details</p>
            </div>
            @if ($employee->bank_account_name)
              <span class="badge success" style="font-size:11px">&#10003; Verified</span>
            @endif
          </div>
          <div class="card-body">
            <div class="meta-grid cols-1" style="gap:12px">
              <div class="meta-item">
                <div class="meta-label">Bank Name</div>
                <div class="meta-value font-bold">{{ $employee->bank_name ?? '—' }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Account Number</div>
                <div class="meta-value mono font-bold">{{ $employee->bank_account_masked ?? '—' }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Account Holder Name</div>
                <div class="meta-value font-bold" style="color:var(--primary-dark)">{{ $employee->bank_account_name ?? '—' }}</div>
              </div>
            </div>
          </div>
        </div>
      @endif

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
                <div class="field">
                  <label for="ee-bank-code">Bank <span class="req">*</span></label>
                  <select id="ee-bank-code" name="bank_code" class="bank-select" data-searchable data-combo-placeholder="Search banks…">
                    <option value="">&mdash;</option>
                    @foreach ($banks as $bank)
                      <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}" @selected(old('bank_code', $employee->bank_code) == $bank['code'] || old('bank_name', $employee->bank_name) == $bank['name'])>{{ $bank['name'] }}</option>
                    @endforeach
                  </select>
                  <input type="hidden" id="ee-bank-name" name="bank_name" value="{{ old('bank_name', $employee->bank_name) }}" />
                </div>
                <div class="field">
                  <label for="ee-account">NUBAN Account Number <span class="req">*</span></label>
                  <div style="position:relative">
                    <input type="text" id="ee-account" name="bank_account" class="bank-account-input" inputmode="numeric" maxlength="10" value="{{ old('bank_account', $employee->bank_account_number) }}" placeholder="10-digit NUBAN number" />
                    <span class="bank-verify-spinner-edit" style="position:absolute;right:10px;top:8px;display:none;font-size:13px;color:var(--primary)">&#9203; Verifying...</span>
                  </div>
                  <div class="bank-verify-msg-edit hint" style="margin-top:4px">
                    @if ($employee->bank_account_masked)
                      Currently: {{ $employee->bank_account_masked }}. Enter 10-digit number to re-verify.
                    @endif
                  </div>
                </div>
                <div class="field">
                  <label for="ee-account-name">Account Holder Name <span class="req">*</span></label>
                  <input type="text" id="ee-account-name" name="bank_account_name" class="bank-account-name-input" value="{{ old('bank_account_name', $employee->bank_account_name) }}" readonly required placeholder="Auto-retrieved on account number blur" style="background:var(--card, #f8fafc);cursor:not-allowed;font-weight:600;color:var(--text-bright, #0f172a)" />
                  <div class="hint">Automatically verified via default payment gateway.</div>
                </div>
              @endif

              <div class="field"><label for="ee-kin">Next of kin</label>
                <input type="text" id="ee-kin" name="next_of_kin_name" value="{{ old('next_of_kin_name', $employee->next_of_kin_name) }}" /></div>
              <div class="field"><label for="ee-kin-phone">Next of kin phone</label>
                <input type="text" id="ee-kin-phone" name="next_of_kin_phone" inputmode="tel" value="{{ old('next_of_kin_phone', $employee->next_of_kin_phone) }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" id="btn-submit-edit-employee">Save changes</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const bankSelect = document.getElementById('ee-bank-code');
        const bankNameInput = document.getElementById('ee-bank-name');
        const accountInput = document.getElementById('ee-account');
        const nameInput = document.getElementById('ee-account-name');
        const spinner = document.querySelector('#modal-edit-employee .bank-verify-spinner-edit');
        const msg = document.querySelector('#modal-edit-employee .bank-verify-msg-edit');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        if (!bankSelect || !accountInput || !nameInput) return;

        function updateBankName() {
          const selected = bankSelect.options[bankSelect.selectedIndex];
          if (selected && selected.dataset.name) {
            bankNameInput.value = selected.dataset.name;
          }
        }

        bankSelect.addEventListener('change', function () {
          updateBankName();
          if (accountInput.value.trim().length === 10) {
            verifyAccount();
          }
        });

        accountInput.addEventListener('blur', function () {
          verifyAccount();
        });

        accountInput.addEventListener('input', function () {
          this.value = this.value.replace(/\D/g, '').slice(0, 10);
          if (this.value.length === 10) {
            verifyAccount();
          } else if (this.value.length > 0) {
            nameInput.value = '';
            if (msg) msg.innerHTML = '<span class="text-muted">Enter 10-digit NUBAN number to verify</span>';
          }
        });

        async function verifyAccount() {
          const bankCode = bankSelect.value.trim();
          const accountNo = accountInput.value.trim();

          if (!bankCode) {
            if (msg) msg.innerHTML = '<span style="color:var(--danger, #dc2626)">Please select a bank first.</span>';
            return;
          }

          if (accountNo.length !== 10) {
            if (msg) msg.innerHTML = '<span style="color:var(--danger, #dc2626)">NUBAN account number must be 10 digits.</span>';
            return;
          }

          updateBankName();
          if (spinner) spinner.style.display = 'inline';
          if (msg) msg.innerHTML = '<span style="color:var(--primary, #0284c7)">Verifying account details with gateway...</span>';

          try {
            const res = await fetch('{{ route('employees.verify-bank') }}', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
              },
              body: JSON.stringify({ bank_code: bankCode, account_number: accountNo }),
            });

            const data = await res.json();

            if (res.ok && data.success && data.account_name) {
              nameInput.value = data.account_name;
              if (data.bank_name && !bankNameInput.value) {
                bankNameInput.value = data.bank_name;
              }
              if (msg) msg.innerHTML = '<span style="color:#15803d;font-weight:600">&#10003; Verified: ' + data.account_name + '</span>';
            } else {
              nameInput.value = '';
              if (msg) msg.innerHTML = '<span style="color:#dc2626;font-weight:600">&#9888; ' + (data.message || 'Verification failed. Check bank and account number.') + '</span>';
            }
          } catch (err) {
            if (msg) msg.innerHTML = '<span style="color:#dc2626">&#9888; Network error during verification.</span>';
          } finally {
            if (spinner) spinner.style.display = 'none';
          }
        }
      });
    </script>
  @endif
@endsection
