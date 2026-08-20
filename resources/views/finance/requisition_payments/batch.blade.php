@extends('layouts.app')
@section('title', 'Payment Batch — ' . $batch->batch_reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('requisition-payments.index', ['tab' => 'batches']) }}">Requisition Payment Batches</a><span class="sep">/</span>
    <span class="here">{{ $batch->batch_reference }}</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Payment Batch &mdash; {{ $batch->batch_reference }}</h1>
      <p>Disbursement transaction details and live gateway settlement record</p>
    </div>
    <div class="page-actions">
      @if (in_array($batch->gateway, ['paystack', 'monnify', 'zainpay']))
        <form method="POST" action="{{ route('requisition-payments.sync-batch', $batch) }}" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-outline" style="font-weight:600;">
            &#128259; Sync with {{ ucfirst($batch->gateway) }}
          </button>
        </form>
      @endif
      <a href="{{ route('requisition-payments.index', ['tab' => 'batches']) }}" class="btn btn-outline">&larr; Back to Batches</a>
      <a href="{{ route('requisition-payments.index') }}" class="btn btn-outline">Approved Requisitions</a>
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if (session('warning'))
    <div class="alert warn mb-16">
      <span>&#9888;</span>
      <div>{{ session('warning') }}</div>
    </div>
  @endif

  @if (session('error'))
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>{{ session('error') }}</div>
    </div>
  @endif

  {{-- Status Alerts --}}
  @if ($batch->status === 'completed')
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>
        <strong>Disbursement Completed &amp; Verified.</strong>
        All funds ({{ \App\Support\Money::format((int) $batch->total_amount_minor) }}) for this batch were confirmed settled by <strong>{{ ucfirst($batch->gateway) }}</strong> on
        <strong>{{ $batch->completed_at ? \App\Support\Wat::dateTime($batch->completed_at) : 'recently' }}</strong>.
        Requisition expenditures and cost-centre balances have been finalized.
      </div>
    </div>
  @elseif (in_array($batch->status, ['processing', 'initialized']))
    <div class="alert warn mb-16">
      <span>&#9203;</span>
      <div>
        <strong>Awaiting 2FA / OTP Authorization or Settlement Confirmation.</strong>
        This payout batch was initiated via <strong>{{ ucfirst($batch->gateway) }}</strong>. Funds and requisition expenditures are confirmed only upon successful gateway settlement verification.
      </div>
    </div>
  @elseif ($batch->status === 'failed')
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>
        <strong>Batch Failed.</strong>
        The transfer could not be settled by {{ ucfirst($batch->gateway) }}. Requisitions have NOT been marked as paid.
      </div>
    </div>
  @endif

  {{-- Summary Stat Cards --}}
  <div class="grid grid-4 mb-16">
    <div class="stat blue">
      <div class="stat-label">Batch Reference</div>
      <div class="stat-value" style="font-size:1.05rem; font-family:monospace;">{{ $batch->batch_reference }}</div>
      <div class="stat-foot">Items: {{ $batch->total_items_count }} payout(s)</div>
    </div>
    <div class="stat">
      <div class="stat-label">Payment Gateway</div>
      <div class="stat-value" style="font-size:1.25rem; font-weight:800; color:#1e40af;">
        {{ ucfirst(str_replace('_', ' ', $batch->gateway)) }}
      </div>
      <div class="stat-foot" style="font-family:monospace; font-size:0.75rem; color:#475569; word-break:break-all;">
        GW: {{ $batch->gateway_batch_reference ?: 'Direct / Internal' }}
      </div>
    </div>
    <div class="stat green">
      <div class="stat-label">Total Amount</div>
      <div class="stat-value" style="color:var(--primary-dark)">{{ \App\Support\Money::format((int) $batch->total_amount_minor) }}</div>
      <div class="stat-foot">Fee: {{ \App\Support\Money::format((int) $batch->total_fee_minor) }}</div>
    </div>
    <div class="stat">
      <div class="stat-label">Batch Status</div>
      <div class="stat-value" style="font-size:1.3rem;">
        <span class="badge {{ ['completed' => 'success', 'processing' => 'warning', 'initialized' => 'warning', 'failed' => 'danger'][$batch->status] ?? 'plain' }}">
          {{ ucfirst($batch->status) }}
        </span>
      </div>
      <div class="stat-foot">
        @if ($batch->completed_at)
          Settled {{ \App\Support\Wat::dateTime($batch->completed_at) }}
        @else
          {{ $batch->gateway_status ?? 'Pending' }}
        @endif
      </div>
    </div>
  </div>

  {{-- OTP Authorization Box (if batch is pending authorization) --}}
  @if (in_array($batch->status, ['processing', 'initialized']))
    <div class="card mb-16" id="otp-card" style="border:2px solid #f59e0b; background:#fffbeb;">
      <div class="card-head" style="background:#fef3c7; border-bottom:1px solid #fde68a;">
        <div>
          <h3 style="color:#92400e; margin:0;">&#9889; Authorize Payout Batch with OTP Code</h3>
          <p style="margin:2px 0 0; color:#78350f;">
            An authorization code was dispatched by {{ ucfirst($batch->gateway) }} to authorize transfer of <strong>{{ \App\Support\Money::format((int) $batch->total_amount_minor) }}</strong>
          </p>
        </div>
      </div>
      <div class="card-body">
        <form method="POST" action="{{ route('requisition-payments.validate-batch-otp', $batch) }}" class="form-grid" style="grid-template-columns: 280px auto; gap:16px; align-items:end;">
          @csrf
          <div class="field" style="margin:0;">
            <label for="otp" style="font-weight:700; color:#78350f;">Enter OTP / 2FA Token <span class="req">*</span></label>
            <input type="text" id="otp" name="otp" required autofocus
                   placeholder="e.g. 123456"
                   class="form-control"
                   style="font-size:1.3rem; font-family:monospace; letter-spacing:4px; font-weight:800; text-align:center; padding:8px 12px;" />
          </div>
          <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-primary" style="background:#0284c7; padding:10px 20px; font-weight:700;">
              Authorize &amp; Verify Batch Payout &rarr;
            </button>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('resend-otp-form').submit()">
              Resend OTP Code
            </button>
          </div>
        </form>

        <form id="resend-otp-form" method="POST" action="{{ route('requisition-payments.resend-batch-otp', $batch) }}" style="display:none;">
          @csrf
        </form>
      </div>
    </div>
  @endif

  {{-- Batch Line Items --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Payout Items ({{ $batch->items->count() }})</h3>
        <p>Individual line transaction details and gateway settlement verification</p>
      </div>
      @if (in_array($batch->gateway, ['paystack', 'monnify', 'zainpay']))
        <form method="POST" action="{{ route('requisition-payments.sync-batch', $batch) }}">
          @csrf
          <button type="submit" class="btn btn-ghost btn-sm">
            &#128259; Refresh Gateway Status
          </button>
        </form>
      @endif
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr style="background:#f8fafc;">
              <th style="width:22%;">Item Reference</th>
              <th style="width:24%;">Beneficiary</th>
              <th style="width:24%;">Destination Account</th>
              <th style="width:15%; text-align:right;">Amount</th>
              <th style="width:15%; text-align:right;">Status</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($batch->items as $item)
              <tr>
                <td style="font-family:monospace; font-size:0.85rem; font-weight:600;">
                  {{ $item->item_reference }}
                  @if ($item->gateway_reference)
                    <div style="font-size:0.75rem; color:#64748b;">GW Ref: {{ $item->gateway_reference }}</div>
                  @endif
                  @if ($item->failure_reason)
                    <div style="font-size:0.75rem; color:#dc2626; margin-top:2px;">
                      &#9888; {{ $item->failure_reason }}
                    </div>
                  @endif
                </td>
                <td>
                  <div style="font-weight:700; color:#0f172a;">{{ $item->recipient_name }}</div>
                  @if ($item->recipient_email)
                    <div style="font-size:0.75rem; color:#64748b;">{{ $item->recipient_email }}</div>
                  @endif
                </td>
                <td>
                  <div style="font-weight:600; color:#1e293b;">{{ $item->recipient_bank_name ?: 'Bank' }}</div>
                  <div style="font-family:monospace; font-size:0.85rem; color:#0b7d54;">{{ $item->recipient_account_number }}</div>
                </td>
                <td style="text-align:right; font-weight:700; color:#1e40af;">
                  {{ \App\Support\Money::format((int) $item->amount_minor) }}
                </td>
                <td style="text-align:right;">
                  <span class="badge {{ ['successful' => 'success', 'processing' => 'warning', 'initialized' => 'warning', 'failed' => 'danger'][$item->status] ?? 'plain' }}">
                    {{ ucfirst($item->status) }}
                  </span>
                  @if ($item->paid_at)
                    <div style="font-size:0.7rem; color:#64748b; margin-top:2px;">
                      {{ $item->paid_at->format('M d, H:i') }}
                    </div>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
@endsection
