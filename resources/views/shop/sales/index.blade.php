@extends('layouts.app')
@section('title', 'Sales')

@section('content')
  <div class="page-head">
    <div>
      <h1>Sales</h1>
      <p>{{ number_format($saleCountToday) }} transactions &middot; {{ \App\Support\Wat::longDate($date) }}</p>
    </div>
    <div class="page-actions">
      @can('shop.inventory.view')
        <a href="{{ route('shop.inventory') }}" class="btn btn-outline">Inventory</a>
      @endcan
      @if ($canSell)<a href="#modal-sale" class="btn btn-primary">+ Record Sale</a>@endif
    </div>
  </div>

  @unless ($seesRevenue)
    {{-- BR-29 — the Sales Officer persona, verbatim. --}}
    <div class="alert info mb-16">
      <span>&#128274;</span>
      <div>
        <strong>You see your own transactions, not revenue.</strong>
        Daily and monthly totals, margins and stock values are not shown to your role.
      </div>
    </div>
  @endunless

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Transactions</div>
      <div class="stat-value">{{ number_format($saleCountToday) }}</div>
      <div class="stat-foot">on {{ \App\Support\Wat::date($date) }}</div></div>
    @if ($seesRevenue)
      <div class="stat green"><div class="stat-label">Revenue today</div>
        <div class="stat-value">{{ \App\Support\Money::compact($revenueTodayMinor) }}</div>
        <div class="stat-foot">across the shop</div></div>
      <div class="stat amber"><div class="stat-label">Margin today</div>
        <div class="stat-value">{{ \App\Support\Money::compact($marginTodayMinor) }}</div>
        <div class="stat-foot">against the cost recorded at the time of sale</div></div>
      {{-- The deficit on the cooperatives' general funds, so it falls when they
           settle. It used to be the lifetime sum of credit issued, which no
           repayment could reduce. --}}
      <div class="stat red"><div class="stat-label">Credit outstanding</div>
        <div class="stat-value">{{ \App\Support\Money::compact($creditOutstandingMinor) }}</div>
        <div class="stat-foot">owed on cooperative accounts</div></div>
    @else
      <div class="stat"><div class="stat-label">Revenue today</div>
        <div class="stat-value">&mdash;</div><div class="stat-foot">not shown to your role</div></div>
      <div class="stat"><div class="stat-label">Margin</div>
        <div class="stat-value">&mdash;</div><div class="stat-foot">not shown to your role</div></div>
      <div class="stat"><div class="stat-label">Credit outstanding</div>
        <div class="stat-value">&mdash;</div><div class="stat-foot">not shown to your role</div></div>
    @endif
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Transactions</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="date">Date</label>
          <input type="date" id="date" name="date" value="{{ $date }}" /></div>
        <div class="field"><label for="payment_method">Payment</label>
          <select id="payment_method" name="payment_method">
            <option value="">All</option>
            @foreach (['cash' => 'Cash', 'transfer' => 'Transfer', 'credit' => 'Credit', 'milk_deduction' => 'Milk deduction'] as $value => $label)
              <option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="customer_type">Customer</label>
          <select id="customer_type" name="customer_type">
            <option value="">All</option>
            @foreach (['farmer' => 'Farmer', 'cooperative' => 'Cooperative', 'walkin' => 'Walk-in', 'internal' => 'Internal'] as $value => $label)
              <option value="{{ $value }}" @selected(request('customer_type') === $value)>{{ $label }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="q">Receipt</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" /></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('shop.sales.index') }}" class="btn btn-ghost btn-sm">Today</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Receipt</th><th>Customer</th><th>Items</th><th>Payment</th>
            <th class="num">Total</th>
            @if ($seesRevenue)<th class="num">Margin</th>@endif
            <th>Officer</th><th>Time</th>
          </tr></thead>
          <tbody>
            @forelse ($sales as $sale)
              <tr>
                <td class="perm-key">
                  <a href="{{ route('shop.sales.show', $sale) }}" class="text-primary">{{ $sale->receipt_no }}</a>
                  @if ($sale->isVoided())<div class="cell-sub"><span class="badge danger">Voided</span></div>@endif
                  @if ($sale->prescription_reference)
                    <div class="cell-sub">Rx {{ $sale->prescription_reference }}</div>
                  @endif</td>
                <td>{{ $sale->customerLabel() }}
                  <div class="cell-sub">{{ \Illuminate\Support\Str::headline($sale->customer_type) }}</div></td>
                <td>
                  @foreach ($sale->items->take(2) as $item)
                    <div class="text-small">{{ $item->product?->name }} &times;
                      {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</div>
                  @endforeach
                  @if ($sale->items->count() > 2)
                    <div class="cell-sub">+{{ $sale->items->count() - 2 }} more</div>
                  @endif
                </td>
                <td><span class="badge {{ [
                  'cash' => 'success', 'transfer' => 'info',
                  'credit' => 'warning', 'milk_deduction' => 'info',
                ][$sale->payment_method] ?? 'muted' }}">
                  {{ \Illuminate\Support\Str::headline($sale->payment_method) }}</span></td>
                <td class="num font-bold">{{ \App\Support\Money::format($sale->total_minor) }}</td>
                @if ($seesRevenue)
                  <td class="num">{{ \App\Support\Money::format($sale->marginMinor()) }}</td>
                @endif
                <td>{{ $sale->salesOfficer?->name }}</td>
                <td>{{ \App\Support\Wat::time($sale->sold_at) }}</td>
              </tr>
            @empty
              <tr><td colspan="{{ $seesRevenue ? 8 : 7 }}">
                @include('partials.empty', [
                  'title' => 'No sales for this filter',
                  'message' => 'A sales officer sees their own transactions only.',
                  'icon' => '&#128722;',
                ])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $sales, 'noun' => 'sales'])
  </div>

  @if ($canSell)
    <div id="modal-sale" class="modal @if (old('_modal') === 'modal-sale') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Record Sale</h3>
            <p>A sale that would take stock below zero is refused</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('shop.sales.store') }}">
          @csrf
            <input type="hidden" name="_modal" value="modal-sale" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-sale'])
            <div class="form-grid">
              <div class="field"><label for="ns-customer-type">Customer type <span class="req">*</span></label>
                <select id="ns-customer-type" name="customer_type" required>
                  <option value="walkin">Walk-in</option>
                  <option value="farmer" @selected(old('customer_type') === 'farmer')>Farmer</option>
                  <option value="cooperative" @selected(old('customer_type') === 'cooperative')>Cooperative</option>
                  <option value="internal" @selected(old('customer_type') === 'internal')>Internal</option>
                </select></div>
              <div class="field"><label for="ns-farmer">Farmer</label>
                <select id="ns-farmer" data-searchable data-combo-placeholder="Search farmers by name or code…" name="farmer_id">
                  <option value="">—</option>
                  @foreach ($farmers as $farmer)
                    <option value="{{ $farmer->id }}" @selected(old('farmer_id') == $farmer->id)>{{ $farmer->name }} — {{ $farmer->code }}</option>
                  @endforeach
                </select>
                <div class="hint">Required for a farmer sale, and for a milk deduction.</div></div>
              <div class="field"><label for="ns-coop">Cooperative</label>
                <select id="ns-coop" data-searchable data-combo-placeholder="Search cooperatives…" name="cooperative_id">
                  <option value="">—</option>
                  @foreach ($cooperatives as $cooperative)
                    <option value="{{ $cooperative->id }}" @selected(old('cooperative_id') == $cooperative->id)>{{ $cooperative->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ns-name">Customer name</label>
                <input type="text" id="ns-name" name="customer_name" value="{{ old('customer_name') }}" /></div>
              <div class="field"><label for="ns-payment">Payment method <span class="req">*</span></label>
                <select id="ns-payment" name="payment_method" required>
                  <option value="cash" @selected(old('payment_method') === 'cash')>Cash</option>
                  <option value="transfer" @selected(old('payment_method') === 'transfer')>Transfer</option>
                  <option value="credit" @selected(old('payment_method') === 'credit')>Credit (cooperative customers only)</option>
                  <option value="milk_deduction" @selected(old('payment_method') === 'milk_deduction')>Milk deduction</option>
                </select>
                {{-- A credit sale is a debt, and only a cooperative has an account
                     to carry one. The service refuses the rest; saying so here is
                     what stops the officer finding out with a customer waiting. --}}
                <div class="hint">Credit needs a cooperative customer whose category allows it. A farmer buys against milk with a deduction.</div></div>
              <div class="field"><label for="ns-received">Amount received (₦)</label>
                <input type="text" id="ns-received" name="amount_received" inputmode="decimal" value="{{ old('amount_received') }}" /></div>
              <div class="field full"><label for="ns-rx">Prescription reference</label>
                <input type="text" id="ns-rx" name="prescription_reference" value="{{ old('prescription_reference') }}" />
                <div class="hint">Required when any line&rsquo;s category requires a prescription.</div></div>
            </div>

            <div class="divider"></div>
            <h3 class="mb-16">Lines</h3>
            @for ($i = 0; $i < 4; $i++)
              <div class="form-grid mb-16">
                <div class="field"><label for="ns-product-{{ $i }}">Product{!! $i === 0 ? ' <span class="req">*</span>' : '' !!}</label>
                  <select id="ns-product-{{ $i }}" name="items[{{ $i }}][product_id]" data-searchable data-combo-placeholder="Search products…" @required($i === 0)>
                    @unless ($i === 0)<option value="">—</option>@endunless
                    @foreach ($products as $product)
                      <option value="{{ $product->id }}" @selected(old("items.$i.product_id") == $product->id)>
                        {{ $product->name }}
                        — {{ \App\Support\Money::format((int) $product->selling_price_minor) }}
                        ({{ rtrim(rtrim((string) $product->quantity_on_hand, '0'), '.') }} {{ $product->unit }} in stock)
                        @if ($product->category?->requires_prescription) — Rx @endif
                      </option>
                    @endforeach
                  </select></div>
                <div class="field"><label for="ns-qty-{{ $i }}">Quantity{!! $i === 0 ? ' <span class="req">*</span>' : '' !!}</label>
                  <input type="text" id="ns-qty-{{ $i }}" name="items[{{ $i }}][quantity]" inputmode="decimal"
                         value="{{ old("items.$i.quantity", $i === 0 ? '1' : '') }}" @required($i === 0) /></div>
                <div class="field"><label for="ns-price-{{ $i }}">Unit price override (₦)</label>
                  <input type="text" id="ns-price-{{ $i }}" name="items[{{ $i }}][unit_price]" inputmode="decimal" value="{{ old("items.$i.unit_price") }}" /></div>
              </div>
            @endfor

            <div class="field"><label for="ns-notes">Notes</label>
              <textarea id="ns-notes" name="notes" rows="2">{{ old('notes') }}</textarea></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Record sale</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
