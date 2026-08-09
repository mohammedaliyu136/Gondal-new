@extends('layouts.app')
@section('title', $product->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('shop.inventory') }}">Inventory</a><span class="sep">/</span>
    <span class="here">{{ $product->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($product->sku, -3) }}</div>
    <div class="dh-main">
      <h1>{{ $product->name }}</h1>
      <div class="dh-sub">{{ $product->sku }} &middot; {{ $product->category?->name }}
        &middot; {{ rtrim(rtrim((string) $product->quantity_on_hand, '0'), '.') }} {{ $product->unit }} on hand</div>
      <div class="dh-tags">
        <span class="badge {{ $product->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($product->status) }}</span>
        @if ($product->isLowStock())<span class="badge danger">Low stock</span>@endif
        @if ($product->category?->requires_prescription)<span class="pill">prescription required</span>@endif
        @if ($product->category?->track_expiry)<span class="pill">expiry tracked</span>@endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canReceive)<a href="#modal-receive" class="btn btn-primary">Receive stock</a>@endif
      @if ($canAdjust)<a href="#modal-adjust" class="btn btn-outline">Adjust</a>@endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">On hand</div>
      <div class="stat-value">{{ rtrim(rtrim((string) $product->quantity_on_hand, '0'), '.') }}</div>
      <div class="stat-foot">{{ $product->unit }}</div></div>
    <div class="stat amber"><div class="stat-label">Reorder level</div>
      <div class="stat-value">{{ $product->reorder_level ?? '—' }}</div>
      <div class="stat-foot">from category if unset</div></div>
    <div class="stat green"><div class="stat-label">Selling price</div>
      <div class="stat-value">{{ \App\Support\Money::format($product->selling_price_minor) }}</div>
      <div class="stat-foot">per {{ $product->unit }}</div></div>
    @if ($seesRevenue)
      <div class="stat"><div class="stat-label">Margin</div>
        <div class="stat-value">{{ \App\Support\Money::format($marginMinor) }}</div>
        <div class="stat-foot">stock value {{ \App\Support\Money::compact($stockValueMinor) }}</div></div>
    @else
      <div class="stat"><div class="stat-label">Margin</div>
        <div class="stat-value">&mdash;</div>
        <div class="stat-foot">not shown to your role</div></div>
    @endif
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Batches</h3>
          <p>{{ $product->category?->track_expiry ? 'Expiry is tracked for this category, so stock rotates oldest first' : 'Received stock' }}</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Batch</th><th>Supplier</th><th>Received</th><th>Expires</th>
                <th class="num">Received</th><th class="num">Remaining</th>
                @if ($seesRevenue)<th class="num">Unit cost</th>@endif
                <th>Status</th></tr></thead>
              <tbody>
                @forelse ($batches as $batch)
                  <tr>
                    <td class="perm-key">{{ $batch->batch_no }}</td>
                    <td>{{ $batch->supplier ?? '—' }}</td>
                    <td>{{ \App\Support\Wat::date($batch->received_on) }}</td>
                    <td>
                      {{ \App\Support\Wat::date($batch->expiry_on) }}
                      @if ($batch->expiry_on && $batch->daysToExpiry() !== null && $batch->daysToExpiry() < 30)
                        <div class="cell-sub text-danger">{{ $batch->daysToExpiry() }} days</div>
                      @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim((string) $batch->quantity_received, '0'), '.') }}</td>
                    <td class="num font-bold">{{ rtrim(rtrim((string) $batch->quantity_remaining, '0'), '.') }}</td>
                    @if ($seesRevenue)
                      <td class="num">{{ \App\Support\Money::format($batch->unit_cost_minor) }}</td>
                    @endif
                    <td><span class="badge {{ $batch->isExpired() ? 'danger' : ($batch->status === 'active' ? 'success' : 'muted') }}">
                      {{ $batch->isExpired() ? 'Expired' : ucfirst($batch->status) }}</span></td>
                  </tr>
                @empty
                  <tr><td colspan="{{ $seesRevenue ? 8 : 7 }}">
                    @include('partials.empty', ['title' => 'No batches received', 'icon' => '&#128230;'])
                  </td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Stock Movements</h3>
          <p>Every change is a row, with the balance it left behind</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>When</th><th>Type</th><th>Reference</th><th class="num">In</th>
                <th class="num">Out</th><th class="num">Balance</th><th>Reason</th></tr></thead>
              <tbody>
                @forelse ($movements as $movement)
                  <tr>
                    <td>{{ \App\Support\Wat::relative($movement->created_at) }}</td>
                    <td><span class="badge {{ [
                      'stock_in' => 'success', 'sale' => 'info',
                      'adjustment' => 'warning', 'return' => 'muted',
                    ][$movement->movement_type] ?? 'muted' }}">
                      {{ \Illuminate\Support\Str::headline($movement->movement_type) }}</span></td>
                    <td class="perm-key">{{ $movement->reference ?? '—' }}</td>
                    <td class="num">{{ (float) $movement->quantity_in > 0 ? rtrim(rtrim((string) $movement->quantity_in, '0'), '.') : '—' }}</td>
                    <td class="num">{{ (float) $movement->quantity_out > 0 ? rtrim(rtrim((string) $movement->quantity_out, '0'), '.') : '—' }}</td>
                    <td class="num font-bold">{{ rtrim(rtrim((string) $movement->balance_after, '0'), '.') }}</td>
                    <td>{{ $movement->reason?->name ?? '—' }}
                      @if ($movement->explanation)<div class="cell-sub">{{ $movement->explanation }}</div>@endif</td>
                  </tr>
                @empty
                  <tr><td colspan="7">@include('partials.empty', ['title' => 'No movements yet', 'icon' => '&#128202;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Category Behaviour</h3><p>Set on the category by an administrator</p></div></div>
        <div class="card-body">
          <div class="stack" style="gap:10px">
            <label class="check-label"><input type="checkbox" disabled @checked($product->category?->requires_prescription) />
              Requires a prescription reference</label>
            <label class="check-label"><input type="checkbox" disabled @checked($product->category?->track_expiry) />
              Tracks expiry</label>
            <label class="check-label"><input type="checkbox" disabled @checked($product->category?->allow_credit) />
              May be sold on credit</label>
            <label class="check-label"><input type="checkbox" disabled @checked($product->category?->requires_manager_approval) />
              Needs manager approval</label>
          </div>
          @if ($canAdjust)
            {{-- A price change had no route at all before this: the options were
                 a database edit or a duplicate SKU that splits the history. --}}
            <a href="#modal-edit-product" class="btn btn-primary btn-sm">Edit product</a>
          @endif
          @can('shop.categories.view')
            <div class="divider"></div>
            <a href="{{ route('shop.categories.index') }}" class="btn btn-ghost btn-sm">Manage categories</a>
          @endcan
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Detail</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">SKU</div><div class="meta-value mono">{{ $product->sku }}</div></div>
            <div class="meta-item"><div class="meta-label">Unit</div><div class="meta-value">{{ $product->unit ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Supplier</div><div class="meta-value">{{ $product->preferred_supplier ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Status</div><div class="meta-value">{{ ucfirst($product->status) }}</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if ($canReceive)
    <div id="modal-receive" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Receive Stock</h3><p>{{ $product->name }}</p></div>
          <a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('shop.products.stock', $product) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-receive" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-receive'])
            <div class="field mb-16"><label for="rs-batch">Batch number <span class="req">*</span></label>
              <input type="text" id="rs-batch" name="batch_no" required /></div>
            <div class="field mb-16"><label for="rs-supplier">Supplier</label>
              <input type="text" id="rs-supplier" name="supplier" value="{{ $product->preferred_supplier }}" /></div>
            <div class="field mb-16"><label for="rs-qty">Quantity received <span class="req">*</span></label>
              <input type="text" id="rs-qty" name="quantity_received" inputmode="decimal" required /></div>
            <div class="field mb-16"><label for="rs-received">Received on</label>
              <input type="date" id="rs-received" name="received_on" value="{{ \App\Support\Wat::today()->toDateString() }}" /></div>
            <div class="field mb-16">
              <label for="rs-expiry">Expiry date
                @if ($product->category?->track_expiry)<span class="req">*</span>@endif</label>
              <input type="date" id="rs-expiry" name="expiry_on" @required($product->category?->track_expiry) />
              @if ($product->category?->track_expiry)
                <div class="hint">The {{ $product->category->name }} category tracks expiry, so this is required.</div>
              @endif
            </div>
            @if ($seesRevenue)
              <div class="field"><label for="rs-cost">Unit cost (₦)</label>
                <input type="text" id="rs-cost" name="unit_cost" inputmode="decimal"
                       value="{{ \App\Support\Money::decimal($product->cost_price_minor) }}" /></div>
            @endif
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Receive</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canAdjust)
    <div id="modal-adjust" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Adjust Stock</h3><p>A reason and an explanation are both required</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('shop.products.adjust', $product) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-adjust" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-adjust'])
            <div class="field mb-16"><label for="as-delta">Change <span class="req">*</span></label>
              <input type="text" id="as-delta" name="delta" inputmode="decimal" placeholder="-5" required />
              <div class="hint">Negative reduces stock. Stock can never go below zero.</div></div>
            <div class="field mb-16"><label for="as-reason">Reason <span class="req">*</span></label>
              <select id="as-reason" name="reason_id" required>
                @foreach ($adjustmentReasons as $reason)
                  <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                @endforeach
              </select></div>
            <div class="field"><label for="as-explanation">Explanation <span class="req">*</span></label>
              <textarea id="as-explanation" name="explanation" rows="3" required></textarea>
              <div class="hint">This appears in the audit log.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Adjust stock</button>
          </div>
        </form>
      </div>
    </div>
  @endif
  @if ($canAdjust)
    <div id="modal-edit-product" class="modal">
      <div class="modal-card">
        <div class="modal-head">
          <h3>Edit {{ $product->name }}</h3>
          <p>The SKU and the quantity are not editable here — the SKU is what stock
             movements and sale lines are reconciled by, and the quantity has its own
             audited paths so a correction always carries a reason.</p>
        </div>
        <form method="POST" action="{{ route('shop.products.update', $product) }}">
          @csrf
          @method('PUT')
          <div class="modal-body">
            <div class="field">
              <label for="ep-name">Name</label>
              <input type="text" id="ep-name" name="name" required value="{{ old('name', $product->name) }}" />
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="ep-category">Category</label>
                <select id="ep-category" name="product_category_id" required>
                  @foreach ($categories as $category)
                    <option value="{{ $category->id }}"
                      @selected(old('product_category_id', $product->product_category_id) == $category->id)>
                      {{ $category->name }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div class="field">
                <label for="ep-unit">Unit</label>
                <input type="text" id="ep-unit" name="unit" value="{{ old('unit', $product->unit) }}" />
              </div>
              <div class="field">
                <label for="ep-status">Status</label>
                <select id="ep-status" name="status" required>
                  @foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $product->status) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="ep-cost">Cost price (₦)</label>
                {{-- ARCH-6 — naira in, kobo stored. --}}
                <input type="text" id="ep-cost" name="cost_price" inputmode="decimal"
                       value="{{ old('cost_price', number_format($product->cost_price_minor / 100, 2, '.', '')) }}" />
              </div>
              <div class="field">
                <label for="ep-price">Selling price (₦)</label>
                <input type="text" id="ep-price" name="selling_price" inputmode="decimal" required
                       value="{{ old('selling_price', number_format($product->selling_price_minor / 100, 2, '.', '')) }}" />
              </div>
              <div class="field">
                <label for="ep-reorder">Reorder level</label>
                <input type="text" id="ep-reorder" name="reorder_level" inputmode="numeric"
                       value="{{ old('reorder_level', $product->reorder_level) }}" />
              </div>
            </div>
            <div class="field">
              <label for="ep-supplier">Preferred supplier</label>
              <input type="text" id="ep-supplier" name="preferred_supplier"
                     value="{{ old('preferred_supplier', $product->preferred_supplier) }}" />
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