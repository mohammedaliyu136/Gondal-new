@extends('layouts.app')
@section('title', 'Payroll Disbursement — ' . $run->periodLabel())

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('payroll.index') }}">Payroll</a><span class="sep">/</span>
    <span class="here">Disbursement ({{ $run->periodLabel() }})</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Payroll Disbursement &mdash; {{ $run->periodLabel() }}</h1>
      <p>Review staff bank details and process net salary disbursement</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('payroll.index') }}" class="btn btn-outline">Back to Payroll</a>
      @if ($run->status === 'approved' && $canDisburse)
        <a href="#modal-disburse" class="btn btn-primary">Disburse Payment &rarr;</a>
      @endif
    </div>
  </div>

  @if ($run->status === 'paid')
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>
        <strong>Disbursement Completed.</strong>
        This payroll run was disbursed and settled on
        <strong>{{ $run->paid_at ? \App\Support\Wat::dateTime($run->paid_at) : 'recently' }}</strong>.
        Total net amount paid: <strong>{{ \App\Support\Money::format($run->net_total_minor) }}</strong>.
      </div>
    </div>
  @elseif ($run->status === 'approved')
    <div class="alert info mb-16">
      <span>&#8505;&#65039;</span>
      <div>
        <strong>Approved &amp; Ready for Payment.</strong>
        All workflow approval stages have been completed. Verify the banking details below and click
        <strong>Disburse Payment</strong> to execute the transfer batch or record the settlement.
      </div>
    </div>
  @else
    <div class="alert warn mb-16">
      <span>&#9888;</span>
      <div>
        <strong>Status: {{ ucfirst($run->status) }}.</strong>
        This payroll run has not completed its approval workflow yet. Payment disbursement requires an approved status.
      </div>
    </div>
  @endif

  {{-- Top Stat Summary --}}
  <div class="grid grid-4 mb-16">
    <div class="stat blue">
      <div class="stat-label">Period</div>
      <div class="stat-value" style="font-size:1.4rem">{{ $run->periodLabel() }}</div>
      <div class="stat-foot">{{ $run->employee_count }} employees on payroll</div>
    </div>
    <div class="stat">
      <div class="stat-label">Gross Salaries</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->gross_total_minor) }}</div>
      <div class="stat-foot">{{ \App\Support\Money::format($run->gross_total_minor) }} before deductions</div>
    </div>
    <div class="stat amber">
      <div class="stat-label">Statutory Deductions</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->deductions_total_minor) }}</div>
      <div class="stat-foot">Pension (8%) &amp; PAYE (7%)</div>
    </div>
    <div class="stat green">
      <div class="stat-label">Total Net Payable</div>
      <div class="stat-value" style="color:var(--primary-dark)">{{ \App\Support\Money::format($run->net_total_minor) }}</div>
      <div class="stat-foot">
        <span class="badge {{ ['draft' => 'muted', 'processing' => 'warning', 'approved' => 'success', 'paid' => 'success'][$run->status] ?? 'muted' }}">
          {{ ucfirst($run->status) }}
        </span>
      </div>
    </div>
  </div>

  {{-- Payment Channel & Gateway Card --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Disbursement Options &amp; Payment Gateway</h3>
        <p>Configured payout integrations for batch salary execution</p>
      </div>
      @if (auth()->user()?->hasPermission('admin.settings.edit'))
        <a href="{{ route('admin.settings') }}#payments" class="btn btn-ghost btn-sm">Configure Gateways</a>
      @endif
    </div>
    <div class="card-body">
      <div class="grid grid-4" style="gap:12px">
        <div class="card p-12" style="background:#f8fafc;border:1px solid #e2e8f0">
          <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:6px">
            <strong style="font-size:0.95rem">Direct Bank Settlement</strong>
            <span class="badge success plain">Available</span>
          </div>
          <p class="text-small text-muted" style="margin:0">Direct settlement via company bank accounts / corporate internet banking.</p>
        </div>
        @foreach ($gateways as $gwKey => $gw)
          <div class="card p-12" style="background:#f8fafc;border:1px solid #e2e8f0">
            <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:6px">
              <strong style="font-size:0.95rem">{{ $gw['label'] }}</strong>
              @if ($gw['is_enabled'])
                <span class="badge {{ $gw['mode'] === 'live' ? 'success' : 'info' }} plain">
                  {{ ucfirst($gw['mode']) }}
                </span>
              @else
                <span class="badge muted plain">Disabled</span>
              @endif
            </div>
            <p class="text-small text-muted" style="margin:0">
              {{ $gw['is_default'] ? '★ Default Gateway • ' : '' }}
              Automated API transfers &amp; recipient payouts.
            </p>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  {{-- Employee Payslips / Payout Schedule Table --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Employee Payout Schedule ({{ $payslips->total() }} Items)</h3>
        <p>Breakdown of individual net salaries and destination bank accounts</p>
      </div>
      @if ($run->status === 'approved' && $canDisburse)
        <a href="#modal-disburse" class="btn btn-primary btn-sm">Pay Now &rarr;</a>
      @endif
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Payslip Ref</th>
              <th>Employee</th>
              <th>Department</th>
              <th>Bank Account</th>
              <th class="num">Gross</th>
              <th class="num">Deductions</th>
              <th class="num">Net Payable</th>
              <th>Status</th>
              <th class="actions"></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($payslips as $payslip)
              <tr>
                <td class="perm-key">{{ $payslip->reference }}</td>
                <td>
                  <span class="font-bold">{{ $payslip->employee?->name }}</span>
                  <div class="cell-sub perm-key">{{ $payslip->employee?->code }}</div>
                </td>
                <td>{{ $payslip->employee?->department?->name ?? '—' }}</td>
                <td>
                  @if ($payslip->employee?->bank_name || $payslip->employee?->bank_account_masked)
                    <div class="font-bold text-small">{{ $payslip->employee?->bank_name ?: 'Bank' }}</div>
                    <div class="cell-sub perm-key">{{ $payslip->employee?->bank_account_masked ?: '••••••••' }}</div>
                  @else
                    <span class="text-muted text-small">No bank details</span>
                  @endif
                </td>
                <td class="num">{{ \App\Support\Money::format($payslip->gross_minor) }}</td>
                <td class="num text-danger" style="color:var(--danger)">
                  -{{ \App\Support\Money::format($payslip->deductions_minor) }}
                </td>
                <td class="num font-bold" style="color:var(--primary-dark)">
                  {{ \App\Support\Money::format($payslip->net_minor) }}
                </td>
                <td>
                  <span class="badge {{ $run->status === 'paid' ? 'success' : 'info' }}">
                    {{ $run->status === 'paid' ? 'Paid' : 'Pending' }}
                  </span>
                </td>
                <td class="actions">
                  <a href="{{ route('payroll.payslips.show', $payslip) }}" class="btn btn-ghost btn-sm" target="_blank">
                    Payslip
                  </a>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="9">
                  @include('partials.empty', ['title' => 'No payslip records found for this run', 'icon' => '&#128181;'])
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $payslips, 'noun' => 'payslips'])
  </div>

  @if ($run->status === 'approved' && $canDisburse)
    {{-- Modal: Disburse Payment --}}
    <div id="modal-disburse" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Confirm Payroll Disbursement</h3>
            <p>{{ $run->periodLabel() }} &middot; {{ $run->employee_count }} staff members</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payroll.disburse', $run) }}">
          @csrf
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-disburse'])

            <div class="alert info mb-16">
              <span>&#128176;</span>
              <div>
                You are about to disburse total net salaries of
                <strong style="font-size:1.05rem">{{ \App\Support\Money::format($run->net_total_minor) }}</strong>
                to <strong>{{ $run->employee_count }}</strong> employees.
              </div>
            </div>

            <div class="field mb-16">
              <label for="disburse-method">Disbursement Channel / Method <span class="req">*</span></label>
              <select id="disburse-method" name="payment_method" required>
                <option value="bank_transfer" selected>Direct Corporate Bank Settlement / EFT</option>
                @foreach ($gateways as $gwKey => $gw)
                  @if ($gw['is_enabled'])
                    <option value="{{ $gwKey }}">
                      {{ $gw['label'] }} API Transfer ({{ ucfirst($gw['mode']) }})
                    </option>
                  @endif
                @endforeach
                <option value="cash">Cash Voucher / Physical Payout</option>
              </select>
              <div class="hint">Select the channel used to disburse the funds.</div>
            </div>

            <div class="field mb-16">
              <label for="disburse-notes">Reference / Settlement Notes</label>
              <textarea id="disburse-notes" name="reference_notes" rows="2" placeholder="e.g. Batch Transfer Reference #88921 / Zenith Bank Corporate"></textarea>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Authorize and disburse payroll payment of {{ \App\Support\Money::format($run->net_total_minor) }}?');">
              Authorize &amp; Disburse Payment
            </button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
