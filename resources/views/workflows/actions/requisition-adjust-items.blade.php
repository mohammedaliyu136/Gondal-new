<div class="card mb-16" style="border: 1px solid var(--primary-border, #bfdbfe); background: #f8fafc;">
  <div class="card-head" style="background: #eff6ff; padding: 10px 16px;">
    <div>
      @php($actionHandler = $stage->stageActionHandler())
      <h4 style="margin:0; font-size:0.95rem; color: #1e40af;">
        <span style="margin-right:6px;">&#9998;</span> Stage Action: {{ $actionHandler?->label() ?? 'Review & Adjust Line Items' }}
      </h4>
      <p style="margin:0; font-size:0.8rem; color:#475569;">
        {{ $actionHandler?->description() ?? 'Accept or reject requested line items, adjust quantities, and modify estimated unit prices before approving.' }}
      </p>
    </div>
  </div>
  <div class="card-body flush">
    <div class="table-wrap">
      <table class="table" id="req-action-table-{{ $instance->id }}" style="margin-bottom:0; font-size:0.875rem;">
        <thead>
          <tr style="background:#f1f5f9;">
            <th style="width:16%;">Decision <span class="req">*</span></th>
            <th style="width:34%;">Requested Item &amp; Purpose</th>
            <th style="width:14%;" class="num">Quantity <span class="req">*</span></th>
            <th style="width:10%;">Unit</th>
            <th style="width:14%;" class="num">Est. Unit Price (₦) <span class="req">*</span></th>
            <th style="width:12%;" class="num">Subtotal (₦)</th>
          </tr>
        </thead>
        <tbody id="req-action-tbody-{{ $instance->id }}">
          @forelse ($items as $idx => $item)
            <tr class="req-item-row" data-row-id="{{ $idx }}">
              <td style="vertical-align:top;">
                <select name="stage_action_items[{{ $idx }}][status]"
                        class="form-control form-control-sm req-status"
                        onchange="toggleRequisitionRowStatus(this, '{{ $instance->id }}')"
                        style="width:100%; font-size:0.82rem; font-weight:600; padding:4px 6px;">
                  <option value="accept" selected style="color:var(--primary-dark, #0b7d54); font-weight:bold;">&#10003; Accept</option>
                  <option value="reject" style="color:var(--danger, #dc2626); font-weight:bold;">&#10007; Reject</option>
                </select>
              </td>
              <td style="vertical-align:top;">
                <div class="req-item-name" style="font-weight:600; color:var(--text, #1e293b);">{{ $item->item }}</div>
                @if ($item->purpose)
                  <div class="req-item-purpose" style="font-size:0.75rem; color:var(--muted, #64748b);">{{ $item->purpose }}</div>
                @endif
                <input type="hidden" name="stage_action_items[{{ $idx }}][item]" value="{{ $item->item }}" />
                <input type="hidden" name="stage_action_items[{{ $idx }}][purpose]" value="{{ $item->purpose }}" />
                <input type="hidden" name="stage_action_items[{{ $idx }}][unit]" value="{{ $item->unit }}" />
              </td>
              <td style="vertical-align:top;">
                <input type="number" step="any" min="0.01" name="stage_action_items[{{ $idx }}][quantity]"
                       value="{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}"
                       class="form-control form-control-sm req-qty text-right" required
                       oninput="recalcRequisitionActionTotal('{{ $instance->id }}')"
                       style="width:100%; font-size:0.85rem; padding:4px 8px; text-align:right;" />
              </td>
              <td style="vertical-align:top;">
                <span class="badge plain" style="font-size:0.8rem;">{{ $item->unit ?: '—' }}</span>
              </td>
              <td style="vertical-align:top;">
                <input type="number" step="any" min="0" name="stage_action_items[{{ $idx }}][unit_price]"
                       value="{{ number_format(($item->unit_price_minor ?? 0) / 100, 2, '.', '') }}"
                       class="form-control form-control-sm req-price text-right" required
                       oninput="recalcRequisitionActionTotal('{{ $instance->id }}')"
                       style="width:100%; font-size:0.85rem; padding:4px 8px; text-align:right;" />
              </td>
              <td class="num font-bold req-row-total" style="vertical-align:middle; text-align:right;">
                {{ \App\Support\Money::format($item->amount_minor) }}
              </td>
            </tr>
          @empty
            <tr class="req-item-row" data-row-id="0">
              <td colspan="6" class="text-muted" style="text-align:center; padding:16px;">
                No line items found on this requisition.
              </td>
            </tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr style="background:#f8fafc; font-weight:bold;">
            <td colspan="5" style="text-align:right; padding-right:12px;">Total Accepted Requisition Amount:</td>
            <td class="num font-bold" id="req-action-total-{{ $instance->id }}" style="text-align:right; color:#1e40af; font-size:0.95rem;">
              {{ \App\Support\Money::format($requisition->total_minor) }}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<script>
  window.toggleRequisitionRowStatus = function(selectEl, instanceId) {
    const row = selectEl.closest('tr.req-item-row');
    if (!row) return;

    const isRejected = selectEl.value === 'reject';
    const qtyInput = row.querySelector('.req-qty');
    const priceInput = row.querySelector('.req-price');
    const nameEl = row.querySelector('.req-item-name');

    if (isRejected) {
      row.style.background = '#fef2f2';
      row.style.opacity = '0.7';
      if (nameEl) nameEl.style.textDecoration = 'line-through';
      if (qtyInput) qtyInput.setAttribute('readonly', 'readonly');
      if (priceInput) priceInput.setAttribute('readonly', 'readonly');
    } else {
      row.style.background = '';
      row.style.opacity = '1';
      if (nameEl) nameEl.style.textDecoration = 'none';
      if (qtyInput) qtyInput.removeAttribute('readonly');
      if (priceInput) priceInput.removeAttribute('readonly');
    }

    recalcRequisitionActionTotal(instanceId);
  };

  window.recalcRequisitionActionTotal = function(instanceId) {
    const tbody = document.getElementById('req-action-tbody-' + instanceId);
    if (!tbody) return;

    let grandTotal = 0;
    const rows = tbody.querySelectorAll('tr.req-item-row');
    rows.forEach(row => {
      const statusSelect = row.querySelector('.req-status');
      const qtyInput = row.querySelector('.req-qty');
      const priceInput = row.querySelector('.req-price');
      const rowTotalEl = row.querySelector('.req-row-total');

      const isAccepted = !statusSelect || statusSelect.value === 'accept';

      if (isAccepted) {
        const qty = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
        const price = parseFloat(priceInput ? priceInput.value : 0) || 0;
        const subtotal = qty * price;
        grandTotal += subtotal;

        if (rowTotalEl) {
          rowTotalEl.textContent = '₦' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          rowTotalEl.style.color = '';
        }
      } else {
        if (rowTotalEl) {
          rowTotalEl.textContent = 'Rejected';
          rowTotalEl.style.color = 'var(--danger, #dc2626)';
        }
      }
    });

    const totalEl = document.getElementById('req-action-total-' + instanceId);
    if (totalEl) {
      totalEl.textContent = '₦' + grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    const amountInput = document.getElementById('ap-' + instanceId + '-amount');
    if (amountInput) {
      amountInput.value = grandTotal.toFixed(2);
    }
  };
</script>
