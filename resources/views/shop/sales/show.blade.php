@extends('layouts.app')
@section('title', $sale->receipt_no)

@section('content')
  <div class="breadcrumb">
    <a href="{{ route('shop.sales.index') }}">Sales</a><span class="sep">/</span>
    <span>{{ $sale->receipt_no }}</span>
  </div>

  <div class="detail-head">
    <div>
      <h1>{{ $sale->receipt_no }}</h1>
      <div class="dh-meta">
        <span class="pill">{{ \App\Support\Wat::dateTime($sale->sold_at) }}</span>
        <span class="badge {{ $sale->isVoided() ? 'danger' : 'success' }}">
          {{ $sale->isVoided() ? 'Voided' : 'Completed' }}</span>
        <span class="pill">{{ ucfirst(str_replace('_', ' ', $sale->payment_method)) }}</span>
      </div>
    </div>
    <div class="dh-actions">
      @if ($canVoid && ! $sale->isVoided())
        <a href="#modal-void" class="btn btn-ghost text-danger">Void this sale</a>
      @endif
    </div>
  </div>

  @if ($sale->isVoided())
    <div class="alert danger mb-16">
      <span>&#10060;</span>
      <div>
        <strong>This sale was voided
          {{ $sale->voidedBy ? 'by '.$sale->voidedBy->name : '' }}
          on {{ \App\Support\Wat::dateTime($sale->voided_at) }}.</strong>
        {{ $sale->void_reason }}
        The stock was returned and any deduction against the customer was cancelled.
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Items</h3></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Product</th><th class="num">Quantity</th>
                @if ($seesRevenue)<th class="num">Unit price</th><th class="num">Amount</th>@endif
              </tr></thead>
              <tbody>
                @foreach ($sale->items as $item)
                  <tr>
                    <td>{{ $item->product?->name ?? 'Removed product' }}
                      <div class="cell-sub">{{ $item->product?->sku }}</div></td>
                    <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}
                      {{ $item->product?->unit }}</td>
                    @if ($seesRevenue)
                      <td class="num">{{ \App\Support\Money::format((int) $item->unit_price_minor) }}</td>
                      <td class="num font-bold">{{ \App\Support\Money::format((int) $item->amount_minor) }}</td>
                    @endif
                  </tr>
                @endforeach
              </tbody>
              @if ($seesRevenue)
                <tfoot><tr>
                  <th colspan="3">Total</th>
                  <th class="num">{{ \App\Support\Money::format((int) $sale->total_minor) }}</th>
                </tr></tfoot>
              @endif
            </table>
          </div>
        </div>
      </div>

      @if ($deduction)
        <div class="card">
          <div class="card-head"><div><h3>Milk deduction</h3>
            <p>Taken from this farmer&rsquo;s next milk payment</p></div>
            <span class="badge {{ $deduction->status === 'pending' ? 'warning' : ($deduction->status === 'cancelled' ? 'muted' : 'success') }}">
              {{ ucfirst($deduction->status) }}</span>
          </div>
          <div class="card-body">
            <div class="meta-grid cols-2">
              <div class="meta-item"><div class="meta-label">Amount</div>
                <div class="meta-value">{{ \App\Support\Money::format((int) $deduction->amount_minor) }}</div></div>
              <div class="meta-item"><div class="meta-label">Status</div>
                <div class="meta-value">{{ $deduction->description }}</div></div>
            </div>
            @if ($deduction->status === 'pending')
              <div class="hint mt-8">Farmer payment runs are not available yet, so this is held against the next one.</div>
            @endif
          </div>
        </div>
      @endif
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Sale</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Customer</div>
              <div class="meta-value">{{ $sale->customerLabel() }}</div></div>
            <div class="meta-item"><div class="meta-label">Type</div>
              <div class="meta-value">{{ ucfirst($sale->customer_type) }}</div></div>
            <div class="meta-item"><div class="meta-label">Payment</div>
              <div class="meta-value">{{ ucfirst(str_replace('_', ' ', $sale->payment_method)) }}</div></div>
            <div class="meta-item"><div class="meta-label">Sold by</div>
              <div class="meta-value">{{ $sale->salesOfficer?->name ?? '—' }}</div></div>
            @if ($sale->prescription_reference)
              <div class="meta-item"><div class="meta-label">Prescription</div>
                <div class="meta-value">{{ $sale->prescription_reference }}</div></div>
            @endif
            @if ($seesRevenue)
              <div class="meta-item"><div class="meta-label">Received</div>
                <div class="meta-value">{{ \App\Support\Money::format((int) $sale->amount_received_minor) }}</div></div>
            @endif
          </div>
          @if ($sale->notes)
            <div class="divider"></div>
            <div class="text-small">{{ $sale->notes }}</div>
          @endif
        </div>
      </div>
    </div>
  </div>

  @if ($canVoid && ! $sale->isVoided())
    <div id="modal-void" class="modal @if (old('_modal') === 'modal-void') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Void {{ $sale->receipt_no }}</h3>
            <p>The stock goes back and any deduction is cancelled</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('shop.sales.void', $sale) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-void" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-void'])
            <div class="field">
              <label for="void-reason">Reason <span class="req">*</span></label>
              <textarea id="void-reason" name="void_reason" rows="3" required
                        placeholder="Wrong product rung up, customer returned the goods…">{{ old('void_reason') }}</textarea>
              <div class="hint">The sale stays on the record, marked as voided with this reason.</div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger">Void sale</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
