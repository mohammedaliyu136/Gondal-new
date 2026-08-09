@extends('layouts.app')
@section('title', 'One-Stop Shop')

@section('content')
  <div class="page-head">
    <div>
      <h1>One-Stop Shop &mdash; Inventory</h1>
      <p>{{ number_format($products->total()) }} products &middot; {{ number_format($lowStockCount) }} low on stock</p>
    </div>
    <div class="page-actions">
      @can('shop.categories.view')
        <a href="{{ route('shop.categories.index') }}" class="btn btn-outline">Categories</a>
      @endcan
      @can('shop.sales.view')
        <a href="{{ route('shop.sales.index') }}" class="btn btn-outline">Sales</a>
      @endcan
      @if ($canCreate)<a href="#modal-new-product" class="btn btn-primary">+ Add Product</a>@endif
    </div>
  </div>

  @unless ($seesRevenue)
    {{-- BR-29 / the Inventory Officer persona: quantities only. --}}
    <div class="alert info mb-16">
      <span>&#128274;</span>
      <div>
        <strong>Quantities only.</strong>
        Cost prices, stock values and margins are not shown to your role.
      </div>
    </div>
  @endunless

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Products</div>
      <div class="stat-value">{{ number_format($products->total()) }}</div>
      <div class="stat-foot">across {{ $categories->count() }} live categories</div></div>
    <div class="stat amber"><div class="stat-label">Low stock</div>
      <div class="stat-value">{{ number_format($lowStockCount) }}</div>
      <div class="stat-foot">at or below reorder level</div></div>
    @if ($seesRevenue)
      <div class="stat green"><div class="stat-label">Stock value at cost</div>
        <div class="stat-value">{{ \App\Support\Money::compact($stockValueMinor) }}</div>
        <div class="stat-foot">across all products</div></div>
    @else
      <div class="stat"><div class="stat-label">Stock value</div>
        <div class="stat-value">&mdash;</div>
        <div class="stat-foot">not shown to your role</div></div>
    @endif
    <div class="stat"><div class="stat-label">Categories</div>
      <div class="stat-value">{{ $categories->count() }}</div>
      <div class="stat-foot">currently sellable</div></div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Stock</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or SKU" /></div>
        <div class="field"><label for="category">Category</label>
          <select id="category" name="category">
            <option value="">All</option>
            @foreach ($categories as $category)
              <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label class="check-label" for="low_stock">
          <input type="checkbox" id="low_stock" name="low_stock" value="1" @checked(request()->boolean('low_stock')) />
          Low stock only</label></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('shop.inventory') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Product</th><th>Category</th><th class="num">On hand</th><th class="num">Reorder at</th>
            <th class="num">Selling price</th>
            @if ($seesRevenue)<th class="num">Cost</th><th class="num">Stock value</th>@endif
            <th>Flags</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($products as $product)
              <tr>
                <td><div class="font-bold">{{ $product->name }}</div><div class="cell-sub perm-key">{{ $product->sku }}</div></td>
                <td>{{ $product->category?->name }}</td>
                <td class="num {{ $product->isLowStock() ? 'text-danger font-bold' : 'font-bold' }}">
                  {{ rtrim(rtrim((string) $product->quantity_on_hand, '0'), '.') }} {{ $product->unit }}
                </td>
                <td class="num">{{ $product->reorder_level ?? '—' }}</td>
                <td class="num">{{ \App\Support\Money::format($product->selling_price_minor) }}</td>
                @if ($seesRevenue)
                  <td class="num">{{ \App\Support\Money::format($product->cost_price_minor) }}</td>
                  <td class="num">{{ \App\Support\Money::format($product->stockValueMinor()) }}</td>
                @endif
                <td>
                  @if ($product->category?->requires_prescription)<span class="badge danger plain">Rx</span>@endif
                  @if ($product->category?->track_expiry)<span class="badge warning plain">expiry</span>@endif
                  @if ($product->category?->allow_credit)<span class="badge info plain">credit</span>@endif
                  @if ($product->isLowStock())<span class="badge danger">low</span>@endif
                </td>
                <td class="actions"><a href="{{ route('shop.products.show', $product) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="{{ $seesRevenue ? 9 : 7 }}">
                @include('partials.empty', ['title' => 'No products for this filter', 'icon' => '&#128230;'])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $products, 'noun' => 'products'])
  </div>

  @if ($canCreate)
    <div id="modal-new-product" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add Product</h3><p>Unit and reorder level default from the category</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('shop.products.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-product" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-product'])
            <div class="form-grid">
              <div class="field"><label for="np-sku">SKU <span class="req">*</span></label>
                <input type="text" id="np-sku" name="sku" required /></div>
              <div class="field"><label for="np-name">Name <span class="req">*</span></label>
                <input type="text" id="np-name" name="name" required /></div>
              <div class="field"><label for="np-category">Category <span class="req">*</span></label>
                <select id="np-category" name="product_category_id" required>
                  @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                  @endforeach
                </select>
                <div class="hint">Ask an administrator to add a category if the one you need is missing.</div></div>
              <div class="field"><label for="np-unit">Unit</label>
                <input type="text" id="np-unit" name="unit" placeholder="from category" /></div>
              <div class="field"><label for="np-selling">Selling price (₦) <span class="req">*</span></label>
                <input type="text" id="np-selling" name="selling_price" inputmode="decimal" required /></div>
              @if ($seesRevenue)
                <div class="field"><label for="np-cost">Cost price (₦)</label>
                  <input type="text" id="np-cost" name="cost_price" inputmode="decimal" /></div>
              @endif
              <div class="field"><label for="np-reorder">Reorder level</label>
                <input type="number" id="np-reorder" name="reorder_level" min="0" placeholder="from category" /></div>
              <div class="field"><label for="np-supplier">Preferred supplier</label>
                <input type="text" id="np-supplier" name="preferred_supplier" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Add product</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
