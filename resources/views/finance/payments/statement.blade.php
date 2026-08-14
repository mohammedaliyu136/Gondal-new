@extends('layouts.app')
@section('title', 'Statement — '.$farmer->name)

@section('content')
  <div class="breadcrumb no-print">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('farmers.index') }}">Farmers</a><span class="sep">/</span>
    <a href="{{ route('farmers.show', $farmer) }}">{{ $farmer->name }}</a><span class="sep">/</span>
    <span>Statement</span>
  </div>

  <div class="detail-head">
    <div>
      <h1>{{ $farmer->name }}</h1>
      <div class="pill-row">
        <span class="pill">{{ $farmer->code }}</span>
        <span class="pill">{{ $farmer->community?->name }}{{ $farmer->community?->lga ? ', '.$farmer->community->lga->name : '' }}</span>
        @if ($farmer->cooperative)
          <span class="pill">{{ $farmer->cooperative->name }}</span>
        @endif
        <span class="pill">{{ $farmer->defaultCollectionPoint?->name }}</span>
      </div>
      <p class="hint">Statement produced {{ $generated_at }}</p>
    </div>
    <div class="dh-actions no-print">
      <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
    </div>
  </div>

  {{-- The three questions, in the order a farmer asks them. --}}
  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Owed for unpaid milk</div>
      <div class="stat-value">{{ \App\Support\Money::compact($outstanding['net_minor']) }}</div>
      <div class="stat-foot">{{ \App\Support\Volume::format($outstanding['litres']) }} not yet on a run</div></div>
    <div class="stat green"><div class="stat-label">Received to date</div>
      <div class="stat-value">{{ \App\Support\Money::compact($totals['received_minor']) }}</div>
      <div class="stat-foot">{{ $disbursements->count() }} payout(s)</div></div>
    <div class="stat amber"><div class="stat-label">Approved, not yet handed over</div>
      <div class="stat-value">{{ \App\Support\Money::compact($totals['unpaid_on_runs_minor']) }}</div>
      <div class="stat-foot">on runs already approved</div></div>
    <div class="stat red"><div class="stat-label">Owing</div>
      <div class="stat-value">{{ \App\Support\Money::compact($totals['debt_outstanding_minor']) }}</div>
      <div class="stat-foot">to come off future milk</div></div>
  </div>

  @if ($outstanding['held'])
    <div class="alert warn mb-16 no-print">
      <strong>This farmer's payment is held pending revalidation.</strong>
      The money below is computed and owed &mdash; the milk was collected and it is the
      check that is missing. It becomes payable as soon as a field worker verifies them.
    </div>
  @endif


  {{-- Where the money is sent. Editable only with a finance grant: an Extension
       Agent may correct this farmer's herd size and must not be able to point
       their bank payments elsewhere. --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Where payments go</h3><p>Checked by the payer before money changes hands</p></div>
      @if ($canEditPayout)
        <div class="no-print"><a href="#modal-payout" class="btn btn-sm btn-outline">Change</a></div>
      @endif
    </div>
    <div class="card-body">
      <div class="grid grid-4">
        <div><div class="meta-label">Method</div><div class="meta-value">
          {{ $farmer->payout_method ? \Illuminate\Support\Str::headline($farmer->payout_method) : 'Cash at the point' }}</div></div>
        <div><div class="meta-label">Bank</div><div class="meta-value">{{ $farmer->bank_name ?: '—' }}</div></div>
        <div><div class="meta-label">Account</div><div class="meta-value">{{ $farmer->bank_account_masked ?: '—' }}</div></div>
        <div><div class="meta-label">Mobile money</div><div class="meta-value">{{ $farmer->mobile_money_number ?: '—' }}</div></div>
      </div>
      @if (! $farmer->payout_method)
        <p class="hint">Nothing recorded, so this farmer is paid in cash at their collection point.</p>
      @endif
    </div>
  </div>

  {{-- Question 1. The figure disputed at a collection point, so it is broken
       down to the delivery rather than given as a single number. --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Milk not yet paid for</h3><p>Delivered and priced, waiting for the next payment run</p></div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Delivery</th><th class="num">Litres</th><th class="num">Rate</th><th>Priced on</th><th class="num">Value</th>
          </tr></thead>
          <tbody>
            @forelse ($outstanding['lines'] as $line)
              <tr>
                <td><span class="perm-key">{{ $line['reference'] }}</span></td>
                <td class="num">{{ $line['litres_payable'] }} L</td>
                <td class="num">{{ \App\Support\Money::format($line['rate_per_litre_minor']) }}</td>
                <td>{{ $line['consignment'] }}@if ($line['grade']) <small class="hint">graded {{ $line['grade'] }}</small>@endif</td>
                <td class="num">{{ \App\Support\Money::format($line['line_gross_minor']) }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="hint">Every delivery so far is already on a payment run.</td></tr>
            @endforelse
          </tbody>
          @if (count($outstanding['lines']) > 0)
            <tfoot>
              <tr><th colspan="4">Gross</th><th class="num">{{ \App\Support\Money::format($outstanding['gross_minor']) }}</th></tr>
              <tr><td colspan="4">Less savings</td><td class="num">&minus;{{ \App\Support\Money::format($outstanding['savings_minor']) }}</td></tr>
              <tr><td colspan="4">Less levy</td><td class="num">&minus;{{ \App\Support\Money::format($outstanding['levy_minor']) }}</td></tr>
              <tr><td colspan="4">Less social fund</td><td class="num">&minus;{{ \App\Support\Money::format($outstanding['social_minor']) }}</td></tr>
              <tr><td colspan="4">Less shop credit</td><td class="num">&minus;{{ \App\Support\Money::format($outstanding['shop_deduction_minor']) }}</td></tr>
              <tr><th colspan="4">Would be paid</th><th class="num">{{ \App\Support\Money::format($outstanding['net_minor']) }}</th></tr>
            </tfoot>
          @endif
        </table>
      </div>
    </div>
  </div>

  {{-- Question 2. --}}
  <div class="card mb-16">
    <div class="card-head"><div><h3>Payments</h3><p>What was worked out, run by run</p></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Run</th><th>Period</th><th class="num">Litres</th><th class="num">Gross</th>
            <th class="num">Savings</th><th class="num">Levy</th><th class="num">Social</th>
            <th class="num">Shop</th><th class="num">Net</th><th>Status</th>
          </tr></thead>
          <tbody>
            @forelse ($payments as $payment)
              <tr>
                <td>{{ $payment->run?->reference }}</td>
                <td>{{ $payment->run?->period_start?->toDateString() }} &rarr; {{ $payment->run?->period_end?->toDateString() }}</td>
                <td class="num">{{ \App\Support\Volume::format($payment->litres_paid) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->gross_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->savings_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->levy_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->social_minor) }}</td>
                <td class="num">{{ \App\Support\Money::format($payment->shop_deduction_minor) }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($payment->net_minor) }}</td>
                <td><span class="badge {{ $payment->status === 'paid' ? 'success' : ($payment->status === 'reversed' ? 'muted' : ($payment->isHeld() ? 'danger' : 'warning')) }}">
                  {{ \Illuminate\Support\Str::headline($payment->status) }}</span></td>
              </tr>
            @empty
              <tr><td colspan="10" class="hint">This farmer has not been on a payment run yet.</td></tr>
            @endforelse
          </tbody>
          @if ($payments->isNotEmpty())
            <tfoot>
              {{-- Reversed rows are excluded from these totals but still listed
                   above, so a reversal is visible next to the debt it created. --}}
              <tr>
                <th colspan="2">Total, excluding reversals</th>
                <th class="num">{{ \App\Support\Volume::format($totals['litres_paid']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['gross_minor']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['savings_minor']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['levy_minor']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['social_minor']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['shop_minor']) }}</th>
                <th class="num">{{ \App\Support\Money::format($totals['net_minor']) }}</th>
                <th></th>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-head"><div><h3>Money handed over</h3><p>Who received it, when, and how</p></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>When</th><th class="num">Amount</th><th>How</th><th>Received by</th><th>Paid by</th><th>Reference</th></tr></thead>
          <tbody>
            @forelse ($disbursements as $payout)
              <tr>
                <td>{{ \App\Support\Wat::dateTime($payout->disbursed_at) }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($payout->amount_minor) }}</td>
                <td>{{ \Illuminate\Support\Str::headline($payout->method) }}</td>
                <td>
                  {{ $payout->received_by ?: $farmer->name }}
                  {{-- Paying anyone but the farmer needs a written authority, and
                       the statement is where the farmer gets to see that it happened. --}}
                  @if ($payout->received_by_relation && $payout->received_by_relation !== 'self')
                    <small class="hint d-block">{{ \Illuminate\Support\Str::headline($payout->received_by_relation) }}@if ($payout->proxy_authority_ref) &middot; authority {{ $payout->proxy_authority_ref }}@endif</small>
                  @endif
                </td>
                <td>{{ $payout->paidBy?->name }}</td>
                <td>{{ $payout->external_reference ?: '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="hint">Nothing has been handed over yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- Question 3. --}}
  <div class="card">
    <div class="card-head"><div><h3>Amounts taken off</h3><p>Shop credit and any recoverable overpayment</p></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Raised</th><th>What for</th><th class="num">Amount</th><th>Status</th></tr></thead>
          <tbody>
            @forelse ($deductions as $deduction)
              <tr>
                <td>{{ \App\Support\Wat::dateTime($deduction->created_at) }}</td>
                <td>{{ $deduction->description }}</td>
                <td class="num">{{ \App\Support\Money::format($deduction->amount_minor) }}</td>
                <td><span class="badge {{ $deduction->status === 'settled' ? 'success' : ($deduction->status === 'cancelled' ? 'muted' : 'warning') }}">
                  {{ \Illuminate\Support\Str::headline($deduction->status) }}</span></td>
              </tr>
            @empty
              <tr><td colspan="4" class="hint">Nothing is being taken off this farmer's milk money.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  @if ($canEditPayout)
    <div id="modal-payout" class="modal no-print">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Where {{ $farmer->name }} is paid</h3>
          <p>Changing this is logged</p></div><a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('farmers.payout-details', $farmer) }}">
          @csrf
          @method('PUT')
          <div class="modal-body">
            <div class="form-grid">
              <div class="field full"><label for="po-method">Method</label>
                <select id="po-method" name="payout_method">
                  <option value="">Cash at the point</option>
                  <option value="bank" @selected($farmer->payout_method === 'bank')>Bank transfer</option>
                  <option value="mobile_money" @selected($farmer->payout_method === 'mobile_money')>Mobile money</option>
                  <option value="via_cooperative" @selected($farmer->payout_method === 'via_cooperative')>Via the cooperative</option>
                </select></div>
              <div class="field"><label for="po-bank">Bank</label>
                <input type="text" id="po-bank" name="bank_name" value="{{ $farmer->bank_name }}" /></div>
              <div class="field"><label for="po-acct">Account number</label>
                <input type="text" id="po-acct" name="bank_account_number" autocomplete="off" />
                {{-- Only the last four digits are kept. Enough for a payer to
                     check the right account; not enough to move money with. --}}
                <div class="hint">Only the last four digits are stored.
                  @if ($farmer->bank_account_masked) Currently {{ $farmer->bank_account_masked }}. @endif
                  Leave blank to keep it.</div></div>
              <div class="field full"><label for="po-momo">Mobile money number</label>
                <input type="text" id="po-momo" name="mobile_money_number" value="{{ $farmer->mobile_money_number }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
