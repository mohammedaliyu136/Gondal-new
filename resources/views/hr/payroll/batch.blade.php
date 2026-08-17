@extends('layouts.app')
@section('title', 'Payment Batch — ' . $batch->batch_reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('payroll.index') }}">Payroll</a><span class="sep">/</span>
    <a href="{{ route('payroll.payment', $run) }}">{{ $run->periodLabel() }}</a><span class="sep">/</span>
    <span class="here">Batch: {{ $batch->batch_reference }}</span>
  </div>

  <div class="page-head">
    <div>
      <div class="flex items-center gap-8 mb-4">
        <h1>Payment Batch &mdash; {{ $batch->batch_reference }}</h1>
        <span class="badge {{ ['completed' => 'success', 'processing' => 'warning', 'partially_completed' => 'warning', 'failed' => 'danger'][$batch->status] ?? 'muted' }}" style="font-size:0.85rem">
          {{ ucfirst(str_replace('_', ' ', $batch->status)) }}
        </span>
        @if ($batch->gateway_status)
          <span class="badge info plain" style="font-size:0.85rem">
            GW: {{ $batch->gateway_status }}
          </span>
        @endif
      </div>
      <p>Detailed payout schedule and live gateway settlement records for {{ $run->periodLabel() }}</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('payroll.payment', $run) }}" class="btn btn-outline">&larr; Back to Payment Overview</a>
      @if (in_array($batch->gateway, ['monnify', 'paystack']))
        <form method="POST" action="{{ route('payroll.batches.sync', [$run, $batch]) }}" style="display:inline">
          @csrf
          <button type="submit" class="btn btn-ghost" title="Re-query live gateway to synchronize batch, items, and payslip settlement records">
            &#8635; Sync Gateway Status
          </button>
        </form>
      @endif
      @php
        $isAwaitingOtp = !in_array($batch->status, ['completed', 'settled', 'failed', 'cancelled'])
          && !in_array(strtoupper($batch->gateway_status ?? ''), ['EXPIRED', 'FAILED', 'SUCCESS', 'COMPLETED', 'SETTLED', 'CANCELLED', 'REJECTED'])
          && in_array($batch->gateway, ['monnify', 'paystack']);
      @endphp
      @if ($isAwaitingOtp)
        <a href="#modal-otp-{{ $batch->id }}" class="btn btn-primary">
          Enter OTP &rarr;
        </a>
      @endif
    </div>
  </div>

  {{-- Summary Stats Grid --}}
  <div class="grid grid-4 mb-16">
    <div class="stat">
      <div class="stat-label">Total Amount</div>
      <div class="stat-value font-bold" style="color:var(--primary-dark)">{{ \App\Support\Money::format($batch->total_amount_minor) }}</div>
      <div class="stat-foot">Net salary payout across {{ $batch->total_items_count }} items</div>
    </div>
    <div class="stat blue">
      <div class="stat-label">Gateway &amp; Channel</div>
      <div class="stat-value" style="font-size:1.35rem">{{ ucfirst(str_replace('_', ' ', $batch->gateway)) }}</div>
      <div class="stat-foot perm-key">{{ $batch->gateway_batch_reference ?: $batch->batch_reference }}</div>
    </div>
    <div class="stat amber">
      <div class="stat-label">Transaction Fee</div>
      <div class="stat-value">{{ \App\Support\Money::format($batch->total_fee_minor) }}</div>
      <div class="stat-foot">Charged by {{ ucfirst($batch->gateway) }} gateway</div>
    </div>
    <div class="stat green">
      <div class="stat-label">Item Settlement</div>
      <div class="stat-value">
        <span style="color:var(--success)">{{ $batch->successful_items_count }}</span> / {{ $batch->total_items_count }}
      </div>
      <div class="stat-foot">
        @if ($batch->failed_items_count > 0)
          <span class="badge danger plain">{{ $batch->failed_items_count }} Failed</span>
        @else
          <span class="text-muted">0 Failures</span>
        @endif
      </div>
    </div>
  </div>

  {{-- Batch Metadata Card --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Batch Information &amp; Audit Trail</h3>
        <p>Execution metadata and gateway identifiers</p>
      </div>
    </div>
    <div class="card-body">
      <div class="grid grid-3" style="gap:16px">
        <div>
          <div class="text-muted text-small mb-2">Initiated By</div>
          <div class="font-bold">{{ $batch->initiatedBy?->name ?? 'System' }}</div>
          <div class="text-small text-muted">{{ $batch->disbursed_at ? \App\Support\Wat::dateTime($batch->disbursed_at) : '—' }}</div>
        </div>
        <div>
          <div class="text-muted text-small mb-2">Authorized By</div>
          <div class="font-bold">{{ $batch->authorizedBy?->name ?? ($batch->status === 'completed' ? 'System' : 'Pending Authorization') }}</div>
          <div class="text-small text-muted">{{ $batch->completed_at ? \App\Support\Wat::dateTime($batch->completed_at) : 'Awaiting completion' }}</div>
        </div>
        <div>
          <div class="text-muted text-small mb-2">Gateway Reference Code</div>
          <div class="font-bold perm-key">{{ $batch->gateway_batch_reference ?: '—' }}</div>
          <div class="text-small text-muted">Gateway Status: <strong>{{ $batch->gateway_status ?: 'PROCESSING' }}</strong></div>
        </div>
      </div>
      @if ($batch->notes)
        <div class="mt-12 pt-12" style="border-top:1px solid #e2e8f0">
          <div class="text-muted text-small">Disbursement Notes:</div>
          <p class="mb-0 mt-2 text-small">{{ $batch->notes }}</p>
        </div>
      @endif
    </div>
  </div>

  {{-- Batch Items Table Joined with Payslips --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Batch Payout Items &amp; Payslip Records ({{ $items->total() }} Employees)</h3>
        <p>Individual bank transfer records linked directly to salary payslips</p>
      </div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item Ref</th>
              <th>Employee &amp; Department</th>
              <th>Destination Bank Account</th>
              <th class="num">Net Salary</th>
              <th class="num">Fee</th>
              <th>Gateway Ref</th>
              <th>Gateway Status</th>
              <th>Status</th>
              <th>Gateway Response / Message</th>
              <th class="actions"></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($items as $item)
              @php
                $payslip = $item->recipient_id ? ($payslipsByEmployee[$item->recipient_id] ?? null) : null;
              @endphp
              <tr>
                <td class="perm-key font-bold">{{ $item->item_reference }}</td>
                <td>
                  <span class="font-bold">{{ $item->recipient_name }}</span>
                  @if ($item->recipient?->code)
                    <div class="cell-sub perm-key">{{ $item->recipient->code }}</div>
                  @endif
                  @if ($item->recipient?->department)
                    <div class="text-small text-muted">{{ $item->recipient->department->name }}</div>
                  @endif
                </td>
                <td>
                  <div class="font-bold text-small">{{ $item->recipient_bank_name ?: 'Bank' }}</div>
                  <div class="cell-sub perm-key">{{ $item->recipient_account_number }}</div>
                </td>
                <td class="num font-bold" style="color:var(--primary-dark)">
                  {{ \App\Support\Money::format($item->amount_minor) }}
                  @if ($payslip)
                    <div class="cell-sub text-muted" style="font-size:0.75rem">
                      Gross: {{ \App\Support\Money::compact($payslip->gross_minor) }} &middot; Ded: {{ \App\Support\Money::compact($payslip->deductions_minor) }}
                    </div>
                  @endif
                </td>
                <td class="num text-muted">{{ \App\Support\Money::format($item->fee_minor) }}</td>
                <td>
                  @if ($item->gateway_reference)
                    <span class="perm-key text-small font-bold">{{ $item->gateway_reference }}</span>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td>
                  @if ($item->gateway_status)
                    <span class="badge {{ in_array(strtoupper($item->gateway_status), ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID']) ? 'success plain' : (in_array(strtoupper($item->gateway_status), ['FAILED', 'ERROR', 'REVERSED']) ? 'danger plain' : 'warning plain') }}">
                      {{ $item->gateway_status }}
                    </span>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td>
                  <span class="badge {{ ['successful' => 'success', 'processing' => 'warning', 'pending' => 'info', 'failed' => 'danger'][$item->status] ?? 'muted' }}">
                    {{ ucfirst($item->status) }}
                  </span>
                  @if ($payslip)
                    <div class="cell-sub" style="margin-top:3px">
                      <span class="badge {{ $payslip->status === 'paid' ? 'success' : 'muted' }} plain" style="font-size:0.7rem;padding:1px 5px">
                        Payslip: {{ ucfirst($payslip->status) }}
                      </span>
                    </div>
                  @endif
                </td>
                <td style="max-width:260px">
                  @if ($item->status === 'failed' || $item->failure_reason)
                    <span class="text-danger font-bold text-small" style="color:var(--danger)">
                      &#9888; {{ $item->failure_reason ?: ($item->gateway_response['message'] ?? 'Failed') }}
                    </span>
                  @elseif (is_array($item->gateway_response) && isset($item->gateway_response['message']))
                    <span class="text-muted text-small">{{ $item->gateway_response['message'] }}</span>
                  @else
                    <span class="text-muted text-small">{{ $item->paid_at ? 'Settled on ' . \App\Support\Wat::dateTime($item->paid_at) : 'Dispatched' }}</span>
                  @endif
                </td>
                <td class="actions">
                  @if ($payslip)
                    <a href="{{ route('payroll.payslips.show', $payslip) }}" class="btn btn-ghost btn-sm" target="_blank" title="Open Payslip">
                      View Payslip &rarr;
                    </a>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="10" class="text-center text-muted p-24">
                  No payment items found in this batch.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($items->hasPages())
        <div class="card-foot">
          {{ $items->links() }}
        </div>
      @endif
    </div>
  </div>

  {{-- Authorization / OTP Modal --}}
  @if ($isAwaitingOtp)
    <div id="modal-otp-{{ $batch->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div>
            <h3>Authorize Batch Transfer</h3>
            <p>{{ $batch->batch_reference }} &middot; {{ ucfirst(str_replace('_', ' ', $batch->gateway)) }}</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payroll.batches.otp', [$run, $batch]) }}">
          @csrf
          <div class="modal-body">
            <div class="alert info mb-16">
              <span>&#128274;</span>
              <div>
                Enter the One-Time Password (OTP) or authorization code sent to your gateway phone/email.
                <div style="margin-top:6px;font-size:0.82rem;opacity:0.9">
                  <em>Note:</em> If OTP is disabled on your {{ ucfirst($batch->gateway) }} account, submitting will automatically verify and finalize the batch.
                </div>
              </div>
            </div>
            <div class="field">
              <label for="otp-{{ $batch->id }}">Authorization OTP / Code <span class="req">*</span></label>
              <input type="password" id="otp-{{ $batch->id }}" name="otp" required placeholder="e.g. 123456" autocomplete="one-time-code" autofocus />
              <div class="hint">Sent by {{ ucfirst($batch->gateway) }} gateway.</div>
            </div>
          </div>
          <div class="modal-foot" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div>
              <button type="button" id="resend-otp-btn-{{ $batch->id }}" class="btn btn-ghost btn-sm" onclick="resendBatchOtp('{{ $batch->id }}', '{{ route('payroll.batches.resend-otp', [$run, $batch]) }}')" disabled>
                &#8635; Resend OTP (<span id="resend-timer-{{ $batch->id }}">60</span>s)
              </button>
            </div>
            <div style="display:flex;gap:8px">
              <a href="#" class="btn btn-outline">Cancel</a>
              <button type="submit" class="btn btn-primary">Submit OTP &amp; Finalize &rarr;</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <script>
      (function() {
        var batchId = '{{ $batch->id }}';
        var remaining = 60;
        var btn = document.getElementById('resend-otp-btn-' + batchId);
        var timerSpan = document.getElementById('resend-timer-' + batchId);

        function tick() {
          if (!btn || !timerSpan) return;
          remaining--;
          if (remaining <= 0) {
            btn.disabled = false;
            btn.innerHTML = '&#8635; Resend OTP';
          } else {
            btn.disabled = true;
            btn.innerHTML = '&#8635; Resend OTP (' + remaining + 's)';
            setTimeout(tick, 1000);
          }
        }
        setTimeout(tick, 1000);

        window.resendBatchOtp = function(id, url) {
          var resendBtn = document.getElementById('resend-otp-btn-' + id);
          if (!resendBtn || resendBtn.disabled) return;

          resendBtn.disabled = true;
          resendBtn.innerHTML = '&#8635; Requesting new OTP...';

          var form = document.createElement('form');
          form.method = 'POST';
          form.action = url;

          var csrf = document.createElement('input');
          csrf.type = 'hidden';
          csrf.name = '_token';
          csrf.value = '{{ csrf_token() }}';
          form.appendChild(csrf);

          document.body.appendChild(form);
          form.submit();
        };
      })();
    </script>
  @endif
@endsection
