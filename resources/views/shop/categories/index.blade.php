@extends('layouts.app')
@section('title', 'Product Categories')

@section('content')
  <div class="page-head">
    <div>
      <h1>Product Categories</h1>
      <p>A category created here is sellable immediately</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('shop.inventory') }}" class="btn btn-outline">Inventory</a>
      @if ($canCreate)<a href="#modal-new-cat" class="btn btn-primary">+ Add Category</a>@endif
    </div>
  </div>

  <div class="alert success mb-16">
    <span>&#9989;</span>
    <div>
      <strong>Retiring a category hides it from new sales and keeps its history.</strong>
      Categories are never deleted, and a retired one can be reinstated.
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Categories</h3></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Category</th><th>Default unit</th><th class="num">Reorder</th>
            <th class="perm-check">Rx</th><th class="perm-check">Expiry</th>
            <th class="perm-check">Credit</th><th class="perm-check">Approval</th>
            <th class="num">Products</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($categories as $category)
              <tr>
                <td><div class="font-bold">{{ $category->name }}</div>
                  <div class="cell-sub perm-key">{{ $category->code }}</div>
                  @if ($category->description)<div class="cell-sub">{{ $category->description }}</div>@endif</td>
                <td>{{ $category->default_unit ?? '—' }}</td>
                <td class="num">{{ $category->default_reorder_level ?? '—' }}</td>
                <td class="perm-check"><input type="checkbox" disabled @checked($category->requires_prescription) /></td>
                <td class="perm-check"><input type="checkbox" disabled @checked($category->track_expiry) /></td>
                <td class="perm-check"><input type="checkbox" disabled @checked($category->allow_credit) /></td>
                <td class="perm-check"><input type="checkbox" disabled @checked($category->requires_manager_approval) /></td>
                <td class="num">{{ $category->products_count }}</td>
                <td><span class="badge {{ $category->isRetired() ? 'muted' : 'success' }}">
                  {{ $category->isRetired() ? 'Retired' : 'Sellable' }}</span>
                  @if ($category->retired_at)
                    <div class="cell-sub">{{ \App\Support\Wat::date($category->retired_at) }}</div>
                  @endif</td>
                <td class="actions">
                  @if ($canEdit)
                    <a href="#modal-edit-{{ $category->id }}" class="btn btn-ghost btn-sm">Edit</a>
                  @endif
                  @if ($canRetire)
                    <form method="POST" action="{{ route('shop.categories.retire', $category) }}" style="display:inline">
                      @csrf
                      <button type="submit" class="btn btn-ghost btn-sm {{ $category->isRetired() ? '' : 'text-danger' }}">
                        {{ $category->isRetired() ? 'Reinstate' : 'Retire' }}
                      </button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="10">@include('partials.empty', [
                'title' => 'No categories yet',
                'message' => 'Create one and it is immediately available to new products and sales.',
                'icon' => '&#127991;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $categories, 'noun' => 'categories'])
  </div>

  @if ($canCreate)
    <div id="modal-new-cat" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Add Category</h3><p>The flags below control how products in this category behave</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('shop.categories.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-cat" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-cat'])
            <div class="form-grid">
              <div class="field"><label for="nc-code">Code <span class="req">*</span></label>
                <input type="text" id="nc-code" name="code" required /></div>
              <div class="field"><label for="nc-name">Name <span class="req">*</span></label>
                <input type="text" id="nc-name" name="name" required /></div>
              <div class="field"><label for="nc-unit">Default unit</label>
                <input type="text" id="nc-unit" name="default_unit" placeholder="bag, litre, dose" /></div>
              <div class="field"><label for="nc-reorder">Default reorder level</label>
                <input type="number" id="nc-reorder" name="default_reorder_level" min="0" /></div>
              <div class="field full"><label for="nc-description">Description</label>
                <textarea id="nc-description" name="description" rows="2"></textarea></div>
              <div class="field full">
                <label>Behaviour</label>
                <div class="stack" style="gap:10px;margin-top:6px">
                  <label class="check-label"><input type="checkbox" name="requires_prescription" value="1" />
                    Requires a prescription reference on every sale</label>
                  <label class="check-label"><input type="checkbox" name="track_expiry" value="1" />
                    Track expiry dates and rotate stock oldest first</label>
                  <label class="check-label"><input type="checkbox" name="allow_credit" value="1" />
                    May be sold on credit to a cooperative</label>
                  <label class="check-label"><input type="checkbox" name="requires_manager_approval" value="1" />
                    Needs manager approval</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create category</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canEdit)
    @foreach ($categories as $category)
      <div id="modal-edit-{{ $category->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head"><div><h3>Edit {{ $category->name }}</h3><p>{{ $category->code }}</p></div>
            <a href="#" class="modal-close">&times;</a></div>
          <form method="POST" action="{{ route('shop.categories.update', $category) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-edit-{{ $category->id }}" /> @method('PUT')
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-'.$category->id.''])
              <div class="form-grid">
                <div class="field"><label for="ec-{{ $category->id }}-name">Name <span class="req">*</span></label>
                  <input type="text" id="ec-{{ $category->id }}-name" name="name" value="{{ $category->name }}" required /></div>
                <div class="field"><label for="ec-{{ $category->id }}-unit">Default unit</label>
                  <input type="text" id="ec-{{ $category->id }}-unit" name="default_unit" value="{{ $category->default_unit }}" /></div>
                <div class="field"><label for="ec-{{ $category->id }}-reorder">Default reorder level</label>
                  <input type="number" id="ec-{{ $category->id }}-reorder" name="default_reorder_level"
                         value="{{ $category->default_reorder_level }}" min="0" /></div>
                <div class="field full"><label for="ec-{{ $category->id }}-desc">Description</label>
                  <textarea id="ec-{{ $category->id }}-desc" name="description" rows="2">{{ $category->description }}</textarea></div>
                <div class="field full">
                  <label>Behaviour</label>
                  <div class="stack" style="gap:10px;margin-top:6px">
                    <label class="check-label"><input type="checkbox" name="requires_prescription" value="1"
                      @checked($category->requires_prescription) /> Requires a prescription reference</label>
                    <label class="check-label"><input type="checkbox" name="track_expiry" value="1"
                      @checked($category->track_expiry) /> Track expiry dates</label>
                    <label class="check-label"><input type="checkbox" name="allow_credit" value="1"
                      @checked($category->allow_credit) /> May be sold on credit</label>
                    <label class="check-label"><input type="checkbox" name="requires_manager_approval" value="1"
                      @checked($category->requires_manager_approval) /> Needs manager approval</label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save category</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif
@endsection
