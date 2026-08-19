@extends('layouts.app')
@section('title', 'Requisitions')

@section('content')
  <div class="page-head">
    <div>
      <h1>Requisitions</h1>
      <p>{{ number_format($requisitions->total()) }} in your scope &middot; {{ number_format($awaitingCount) }} in review</p>
    </div>
    <div class="page-actions">
      @can('purchase.approve.*')
        <a href="{{ route('approvals.index') }}" class="btn btn-outline">My Approvals</a>
      @endcan
      @if ($canCreate)
        <a href="#modal-new-req" class="btn btn-primary">+ New Requisition</a>
      @endif
    </div>
  </div>

  @if ($workflow)
    {{-- BR-19 — show where a total will route before anyone submits. --}}
    <div class="alert info mb-16">
      <span>&#8505;&#65039;</span>
      <div>
        <strong>Routing is by amount band.</strong>
        @foreach ($workflow->bands as $band)
          {{ $band->name }}: {{ $band->describeRange() }} &rarr;
          {{ $band->stages->pluck('name')->implode(' &rarr; ') }}@if (! $loop->last);@endif
        @endforeach
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>All Requisitions</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Reference or title" /></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['draft', 'in_review', 'approved', 'rejected', 'cancelled'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="department">Department</label>
          <select id="department" name="department">
            <option value="">All</option>
            @foreach ($departments as $department)
              <option value="{{ $department->id }}" @selected(request('department') == $department->id)>{{ $department->name }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('requisitions.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Reference</th><th>Item</th><th>Requester</th><th>Department</th>
            <th class="num">Amount</th><th>Stage</th><th>Urgency</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($requisitions as $requisition)
              <tr>
                <td><a href="{{ route('requisitions.show', $requisition) }}" class="perm-key">{{ $requisition->reference }}</a>
                  @if ($requisition->revises_requisition_id)<div class="cell-sub">revision</div>@endif</td>
                <td>{{ $requisition->title }}
                  @if ($requisition->category)<div class="cell-sub">{{ $requisition->category }}</div>@endif</td>
                <td>{{ $requisition->requester?->name }}</td>
                <td>{{ $requisition->department?->name ?? '—' }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($requisition->total_minor) }}
                  @if ($requisition->approved_total_minor !== null && $requisition->approved_total_minor !== $requisition->total_minor)
                    <div class="cell-sub">approved {{ \App\Support\Money::format($requisition->approved_total_minor) }}</div>
                  @endif</td>
                <td>
                  @if ($requisition->workflowInstance?->currentStage)
                    {{ $requisition->workflowInstance->currentStage->name }}
                    <div class="cell-sub">{{ $requisition->workflowInstance->stageNumber() }} of {{ $requisition->workflowInstance->stageCount() }}</div>
                  @else
                    &mdash;
                  @endif
                </td>
                <td><span class="badge {{ ['low' => 'muted', 'normal' => 'info', 'high' => 'danger'][$requisition->urgency] ?? 'muted' }}">
                  {{ ucfirst($requisition->urgency) }}</span></td>
                <td><span class="badge {{ [
                  'draft' => 'muted', 'in_review' => 'warning', 'approved' => 'success',
                  'rejected' => 'danger', 'cancelled' => 'muted',
                ][$requisition->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($requisition->status) }}</span></td>
                <td class="actions"><a href="{{ route('requisitions.show', $requisition) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', ['title' => 'No requisitions in your scope', 'icon' => '&#128221;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $requisitions, 'noun' => 'requisitions'])
  </div>

  @if ($canCreate)
    <div id="modal-new-req" class="modal @if (old('_modal') === 'modal-new-req') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <div><h3>New Requisition</h3><p>The total decides which approval route it follows</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('requisitions.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-req" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-req'])
            <div class="form-grid">
              <div class="field full"><label for="nr-title">Title <span class="req">*</span></label>
                <input type="text" id="nr-title" name="title" value="{{ old('title') }}" placeholder="e.g. Laboratory reagents / Office supplies" required /></div>
              <div class="field"><label for="nr-department">Department</label>
                <select id="nr-department" name="department_id">
                  <option value="">My department</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="nr-category">Category</label>
                <input type="text" id="nr-category" name="category" value="{{ old('category') }}" placeholder="e.g. stationery, consumables" /></div>
              <div class="field"><label for="nr-urgency">Urgency <span class="req">*</span></label>
                <select id="nr-urgency" name="urgency" required>
                  <option value="low" @selected(old('urgency') === 'low')>Low</option>
                  <option value="normal" @selected(old('urgency', 'normal') === 'normal')>Normal</option>
                  <option value="high" @selected(old('urgency') === 'high')>High</option>
                </select></div>
              <div class="field"><label for="nr-needed">Needed by</label>
                <input type="date" id="nr-needed" name="needed_by" value="{{ old('needed_by') }}" /></div>
              <div class="field full"><label for="nr-vendor">Suggested vendor</label>
                <input type="text" id="nr-vendor" name="suggested_vendor" value="{{ old('suggested_vendor') }}" placeholder="Optional vendor name" />
                <div class="hint">Type the vendor name if known.</div></div>
            </div>

            <div class="divider"></div>
            <div class="flex-between mb-16">
              <div>
                <h3 style="margin:0;">Line items</h3>
                <p class="text-small text-muted" style="margin:0;">Add items needed for this requisition.</p>
              </div>
              <button type="button" class="btn btn-sm btn-outline" onclick="addRequisitionCreateRow()">
                + Add Line Item
              </button>
            </div>

            <div class="table-wrap mb-16">
              <table class="table" style="margin-bottom:0; font-size:0.875rem;">
                <thead>
                  <tr style="background:#f8fafc;">
                    <th style="width:32%;">Item Description <span class="req">*</span></th>
                    <th style="width:20%;">Purpose</th>
                    <th style="width:12%;" class="num">Qty <span class="req">*</span></th>
                    <th style="width:12%;">Unit</th>
                    <th style="width:14%;" class="num">Unit Price (₦) <span class="req">*</span></th>
                    <th style="width:10%;" class="num">Subtotal (₦)</th>
                    <th style="width:5%; text-align:center;">&times;</th>
                  </tr>
                </thead>
                <tbody id="req-create-tbody">
                  <tr class="req-create-row" data-row-id="0">
                    <td>
                      <input type="text" name="items[0][item]" placeholder="Item name" required
                             class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
                    </td>
                    <td>
                      <input type="text" name="items[0][purpose]" placeholder="Purpose / Note"
                             class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
                    </td>
                    <td>
                      <input type="number" step="any" min="0.01" name="items[0][quantity]" value="1" required
                             class="form-control form-control-sm req-create-qty" oninput="recalcRequisitionCreateTotal()"
                             style="width:100%; font-size:0.85rem; padding:6px 8px; text-align:right;" />
                    </td>
                    <td>
                      <input type="text" name="items[0][unit]" placeholder="pcs, kg, box"
                             class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
                    </td>
                    <td>
                      <input type="number" step="any" min="0" name="items[0][unit_price]" value="0.00" required
                             class="form-control form-control-sm req-create-price" oninput="recalcRequisitionCreateTotal()"
                             style="width:100%; font-size:0.85rem; padding:6px 8px; text-align:right;" />
                    </td>
                    <td class="num font-bold req-create-subtotal" style="vertical-align:middle; text-align:right;">
                      ₦0.00
                    </td>
                    <td style="text-align:center; vertical-align:middle;">
                      <button type="button" class="btn btn-ghost btn-xs text-danger" title="Remove row"
                              onclick="removeRequisitionCreateRow(this)" style="color:var(--danger); padding:2px 6px;">
                        &times;
                      </button>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr style="background:#f8fafc; font-weight:bold;">
                    <td colspan="5" style="text-align:right; padding-right:12px;">Total Estimated Requisition Amount:</td>
                    <td class="num font-bold" id="req-create-grand-total" style="text-align:right; color:var(--primary-dark, #0b7d54); font-size:1rem;">
                      ₦0.00
                    </td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <div class="hint">Prices are in Naira. Total calculates in real time as quantities and unit prices are typed.</div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-outline">Save draft</button>
            <button type="submit" name="submit" value="1" class="btn btn-primary">Save and submit</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      window.recalcRequisitionCreateTotal = function() {
        const tbody = document.getElementById('req-create-tbody');
        if (!tbody) return;

        let grandTotal = 0;
        const rows = tbody.querySelectorAll('tr.req-create-row');
        rows.forEach(row => {
          const qtyInput = row.querySelector('.req-create-qty');
          const priceInput = row.querySelector('.req-create-price');
          const subtotalEl = row.querySelector('.req-create-subtotal');

          const qty = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
          const price = parseFloat(priceInput ? priceInput.value : 0) || 0;
          const subtotal = qty * price;
          grandTotal += subtotal;

          if (subtotalEl) {
            subtotalEl.textContent = '₦' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }
        });

        const totalEl = document.getElementById('req-create-grand-total');
        if (totalEl) {
          totalEl.textContent = '₦' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
      };

      window.addRequisitionCreateRow = function() {
        const tbody = document.getElementById('req-create-tbody');
        if (!tbody) return;

        const nextIndex = tbody.querySelectorAll('tr.req-create-row').length + Date.now();
        const tr = document.createElement('tr');
        tr.className = 'req-create-row';
        tr.setAttribute('data-row-id', nextIndex);
        tr.innerHTML = `
          <td>
            <input type="text" name="items[${nextIndex}][item]" placeholder="Item name" required
                   class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
          </td>
          <td>
            <input type="text" name="items[${nextIndex}][purpose]" placeholder="Purpose / Note"
                   class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
          </td>
          <td>
            <input type="number" step="any" min="0.01" name="items[${nextIndex}][quantity]" value="1" required
                   class="form-control form-control-sm req-create-qty" oninput="recalcRequisitionCreateTotal()"
                   style="width:100%; font-size:0.85rem; padding:6px 8px; text-align:right;" />
          </td>
          <td>
            <input type="text" name="items[${nextIndex}][unit]" placeholder="pcs, kg, box"
                   class="form-control form-control-sm" style="width:100%; font-size:0.85rem; padding:6px 8px;" />
          </td>
          <td>
            <input type="number" step="any" min="0" name="items[${nextIndex}][unit_price]" value="0.00" required
                   class="form-control form-control-sm req-create-price" oninput="recalcRequisitionCreateTotal()"
                   style="width:100%; font-size:0.85rem; padding:6px 8px; text-align:right;" />
          </td>
          <td class="num font-bold req-create-subtotal" style="vertical-align:middle; text-align:right;">
            ₦0.00
          </td>
          <td style="text-align:center; vertical-align:middle;">
            <button type="button" class="btn btn-ghost btn-xs text-danger" title="Remove row"
                    onclick="removeRequisitionCreateRow(this)" style="color:var(--danger); padding:2px 6px;">
              &times;
            </button>
          </td>
        `;
        tbody.appendChild(tr);
        recalcRequisitionCreateTotal();
      };

      window.removeRequisitionCreateRow = function(btn) {
        const tbody = document.getElementById('req-create-tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr.req-create-row');
        if (rows.length <= 1) {
          alert('A requisition requires at least one line item.');
          return;
        }

        const tr = btn.closest('tr');
        if (tr) {
          tr.remove();
          recalcRequisitionCreateTotal();
        }
      };
    </script>
  @endif
@endsection
