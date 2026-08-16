@extends('layouts.app')
@section('title', 'Payslip '.$payslip->reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    @can('hr.payroll.view')
      <a href="{{ route('payroll.index') }}">Payroll</a><span class="sep">/</span>
    @else
      <a href="{{ route('profile') }}">My Profile</a><span class="sep">/</span>
    @endcan
    <span class="here">{{ $payslip->reference }}</span>
  </div>

  @if ($isOwn)
    <div class="alert info mb-16">
      <span>&#8505;&#65039;</span>
      <div>This is your own payslip.</div>
    </div>
  @endif

  @if ($payslip->payrollRun?->status === 'draft' && (auth()->user()->can('hr.payroll.create') || auth()->user()->can('hr.payroll.edit')))
    <div class="card mb-16" style="background:#f0fdf4;border:1px solid #bbf7d0">
      <div class="card-head flex-between" style="padding:12px 18px">
        <div style="color:#166534">
          <strong>Draft Payslip &middot; {{ $payslip->payrollRun->periodLabel() }}</strong>
          <div style="font-size:12.5px;color:#15803d">You can adjust this employee's salary profile, commissions, or recalculate/remove this draft payslip.</div>
        </div>
        <div class="flex" style="gap:8px">
          @if ($payslip->employee)
            <a href="{{ route('employees.salary.edit', $payslip->employee) }}" class="btn btn-outline btn-sm">
              Edit Salary Structure &rarr;
            </a>
          @endif
          <form method="POST" action="{{ route('payroll.payslips.recalculate', $payslip) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">
              <span>&#8635;</span> Recalculate
            </button>
          </form>
          <form method="POST" action="{{ route('payroll.payslips.destroy', $payslip) }}" style="display:inline" onsubmit="return confirm('Remove {{ $payslip->employee?->name }} from this draft payroll run?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-ghost btn-sm text-danger">
              Remove from Run
            </button>
          </form>
        </div>
      </div>
    </div>
  @endif

  <div class="payslip">
    <div class="payslip-head">
      <div>
        <h1>Payslip</h1>
        <div class="text-muted">{{ $payslip->reference }} &middot; {{ $payslip->payrollRun?->periodLabel() }}</div>
      </div>
      <div class="right">
        <div class="font-bold">Gondal Fulbe Development Co.</div>
        <div class="text-small text-muted">Kano State, Nigeria</div>
      </div>
    </div>

    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Employee</div>
        <div class="meta-value">{{ $payslip->employee?->name }}</div></div>
      <div class="meta-item"><div class="meta-label">Code</div>
        <div class="meta-value mono">{{ $payslip->employee?->code }}</div></div>
      <div class="meta-item"><div class="meta-label">Department</div>
        <div class="meta-value">{{ $payslip->employee?->department?->name ?? '—' }}</div></div>
      <div class="meta-item"><div class="meta-label">Grade</div>
        <div class="meta-value">{{ $payslip->employee?->grade_level ?? '—' }}</div></div>
    </div>

    <div class="divider"></div>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Earnings</th><th class="num">Amount</th></tr></thead>
        <tbody>
          @foreach (($payslip->breakdown['earnings'] ?? []) as $line)
            <tr>
              <td>{{ $line['label'] }}</td>
              <td class="num">{{ \App\Support\Money::format($line['amount_minor']) }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr class="total-row"><th>Gross</th>
            <th class="num">{{ \App\Support\Money::format($payslip->gross_minor) }}</th></tr>
        </tfoot>
      </table>
    </div>

    <div class="table-wrap mt-16">
      <table>
        <thead><tr><th>Deductions</th><th class="num">Amount</th></tr></thead>
        <tbody>
          @foreach (($payslip->breakdown['deductions'] ?? []) as $line)
            <tr>
              <td>{{ $line['label'] }}</td>
              <td class="num">{{ \App\Support\Money::format($line['amount_minor']) }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr class="total-row"><th>Total deductions</th>
            <th class="num">{{ \App\Support\Money::format($payslip->deductions_minor) }}</th></tr>
        </tfoot>
      </table>
    </div>

    <div class="divider"></div>

    <div class="meta-grid cols-3">
      <div class="meta-item"><div class="meta-label">Net pay</div>
        <div class="meta-value big">{{ \App\Support\Money::format($payslip->net_minor) }}</div></div>
      <div class="meta-item"><div class="meta-label">Year to date (gross)</div>
        <div class="meta-value">{{ \App\Support\Money::format($payslip->ytd['gross_minor'] ?? 0) }}</div></div>
      <div class="meta-item"><div class="meta-label">Bank</div>
        <div class="meta-value">{{ $payslip->employee?->bank_name ?? '—' }}</div>
        <div class="cell-sub mono">{{ $payslip->employee?->bank_account_masked ?? '—' }}</div></div>
    </div>

    <div class="text-small text-muted mt-20">
      Only the last digits of your account number are shown. Query anything on this payslip with HR.
    </div>
  </div>
@endsection
