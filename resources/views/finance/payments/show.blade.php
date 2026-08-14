@extends('layouts.app')
@section('title', $run->reference)

@section('content')
  <div class="breadcrumb">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('payment-runs.index') }}">Farmer Payments</a><span class="sep">/</span>
    <span>{{ $run->reference }}</span>
  </div>

  <div class="detail-head">
    <div>
      <h1>{{ $run->reference }}</h1>
      <div class="pill-row">
        <span class="badge {{ $run->status === 'paid' ? 'success' : ($run->status === 'cancelled' ? 'muted' : 'warning') }}">
          {{ \Illuminate\Support\Str::headline($run->status) }}</span>
        <span class="pill">{{ number_format($run->farmer_count) }} farmers</span>
        <span class="pill">{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</span>
      </div>
    </div>
    <div class="dh-actions">
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
                  @if ($canDisburse && $run->isApproved() && ! $payment->isHeld() && $payment->outstandingMinor() > 0)
                    <a href="#modal-pay-{{ $payment->id }}" class="btn btn-sm btn-primary">Pay</a>
                  @endif
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

  @if ($canDisburse && $run->isApproved())
    @foreach ($payments as $payment)
      @if (! $payment->isHeld() && $payment->outstandingMinor() > 0)
        <div id="modal-pay-{{ $payment->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><div><h3>Pay {{ $payment->farmer?->name }}</h3>
              <p>{{ \App\Support\Money::format($payment->outstandingMinor()) }} outstanding</p></div>
              <a href="#" class="modal-close">&times;</a></div>
            <form method="POST" action="{{ route('farmer-payments.disburse', $payment) }}">
              @csrf
              <div class="modal-body">
                <div class="form-grid">
                  <div class="field"><label>Amount (kobo)</label>
                    <input type="number" name="amount_minor" value="{{ $payment->outstandingMinor() }}" required /></div>
                  {{-- Defaulted to what the farmer's record says, so the payer
                       is not choosing from memory at a collection point. --}}
                  <div class="field"><label>Method</label>
                    <select name="method" required>
                      <option value="cash" @selected(($payment->farmer?->payout_method ?: 'cash') === 'cash')>Cash at the point</option>
                      <option value="bank" @selected($payment->farmer?->payout_method === 'bank')>Bank transfer</option>
                      <option value="mobile_money" @selected($payment->farmer?->payout_method === 'mobile_money')>Mobile money</option>
                      <option value="via_cooperative" @selected($payment->farmer?->payout_method === 'via_cooperative')>Via the cooperative</option>
                    </select>
                    @if ($payment->farmer?->payout_method === 'bank' && $payment->farmer?->bank_account_masked)
                      <div class="hint">{{ $payment->farmer->bank_name }} {{ $payment->farmer->bank_account_masked }}</div>
                    @elseif ($payment->farmer?->payout_method === 'mobile_money' && $payment->farmer?->mobile_money_number)
                      <div class="hint">{{ $payment->farmer->mobile_money_number }}</div>
                    @endif
                  </div>
                  <div class="field"><label>Received by</label>
                    <input type="text" name="received_by" value="{{ $payment->farmer?->name }}" /></div>
                  <div class="field"><label>Relation</label>
                    <select name="received_by_relation">
                      <option value="self">The farmer</option>
                      <option value="son">Son</option>
                      <option value="wife">Wife</option>
                      <option value="other">Someone else</option>
                    </select></div>
                  <div class="field full"><label>Authority reference <span class="hint">(if not the farmer)</span></label>
                    <input type="text" name="proxy_authority_ref" />
                    <div class="hint">Paying anyone but the farmer needs a written authority. It is logged.</div></div>
                  <div class="field full"><label>Bank / mobile reference</label>
                    <input type="text" name="external_reference" /></div>
                </div>
              </div>
              <div class="modal-foot">
                <a href="#" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Record payout</button>
              </div>
            </form>
          </div>
        </div>
      @endif
    @endforeach
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
@endsection
