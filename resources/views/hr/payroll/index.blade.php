@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
  <div class="page-head">
    <div>
      <h1>Payroll</h1>
      <p>Staff payroll runs, sent for approval before payment</p>
    </div>
    <div class="page-actions">
      @if ($canRun)<a href="#modal-run" class="btn btn-primary">+ Generate Run</a>@endif
    </div>
  </div>

  <div class="alert warn mb-16">
    <span>&#128274;</span>
    <div>
      <strong>This screen covers staff payroll only.</strong>
      Farmer and transport payments are not available yet.
    </div>
  </div>

  {{--
    Said on the screen for the same reason §15.1 is: an HR officer who read the
    prototype will assume a payroll components table and attendance proration
    exist. They do not, and a run that looks authoritative and is not is worse
    than one that admits what it left out.
  --}}
  <div class="alert info mb-16">
    <span>&#9888;</span>
    <div>
      <strong>The deduction schedule is a placeholder, and runs are not prorated for absence.</strong>
      Every payslip applies a flat 8% pension and 7% PAYE on the taxable balance, and pays the full
      monthly gross to everyone on the register — there is no earnings and deductions table to configure,
      no proration for a mid-month joiner or leaver, and no unpaid-absence or lateness deduction.
      Confirm each net figure against the statutory bands before it is paid.
    </div>
  </div>

  @if ($current)
    <div class="grid grid-4 mb-16">
      <div class="stat blue"><div class="stat-label">{{ $current->periodLabel() }}</div>
        <div class="stat-value">{{ $current->employee_count }}</div>
        <div class="stat-foot">employees on this run</div></div>
      <div class="stat green"><div class="stat-label">Gross</div>
        <div class="stat-value">{{ \App\Support\Money::compact($current->gross_total_minor) }}</div>
        <div class="stat-foot">before deductions</div></div>
      <div class="stat amber"><div class="stat-label">Deductions</div>
        <div class="stat-value">{{ \App\Support\Money::compact($current->deductions_total_minor) }}</div>
        <div class="stat-foot">pension and PAYE</div></div>
      <div class="stat"><div class="stat-label">Net</div>
        <div class="stat-value">{{ \App\Support\Money::compact($current->net_total_minor) }}</div>
        <div class="stat-foot">
          <span class="badge {{ ['draft' => 'muted', 'processing' => 'warning', 'approved' => 'success', 'paid' => 'success'][$current->status] ?? 'muted' }}">
            {{ ucfirst($current->status) }}</span>
        </div></div>
    </div>
  @endif

  <div class="stack">
    @if ($current)
      <div class="card">
        <div class="card-head">
          <div><h3>{{ $current->periodLabel() }} Payslips</h3>
            <p>Test accounts were excluded when this run was generated</p></div>
          @if ($canRun && $current->status === 'draft')
            <form method="POST" action="{{ route('payroll.submit', $current) }}">
              @csrf
              <button type="submit" class="btn btn-primary btn-sm">Submit for approval</button>
            </form>
          @endif
          @if ($canMarkPaid && $current->status === 'approved')
            <form method="POST" action="{{ route('payroll.paid', $current) }}">
              @csrf
              <button type="submit" class="btn btn-primary btn-sm">Mark paid</button>
            </form>
          @endif
          @if ($current->status === 'paid' && $current->paid_at)
            <span class="badge success">Paid {{ \App\Support\Wat::dateTime($current->paid_at) }}</span>
          @endif
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Reference</th><th>Employee</th><th>Department</th>
                <th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net</th>
                <th class="actions"></th></tr></thead>
              <tbody>
                @forelse ($payslips as $payslip)
                  <tr>
                    <td class="perm-key">{{ $payslip->reference }}</td>
                    <td>{{ $payslip->employee?->name }}</td>
                    <td>{{ $payslip->employee?->department?->name ?? '—' }}</td>
                    <td class="num">{{ \App\Support\Money::format($payslip->gross_minor) }}</td>
                    <td class="num">{{ \App\Support\Money::format($payslip->deductions_minor) }}</td>
                    <td class="num font-bold">{{ \App\Support\Money::format($payslip->net_minor) }}</td>
                    <td class="actions">
                      <a href="{{ route('payroll.payslips.show', $payslip) }}" class="btn btn-ghost btn-sm">Open</a>
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="7">@include('partials.empty', ['title' => 'No payslips on this run', 'icon' => '&#128181;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @include('partials.pagination', ['paginator' => $payslips, 'noun' => 'payslips'])
      </div>
    @endif

    <div class="card">
      <div class="card-head"><div><h3>Runs</h3></div></div>
      <div class="card-body flush">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Period</th><th class="num">Employees</th><th class="num">Gross</th>
              <th class="num">Net</th><th>Stage</th><th>Status</th><th>Run by</th></tr></thead>
            <tbody>
              @forelse ($runs as $run)
                <tr>
                  <td class="font-bold">{{ $run->periodLabel() }}</td>
                  <td class="num">{{ $run->employee_count }}</td>
                  <td class="num">{{ \App\Support\Money::format($run->gross_total_minor) }}</td>
                  <td class="num font-bold">{{ \App\Support\Money::format($run->net_total_minor) }}</td>
                  <td>{{ $run->workflowInstance?->currentStage?->name ?? '—' }}</td>
                  <td><span class="badge {{ ['draft' => 'muted', 'processing' => 'warning', 'approved' => 'success', 'paid' => 'success'][$run->status] ?? 'muted' }}">
                    {{ ucfirst($run->status) }}</span></td>
                  <td>{{ $run->runBy?->name }}</td>
                </tr>
              @empty
                <tr><td colspan="7">@include('partials.empty', ['title' => 'No payroll runs yet', 'icon' => '&#128181;'])</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
      @include('partials.pagination', ['paginator' => $runs, 'noun' => 'runs'])
    </div>
  </div>

  @if ($canRun)
    <div id="modal-run" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Generate Payroll Run</h3><p>One run per month</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payroll.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-run" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-run'])
            <div class="form-grid">
              <div class="field"><label for="pr-month">Month <span class="req">*</span></label>
                <select id="pr-month" name="period_month" required>
                  @foreach (range(1, 12) as $month)
                    <option value="{{ $month }}" @selected((int) $nextPeriod->format('n') === $month)>
                      {{ \Illuminate\Support\Carbon::create(null, $month, 1)->format('F') }}
                    </option>
                  @endforeach
                </select></div>
              <div class="field"><label for="pr-year">Year <span class="req">*</span></label>
                <input type="number" id="pr-year" name="period_year" value="{{ $nextPeriod->format('Y') }}"
                       min="2020" max="2100" required /></div>
            </div>
            <div class="alert info mt-16">
              <span>&#129514;</span>
              <div>Employees whose account is flagged as a test user are excluded.</div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Generate</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
