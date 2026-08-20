<div class="card mb-16" style="border: 1px solid var(--primary-border, #bfdbfe); background: #f8fafc;">
  <div class="card-head" style="background: #eff6ff; padding: 10px 16px;">
    <div>
      @php($actionHandler = $stage->stageActionHandler())
      <h4 style="margin:0; font-size:0.95rem; color: #1e40af;">
        <span style="margin-right:6px;">&#127970;</span> Stage Action: {{ $actionHandler?->label() ?? 'Assign Service Provider & Bank Details' }}
      </h4>
      <p style="margin:0; font-size:0.8rem; color:#475569;">
        {{ $actionHandler?->description() ?? 'Select or assign the authorized service provider/vendor and review disbursement bank account details.' }}
      </p>
    </div>
  </div>
  <div class="card-body" style="padding: 16px;">
    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
      <div class="field" style="grid-column: span 2;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
          <label for="sp-select-{{ $instance->id }}" style="margin:0; font-weight:700;">
            Select Service Provider / Vendor
          </label>
          <a href="{{ route('service-providers.index') }}" target="_blank" style="font-size:0.75rem; color:#1e40af; text-decoration:none; font-weight:600;">
            + Register New Provider &nearr;
          </a>
        </div>
        <select id="sp-select-{{ $instance->id }}" name="service_provider_id" class="form-control"
                data-searchable data-combo-placeholder="Search service providers…"
                onchange="onServiceProviderSelected(this, '{{ $instance->id }}')"
                style="width:100%; font-weight:600; font-size:0.9rem;">
          <option value="">-- Choose Registered Service Provider --</option>
          @foreach ($serviceProviders as $sp)
            <option value="{{ $sp->id }}"
                    data-bank="{{ $sp->bank_name }}"
                    data-account="{{ $sp->bank_account }}"
                    data-name="{{ $sp->account_name ?: $sp->name }}"
                    data-contact="{{ $sp->contact ?: $sp->email }}"
                    data-address="{{ $sp->billing_address ?: $sp->billing_city }}"
                    {{ ($requisition->service_provider_id === $sp->id || ($requisition->suggested_vendor && strtolower(trim($requisition->suggested_vendor)) === strtolower(trim($sp->name)))) ? 'selected' : '' }}>
              {{ $sp->name }} @if ($sp->bank_account) ({{ $sp->bank_name }} - {{ $sp->bank_account }}) @endif
            </option>
          @endforeach
        </select>
        @if ($requisition->suggested_vendor && ! $requisition->service_provider_id)
          <div class="hint mt-1" style="font-size:0.75rem; color:#64748b;">
            Requester suggested: <strong>{{ $requisition->suggested_vendor }}</strong>
          </div>
        @endif
      </div>

      {{-- Bank & Account Preview Box --}}
      <div id="sp-preview-box-{{ $instance->id }}" style="grid-column: span 2; display:none; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:12px 16px; margin-top:2px;">
        <div style="font-size:0.8rem; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; display:flex; justify-content:space-between;">
          <span>Disbursement Account Verification</span>
          <span class="badge success plain" style="font-size:0.7rem;">Verified Provider</span>
        </div>
        <div class="grid grid-3" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; font-size:0.85rem;">
          <div>
            <div class="text-muted" style="font-size:0.75rem;">Bank Name</div>
            <div id="sp-bank-name-{{ $instance->id }}" style="font-weight:700; color:#0f172a;">—</div>
          </div>
          <div>
            <div class="text-muted" style="font-size:0.75rem;">Account Number</div>
            <div id="sp-bank-account-{{ $instance->id }}" style="font-family:monospace; font-weight:700; color:#0b7d54; font-size:0.95rem;">—</div>
          </div>
          <div>
            <div class="text-muted" style="font-size:0.75rem;">Account Beneficiary</div>
            <div id="sp-account-name-{{ $instance->id }}" style="font-weight:700; color:#0f172a;">—</div>
          </div>
        </div>
        <div id="sp-extra-row-{{ $instance->id }}" style="margin-top:8px; padding-top:6px; border-top:1px dashed #e2e8f0; font-size:0.75rem; color:#64748b; display:flex; justify-content:space-between;">
          <span id="sp-contact-{{ $instance->id }}"></span>
          <span id="sp-address-{{ $instance->id }}"></span>
        </div>
      </div>

      <div class="field" style="grid-column: span 2;">
        <label for="sp-notes-{{ $instance->id }}">Payment / Disbursement Reference Notes (Optional)</label>
        <input type="text" id="sp-notes-{{ $instance->id }}" name="account_notes" class="form-control"
               placeholder="e.g. Approved for direct bank transfer via Treasury" />
      </div>
    </div>
  </div>
</div>

<script>
  window.onServiceProviderSelected = function(selectEl, instanceId) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const previewBox = document.getElementById('sp-preview-box-' + instanceId);
    if (!previewBox) return;

    if (!selectedOption || !selectedOption.value) {
      previewBox.style.display = 'none';
      return;
    }

    const bank = selectedOption.getAttribute('data-bank') || '—';
    const account = selectedOption.getAttribute('data-account') || '—';
    const name = selectedOption.getAttribute('data-name') || '—';
    const contact = selectedOption.getAttribute('data-contact') || '';
    const address = selectedOption.getAttribute('data-address') || '';

    document.getElementById('sp-bank-name-' + instanceId).textContent = bank;
    document.getElementById('sp-bank-account-' + instanceId).textContent = account;
    document.getElementById('sp-account-name-' + instanceId).textContent = name;

    const contactEl = document.getElementById('sp-contact-' + instanceId);
    if (contactEl) contactEl.textContent = contact ? 'Contact: ' + contact : '';

    const addressEl = document.getElementById('sp-address-' + instanceId);
    if (addressEl) addressEl.textContent = address ? 'Location: ' + address : '';

    previewBox.style.display = 'block';
  };

  // Initial trigger if a service provider is pre-selected
  (function() {
    const select = document.getElementById('sp-select-{{ $instance->id }}');
    if (select && select.value) {
      onServiceProviderSelected(select, '{{ $instance->id }}');
    }
  })();
</script>
