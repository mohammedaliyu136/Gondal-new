@extends('layouts.app')
@section('title', 'Salary & Compensation Structure — ' . $employee->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('employees.index') }}">Human Resources</a><span class="sep">/</span>
    <a href="{{ route('employees.show', $employee) }}">{{ $employee->name }}</a><span class="sep">/</span>
    <span class="here">Salary &amp; Compensation Structure</span>
  </div>

  @php
    $currentMonthName = date('F Y');
    $currentMonthShort = date('M Y');
    $fmtMoney = fn(?int $kobo) => $kobo ? number_format($kobo / 100, 2) : '0.00';
    $activeLoanBalance = (int) $employee->activeLoans->sum('balance_minor');
    $activeLoanMonthly = (int) $employee->activeLoans->sum('monthly_installment_minor');
    $unbilledCommissions = (int) $employee->commissions->where('status', 'approved')->whereNull('payslip_id')->sum('amount_minor');
    $unbilledOvertimes = (int) $employee->overtimes->where('status', 'approved')->whereNull('payslip_id')->sum('total_amount_minor');
    $totalVariableAdditions = $unbilledCommissions + $unbilledOvertimes;

    $baseGross = (int) ($profile ? $profile->computeGrossMinor() : $employee->gross_monthly_minor);
    $currentMonthGross = $baseGross + $totalVariableAdditions;
    $regularDeductions = (int) ($profile ? $profile->computeDeductionsMinor() : 0);
    $totalCurrentMonthDeductions = $regularDeductions + $activeLoanMonthly;
    $currentMonthEstimatedNet = max(0, $currentMonthGross - $totalCurrentMonthDeductions);
  @endphp

  <div class="page-head">
    <div>
      <h1>Salary &amp; Compensation Structure</h1>
      <p>Configure base pay, recurring allowances, staff loan deductions, commissions, and statutory rules for <strong>{{ $employee->name }}</strong> ({{ $employee->code }})</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('employees.show', $employee) }}" class="btn btn-outline">&larr; Back to Employee Profile</a>
    </div>
  </div>

  {{-- Live Dynamic Calculation Preview Bar (Includes Base, Variable Pay, Loans & Estimated Current Month Net) --}}
  <div class="grid grid-4 mb-16">
    <div class="stat blue">
      <div class="stat-label">Regular Base Gross</div>
      <div class="stat-value" id="preview-base-gross">
        {{ \App\Support\Money::format($baseGross) }}
      </div>
      <div class="stat-foot">Basic salary + Fixed monthly allowances</div>
    </div>

    <div class="stat purple">
      <div class="stat-label">Variable Pay ({{ $currentMonthShort }})</div>
      <div class="stat-value" id="preview-variable-additions" style="color:#7c3aed">
        +{{ \App\Support\Money::format($totalVariableAdditions) }}
      </div>
      <div class="stat-foot">Commissions + Overtime queued for payout</div>
    </div>

    <div class="stat amber">
      <div class="stat-label">Total Deductions ({{ $currentMonthShort }})</div>
      <div class="stat-value text-danger" id="preview-total-deductions" style="color:var(--danger)">
        -{{ \App\Support\Money::format($totalCurrentMonthDeductions) }}
      </div>
      <div class="stat-foot">Taxes, Pension &amp; Loan Deduction ({{ \App\Support\Money::format($activeLoanMonthly) }})</div>
    </div>

    <div class="stat green">
      <div class="stat-label">Estimated {{ $currentMonthShort }} Net Payout</div>
      <div class="stat-value font-bold" id="preview-current-net" style="color:var(--primary-dark)">
        {{ \App\Support\Money::format($currentMonthEstimatedNet) }}
      </div>
      <div class="stat-foot">Estimated take-home pay for current month</div>
    </div>
  </div>

  <div class="split">
    {{-- Left Main Column: Unified Salary & Deductions Card + Dynamic Ledgers --}}
    <div class="stack" style="flex:2">
      <form method="POST" action="{{ route('employees.salary.update', $employee) }}" id="salary-form">
        @csrf
        @method('PUT')

        {{-- Single Unified Card: Fixed Salary Structure, Statutory Deductions, Effective Date & Remarks --}}
        <div class="card mb-16" id="salary-structure-card">
          <div class="card-head flex-between">
            <div>
              <h3>Fixed Salary Structure &amp; Statutory Rules</h3>
              <p>Regular monthly base earnings, allowances, pension, tax rules, and effective date</p>
            </div>
            <div class="flex" style="gap:8px;align-items:center">
              @if ($canEdit)
                <button type="button" class="btn btn-outline btn-sm" id="btn-toggle-edit">
                  <span>&#9998;</span> Edit Salary Structure
                </button>
                <button type="button" class="btn btn-ghost btn-sm" id="btn-apply-50-30-20" style="display:none">
                  Apply 50/30/20 Rule
                </button>
              @else
                <span class="badge muted plain" style="font-size:0.75rem">&#128274; Read Only</span>
              @endif
            </div>
          </div>

          <div class="card-body">
            {{-- Part 1: Base Salary & Fixed Recurring Allowances --}}
            <h4 class="mb-12 font-bold" style="color:var(--text);font-size:0.95rem;display:flex;align-items:center;gap:6px">
              <span class="badge info plain" style="font-size:0.7rem;padding:2px 6px">1</span>
              Base Salary &amp; Fixed Allowances
            </h4>

            <div class="form-grid mb-16">
              <div class="field full">
                <label for="f-basic">Basic Salary (₦ / month) <span class="req">*</span></label>
                <input type="text" id="f-basic" name="basic_salary" inputmode="decimal"
                       value="{{ $fmtMoney($profile->basic_salary_minor) }}" class="calc-input money-input font-bold" disabled required />
                <div class="hint">The core taxable base wage before allowances.</div>
              </div>

              <div class="field">
                <label for="f-housing">Housing Allowance (₦)</label>
                <input type="text" id="f-housing" name="housing_allowance" inputmode="decimal"
                       value="{{ $fmtMoney($profile->housing_allowance_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field">
                <label for="f-transport">Transport Allowance (₦)</label>
                <input type="text" id="f-transport" name="transport_allowance" inputmode="decimal"
                       value="{{ $fmtMoney($profile->transport_allowance_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field">
                <label for="f-utility">Utility &amp; Meal Allowance (₦)</label>
                <input type="text" id="f-utility" name="utility_allowance" inputmode="decimal"
                       value="{{ $fmtMoney($profile->utility_allowance_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field">
                <label for="f-medical">Medical &amp; Health Allowance (₦)</label>
                <input type="text" id="f-medical" name="medical_allowance" inputmode="decimal"
                       value="{{ $fmtMoney($profile->medical_allowance_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field full">
                <label for="f-other-allowance">Other Fixed Allowance (₦)</label>
                <input type="text" id="f-other-allowance" name="other_allowance" inputmode="decimal"
                       value="{{ $fmtMoney($profile->other_allowance_minor) }}" class="calc-input money-input" disabled />
                <div class="hint">Special duty allowance, hazard pay, or responsibility allowance.</div>
              </div>
            </div>

            <div class="divider mb-16" style="border-top:1px solid var(--border,#e2e8f0);margin:16px 0"></div>

            {{-- Part 2: Statutory Rules & Regular Deductions --}}
            <h4 class="mb-12 font-bold" style="color:var(--text);font-size:0.95rem;display:flex;align-items:center;gap:6px">
              <span class="badge warning plain" style="font-size:0.7rem;padding:2px 6px">2</span>
              Statutory Rules &amp; Regular Deductions
            </h4>

            <div class="form-grid mb-16">
              <div class="field">
                <label for="f-pension-rate">Pension Rate (%)</label>
                <input type="text" id="f-pension-rate" name="pension_rate_pct" inputmode="decimal"
                       value="{{ number_format($profile->pension_rate_pct ?: 8.0, 2) }}" class="calc-input" disabled />
                <div class="mt-8">
                  <label class="check-label">
                    <input type="checkbox" id="f-pension-exempt" name="is_pension_exempt" value="1"
                           @checked($profile->is_pension_exempt) class="calc-input" disabled />
                    Exempt from Pension Deduction
                  </label>
                </div>
              </div>

              <div class="field">
                <label for="f-tax-rate">PAYE Tax Rate (%)</label>
                <input type="text" id="f-tax-rate" name="tax_rate_pct" inputmode="decimal"
                       value="{{ number_format($profile->tax_rate_pct ?: 7.0, 2) }}" class="calc-input" disabled />
                <div class="mt-8">
                  <label class="check-label">
                    <input type="checkbox" id="f-tax-exempt" name="is_tax_exempt" value="1"
                           @checked($profile->is_tax_exempt) class="calc-input" disabled />
                    Exempt from PAYE Tax
                  </label>
                </div>
              </div>

              <div class="field">
                <label for="f-nhis">Health Insurance / NHIS (₦)</label>
                <input type="text" id="f-nhis" name="nhis" inputmode="decimal"
                       value="{{ $fmtMoney($profile->nhis_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field">
                <label for="f-union">Union / Cooperative Dues (₦)</label>
                <input type="text" id="f-union" name="union_dues" inputmode="decimal"
                       value="{{ $fmtMoney($profile->union_dues_minor) }}" class="calc-input money-input" disabled />
              </div>

              <div class="field full">
                <label for="f-other-deduction">Other Regular Deductions (₦)</label>
                <input type="text" id="f-other-deduction" name="other_deduction" inputmode="decimal"
                       value="{{ $fmtMoney($profile->other_deduction_minor) }}" class="calc-input money-input" disabled />
              </div>
            </div>

            <div class="divider mb-16" style="border-top:1px solid var(--border,#e2e8f0);margin:16px 0"></div>

            {{-- Part 3: Structure Effective Date & Remarks --}}
            <h4 class="mb-12 font-bold" style="color:var(--text);font-size:0.95rem;display:flex;align-items:center;gap:6px">
              <span class="badge primary plain" style="font-size:0.7rem;padding:2px 6px">3</span>
              Structure Effective Date &amp; Remarks
            </h4>

            <div class="form-grid">
              <div class="field">
                <label for="f-effective">Effective Date</label>
                <input type="date" id="f-effective" name="effective_date"
                       value="{{ $profile->effective_date ? $profile->effective_date->format('Y-m-d') : date('Y-m-d') }}" disabled />
                <div class="hint">The payroll period this structure takes effect.</div>
              </div>

              <div class="field full">
                <label for="f-notes">HR Compensation Notes</label>
                <textarea id="f-notes" name="notes" rows="2" placeholder="e.g. Salary review approved on promotion to Senior Grade Level 4" disabled>{{ $profile->notes }}</textarea>
              </div>
            </div>
          </div>

          @if ($canEdit)
            <div class="card-foot flex-between" id="card-edit-actions" style="display:none">
              <button type="button" class="btn btn-outline" id="btn-cancel-edit">Cancel</button>
              <button type="submit" class="btn btn-primary" id="btn-save-salary">
                Save Fixed Salary Structure &rarr;
              </button>
            </div>
          @endif
        </div>
      </form>

      {{-- Section 3: Dynamic Staff Loans & Cash Advances Card --}}
      <div class="card mb-16">
        <div class="card-head flex-between">
          <div>
            <h3>Staff Loans &amp; Cash Advances Ledger</h3>
            <p>Active loan amortisations, monthly payroll deductions &amp; settlement history</p>
          </div>
          @if ($canSetSalary)
            <a href="#modal-grant-loan" class="btn btn-primary btn-sm">
              <span>+</span> Grant Loan / Advance
            </a>
          @else
            <span class="badge muted plain" style="font-size:0.75rem">&#128274; Read Only</span>
          @endif
        </div>
        <div class="card-body flush">
          @if ($employee->staffLoans->isNotEmpty())
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Type &amp; Date</th>
                    <th class="num">Principal</th>
                    <th class="num">Monthly Deduction</th>
                    <th class="num">Repaid / Bal</th>
                    <th>Status</th>
                    @if ($canSetSalary)
                      <th class="actions">Actions</th>
                    @endif
                  </tr>
                </thead>
                <tbody>
                  @foreach ($employee->staffLoans as $loan)
                    <tr>
                      <td class="perm-key font-bold">{{ $loan->reference }}</td>
                      <td>
                        <strong>{{ $loan->compensationType?->name ?? 'Staff Loan' }}</strong>
                        <div class="cell-sub">Disbursed: {{ \App\Support\Wat::date($loan->disbursed_on) }}</div>
                      </td>
                      <td class="num font-bold">{{ \App\Support\Money::format($loan->principal_amount_minor) }}</td>
                      <td class="num font-bold" style="color:var(--danger)">
                        {{ \App\Support\Money::format($loan->monthly_installment_minor) }}/mo
                      </td>
                      <td class="num">
                        <div>{{ \App\Support\Money::format($loan->total_repaid_minor) }} / <strong style="color:var(--amber-700)">{{ \App\Support\Money::format($loan->balance_minor) }}</strong></div>
                        <div style="background:#e2e8f0;height:6px;border-radius:3px;margin-top:4px;overflow:hidden">
                          <div style="background:#16a34a;width:{{ $loan->repaymentPercentage() }}%;height:6px"></div>
                        </div>
                      </td>
                      <td>
                        <span class="badge {{ ['active' => 'success', 'paused' => 'warning', 'completed' => 'info', 'written_off' => 'muted'][$loan->status] ?? 'muted' }} plain">
                          {{ ucfirst($loan->status) }}
                        </span>
                      </td>
                      @if ($canSetSalary)
                        <td class="actions">
                          @if ($loan->status === 'active' && $loan->balance_minor > 0)
                            <a href="#modal-repay-{{ $loan->id }}" class="btn btn-ghost btn-sm">Repay</a>
                          @endif
                          @if ($loan->balance_minor > 0)
                            <form method="POST" action="{{ route('staff-loans.toggle', $loan) }}" style="display:inline">
                              @csrf
                              <button type="submit" class="btn btn-ghost btn-sm text-muted">
                                {{ $loan->status === 'active' ? 'Pause' : 'Resume' }}
                              </button>
                            </form>
                          @endif
                        </td>
                      @endif
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="p-16 text-center text-muted" style="padding:24px;text-align:center">
              <p>No active or historical loans recorded for {{ $employee->name }}.</p>
              @if ($canSetSalary)
                <a href="#modal-grant-loan" class="btn btn-outline btn-sm mt-8">Grant New Loan / Cash Advance &rarr;</a>
              @endif
            </div>
          @endif
        </div>
      </div>

      {{-- Section 4: Dynamic Variable Earnings (Commissions & Overtime) Card --}}
      <div class="card mb-16">
        <div class="card-head flex-between">
          <div>
            <h3>Variable Earnings (Commissions &amp; Overtime)</h3>
            <p>Transaction-based performance bonuses, sales milestones, and overtime logs queued for payroll</p>
          </div>
          @if ($canSetSalary)
            <div class="flex" style="gap:8px">
              <a href="#modal-add-commission" class="btn btn-outline btn-sm">
                <span>+</span> Add Commission
              </a>
              <a href="#modal-record-overtime" class="btn btn-outline btn-sm">
                <span>+</span> Record Overtime
              </a>
            </div>
          @else
            <span class="badge muted plain" style="font-size:0.75rem">&#128274; Read Only</span>
          @endif
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Ref &amp; Type</th>
                  <th>Date &amp; Details</th>
                  <th class="num">Amount</th>
                  <th>Target Period</th>
                  <th>Status</th>
                  @if ($canSetSalary)
                    <th class="actions"></th>
                  @endif
                </tr>
              </thead>
              <tbody>
                {{-- Commissions --}}
                @foreach ($employee->commissions as $comm)
                  <tr>
                    <td class="perm-key font-bold">
                      <span class="badge info plain" style="font-size:0.7rem;padding:2px 6px">COMM</span>
                      {{ $comm->reference }}
                    </td>
                    <td>
                      <strong>{{ $comm->description }}</strong>
                      <div class="cell-sub">Earned: {{ \App\Support\Wat::date($comm->earned_on) }}</div>
                    </td>
                    <td class="num font-bold" style="color:var(--primary-dark)">{{ \App\Support\Money::format($comm->amount_minor) }}</td>
                    <td>{{ date('M Y', mktime(0, 0, 0, $comm->period_month, 1, $comm->period_year)) }}</td>
                    <td>
                      @if ($comm->status === 'processed_in_payroll')
                        <span class="badge success plain">Processed in Payroll</span>
                      @else
                        <span class="badge warning plain">Queued for Payroll</span>
                      @endif
                    </td>
                    @if ($canSetSalary)
                      <td class="actions">
                        @if ($comm->status !== 'processed_in_payroll')
                          <form method="POST" action="{{ route('commissions.destroy', $comm) }}" onsubmit="return confirm('Remove this unbilled commission?')" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-danger">&times; Remove</button>
                          </form>
                        @endif
                      </td>
                    @endif
                  </tr>
                @endforeach

                {{-- Overtime --}}
                @foreach ($employee->overtimes as $ot)
                  <tr>
                    <td class="perm-key font-bold">
                      <span class="badge amber plain" style="font-size:0.7rem;padding:2px 6px">OVERTIME</span>
                      {{ $ot->reference }}
                    </td>
                    <td>
                      <strong>{{ $ot->description }}</strong>
                      <div class="cell-sub">{{ $ot->hours }} hrs @ {{ \App\Support\Money::format($ot->hourly_rate_minor) }}/hr &middot; {{ \App\Support\Wat::date($ot->worked_on) }}</div>
                    </td>
                    <td class="num font-bold" style="color:var(--primary-dark)">{{ \App\Support\Money::format($ot->total_amount_minor) }}</td>
                    <td>{{ date('M Y', mktime(0, 0, 0, $ot->period_month, 1, $ot->period_year)) }}</td>
                    <td>
                      @if ($ot->status === 'processed_in_payroll')
                        <span class="badge success plain">Processed in Payroll</span>
                      @else
                        <span class="badge warning plain">Queued for Payroll</span>
                      @endif
                    </td>
                    @if ($canSetSalary)
                      <td class="actions">
                        @if ($ot->status !== 'processed_in_payroll')
                          <form method="POST" action="{{ route('overtimes.destroy', $ot) }}" onsubmit="return confirm('Remove this unbilled overtime log?')" style="display:inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm text-danger">&times; Remove</button>
                          </form>
                        @endif
                      </td>
                    @endif
                  </tr>
                @endforeach

                @if ($employee->commissions->isEmpty() && $employee->overtimes->isEmpty())
                  <tr>
                    <td colspan="6" class="empty">No commissions or overtime entries recorded.</td>
                  </tr>
                @endif
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    {{-- Right Sidebar Column --}}
    <div class="stack" style="flex:1">
      {{-- Card 1: Estimated Current Month Payroll Payout Card --}}
      <div class="card mb-16" style="border-top:3px solid var(--primary-dark,#16a34a)">
        <div class="card-head">
          <div>
            <h3>Estimated {{ $currentMonthName }} Payout</h3>
            <p>Projected bank disbursement for upcoming payroll run</p>
          </div>
        </div>
        <div class="card-body">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 14px;margin-bottom:14px">
            <div class="meta-label text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px">Estimated Net Bank Disbursement</div>
            <div class="font-bold" id="sidebar-est-net" style="font-size:1.5rem;color:#15803d;margin-top:2px">
              {{ \App\Support\Money::format($currentMonthEstimatedNet) }}
            </div>
            <div class="cell-sub" style="color:#166534;margin-top:4px">
              Destination: {{ $employee->bank_name ?: 'Bank' }} ({{ $employee->bank_account_masked ?: '••••••••' }})
            </div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Regular Base Gross</div>
            <div class="meta-value font-bold" id="sidebar-base-gross">{{ \App\Support\Money::format($baseGross) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Queued Commissions</div>
            <div class="meta-value" style="color:#7c3aed" id="sidebar-commissions">+{{ \App\Support\Money::format($unbilledCommissions) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Queued Overtime</div>
            <div class="meta-value" style="color:#7c3aed" id="sidebar-overtimes">+{{ \App\Support\Money::format($unbilledOvertimes) }}</div>
          </div>

          <div class="meta-item mb-12 flex-between font-bold" style="border-top:1px dashed #e2e8f0;padding-top:6px">
            <div>Total Monthly Gross</div>
            <div id="sidebar-total-gross" style="color:var(--primary-dark)">{{ \App\Support\Money::format($currentMonthGross) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Statutory Pension ({{ number_format($profile->pension_rate_pct ?: 8.0, 1) }}%)</div>
            <div class="meta-value text-danger" id="sidebar-pension">-{{ \App\Support\Money::format($profile->is_pension_exempt ? 0 : round($baseGross * ($profile->pension_rate_pct ?: 8.0) / 100)) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Statutory PAYE Tax</div>
            <div class="meta-value text-danger" id="sidebar-tax">-{{ \App\Support\Money::format($profile->is_tax_exempt ? 0 : round(max(0, $baseGross - ($profile->is_pension_exempt ? 0 : round($baseGross * ($profile->pension_rate_pct ?: 8.0) / 100))) * ($profile->tax_rate_pct ?: 7.0) / 100)) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label">Other Regular Deductions</div>
            <div class="meta-value text-danger" id="sidebar-other-deductions">-{{ \App\Support\Money::format((int)$profile->nhis_minor + (int)$profile->union_dues_minor + (int)$profile->other_deduction_minor) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between">
            <div class="meta-label font-bold" style="color:var(--amber-800)">Staff Loan Repayment</div>
            <div class="meta-value font-bold" style="color:var(--danger)" id="sidebar-loan-deduction">-{{ \App\Support\Money::format($activeLoanMonthly) }}</div>
          </div>

          <div class="meta-item mb-8 flex-between font-bold" style="border-top:1px dashed #e2e8f0;padding-top:6px;color:var(--danger)">
            <div>Total Deductions</div>
            <div id="sidebar-total-deductions">-{{ \App\Support\Money::format($totalCurrentMonthDeductions) }}</div>
          </div>
        </div>
      </div>

      {{-- Card 2: Active Loans & Variable Ledger Quick Glance --}}
      <div class="card mb-16">
        <div class="card-head">
          <div>
            <h3>Active Loans &amp; Queued Pay</h3>
            <p>Summary of dynamic monthly items</p>
          </div>
        </div>
        <div class="card-body">
          <div class="meta-item mb-12">
            <div class="meta-label">Outstanding Loan Debt</div>
            <div class="meta-value font-bold" style="color:var(--amber-700)">
              {{ \App\Support\Money::format($activeLoanBalance) }}
            </div>
            <div class="cell-sub">Auto-deducted: {{ \App\Support\Money::format($activeLoanMonthly) }}/mo</div>
          </div>
          <div class="meta-item mb-12">
            <div class="meta-label">Queued Commissions ({{ $employee->commissions->where('status', 'approved')->whereNull('payslip_id')->count() }})</div>
            <div class="meta-value font-bold" style="color:#7c3aed">
              {{ \App\Support\Money::format($unbilledCommissions) }}
            </div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Queued Overtime ({{ $employee->overtimes->where('status', 'approved')->whereNull('payslip_id')->count() }})</div>
            <div class="meta-value font-bold" style="color:#7c3aed">
              {{ \App\Support\Money::format($unbilledOvertimes) }}
            </div>
          </div>
        </div>
      </div>

      {{-- Card 3: Employee Details --}}
      <div class="card mb-16">
        <div class="card-head">
          <div>
            <h3>Employee Details</h3>
            <p>{{ $employee->code }}</p>
          </div>
        </div>
        <div class="card-body">
          <div class="meta-item mb-12">
            <div class="meta-label">Department</div>
            <div class="meta-value font-bold">{{ $employee->department?->name ?? '—' }}</div>
          </div>
          <div class="meta-item mb-12">
            <div class="meta-label">Position / Role</div>
            <div class="meta-value">{{ $employee->position ?? '—' }}</div>
          </div>
          <div class="meta-item mb-12">
            <div class="meta-label">Bank Destination</div>
            <div class="meta-value font-bold">{{ $employee->bank_name ?: 'Bank' }}</div>
            <div class="cell-sub perm-key">{{ $employee->bank_account_masked ?: '••••••••' }}</div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Employment Status</div>
            <div class="meta-value">
              <span class="badge {{ ['confirmed' => 'success', 'probation' => 'warning', 'on_leave' => 'info'][$employee->status] ?? 'muted' }}">
                {{ ucfirst($employee->status) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Modal: Grant Staff Loan / Cash Advance --}}
  @if ($canSetSalary)
    <div id="modal-grant-loan" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Grant Staff Loan / Cash Advance</h3>
            <p>{{ $employee->name }} &middot; {{ $employee->code }}</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('employees.loans.store', $employee) }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field full">
                <label for="ln-type">Loan Scheme / Type</label>
                <select id="ln-type" name="compensation_type_id">
                  <option value="">General Staff Loan</option>
                  @foreach ($loanTypes as $lt)
                    <option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->code }})</option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="ln-principal">Principal Amount (₦) <span class="req">*</span></label>
                <input type="text" id="ln-principal" name="principal_amount" inputmode="decimal" placeholder="e.g. 100,000" required />
              </div>

              <div class="field">
                <label for="ln-installment">Monthly Deduction (₦) <span class="req">*</span></label>
                <input type="text" id="ln-installment" name="monthly_installment" inputmode="decimal" placeholder="e.g. 20,000" required />
                <div class="hint">Auto-deducted on each monthly payroll.</div>
              </div>

              <div class="field">
                <label for="ln-disbursed">Disbursement Date <span class="req">*</span></label>
                <input type="date" id="ln-disbursed" name="disbursed_on" value="{{ date('Y-m-d') }}" required />
              </div>

              <div class="field">
                <label for="ln-month">Start Deduction Month <span class="req">*</span></label>
                <div style="display:flex;gap:8px">
                  <select name="start_period_month" required style="flex:1">
                    @for ($m = 1; $m <= 12; $m++)
                      <option value="{{ $m }}" @selected($m == date('n'))>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                    @endfor
                  </select>
                  <input type="number" name="start_period_year" value="{{ date('Y') }}" min="2024" max="2035" required style="width:90px" />
                </div>
              </div>

              <div class="field full">
                <label for="ln-notes">Notes / Approval Justification</label>
                <input type="text" id="ln-notes" name="notes" placeholder="e.g. Approved by MD for vehicle repairs" />
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Grant Loan &rarr;</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modal: Record Commission --}}
    <div id="modal-add-commission" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Record Commission / Incentive</h3>
            <p>{{ $employee->name }} &middot; Variable performance earnings</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('employees.commissions.store', $employee) }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field full">
                <label for="com-type">Commission Type / Milestone</label>
                <select id="com-type" name="compensation_type_id">
                  <option value="">General Performance Commission</option>
                  @foreach ($commissionTypes as $ct)
                    <option value="{{ $ct->id }}">{{ $ct->name }} ({{ $ct->code }})</option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="com-amount">Commission Amount (₦) <span class="req">*</span></label>
                <input type="text" id="com-amount" name="amount" inputmode="decimal" placeholder="e.g. 25,000" required />
              </div>

              <div class="field">
                <label for="com-date">Earned Date <span class="req">*</span></label>
                <input type="date" id="com-date" name="earned_on" value="{{ date('Y-m-d') }}" required />
              </div>

              <div class="field full">
                <label for="com-desc">Description / Reason <span class="req">*</span></label>
                <input type="text" id="com-desc" name="description" placeholder="e.g. Exceeded milk collection milestone by 2,000L" required />
                <div class="hint">Will appear as an itemized earnings line on the monthly payslip.</div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Record Commission &rarr;</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modal: Record Overtime --}}
    <div id="modal-record-overtime" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Record Overtime Shift</h3>
            <p>{{ $employee->name }} &middot; Additional worked hours</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('employees.overtimes.store', $employee) }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field">
                <label for="ot-hours">Hours Worked <span class="req">*</span></label>
                <input type="number" step="0.5" id="ot-hours" name="hours" placeholder="e.g. 8.5" required />
              </div>

              <div class="field">
                <label for="ot-rate">Hourly Rate (₦/hr) <span class="req">*</span></label>
                <input type="text" id="ot-rate" name="hourly_rate" inputmode="decimal" placeholder="e.g. 1,500" required />
              </div>

              <div class="field">
                <label for="ot-date">Date Worked <span class="req">*</span></label>
                <input type="date" id="ot-date" name="worked_on" value="{{ date('Y-m-d') }}" required />
              </div>

              <div class="field">
                <label for="ot-desc">Duty / Description <span class="req">*</span></label>
                <input type="text" id="ot-desc" name="description" placeholder="e.g. Night milk processing shift" required />
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Overtime &rarr;</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Modals: Repay Loans --}}
    @foreach ($employee->staffLoans as $loan)
      @if ($loan->balance_minor > 0)
        <div id="modal-repay-{{ $loan->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog">
            <div class="modal-head">
              <div>
                <h3>Record Loan Repayment</h3>
                <p>{{ $loan->reference }} &middot; Outstanding: {{ \App\Support\Money::format($loan->balance_minor) }}</p>
              </div>
              <a href="#" class="modal-close">&times;</a>
            </div>
            <form method="POST" action="{{ route('staff-loans.repay', $loan) }}">
              @csrf
              <div class="modal-body">
                <div class="form-grid">
                  <div class="field full">
                    <label for="rp-amount-{{ $loan->id }}">Repayment Amount (₦) <span class="req">*</span></label>
                    <input type="text" id="rp-amount-{{ $loan->id }}" name="amount" inputmode="decimal"
                           value="{{ \App\Support\Money::decimal($loan->monthly_installment_minor) }}" required />
                    <div class="hint">Max repayable balance: {{ \App\Support\Money::format($loan->balance_minor) }}</div>
                  </div>

                  <div class="field full">
                    <label for="rp-notes-{{ $loan->id }}">Payment Reference / Remarks</label>
                    <input type="text" id="rp-notes-{{ $loan->id }}" name="notes" placeholder="e.g. Direct cash settlement at finance desk" />
                  </div>
                </div>
              </div>
              <div class="modal-foot">
                <a href="#" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">Confirm Repayment &rarr;</button>
              </div>
            </form>
          </div>
        </div>
      @endif
    @endforeach
  @endif

  {{-- Live Dynamic Calculation, Thousand Separators & Edit-Mode Toggle Script --}}
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('salary-form');
      const allInputs = form.querySelectorAll('input, select, textarea');
      const calcInputs = form.querySelectorAll('.calc-input');
      const moneyInputs = form.querySelectorAll('.money-input');
      const btnToggle = document.getElementById('btn-toggle-edit');
      const btn503020 = document.getElementById('btn-apply-50-30-20');
      const editActions = document.getElementById('card-edit-actions');
      const btnCancel = document.getElementById('btn-cancel-edit');

      // Fixed variables from PHP
      const variableAdditions = {{ $totalVariableAdditions / 100 }};
      const activeLoanMonthly = {{ $activeLoanMonthly / 100 }};

      let isEditMode = false;
      const initialValues = new Map();

      // Store initial values for cancel action
      allInputs.forEach(input => {
        if (input.type === 'checkbox') {
          initialValues.set(input, input.checked);
        } else {
          initialValues.set(input, input.value);
        }
      });

      function setEditMode(enabled) {
        isEditMode = enabled;
        allInputs.forEach(input => {
          input.disabled = !enabled;
        });

        if (btnToggle) {
          btnToggle.innerHTML = enabled ? '<span>&#128274;</span> Lock Editing' : '<span>&#9998;</span> Edit Salary Structure';
          btnToggle.className = enabled ? 'btn btn-ghost btn-sm' : 'btn btn-outline btn-sm';
        }

        if (btn503020) {
          btn503020.style.display = enabled ? 'inline-flex' : 'none';
        }

        if (editActions) {
          editActions.style.display = enabled ? 'flex' : 'none';
        }
      }

      if (btnToggle) {
        btnToggle.addEventListener('click', function () {
          setEditMode(!isEditMode);
        });
      }

      if (btnCancel) {
        btnCancel.addEventListener('click', function () {
          allInputs.forEach(input => {
            if (initialValues.has(input)) {
              if (input.type === 'checkbox') {
                input.checked = initialValues.get(input);
              } else {
                input.value = initialValues.get(input);
              }
            }
          });
          setEditMode(false);
          recalculate();
        });
      }

      function parseMoney(id) {
        const el = document.getElementById(id);
        if (!el) return 0;
        const val = parseFloat(String(el.value).replace(/,/g, ''));
        return isNaN(val) || val < 0 ? 0 : val;
      }

      function formatNumberString(val) {
        if (!val && val !== 0) return '';
        let clean = String(val).replace(/[^0-9.]/g, '');
        let parts = clean.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        if (parts.length > 2) {
          parts = [parts[0], parts.slice(1).join('')];
        }
        return parts.join('.');
      }

      function formatMoneyDisplay(amount) {
        return '₦ ' + amount.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      function recalculate() {
        const basic = parseMoney('f-basic');
        const housing = parseMoney('f-housing');
        const transport = parseMoney('f-transport');
        const utility = parseMoney('f-utility');
        const medical = parseMoney('f-medical');
        const otherAllowance = parseMoney('f-other-allowance');

        const baseGross = basic + housing + transport + utility + medical + otherAllowance;
        const totalGross = baseGross + variableAdditions;

        const isPensionExempt = document.getElementById('f-pension-exempt').checked;
        const pensionRate = parseMoney('f-pension-rate') || 8.0;
        const pension = isPensionExempt ? 0 : Math.round((baseGross * pensionRate) / 100);

        const isTaxExempt = document.getElementById('f-tax-exempt').checked;
        const taxRate = parseMoney('f-tax-rate') || 7.0;
        const taxable = Math.max(0, baseGross - pension);
        const tax = isTaxExempt ? 0 : Math.round((taxable * taxRate) / 100);

        const nhis = parseMoney('f-nhis');
        const union = parseMoney('f-union');
        const otherDeduction = parseMoney('f-other-deduction');
        const otherDeductionsTotal = nhis + union + otherDeduction;

        const regularDeductions = pension + tax + otherDeductionsTotal;
        const totalMonthDeductions = regularDeductions + activeLoanMonthly;
        const currentMonthNet = Math.max(0, totalGross - totalMonthDeductions);

        // Top Preview Bar
        document.getElementById('preview-base-gross').textContent = formatMoneyDisplay(baseGross);
        document.getElementById('preview-total-deductions').textContent = '- ' + formatMoneyDisplay(totalMonthDeductions);
        document.getElementById('preview-current-net').textContent = formatMoneyDisplay(currentMonthNet);

        // Sidebar Breakdown
        const sbEstNet = document.getElementById('sidebar-est-net');
        const sbBaseGross = document.getElementById('sidebar-base-gross');
        const sbTotalGross = document.getElementById('sidebar-total-gross');
        const sbPension = document.getElementById('sidebar-pension');
        const sbTax = document.getElementById('sidebar-tax');
        const sbOtherDed = document.getElementById('sidebar-other-deductions');
        const sbTotalDed = document.getElementById('sidebar-total-deductions');

        if (sbEstNet) sbEstNet.textContent = formatMoneyDisplay(currentMonthNet);
        if (sbBaseGross) sbBaseGross.textContent = formatMoneyDisplay(baseGross);
        if (sbTotalGross) sbTotalGross.textContent = formatMoneyDisplay(totalGross);
        if (sbPension) sbPension.textContent = '- ' + formatMoneyDisplay(pension);
        if (sbTax) sbTax.textContent = '- ' + formatMoneyDisplay(tax);
        if (sbOtherDed) sbOtherDed.textContent = '- ' + formatMoneyDisplay(otherDeductionsTotal);
        if (sbTotalDed) sbTotalDed.textContent = '- ' + formatMoneyDisplay(totalMonthDeductions);
      }

      // Format money inputs live with thousand separators
      moneyInputs.forEach(input => {
        input.addEventListener('input', function () {
          const cursor = this.selectionStart;
          const oldLen = this.value.length;
          this.value = formatNumberString(this.value);
          const newLen = this.value.length;
          this.setSelectionRange(cursor + (newLen - oldLen), cursor + (newLen - oldLen));
          recalculate();
        });

        input.addEventListener('blur', function () {
          const num = parseFloat(this.value.replace(/,/g, ''));
          if (!isNaN(num) && num >= 0) {
            this.value = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          } else if (this.value === '') {
            this.value = '0.00';
          }
          recalculate();
        });
      });

      calcInputs.forEach(input => {
        input.addEventListener('change', recalculate);
      });

      // Quick 50/30/20 standard template
      if (btn503020) {
        btn503020.addEventListener('click', function () {
          const currentGross = parseMoney('f-basic') + parseMoney('f-housing') + parseMoney('f-transport') || 100000;
          const b = (currentGross * 0.50).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          const h = (currentGross * 0.30).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          const t = (currentGross * 0.20).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

          document.getElementById('f-basic').value = b;
          document.getElementById('f-housing').value = h;
          document.getElementById('f-transport').value = t;
          recalculate();
        });
      }

      recalculate();
    });
  </script>
@endsection
