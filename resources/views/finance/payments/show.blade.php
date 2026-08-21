@extends('layouts.app')
@section('title', $run->reference)

@section('content')
  <div class="breadcrumb">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('payment-runs.index') }}">Farmer Payments</a><span class="sep">/</span>
    <span>{{ $run->reference }}</span>
  </div>

  <div class="detail-head">
    <div class="dh-main">
      <h1>{{ $run->reference }}</h1>
      <div class="pill-row">
        <span class="badge {{ $run->status === 'paid' ? 'success' : ($run->status === 'cancelled' ? 'muted' : 'warning') }}">
          {{ \Illuminate\Support\Str::headline($run->status) }}</span>
        <span class="pill">{{ number_format($run->farmer_count) }} farmers</span>
        <span class="pill">{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</span>
      </div>
    </div>
    <div class="dh-actions" style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
      @if ($canDisburse && $run->isApproved())
        <a href="#modal-electronic-disburse" class="btn btn-primary font-bold" style="background:#0b7d54; border-color:#0b7d54; color:#fff;">
          &#128179; Initiate Payout
        </a>
      @endif
      @if ($canCancel && $run->status === 'draft')
        <a href="#modal-submit" class="btn btn-primary">Send for approval</a>
        <a href="#modal-cancel" class="btn btn-outline">Cancel run</a>
      @endif
      @if ($canReverse)
        <a href="#modal-reverse-run" class="btn btn-outline danger">Reverse run</a>
      @endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Gross</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->gross_total_minor) }}</div>
      <div class="stat-foot">litres &times; snapshotted rate</div></div>
    <div class="stat red"><div class="stat-label">Deductions</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->deductions_total_minor) }}</div>
      <div class="stat-foot">savings, levy, social, shop</div></div>
    <div class="stat amber"><div class="stat-label">Held</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->held_net_minor) }}</div>
      <div class="stat-foot">{{ $run->held_count }} farmer(s) unvalidated</div></div>
    {{-- The one an officer loads a vehicle from. Net INCLUDES held money. --}}
    <div class="stat green"><div class="stat-label">Cash required</div>
      <div class="stat-value">{{ \App\Support\Money::compact($run->cash_required_minor) }}</div>
      <div class="stat-foot">net less held &mdash; send this, not net</div></div>
  </div>

  @if ($run->held_count > 0)
    <div class="alert warn mb-16">
      <strong>{{ $run->held_count }} farmer(s) have payment held pending revalidation.</strong>
      The money is computed and owed &mdash; it is the check that is missing, not the milk. It becomes
      payable as soon as a field worker verifies them, and is deliberately excluded from
      &ldquo;cash required&rdquo; so it is not carried to a point and left there.
    </div>
  @endif

  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Reconciliation</h3><p>What should be handed over, and what has been</p></div>
    </div>
    <div class="card-body">
      <div class="grid grid-4">
        <div><div class="meta-label">Payable</div><div class="meta-value">{{ \App\Support\Money::format($reconciliation['payable']) }}</div></div>
        <div><div class="meta-label">Disbursed</div><div class="meta-value">{{ \App\Support\Money::format($reconciliation['disbursed']) }}</div></div>
        <div><div class="meta-label">Outstanding</div><div class="meta-value">{{ \App\Support\Money::format($reconciliation['outstanding']) }}</div></div>
        <div><div class="meta-label">Settled</div><div class="meta-value">{{ $reconciliation['paid'] }} / {{ $reconciliation['farmers'] }}</div></div>
      </div>
    </div>
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
                    <a href="{{ route('payment-runs.batches.show', [$run, $b]) }}" class="perm-key font-bold">
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
                    <a href="{{ route('payment-runs.batches.show', [$run, $b]) }}" class="btn btn-sm btn-outline">
                      View Batch &rarr;
                    </a>
                    @if (in_array($b->gateway, ['monnify', 'paystack']) && $b->status !== 'cancelled')
                      <form method="POST" action="{{ route('payment-runs.batches.sync', [$run, $b]) }}" style="display:inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" title="Sync with Gateway">&#8635;</button>
                      </form>
                    @endif
                    @if (in_array($b->status, ['initialized', 'processing', 'pending_otp', 'draft']))
                      <form method="POST" action="{{ route('payment-runs.batches.cancel', [$run, $b]) }}" style="display:inline" onsubmit="return confirm('Cancel this batch to prevent duplicate disbursement?')">
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
    <div class="card-head"><div><h3>Farmers</h3><p>Every figure below can be opened and argued with</p></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Farmer</th><th class="num">Litres</th><th class="num">Gross</th>
            <th class="num">Savings</th><th class="num">Levy</th><th class="num">Social</th>
            <th class="num">Shop</th><th class="num">Net</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
            @forelse ($payments as $payment)
              <tr>
                <td>{{ $payment->farmer?->name }}<small class="hint d-block">{{ $payment->farmer?->code }}</small></td>
                <td class="num">{{ \App\Support\Volume::format($payment->litres_paid) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->gross_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->savings_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->levy_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->social_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->shop_deduction_minor) }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($payment->net_minor) }}</td>
                <td>
                  <span class="badge {{ $payment->status === 'paid' ? 'success' : ($payment->status === 'reversed' ? 'muted' : ($payment->isHeld() ? 'danger' : 'warning')) }}">
                    {{ \Illuminate\Support\Str::headline($payment->status) }}</span>
                </td>
                <td class="actions">
                  @if ($canReverse && $payment->status !== 'reversed')
                    <a href="#modal-reverse-{{ $payment->id }}" class="btn btn-sm btn-ghost">Reverse</a>
                  @endif
                </td>
              </tr>
              @if ($payment->breakdown['lines'] ?? false)
                <tr class="sub-row">
                  <td colspan="10">
                    <div class="hint">
                      @foreach ($payment->breakdown['lines'] as $line)
                        <span class="perm-key">{{ $line['reference'] }}</span>
                        {{ $line['litres_payable'] }} L &times; {{ \App\Support\Money::format($line['rate_per_litre_minor']) }}
                        {{-- The pooled-grade decision, made visible. This is the
                             answer to "why was I paid B rates?" --}}
                        @if ($line['grade']) (graded {{ $line['grade'] }} on {{ $line['consignment'] }}) @endif
                        = {{ \App\Support\Money::format($line['line_gross_minor']) }}@if (! $loop->last) &nbsp;&middot;&nbsp; @endif
                      @endforeach
                    </div>
                  </td>
                </tr>
              @endif
            @empty
              <tr><td colspan="10">
                @include('partials.empty', [
                  'title' => 'Nothing to pay',
                  'message' => 'Every delivery in this scope is already on another run.',
                  'icon' => '&#128176;',
                ])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  @if ($canCancel && $run->status === 'draft')
    <div id="modal-submit" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Send {{ $run->reference }} for approval</h3>
          <p>Accounts, then the General Manager</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('payment-runs.submit', $run) }}">
          @csrf
          <div class="modal-body">
            <p>{{ \App\Support\Money::format($run->cash_required_minor) }} to
               {{ number_format($run->farmer_count) }} farmers.</p>
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

    <div id="modal-cancel" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Cancel {{ $run->reference }}</h3></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('payment-runs.cancel', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="field"><label for="cx-reason">Why?</label>
              <input type="text" id="cx-reason" name="reason" required /></div>
            <div class="hint">The deliveries this run claimed are released and will appear on the next one.</div>
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
    {{-- Reversal is the only action here that can leave a farmer owing money, so
         both modals state the clawback in naira before the button is reachable. --}}
    <div id="modal-reverse-run" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Reverse {{ $run->reference }}</h3>
          <p>Every payment on this run</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('payment-runs.reverse', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="field"><label for="rv-reason">Why is this run being reversed?</label>
              <input type="text" id="rv-reason" name="reason" required />
              <div class="hint">This is shown to anyone who asks, including the farmer.</div></div>
            <div class="alert warn">
              <strong>{{ \App\Support\Money::format($reconciliation['disbursed']) }} has already been handed over.</strong>
              Money that has left cannot be un-handed &mdash; it becomes a debt each farmer carries
              and is recovered from their later milk. Milk that was <em>not</em> paid for simply
              becomes payable again on the next run.
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
        @php $clawback = (int) $payment->disbursements->sum('amount_minor'); @endphp
        <div id="modal-reverse-{{ $payment->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><div><h3>Reverse the payment to {{ $payment->farmer?->name }}</h3>
              <p>{{ \App\Support\Money::format($payment->net_minor) }} on {{ $run->reference }}</p></div>
              <a href="#" class="modal-close">&times;</a></div>
            <form method="POST" action="{{ route('farmer-payments.reverse', $payment) }}">
              @csrf
              <div class="modal-body">
                <div class="field"><label for="rvp-reason-{{ $payment->id }}">Why?</label>
                  <input type="text" id="rvp-reason-{{ $payment->id }}" name="reason" required /></div>
                @if ($clawback > 0)
                  <div class="alert warn">
                    <strong>{{ \App\Support\Money::format($clawback) }} has already been paid to this farmer.</strong>
                    Reversing makes that amount a debt recovered from their future milk payments.
                    Be sure before you do this.
                  </div>
                @else
                  <div class="hint">
                    Nothing has been paid out yet. The milk on this line is released and will be
                    paid on the next run, and any shop credit it settled goes back to outstanding.
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

  {{-- Electronic Batch Disbursement Modal --}}
  @if ($canDisburse && $run->isApproved())
    <div id="modal-electronic-disburse" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog" style="max-width:760px;">
        <div class="modal-head">
          <div>
            <h3>Initiate Payout</h3>
            <p>Disburse approved farmer milk payments via payment gateway &amp; debit farmer wallets</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payment-runs.disburse', $run) }}">
          @csrf
          <div class="modal-body">
            <div class="grid grid-2 mb-16" style="gap:16px;">
              <div class="field">
                <label for="disburse_gateway">Payment Gateway / Channel <span class="req">*</span></label>
                <select id="disburse_gateway" name="gateway" class="form-control font-bold" required>
                  @if (isset($gateways) && !empty($gateways))
                    @foreach ($gateways as $gwKey => $gwInfo)
                      <option value="{{ $gwKey }}" {{ $gwKey === 'monnify' ? 'selected' : '' }}>
                        {{ $gwInfo['name'] ?? ucfirst($gwKey) }} ({{ $gwInfo['driver'] ?? 'Direct Transfer' }})
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
                       placeholder="e.g. Milk settlement batch {{ $run->reference }}" value="Milk settlement batch {{ $run->reference }}" />
              </div>
            </div>

            <h4 style="margin:16px 0 8px; font-size:0.95rem; color:#0f172a;">Select Farmers to Include in this Batch</h4>
            <div class="table-wrap" style="max-height:280px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
              <table class="table">
                <thead>
                  <tr style="background:#f8fafc; position:sticky; top:0; z-index:2;">
                    <th style="width:30px;">
                      <input type="checkbox" id="select_all_farmers" checked onchange="document.querySelectorAll('.farmer-checkbox').forEach(cb => cb.checked = this.checked)" />
                    </th>
                    <th>Farmer</th>
                    <th>Bank &amp; Account</th>
                    <th class="num">Outstanding (₦)</th>
                    <th class="num" style="width:140px;">Payout Amount (₦)</th>
                  </tr>
                </thead>
                  @php
                    $payablePayments = $payments->filter(fn ($p) => !$p->isHeld() && $p->status !== 'paid' && $p->status !== 'reversed' && $p->outstandingMinor() > 0);
                  @endphp
                  @forelse ($payablePayments as $p)
                    @php
                      $cleanAcc = preg_replace('/\D/', '', (string) ($p->farmer?->bank_account ?? ($p->farmer?->bank_account_number ?? '')));
                      $hasValidAcc = strlen($cleanAcc) === 10;
                    @endphp
                    <tr style="{{ !$hasValidAcc ? 'background:#fef2f2;' : '' }}">
                      <td>
                        <input type="checkbox" name="selected_payments[]" value="{{ $p->id }}" class="farmer-checkbox" {{ $hasValidAcc ? 'checked' : '' }} />
                      </td>
                      <td>
                        <strong>{{ $p->farmer?->name }}</strong>
                        <div class="cell-sub">{{ $p->farmer?->code }}</div>
                      </td>
                      <td>
                        <div style="font-weight:600;">{{ $p->farmer?->bank_name ?: 'Bank not set' }}</div>
                        @if ($hasValidAcc)
                          <div class="cell-sub mono font-bold" style="color:#16a34a;">{{ $cleanAcc }} &check;</div>
                        @else
                          <div class="cell-sub font-bold text-danger" style="color:#dc2626; font-size:0.75rem;">
                            &#9888; {{ $p->farmer?->bank_account_masked ?: 'No 10-digit account' }}
                          </div>
                        @endif
                      </td>
                      <td class="num font-bold" style="color:#0b7d54;">
                        {{ \App\Support\Money::format($p->outstandingMinor()) }}
                      </td>
                      <td class="num">
                        <input type="number" step="0.01" min="0.01" max="{{ $p->outstandingMinor() / 100 }}"
                               name="amounts[{{ $p->id }}]"
                               value="{{ number_format($p->outstandingMinor() / 100, 2, '.', '') }}"
                               class="form-control font-bold num" style="padding:4px 8px; font-size:0.9rem;" />
                      </td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="5" class="text-muted text-center">No payable farmer balances available on this run.</td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            <div class="alert info mt-16" style="margin-bottom:0;">
              <span>&#8505;</span>
              <div style="font-size:0.85rem;">
                <strong>Automatic Wallet Ledger:</strong> Upon successful settlement, the payout will be automatically deducted from each farmer's wallet, recorded as a <code>debit</code> transaction, and linked directly to this payment run.
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary font-bold" style="background:#0b7d54; border-color:#0b7d54;">
              <span>&#128179;</span> Initiate Payout &rarr;
            </button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
