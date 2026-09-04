@extends('layouts.app')
@section('title', $run->reference)

@section('content')
  <div class="breadcrumb">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('transport-payments.index') }}">Transport Payments</a><span class="sep">/</span>
    <span>{{ $run->reference }}</span>
  </div>

  <div class="detail-head">
    <div>
      <h1>{{ $run->reference }}</h1>
      <div class="pill-row">
        <span class="badge {{ $run->status === 'paid' ? 'success' : ($run->status === 'cancelled' ? 'muted' : 'warning') }}">
          {{ \Illuminate\Support\Str::headline($run->status) }}</span>
        <span class="pill">{{ number_format($run->driver_count) }} drivers</span>
        <span class="pill">{{ number_format($run->trip_count) }} trips</span>
        <span class="pill">{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</span>
      </div>
    </div>
    <div class="dh-actions" style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
      @if ($canDisburse && $run->isApproved())
        <a href="#modal-electronic-disburse" class="btn btn-primary font-bold" style="background:#0b7d54; border-color:#0b7d54; color:#fff;">
          &#128179; Initialise Payment
        </a>
      @endif
      @if ($canCancel && $run->status === 'draft')
        <a href="#modal-tp-submit" class="btn btn-primary">Send for approval</a>
        <a href="#modal-tp-cancel" class="btn btn-outline">Cancel run</a>
      @endif
      @if ($canReverse)
        <a href="#modal-tp-reverse-run" class="btn btn-outline danger">Reverse run</a>
      @endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Total</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->total_minor) }}</div>
      <div class="stat-foot">route fees as logged</div></div>
    <div class="stat green"><div class="stat-label">Disbursed</div>
      <div class="stat-value">{{ \App\Support\Money::compact($reconciliation['disbursed']) }}</div>
      <div class="stat-foot">{{ $reconciliation['paid'] }} / {{ $reconciliation['drivers'] }} settled</div></div>
    <div class="stat amber"><div class="stat-label">Outstanding</div>
      <div class="stat-value">{{ \App\Support\Money::compact($reconciliation['outstanding']) }}</div>
      <div class="stat-foot">still to hand over</div></div>
    <div class="stat"><div class="stat-label">Trips paid for</div>
      <div class="stat-value">{{ number_format($reconciliation['trips']) }}</div>
      <div class="stat-foot">legs claimed by this run</div></div>
  </div>

  {{-- Electronic Payment Batches Card --}}
  @if (isset($batches) && $batches->isNotEmpty())
    <div class="card mb-16">
      <div class="card-head">
        <div>
          <h3>Electronic Payment Batches ({{ $batches->count() }})</h3>
          <p>Disbursements processed via payment gateways</p>
        </div>
      </div>
      <div class="card-body flush">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Batch Ref</th>
                <th>Gateway</th>
                <th class="num">Amount</th>
                <th>Items Settled</th>
                <th>Status</th>
                <th>Disbursed At</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($batches as $b)
                <tr>
                  <td>
                    <a href="{{ route('transport-payments.batches.show', [$run, $b]) }}" class="perm-key font-bold">
                      {{ $b->batch_reference }}
                    </a>
                  </td>
                  <td>
                    <span class="badge info plain">{{ ucfirst(str_replace('_', ' ', $b->gateway)) }}</span>
                  </td>
                  <td class="num font-bold" style="color:#0b7d54;">
                    {{ \App\Support\Money::format($b->total_amount_minor) }}
                  </td>
                  <td>
                    {{ $b->successful_items_count }} / {{ $b->total_items_count }}
                    @if ($b->failed_items_count > 0)
                      <span class="badge danger plain" style="margin-left:4px;">{{ $b->failed_items_count }} failed</span>
                    @endif
                  </td>
                  <td>
                    <span class="badge {{ ['completed' => 'success', 'processing' => 'warning', 'pending_otp' => 'warning', 'partially_completed' => 'warning', 'failed' => 'danger', 'cancelled' => 'muted'][$b->status] ?? 'muted' }}">
                      {{ ucfirst(str_replace('_', ' ', $b->status)) }}
                    </span>
                  </td>
                  <td>{{ $b->disbursed_at ? \App\Support\Wat::dateTime($b->disbursed_at) : '—' }}</td>
                  <td class="actions">
                    <a href="{{ route('transport-payments.batches.show', [$run, $b]) }}" class="btn btn-sm btn-outline">
                      View Batch &rarr;
                    </a>
                    @if (in_array($b->gateway, ['monnify', 'paystack']) && $b->status !== 'cancelled')
                      <form method="POST" action="{{ route('transport-payments.batches.sync', [$run, $b]) }}" style="display:inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" title="Sync with Gateway">&#8635;</button>
                      </form>
                    @endif
                    @if (in_array($b->status, ['initialized', 'processing', 'pending_otp', 'draft']))
                      <form method="POST" action="{{ route('transport-payments.batches.cancel', [$run, $b]) }}" style="display:inline" onsubmit="return confirm('Cancel this batch to prevent duplicate disbursement?')">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" title="Cancel this batch" style="color:#dc2626;">&#10005; Cancel</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
      <div><h3>Drivers and riders</h3><p>Every fee below can be opened and argued with</p></div>
      @if ($run->status === 'draft' && $canCancel)
        <a href="#modal-tp-add-recipient" class="btn btn-sm btn-outline">+ Add recipient</a>
      @endif
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Driver</th><th>Type</th><th class="num">Trips</th><th class="num">Litres carried</th>
            <th class="num">Amount</th><th class="num">Outstanding</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
            @forelse ($payments as $payment)
              <tr>
                <td>{{ $payment->driver?->name }}<small class="hint d-block">{{ $payment->driver?->phone }}</small></td>
                <td>{{ \Illuminate\Support\Str::headline($payment->driver?->type ?? '') }}</td>
                <td class="num">{{ number_format($payment->trip_count) }}</td>
                <td class="num">{{ \App\Support\Volume::format($payment->litres_carried) }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($payment->amount_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->outstandingMinor()) }}</td>
                <td><span class="badge {{ $payment->status === 'paid' ? 'success' : ($payment->status === 'reversed' ? 'muted' : 'warning') }}">
                  {{ \Illuminate\Support\Str::headline($payment->status) }}</span></td>
                <td class="actions">
                  @if ($run->status === 'draft' && $canCancel)
                    <a href="#modal-tp-edit-{{ $payment->id }}" class="btn btn-sm btn-ghost">Edit amount</a>
                    <form method="POST" action="{{ route('transport-payments.remove-recipient', [$run, $payment]) }}" style="display:inline;" onsubmit="return confirm('Remove {{ addslashes($payment->driver?->name) }} from this draft run?');">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-sm btn-ghost danger" style="padding:3px 8px;">Remove</button>
                    </form>
                  @endif
                  @if ($canReverse && $payment->status !== 'reversed')
                    <a href="#modal-tp-reverse-{{ $payment->id }}" class="btn btn-sm btn-ghost">Reverse</a>
                  @endif
                </td>
              </tr>
              @if ($payment->breakdown['legs'] ?? false)
                <tr class="sub-row">
                  <td colspan="8">
                    <div class="hint">
                      @foreach ($payment->breakdown['legs'] as $leg)
                        <span class="perm-key">{{ $leg['reference'] }}</span>
                        @if ($leg['route']) {{ $leg['route'] }} @endif
                        {{ $leg['litres_carried'] }} L
                        = {{ \App\Support\Money::format($leg['fee_minor']) }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
                      @endforeach
                    </div>
                  </td>
                </tr>
              @endif
            @empty
              <tr><td colspan="8">
                @include('partials.empty', [
                  'title' => 'Nothing to pay',
                  'message' => 'Every completed trip in this scope is already on another run.',
                  'icon' => '&#128666;',
                ])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  @if ($canCancel && $run->status === 'draft')
    <div id="modal-tp-submit" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Send {{ $run->reference }} for approval</h3>
          <p>Accounts, then the General Manager if it is large</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('transport-payments.submit', $run) }}">
          @csrf
          <div class="modal-body">
            <p>{{ \App\Support\Money::format($run->total_minor) }} to
               {{ number_format($run->driver_count) }} driver(s) for
               {{ number_format($run->trip_count) }} trip(s).</p>
            {{-- BR-18 is enforced by the workflow engine, not by this screen. --}}
            <div class="hint">You will not be able to approve this yourself.</div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Send for approval</button>
          </div>
        </form>
      </div>
    </div>

    <div id="modal-tp-cancel" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Cancel {{ $run->reference }}</h3></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('transport-payments.cancel', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="field"><label for="tpc-reason">Why?</label>
              <input type="text" id="tpc-reason" name="reason" required /></div>
            <div class="hint">The trips this run claimed are released and will appear on the next one.</div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Keep</a>
            <button type="submit" class="btn btn-primary">Cancel run</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canReverse)
    <div id="modal-tp-reverse-run" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Reverse {{ $run->reference }}</h3>
          <p>Every payment on this run</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('transport-payments.reverse', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="field"><label for="tpr-reason">Why is this run being reversed?</label>
              <input type="text" id="tpr-reason" name="reason" required /></div>
            <div class="alert warn">
              <strong>{{ \App\Support\Money::format($reconciliation['disbursed']) }} has already been handed over.</strong>
              Unlike a farmer, a driver carries no balance in this system, so money already paid
              is <em>not</em> recovered by reversing &mdash; it has to be collected from them.
              Trips that were not paid for become payable again on the next run.
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Keep the run</a>
            <button type="submit" class="btn btn-primary">Reverse run</button>
          </div>
        </form>
      </div>
    </div>

    @foreach ($payments as $payment)
      @if ($payment->status !== 'reversed')
        @php $paidAlready = (int) $payment->disbursements->sum('amount_minor'); @endphp
        <div id="modal-tp-reverse-{{ $payment->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><div><h3>Reverse the payment to {{ $payment->driver?->name }}</h3>
              <p>{{ \App\Support\Money::format($payment->amount_minor) }} on {{ $run->reference }}</p></div>
              <a href="#" class="modal-close">&times;</a></div>
            <form method="POST" action="{{ route('driver-payments.reverse', $payment) }}">
              @csrf
              <div class="modal-body">
                <div class="field"><label for="tprp-reason-{{ $payment->id }}">Why?</label>
                  <input type="text" id="tprp-reason-{{ $payment->id }}" name="reason" required /></div>
                @if ($paidAlready > 0)
                  <div class="alert warn">
                    <strong>{{ \App\Support\Money::format($paidAlready) }} has already been paid to this driver.</strong>
                    Reversing does not take it back &mdash; there is no driver balance for it to sit
                    against. Someone has to collect it. The reason you type here is what they will
                    be shown.
                  </div>
                @else
                  <div class="hint">
                    Nothing has been paid out yet. The trips on this line are released and will be
                    paid on the next run.
                  </div>
                @endif
              </div>
              <div class="modal-foot">
                <a href="#" class="btn btn-ghost">Keep</a>
                <button type="submit" class="btn btn-primary">Reverse payment</button>
              </div>
            </form>
          </div>
        </div>
      @endif
    @endforeach
  @endif

  @if ($run->status === 'draft' && $canCancel)
    {{-- Add Recipient Modal --}}
    <div id="modal-tp-add-recipient" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div>
            <h3>Add recipient to {{ $run->reference }}</h3>
            <p>Add an eligible rider or driver with positive wallet balance</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('transport-payments.add-recipient', $run) }}">
          @csrf
          <div class="modal-body">
            @if ($availableToAdd->isEmpty())
              <div class="alert info">
                No additional riders or drivers currently have an uncommitted positive wallet balance.
              </div>
            @else
              <div class="field" style="margin-bottom:14px;">
                <label for="ar-driver">Rider or Driver <span class="req">*</span></label>
                <select id="ar-driver" name="driver_id" required style="width:100%;">
                  <option value="">Select recipient...</option>
                  @foreach ($availableToAdd as $item)
                    @php $d = $item['driver']; @endphp
                    <option value="{{ $d->id }}" data-balance="{{ number_format($item['available_minor'] / 100, 2, '.', '') }}">
                      {{ $d->name }} ({{ ucfirst($d->type) }}) &bull; Available: {{ \App\Support\Money::format($item['available_minor']) }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="ar-amount">Amount (₦) <span class="req">*</span></label>
                <input type="number" step="0.01" min="0.01" id="ar-amount" name="amount" placeholder="0.00" required />
                <div class="hint">
                  Enter full or partial payment amount. Any remaining balance will stay in the recipient's electronic wallet for subsequent runs.
                </div>
              </div>
            @endif
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($availableToAdd->isEmpty())>Add recipient</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Edit Amount Modals for each draft recipient --}}
    @foreach ($payments as $payment)
      @php
        $d = $payment->driver;
        $walletBal = $d?->wallet?->balance_minor ?? $payment->amount_minor;
        $maxAllowed = ($walletBal) / 100;
      @endphp
      <div id="modal-tp-edit-{{ $payment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog narrow">
          <div class="modal-head">
            <div>
              <h3>Edit amount for {{ $d?->name }}</h3>
              <p>Current wallet balance: {{ \App\Support\Money::format($walletBal) }}</p>
            </div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('transport-payments.update-recipient', [$run, $payment]) }}">
            @csrf
            @method('PUT')
            <div class="modal-body">
              <div class="field">
                <label for="ep-amount-{{ $payment->id }}">Payment Amount (₦) <span class="req">*</span></label>
                <input type="number" step="0.01" min="0.01" max="{{ $maxAllowed }}" id="ep-amount-{{ $payment->id }}"
                       name="amount" value="{{ number_format($payment->amount_minor / 100, 2, '.', '') }}" required />
                <div class="hint">
                  You can set a lower amount for partial payment. When disbursed, only this amount will be debited from the recipient's wallet, leaving the rest payable in future runs.
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save amount</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const selectDriver = document.getElementById('ar-driver');
        const inputAmount = document.getElementById('ar-amount');
        if (selectDriver && inputAmount) {
          selectDriver.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.dataset.balance) {
              inputAmount.value = opt.dataset.balance;
              inputAmount.max = opt.dataset.balance;
            }
          });
        }
      });
    </script>
  @endif

  {{-- 5. ELECTRONIC GATEWAY BATCH DISBURSEMENT MODAL --}}
  @if ($canDisburse && $run->isApproved())
    <div id="modal-electronic-disburse" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <div>
            <h3>&#128179; Initiate Electronic Payout Batch</h3>
            <p>Disburse approved transport fees to driver and rider bank accounts</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('transport-payments.disburse-batch', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="grid grid-2 mb-16">
              <div class="field">
                <label for="disburse_gateway">Payment Gateway / Method <span class="req">*</span></label>
                <select id="disburse_gateway" name="gateway" class="form-control" required>
                  @if (isset($gateways) && !empty($gateways))
                    @foreach ($gateways as $gwKey => $gwInfo)
                      <option value="{{ $gwKey }}" {{ ($gwInfo['is_default'] ?? ($gwKey === 'monnify' || $loop->first)) ? 'selected' : '' }} {{ !($gwInfo['is_enabled'] ?? true) ? 'disabled' : '' }}>
                        {{ $gwInfo['label'] ?? ($gwInfo['name'] ?? ucfirst($gwKey)) }} {{ !($gwInfo['is_enabled'] ?? true) ? '(Disabled)' : '' }}
                      </option>
                    @endforeach
                  @else
                    <option value="monnify" selected>Monnify (Direct Bank Transfer)</option>
                    <option value="paystack">Paystack Transfer</option>
                    <option value="bank_transfer">Direct Bank Settlement</option>
                  @endif
                </select>
              </div>
              <div class="field">
                <label for="disburse_notes">Disbursement Notes / Narration</label>
                <input type="text" id="disburse_notes" name="notes" class="form-control"
                       placeholder="e.g. Transport settlement batch {{ $run->reference }}" value="Transport settlement batch {{ $run->reference }}" />
              </div>
            </div>

            @php
              $payablePayments = $payments->filter(fn ($p) => $p->status !== 'paid' && $p->status !== 'reversed' && $p->outstandingMinor() > 0);
              $invalidPayments = $payablePayments->filter(function ($p) {
                  $cleanAcc = preg_replace('/\D/', '', (string) ($p->driver?->bank_account ?? ''));
                  return strlen($cleanAcc) !== 10 || empty($p->driver?->bank_name);
              });
              $hasInvalidInList = $invalidPayments->isNotEmpty();
            @endphp

            @if ($hasInvalidInList)
              <div class="alert danger mb-16" style="border-left: 4px solid #dc2626; background: #fef2f2; color: #991b1b;">
                <div style="display:flex; align-items:flex-start; gap:10px;">
                  <span style="font-size:1.3rem;">&#9888;</span>
                  <div>
                    <strong style="font-size:0.95rem;">Initialise Payment Disabled &mdash; Incomplete Bank Details:</strong>
                    <div style="margin-top:2px; font-size:0.875rem;">
                      {{ $invalidPayments->count() }} recipient(s) in this run ({{ $invalidPayments->map(fn ($p) => $p->driver?->name)->join(', ') }}) do not have complete bank details (Bank Name &amp; 10-digit NUBAN Account required).
                    </div>
                    <div style="margin-top:8px;">
                      <a href="{{ route('fleet.index') }}" target="_blank" class="btn btn-sm btn-outline" style="background:#fff; color:#dc2626; border-color:#fca5a5; font-weight:600;">
                        Open Fleet Register to Update Bank Details &rarr;
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            @endif

            <h4 style="margin:16px 0 8px; font-size:0.95rem; color:#0f172a; display:flex; justify-content:space-between; align-items:center;">
              <span>Select Drivers / Riders &amp; Specify Payout Amounts</span>
              <span style="font-size:0.8rem; font-weight:normal; color:#64748b;">Amounts are editable for partial payments</span>
            </h4>
            <div class="table-wrap" style="max-height:300px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
              <table class="table">
                <thead>
                  <tr style="background:#f8fafc; position:sticky; top:0; z-index:2;">
                    <th style="width:30px;">
                      <input type="checkbox" id="select_all_drivers" {{ $hasInvalidInList ? 'disabled' : 'checked' }} onchange="toggleAllDisburseDrivers(this)" />
                    </th>
                    <th>Driver / Rider</th>
                    <th>Type</th>
                    <th>Bank &amp; Account</th>
                    <th class="num">Owed (₦)</th>
                    <th class="num" style="width:170px;">Payout Amount (₦) <span style="display:block; font-size:0.75rem; font-weight:700; color:#0b7d54;">✎ Editable</span></th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($payablePayments as $p)
                    @php
                      $cleanAcc = preg_replace('/\D/', '', (string) ($p->driver?->bank_account ?? ''));
                      $hasValidBank = strlen($cleanAcc) === 10 && !empty($p->driver?->bank_name);
                    @endphp
                    <tr style="{{ !$hasValidBank ? 'background:#fef2f2;' : '' }}">
                      <td>
                        <input type="checkbox" name="selected_payments[]" value="{{ $p->id }}"
                               class="driver-checkbox"
                               data-has-bank="{{ $hasValidBank ? '1' : '0' }}"
                               data-payment-id="{{ $p->id }}"
                               {{ $hasValidBank && !$hasInvalidInList ? 'checked' : '' }}
                               {{ !$hasValidBank ? 'disabled' : '' }}
                               onchange="updateDisburseTotals()" />
                      </td>
                      <td>
                        <strong>{{ $p->driver?->name }}</strong>
                        <div class="cell-sub">{{ $p->driver?->phone }}</div>
                      </td>
                      <td>
                        <span class="badge {{ $p->driver?->type === 'driver' ? 'info' : 'plain' }}">{{ ucfirst($p->driver?->type ?? 'driver') }}</span>
                      </td>
                      <td>
                        @if ($hasValidBank)
                          <div style="font-weight:600;">{{ $p->driver?->bank_name }}</div>
                          <div class="cell-sub mono font-bold" style="color:#16a34a;">{{ $cleanAcc }} &check;</div>
                        @else
                          <div class="font-bold" style="color:#dc2626;">
                            &#9888; {{ $p->driver?->bank_name ?: 'Bank not configured' }}
                          </div>
                          <div class="cell-sub font-bold" style="color:#dc2626; font-size:0.75rem;">
                            {{ $cleanAcc ? $cleanAcc . ' (Invalid Account)' : 'Missing 10-digit Account' }}
                          </div>
                          <a href="{{ route('fleet.index') }}" target="_blank" style="display:inline-block; font-size:0.75rem; color:#0284c7; text-decoration:underline; margin-top:2px;">
                            Configure in Fleet &rarr;
                          </a>
                        @endif
                      </td>
                      <td class="num font-bold" style="color:#0b7d54;">
                        {{ \App\Support\Money::format($p->outstandingMinor()) }}
                      </td>
                      <td class="num">
                        <div style="position:relative; display:inline-flex; align-items:center;">
                          <span style="position:absolute; left:8px; font-weight:700; color:#64748b; font-size:0.85rem; pointer-events:none;">₦</span>
                          <input type="number" step="0.01" min="0.01" max="{{ $p->outstandingMinor() / 100 }}"
                                 id="payout_amount_{{ $p->id }}"
                                 name="amounts[{{ $p->id }}]"
                                 value="{{ number_format($p->outstandingMinor() / 100, 2, '.', '') }}"
                                 data-max="{{ $p->outstandingMinor() / 100 }}"
                                 data-payment-id="{{ $p->id }}"
                                 class="disburse-amount-input"
                                 style="width:145px; padding:6px 8px 6px 22px; font-weight:700; font-size:0.95rem; text-align:right; border:1.5px solid #0f9d58; border-radius:6px; background:#ffffff !important; color:#0f172a; cursor:text;"
                                 oninput="onAmountChanged(this)"
                                 onblur="validateAmountBounds(this)"
                                 placeholder="0.00"
                                 title="Click to edit payout amount (max {{ \App\Support\Money::format($p->outstandingMinor()) }})" />
                        </div>
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="6" class="text-muted text-center">No payable balances available on this run.</td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            {{-- Dynamic Calculation Summary Bar --}}
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
              <span style="font-size:0.875rem; color:#475569;">
                Selected: <strong id="disburse-selected-count">0</strong> recipient(s)
              </span>
              <span style="font-size:0.95rem; color:#0f172a;">
                Total Payout: <strong id="disburse-total-amount" style="color:#0b7d54; font-size:1.15rem; margin-left:4px;">₦0.00</strong>
              </span>
            </div>

            <div class="alert info mt-14" style="margin-bottom:0;">
              <span>&#8505;</span>
              <div style="font-size:0.85rem;">
                <strong>Wallet Ledger Debit:</strong> Successful payout amounts will be automatically debited from each recipient's electronic wallet. Any unpaid remainder stays available for subsequent payment runs.
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" id="btn-submit-electronic-disburse" class="btn btn-primary font-bold"
                    {{ $hasInvalidInList ? 'disabled' : '' }}
                    style="background:#0b7d54; border-color:#0b7d54; {{ $hasInvalidInList ? 'opacity:0.5; cursor:not-allowed;' : '' }}"
                    title="{{ $hasInvalidInList ? 'Initialise payment disabled: Recipients without valid bank details exist in this run' : 'Initialise Payment' }}">
              <span>&#128179;</span> Initialise Payment &rarr;
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      function onAmountChanged(input) {
        const paymentId = input.dataset.paymentId;
        const cb = document.querySelector('.driver-checkbox[data-payment-id="' + paymentId + '"]');
        if (cb && !cb.disabled && !cb.checked) {
          cb.checked = true;
        }
        updateDisburseTotals();
      }

      function validateAmountBounds(input) {
        const max = parseFloat(input.dataset.max) || 0;
        let val = parseFloat(input.value);
        if (isNaN(val) || val <= 0) {
          input.value = max.toFixed(2);
        } else if (val > max) {
          input.value = max.toFixed(2);
        } else {
          input.value = val.toFixed(2);
        }
        updateDisburseTotals();
      }

      function toggleAllDisburseDrivers(selectAllCb) {
        const checkboxes = document.querySelectorAll('.driver-checkbox');
        checkboxes.forEach(cb => {
          if (!cb.disabled) {
            cb.checked = selectAllCb.checked;
          }
        });
        updateDisburseTotals();
      }

      function updateDisburseTotals() {
        const checkboxes = document.querySelectorAll('.driver-checkbox');
        const submitBtn = document.getElementById('btn-submit-electronic-disburse');
        const countEl = document.getElementById('disburse-selected-count');
        const totalEl = document.getElementById('disburse-total-amount');

        let selectedCount = 0;
        let totalNaira = 0;
        let hasInvalidSelected = false;

        checkboxes.forEach(cb => {
          const paymentId = cb.dataset.paymentId;
          const amountInput = document.getElementById('payout_amount_' + paymentId);
          const hasBank = cb.dataset.hasBank === '1';

          // Amount inputs are always editable
          if (amountInput) {
            amountInput.disabled = false;
            amountInput.removeAttribute('disabled');
          }

          if (cb.checked) {
            if (!hasBank) {
              hasInvalidSelected = true;
            }
            selectedCount++;
            if (amountInput) {
              let val = parseFloat(amountInput.value) || 0;
              totalNaira += val;
            }
          }
        });

        if (countEl) countEl.textContent = selectedCount;
        if (totalEl) totalEl.textContent = '₦' + totalNaira.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        const hasInvalidInList = {{ $hasInvalidInList ? 'true' : 'false' }};
        const shouldDisable = hasInvalidInList || hasInvalidSelected || selectedCount === 0 || totalNaira <= 0;

        if (submitBtn) {
          submitBtn.disabled = shouldDisable;
          submitBtn.style.opacity = shouldDisable ? '0.5' : '1';
          submitBtn.style.cursor = shouldDisable ? 'not-allowed' : 'pointer';
          submitBtn.title = hasInvalidInList
            ? 'Initialise payment disabled: Recipients without valid bank details exist in this run.'
            : (selectedCount === 0 ? 'Select at least one recipient' : 'Initialise payment');
        }
      }

      document.addEventListener('DOMContentLoaded', updateDisburseTotals);
    </script>
  @endif
@endsection
