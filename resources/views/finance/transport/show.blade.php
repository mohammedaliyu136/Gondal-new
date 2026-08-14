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
    <div class="dh-actions">
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

  <div class="card">
    <div class="card-head"><div><h3>Drivers and riders</h3><p>Every fee below can be opened and argued with</p></div></div>
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
                  @if ($canDisburse && $run->isApproved() && $payment->outstandingMinor() > 0)
                    <a href="#modal-tp-pay-{{ $payment->id }}" class="btn btn-sm btn-primary">Pay</a>
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

  @if ($canDisburse && $run->isApproved())
    @foreach ($payments as $payment)
      @if ($payment->outstandingMinor() > 0)
        <div id="modal-tp-pay-{{ $payment->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><div><h3>Pay {{ $payment->driver?->name }}</h3>
              <p>{{ \App\Support\Money::format($payment->outstandingMinor()) }} outstanding across
                 {{ number_format($payment->trip_count) }} trip(s)</p></div>
              <a href="#" class="modal-close">&times;</a></div>
            <form method="POST" action="{{ route('driver-payments.disburse', $payment) }}">
              @csrf
              <div class="modal-body">
                <div class="form-grid">
                  <div class="field"><label>Amount (kobo)</label>
                    <input type="number" name="amount_minor" value="{{ $payment->outstandingMinor() }}" required /></div>
                  <div class="field"><label>Method</label>
                    <select name="method" required>
                      <option value="cash">Cash</option>
                      <option value="bank">Bank transfer</option>
                      <option value="mobile_money">Mobile money</option>
                    </select></div>
                  <div class="field"><label>Received by</label>
                    <input type="text" name="received_by" value="{{ $payment->driver?->name }}" /></div>
                  <div class="field"><label>Bank / mobile reference</label>
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
@endsection
