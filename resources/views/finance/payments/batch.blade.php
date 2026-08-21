@extends('layouts.app')
@section('title', 'Farmer Payment Batch — ' . $batch->batch_reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('payment-runs.index') }}">Farmer Payments</a><span class="sep">/</span>
    <a href="{{ route('payment-runs.show', $run) }}">{{ $run->reference }}</a><span class="sep">/</span>
    <span class="here">Batch: {{ $batch->batch_reference }}</span>
  </div>

  <div class="detail-head mb-16">
    <div class="avatar-lg" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:1.5rem; display:flex; align-items:center; justify-content:center; border-radius:12px; min-width:56px; height:56px;">
      &#128179;
    </div>
    <div class="dh-main">
      <h1>Payment Batch &mdash; {{ $batch->batch_reference }}</h1>
      <div class="dh-sub">
        <strong>Run:</strong> {{ $run->reference }}
        &nbsp;&bull;&nbsp; <strong>Gateway:</strong> {{ ucfirst(str_replace('_', ' ', $batch->gateway)) }}
        &nbsp;&bull;&nbsp; <strong>Initiated:</strong> {{ $batch->disbursed_at ? \App\Support\Wat::dateTime($batch->disbursed_at) : '—' }}
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['completed' => 'success', 'processing' => 'warning', 'pending_otp' => 'warning', 'partially_completed' => 'warning', 'failed' => 'danger', 'cancelled' => 'muted'][$batch->status] ?? 'muted' }}">
          {{ ucfirst(str_replace('_', ' ', $batch->status)) }}
        </span>
        @if ($batch->gateway_status)
          <span class="badge {{ in_array(strtoupper($batch->gateway_status), ['EXPIRED', 'FAILED', 'CANCELLED', 'REJECTED']) ? 'danger' : (in_array(strtoupper($batch->gateway_status), ['SUCCESS', 'COMPLETED', 'PAID']) ? 'success' : 'warning') }} font-bold">
            Gateway Status: {{ strtoupper($batch->gateway_status) }}
          </span>
        @endif
      </div>
    </div>
    <div class="dh-actions" style="display:flex; align-items:center; gap:8px;">
      <a href="{{ route('payment-runs.show', $run) }}" class="btn btn-outline">&larr; Back to Payment Run</a>
      @if (in_array($batch->gateway, ['monnify', 'paystack']) && $batch->status !== 'cancelled')
        <form method="POST" action="{{ route('payment-runs.batches.sync', [$run, $batch]) }}" style="display:inline">
          @csrf
          <button type="submit" class="btn btn-ghost" title="Re-query gateway to synchronize settlement and farmer wallet records">
            &#8635; Sync Gateway Status
          </button>
        </form>
      @endif
      @if (in_array($batch->status, ['initialized', 'processing', 'pending_otp', 'draft']))
        <form method="POST" action="{{ route('payment-runs.batches.cancel', [$run, $batch]) }}" style="display:inline" onsubmit="return confirm('Are you sure you want to cancel this payout batch? This will invalidate any uncompleted items in this batch to prevent duplicate disbursement.')">
          @csrf
          <button type="submit" class="btn btn-outline danger" style="color:#dc2626; border-color:#dc2626;">
            &#10005; Cancel Batch
          </button>
        </form>
      @endif
      @php
        $isAwaitingOtp = !in_array($batch->status, ['completed', 'settled', 'failed', 'cancelled'])
          && !in_array(strtoupper($batch->gateway_status ?? ''), ['EXPIRED', 'FAILED', 'SUCCESS', 'COMPLETED', 'SETTLED', 'CANCELLED', 'REJECTED'])
          && in_array($batch->gateway, ['monnify', 'paystack']);
      @endphp
      @if ($isAwaitingOtp && $canAuthorize)
        <form method="POST" action="{{ route('payment-runs.batches.resend-otp', [$run, $batch]) }}" style="display:inline">
          @csrf
          <button type="submit" class="btn btn-outline" title="Resend 2FA authorization OTP to registered phone/email">
            &#128227; Resend OTP
          </button>
        </form>
        <a href="#modal-otp-{{ $batch->id }}" class="btn btn-primary font-bold">
          Enter OTP &rarr;
        </a>
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#10003;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if (session('info'))
    <div class="alert info mb-16">
      <span>&#8505;</span>
      <div>{{ session('info') }}</div>
    </div>
  @endif

  @if (session('error'))
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>{{ session('error') }}</div>
    </div>
  @endif

  {{-- Summary Stats Grid --}}
  <div class="grid grid-4 mb-16">
    <div class="stat">
      <div class="stat-label">Total Payout Amount</div>
      <div class="stat-value font-bold" style="color:#0b7d54">{{ \App\Support\Money::format($batch->total_amount_minor) }}</div>
      <div class="stat-foot">Disbursement across {{ $batch->total_items_count }} farmer(s)</div>
    </div>
    <div class="stat blue">
      <div class="stat-label">Gateway &amp; Channel</div>
      <div class="stat-value" style="font-size:1.35rem">{{ ucfirst(str_replace('_', ' ', $batch->gateway)) }}</div>
      <div class="stat-foot perm-key">{{ $batch->gateway_batch_reference ?: $batch->batch_reference }}</div>
    </div>
    <div class="stat amber">
      <div class="stat-label">Gateway Fee</div>
      <div class="stat-value">{{ \App\Support\Money::format($batch->total_fee_minor) }}</div>
      <div class="stat-foot">Charged by {{ ucfirst($batch->gateway) }} gateway</div>
    </div>
    <div class="stat green">
      <div class="stat-label">Item Settlement</div>
      <div class="stat-value">
        <span style="color:#16a34a">{{ $batch->successful_items_count }}</span> / {{ $batch->total_items_count }}
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
        <p>Execution metadata, authorization, and gateway identifiers</p>
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
          <div class="text-muted text-small mb-2">Gateway Reference</div>
          <div class="font-bold perm-key">{{ $batch->gateway_batch_reference ?: 'None assigned' }}</div>
          <div class="text-small text-muted">{{ $batch->notes ?: 'No notes provided' }}</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Item Settlement List --}}
  <div class="card">
    <div class="card-head">
      <div>
        <h3>Farmer Payout Items &amp; Settlement Status</h3>
        <p>Individual recipient account transfers and live wallet debit statuses</p>
      </div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Item Reference</th>
              <th>Farmer Beneficiary</th>
              <th>Bank &amp; Account Details</th>
              <th class="num">Payout Amount</th>
              <th>Status</th>
              <th>Settled At</th>
              <th>Gateway Ref</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($batch->items as $item)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                  <span class="perm-key font-bold">{{ $item->item_reference }}</span>
                </td>
                <td>
                  <div style="font-weight:600; color:#0f172a;">{{ $item->recipient_name }}</div>
                  @if ($item->recipient_phone)
                    <div class="cell-sub">{{ $item->recipient_phone }}</div>
                  @endif
                </td>
                <td>
                  <div style="font-weight:600;">{{ $item->recipient_bank_name }}</div>
                  <div class="cell-sub mono">{{ $item->recipient_account_number }}</div>
                </td>
                <td class="num font-bold" style="color:#0b7d54; font-size:1rem;">
                  {{ \App\Support\Money::format($item->amount_minor) }}
                </td>
                <td>
                  <span class="badge {{ [
                    'successful' => 'success',
                    'initialized' => 'info',
                    'pending' => 'warning',
                    'failed' => 'danger',
                    'reversed' => 'muted',
                    'cancelled' => 'muted',
                  ][$item->status] ?? 'muted' }}">
                    {{ ucfirst($item->status) }}
                  </span>
                  @if ($item->gateway_status && strtoupper($item->gateway_status) !== strtoupper($item->status))
                    <div class="cell-sub font-bold" style="font-size:0.75rem; color:#64748b;">GW: {{ strtoupper($item->gateway_status) }}</div>
                  @endif
                  @if ($item->failure_reason)
                    <div class="cell-sub text-danger" style="font-size:0.75rem;">{{ $item->failure_reason }}</div>
                  @endif
                </td>
                <td>
                  {{ $item->paid_at ? \App\Support\Wat::dateTime($item->paid_at) : '—' }}
                </td>
                <td>
                  <span class="mono text-small text-muted">{{ $item->gateway_reference ?: '—' }}</span>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="8" class="text-muted">No items found in this payment batch.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- OTP Validation Modal --}}
  @if ($isAwaitingOtp)
    <div id="modal-otp-{{ $batch->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div>
            <h3>Authorize Batch Transfer with OTP</h3>
            <p>Batch: <strong class="perm-key">{{ $batch->batch_reference }}</strong></p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payment-runs.batches.otp', [$run, $batch]) }}">
          @csrf
          <div class="modal-body">
            <p class="mb-16">
              A one-time authorization code (OTP) was sent by <strong>{{ ucfirst($batch->gateway) }}</strong> to your registered mobile device/authenticator for {{ \App\Support\Money::format($batch->total_amount_minor) }}.
            </p>
            <div class="field mb-16">
              <label for="otp_input_{{ $batch->id }}">Enter OTP Code <span class="req">*</span></label>
              <input type="text" id="otp_input_{{ $batch->id }}" name="otp" required autofocus
                     class="form-control mono text-center" style="font-size:1.4rem; letter-spacing:0.25rem;"
                     placeholder="123456" maxlength="10" autocomplete="one-time-code" />
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
              <span class="text-small text-muted">Didn't receive the OTP code?</span>
            </div>
          </div>
          <div class="modal-foot" style="display:flex; justify-content:space-between; align-items:center;">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <div style="display:flex; gap:8px;">
              <button type="submit" class="btn btn-primary font-bold">
                Verify &amp; Authorize Payout &rarr;
              </button>
            </div>
          </div>
        </form>
        <div style="padding:0 24px 16px; text-align:right;">
          <form method="POST" action="{{ route('payment-runs.batches.resend-otp', [$run, $batch]) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-ghost" style="text-decoration:underline; color:#0b7d54;">
              &#128227; Click here to resend OTP
            </button>
          </form>
        </div>
      </div>
    </div>
  @endif
@endsection
