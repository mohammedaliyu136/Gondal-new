@extends('layouts.app')
@section('title', 'Requisition Payment — ' . $requisition->reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('requisition-payments.index') }}">Requisition Payments</a><span class="sep">/</span>
    <span class="here">{{ $requisition->reference }}</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Disbursement Console &mdash; {{ $requisition->reference }}</h1>
      <p>{{ $requisition->title }} &middot; {{ $requisition->department?->name ?? 'General' }}</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('requisition-payments.index') }}" class="btn btn-outline">&larr; Back to Payouts List</a>
      <a href="{{ route('requisitions.show', $requisition) }}" class="btn btn-outline" target="_blank">View Requisition Document &nearr;</a>
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if (session('error'))
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>{{ session('error') }}</div>
    </div>
  @endif

  @if ($remainingMinor <= 0)
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>
        <strong>Fully Disbursed &amp; Settled.</strong>
        All authorized funds for this requisition ({{ \App\Support\Money::format($authorisedMinor) }}) have been disbursed.
      </div>
    </div>
  @elseif ($spentMinor > 0)
    <div class="alert warn mb-16">
      <span>&#9203;</span>
      <div>
        <strong>Partially Disbursed.</strong>
        {{ \App\Support\Money::format($spentMinor) }} has been paid out of {{ \App\Support\Money::format($authorisedMinor) }} authorized.
        Remaining balance to pay: <strong>{{ \App\Support\Money::format($remainingMinor) }}</strong>.
      </div>
    </div>
  @else
    <div class="alert info mb-16">
      <span>&#8505;&#65039;</span>
      <div>
        <strong>Approved &amp; Ready for Disbursement.</strong>
        All approval stages cleared on {{ $requisition->decided_at ? \App\Support\Wat::dateTime($requisition->decided_at) : 'recently' }}.
        Verify the beneficiary account details below to execute payout.
      </div>
    </div>
  @endif

  {{-- Summary Metrics --}}
  <div class="grid grid-3 mb-16">
    <div class="stat blue">
      <div class="stat-label">Authorized Amount</div>
      <div class="stat-value">{{ \App\Support\Money::format($authorisedMinor) }}</div>
      <div class="stat-foot">Approved workflow total</div>
    </div>
    <div class="stat green">
      <div class="stat-label">Disbursed So Far</div>
      <div class="stat-value" style="color:var(--primary-dark)">{{ \App\Support\Money::format($spentMinor) }}</div>
      <div class="stat-foot">{{ $requisition->expenditures->count() }} disbursement entries</div>
    </div>
    <div class="stat amber">
      <div class="stat-label">Remaining Balance</div>
      <div class="stat-value" style="color:#b45309">{{ \App\Support\Money::format($remainingMinor) }}</div>
      <div class="stat-foot">
        @if ($remainingMinor <= 0)
          <span class="badge success">Paid</span>
        @else
          <span class="badge warn">Pending Payout</span>
        @endif
      </div>
    </div>
  </div>

  <div class="grid grid-2 mb-16" style="grid-template-columns: 1fr 1fr; gap:16px;">
    {{-- Service Provider & Disbursement Account Card --}}
    <div class="card">
      <div class="card-head" style="background:#eff6ff;">
        <div>
          <h3 style="color:#1e40af; margin:0;">&#127970; Beneficiary &amp; Bank Account Details</h3>
          <p style="margin:0;">Disbursement recipient registered on this requisition</p>
        </div>
        @if ($requisition->serviceProvider)
          <a href="{{ route('service-providers.index') }}" target="_blank" class="btn btn-ghost btn-xs">
            Manage Provider &nearr;
          </a>
        @endif
      </div>
      <div class="card-body">
        @php($sp = $requisition->serviceProvider)
        @if ($sp)
          <div style="font-size:1.1rem; font-weight:700; color:#0f172a; margin-bottom:8px;">
            {{ $sp->name }}
          </div>

          <div class="card p-12 mb-12" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px;">
            <div style="font-size:0.75rem; font-weight:700; color:#1e40af; text-transform:uppercase; margin-bottom:6px;">
              Bank Settlement Destination
            </div>
            <div class="grid grid-2" style="gap:10px; font-size:0.85rem;">
              <div>
                <div class="text-muted" style="font-size:0.75rem;">Bank Name</div>
                <div style="font-weight:700; color:#1e293b;">{{ $sp->bank_name ?: '—' }}</div>
              </div>
              <div>
                <div class="text-muted" style="font-size:0.75rem;">NUBAN Account Number</div>
                <div style="font-family:monospace; font-weight:700; color:#0b7d54; font-size:1rem;">
                  {{ $sp->bank_account ?: '—' }}
                </div>
              </div>
              <div style="grid-column: span 2;">
                <div class="text-muted" style="font-size:0.75rem;">Account Beneficiary Name</div>
                <div style="font-weight:700; color:#0f172a;">
                  {{ $sp->account_name ?: $sp->name }}
                </div>
              </div>
            </div>
          </div>

          <div style="font-size:0.8rem; color:#64748b;">
            @if ($sp->email)<div>&#9993; {{ $sp->email }}</div>@endif
            @if ($sp->contact)<div>&#9742; {{ $sp->contact }}</div>@endif
            @if ($sp->billing_address)<div>&#128205; {{ $sp->billing_address }}, {{ $sp->billing_city }}</div>@endif
          </div>
        @else
          <div class="alert warn" style="margin:0;">
            <span>&#9888;</span>
            <div>
              <strong>No Service Provider Linked.</strong>
              Requester suggested: <em>{{ $requisition->suggested_vendor ?: 'None' }}</em>.
              Disbursement can be executed to the suggested vendor via manual settlement.
            </div>
          </div>
        @endif
      </div>
    </div>

    {{-- Disburse Payment Form Card --}}
    <div class="card" id="disburse-card">
      <div class="card-head" style="background:#f0fdf4;">
        <div>
          <h3 style="color:#166534; margin:0;">&#128181; Execute Payment Disbursement</h3>
          <p style="margin:0;">Disburse funds via configured gateway or record settlement</p>
        </div>
      </div>
      <div class="card-body">
        @if ($remainingMinor <= 0)
          <div style="text-align:center; padding: 24px 0; color:#166534;">
            <div style="font-size:2rem; margin-bottom:6px;">&#9989;</div>
            <h4 style="margin:0 0 4px;">Disbursement Completed</h4>
            <p style="margin:0; font-size:0.85rem;">This requisition is fully paid. No further disbursement required.</p>
          </div>
        @elseif (! $canDisburse)
          <div class="alert muted" style="margin:0;">
            <span>&#128274;</span>
            <div>You do not have permission to disburse funds against this requisition.</div>
          </div>
        @else
          <form method="POST" action="{{ route('requisition-payments.disburse', $requisition) }}">
            @csrf
            <div class="form-grid" style="grid-template-columns: 1fr; gap:12px;">
              <div class="field">
                <label for="payment_method">Payment Channel / Gateway <span class="req">*</span></label>
                <select id="payment_method" name="payment_method" class="form-control" required style="font-weight:600;">
                  <option value="bank_transfer">Direct Bank Settlement (Company Treasury Transfer)</option>
                  @foreach ($gateways as $gKey => $gw)
                    @if ($gw['is_enabled'])
                      <option value="{{ $gKey }}">
                        {{ $gw['label'] }} (Online Gateway)
                      </option>
                    @endif
                  @endforeach
                  <option value="cash">Petty Cash / Cash Float</option>
                </select>
              </div>

              <div class="field">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                  <label for="amount" style="margin:0;">Payout Amount (&#8358;) <span class="req">*</span></label>
                  <button type="button" onclick="setFullAmount()" style="background:none; border:none; color:#0284c7; font-size:0.75rem; font-weight:600; cursor:pointer;">
                    Pay Full Balance ({{ \App\Support\Money::format($remainingMinor) }})
                  </button>
                </div>
                <input type="number" step="0.01" min="0.01" max="{{ number_format($remainingMinor / 100, 2, '.', '') }}"
                       id="amount" name="amount"
                       value="{{ number_format($remainingMinor / 100, 2, '.', '') }}"
                       required class="form-control" style="font-size:1.1rem; font-weight:700;" />
                <div class="hint" style="font-size:0.75rem;">Max payable: {{ \App\Support\Money::format($remainingMinor) }}</div>
              </div>

              <div class="field">
                <label for="notes">Reference Notes / Narration (Optional)</label>
                <input type="text" id="notes" name="notes" class="form-control"
                       placeholder="e.g. Approved disbursement via Zenith Bank transfer" />
              </div>

              <div style="margin-top:6px;">
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px; font-weight:700;">
                  Execute Disbursement &rarr;
                </button>
              </div>
            </div>
          </form>
        @endif
      </div>
    </div>
  </div>

  {{-- Approved Line Items Card --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Approved Line Items</h3>
        <p>Purchasing items cleared through workflow approval</p>
      </div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr style="background:#f8fafc;">
              <th style="width:5%;">#</th>
              <th style="width:40%;">Item Description</th>
              <th style="width:15%; text-align:right;">Quantity</th>
              <th style="width:20%; text-align:right;">Approved Unit Price</th>
              <th style="width:20%; text-align:right;">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($requisition->items as $idx => $item)
              <tr>
                <td class="text-muted">{{ $idx + 1 }}</td>
                <td style="font-weight:600; color:#0f172a;">{{ $item->item }}</td>
                <td style="text-align:right;">{{ (float) $item->quantity }} {{ $item->unit ?: 'pcs' }}</td>
                <td style="text-align:right;">{{ \App\Support\Money::format((int) $item->unit_price_minor) }}</td>
                <td style="text-align:right; font-weight:700; color:#1e293b;">
                  {{ \App\Support\Money::format((int) $item->amount_minor) }}
                </td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr style="background:#f8fafc; font-weight:700;">
              <td colspan="4" style="text-align:right;">Total Approved:</td>
              <td style="text-align:right; color:#1e40af; font-size:1.05rem;">
                {{ \App\Support\Money::format($authorisedMinor) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  {{-- Recorded Expenditures & Payment Batches --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Disbursement History &amp; Payment Batches</h3>
        <p>Ledger of actual funds disbursed against this requisition</p>
      </div>
    </div>
    <div class="card-body flush">
      @if ($requisition->expenditures->isEmpty())
        <div style="text-align:center; padding: 32px 16px; color:#64748b;">
          <p style="margin:0; font-size:0.9rem;">No disbursement payments recorded yet.</p>
        </div>
      @else
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="width:18%;">Disbursed On</th>
                <th style="width:22%;">Vendor / Recipient</th>
                <th style="width:16%;">Method</th>
                <th style="width:16%;">Batch / Invoice Ref</th>
                <th style="width:14%;">Disbursed By</th>
                <th style="width:14%; text-align:right;">Amount (&#8358;)</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($requisition->expenditures as $exp)
                <tr>
                  <td>{{ $exp->spent_on ? $exp->spent_on->format('M d, Y') : '—' }}</td>
                  <td style="font-weight:600; color:#0f172a;">{{ $exp->vendor ?: 'Vendor' }}</td>
                  <td>
                    <span class="badge {{ ['bank' => 'info', 'cash' => 'warning', 'gateway' => 'success'][$exp->method] ?? 'plain' }}">
                      {{ ucfirst($exp->method) }}
                    </span>
                  </td>
                  <td style="font-family:monospace; font-size:0.8rem; color:#475569;">
                    {{ $exp->invoice_reference ?: '—' }}
                  </td>
                  <td style="font-size:0.85rem; color:#334155;">{{ $exp->recordedBy?->name ?? 'System' }}</td>
                  <td style="text-align:right; font-weight:700; color:#059669;">
                    {{ \App\Support\Money::format((int) $exp->amount_minor) }}
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>

  <script>
    function setFullAmount() {
      document.getElementById('amount').value = "{{ number_format($remainingMinor / 100, 2, '.', '') }}";
    }
  </script>
@endsection
