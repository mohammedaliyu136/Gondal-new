@extends('layouts.app')
@section('title', 'Service Providers & Vendors')

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('requisitions.index') }}">Purchases</a><span class="sep">/</span>
    <span class="here">Service Providers</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg" style="background:#f1f5f9; color:#1e40af; border:1px solid #bfdbfe;">
      &#127970;
    </div>
    <div class="dh-main">
      <h1>Service Providers &amp; Vendors</h1>
      <div class="dh-sub">
        Manage registered suppliers, contractors, and payment disbursement accounts
      </div>
      <div class="dh-tags">
        <span class="pill">{{ $stats['total'] }} Total</span>
        <span class="badge success">{{ $stats['active'] }} Active</span>
        @if ($stats['inactive'] > 0)
          <span class="badge muted">{{ $stats['inactive'] }} Inactive</span>
        @endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($canCreate)
        <a href="#modal-create-provider" class="btn btn-primary">
          + Add Service Provider
        </a>
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#10003;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if (session('error'))
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>{{ session('error') }}</div>
    </div>
  @endif

  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3>Filter &amp; Search</h3>
      </div>
    </div>
    <div class="card-body">
      <form method="GET" action="{{ route('service-providers.index') }}" class="table-tools">
        <div class="field" style="flex:1; min-width:240px;">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Search by name, contact, email, bank details..." class="form-control" />
        </div>
        <div class="field" style="min-width:160px;">
          <label for="status">Status</label>
          <select id="status" name="status" class="form-control">
            <option value="">All Statuses</option>
            <option value="1" {{ $statusFilter === '1' ? 'selected' : '' }}>Active</option>
            <option value="0" {{ $statusFilter === '0' ? 'selected' : '' }}>Inactive</option>
          </select>
        </div>
        <div style="display:flex; align-items:flex-end; gap:8px;">
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
          <a href="{{ route('service-providers.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div>
        <h3>Registered Providers ({{ $providers->total() }})</h3>
        <p>Official register of approved service vendors for requisitions and procurement</p>
      </div>
    </div>
    <div class="card-body flush">
      @if ($providers->isEmpty())
        @include('partials.empty', [
          'title' => 'No Service Providers Found',
          'message' => 'No service providers match the current search or filters. Click "+ Add Service Provider" to register a new vendor.',
          'icon' => '&#127970;'
        ])
      @else
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="width:28%;">Provider / Vendor</th>
                <th style="width:20%;">Contact Details</th>
                <th style="width:25%;">Disbursement Bank Details</th>
                <th style="width:15%;">Address / Location</th>
                <th style="width:6%;">Status</th>
                <th style="width:6%; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($providers as $provider)
                <tr>
                  <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                      @if ($provider->image)
                        <img src="{{ $provider->image_url }}" alt="{{ $provider->name }}" style="width:36px; height:36px; border-radius:6px; object-fit:cover; border:1px solid #cbd5e1;" />
                      @else
                        <div style="width:36px; height:36px; border-radius:6px; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid #cbd5e1;">
                          {{ strtoupper(substr($provider->name, 0, 2)) }}
                        </div>
                      @endif
                      <div>
                        <div style="font-weight:700; color:#0f172a; font-size:0.95rem;">
                          {{ $provider->name }}
                        </div>
                        @if ($provider->billing_name)
                          <div style="font-size:0.75rem; color:#64748b;">
                            Attn: {{ $provider->billing_name }}
                          </div>
                        @endif
                      </div>
                    </div>
                  </td>
                  <td>
                    @if ($provider->contact)
                      <div style="font-size:0.85rem; color:#0f172a; font-weight:600;">
                        {{ $provider->contact }}
                      </div>
                    @endif
                    @if ($provider->email)
                      <div style="font-size:0.8rem; color:#64748b;">
                        {{ $provider->email }}
                      </div>
                    @endif
                    @if (!$provider->contact && !$provider->email)
                      <span class="text-muted hint">—</span>
                    @endif
                  </td>
                  <td>
                    @if ($provider->bank_account)
                      <div style="display:flex; align-items:center; gap:6px;">
                        <span style="font-size:0.9rem;">&#127974;</span>
                        <strong style="color:#0f172a;">{{ $provider->bank_name ?: 'Bank' }}</strong>
                      </div>
                      <div style="font-family:monospace; font-size:0.9rem; font-weight:700; color:#0b7d54;">
                        {{ $provider->bank_account }}
                      </div>
                      @if ($provider->account_name)
                        <div style="font-size:0.75rem; color:#64748b;">
                          A/C Name: <em>{{ $provider->account_name }}</em>
                        </div>
                      @endif
                    @else
                      <span class="text-muted hint">— No bank account set —</span>
                    @endif
                  </td>
                  <td>
                    @if ($provider->billing_address || $provider->billing_city || $provider->billing_state)
                      <div style="font-size:0.8rem; color:#334155;">
                        {{ $provider->billing_address }}
                      </div>
                      <div style="font-size:0.75rem; color:#64748b;">
                        {{ implode(', ', array_filter([$provider->billing_city, $provider->billing_state, $provider->billing_country])) }}
                        @if ($provider->billing_zip) ({{ $provider->billing_zip }}) @endif
                      </div>
                    @else
                      <span class="text-muted hint">—</span>
                    @endif
                  </td>
                  <td>
                    <span class="badge {{ $provider->is_active ? 'success' : 'muted' }}">
                      {{ $provider->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:flex; justify-content:flex-end; gap:6px;">
                      @if ($canEdit)
                        <button type="button" class="btn btn-ghost btn-xs"
                                onclick="openEditProviderModal({{ json_encode($provider) }})"
                                title="Edit Service Provider">
                          Edit
                        </button>
                      @endif
                      @if ($canDelete)
                        <form method="POST" action="{{ route('service-providers.destroy', $provider) }}" onsubmit="return confirm('Are you sure you want to remove {{ addslashes($provider->name) }}?');" style="display:inline;">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="btn btn-ghost btn-xs text-danger" title="Delete">
                            &times;
                          </button>
                        </form>
                      @endif
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <div style="padding: 12px 16px;">
          {{ $providers->links() }}
        </div>
      @endif
    </div>
  </div>

  {{-- Create Provider Modal --}}
  @if ($canCreate)
    <div class="modal @if (old('_modal') === 'modal-create-provider') open @endif" id="modal-create-provider">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>+ Add New Service Provider</h3>
            <p>Register vendor/supplier and disbursement bank account</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('service-providers.store') }}" enctype="multipart/form-data">
          @csrf
          <input type="hidden" name="_modal" value="modal-create-provider" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-create-provider'])
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
              <div class="field" style="grid-column: span 2;">
                <label for="create_name">Company / Provider Name <span class="req">*</span></label>
                <input type="text" id="create_name" name="name" value="{{ old('name') }}" required class="form-control" placeholder="e.g. ABC Logistics &amp; Supplies Ltd" />
              </div>
              <div class="field">
                <label for="create_email">Email Address</label>
                <input type="email" id="create_email" name="email" value="{{ old('email') }}" class="form-control" placeholder="billing@provider.com" />
              </div>
              <div class="field">
                <label for="create_contact">Phone / Contact</label>
                <input type="text" id="create_contact" name="contact" value="{{ old('contact') }}" class="form-control" placeholder="+234 801 234 5678" />
              </div>

              <div style="grid-column: span 2; margin-top:8px;">
                <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#127974; Bank &amp; Disbursement Account Details
                </h4>
              </div>

              <div class="field">
                <label for="create_bank_code">Bank Name</label>
                <select id="create_bank_code" name="bank_code" class="bank-select" data-searchable data-combo-placeholder="Search banks…">
                  <option value="">-- Select Bank --</option>
                  @foreach ($banks as $bank)
                    <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}" @selected(old('bank_code') === $bank['code'])>{{ $bank['name'] }}</option>
                  @endforeach
                </select>
                <input type="hidden" id="create_bank_name" name="bank_name" value="{{ old('bank_name') }}" />
              </div>
              <div class="field">
                <label for="create_bank_account">Bank Account Number (NUBAN)</label>
                <div style="position:relative">
                  <input type="text" id="create_bank_account" name="bank_account" value="{{ old('bank_account') }}" class="form-control" inputmode="numeric" maxlength="10" placeholder="10-digit NUBAN account" />
                  <span class="create-bank-spinner" style="position:absolute;right:10px;top:8px;display:none;font-size:13px;color:var(--primary, #0284c7)">&#9203; Verifying...</span>
                </div>
                <div class="create-bank-msg hint" style="margin-top:4px; font-size:0.75rem;"></div>
              </div>
              <div class="field" style="grid-column: span 2;">
                <label for="create_account_name">Account Beneficiary Name</label>
                <input type="text" id="create_account_name" name="account_name" value="{{ old('account_name') }}" class="form-control" readonly placeholder="Auto-retrieved upon account number entry" style="background:#f8fafc; font-weight:600; color:#0f172a; cursor:not-allowed;" />
                <div class="hint" style="font-size:0.75rem; color:#64748b;">Automatically verified via payment gateway.</div>
              </div>

              <div style="grid-column: span 2; margin-top:8px;">
                <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#128205; Billing &amp; Address Information
                </h4>
              </div>

              <div class="field">
                <label for="create_billing_name">Billing Contact / Attention</label>
                <input type="text" id="create_billing_name" name="billing_name" value="{{ old('billing_name') }}" class="form-control" placeholder="Accounts Department" />
              </div>
              <div class="field">
                <label for="create_billing_phone">Billing Phone</label>
                <input type="text" id="create_billing_phone" name="billing_phone" value="{{ old('billing_phone') }}" class="form-control" placeholder="Phone" />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label for="create_billing_address">Street Address</label>
                <textarea id="create_billing_address" name="billing_address" rows="2" class="form-control" placeholder="Office / Billing address">{{ old('billing_address') }}</textarea>
              </div>
              <div class="field">
                <label for="create_billing_city">City</label>
                <input type="text" id="create_billing_city" name="billing_city" value="{{ old('billing_city') }}" class="form-control" placeholder="e.g. Kano" />
              </div>
              <div class="field">
                <label for="create_billing_state">State / Province</label>
                <input type="text" id="create_billing_state" name="billing_state" value="{{ old('billing_state') }}" class="form-control" placeholder="e.g. Kano State" />
              </div>

              <div class="field">
                <label for="create_image">Logo / Image</label>
                <input type="file" id="create_image" name="image" accept="image/*" class="form-control" />
              </div>
              <div class="field" style="display:flex; align-items:center; gap:8px; margin-top:22px;">
                <input type="checkbox" id="create_is_active" name="is_active" value="1" checked style="width:18px; height:18px;" />
                <label for="create_is_active" style="margin:0; font-weight:600; cursor:pointer;">Active Service Provider</label>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Service Provider</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  {{-- Edit Provider Modal --}}
  @if ($canEdit)
    <div class="modal @if (old('_modal') === 'modal-edit-provider') open @endif" id="modal-edit-provider">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div>
            <h3>Edit Service Provider</h3>
            <p>Update provider information and disbursement account</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form id="edit-provider-form" method="POST" action="" enctype="multipart/form-data">
          @csrf
          @method('PUT')
          <input type="hidden" name="_modal" value="modal-edit-provider" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-provider'])
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
              <div class="field" style="grid-column: span 2;">
                <label for="edit_name">Company / Provider Name <span class="req">*</span></label>
                <input type="text" id="edit_name" name="name" required class="form-control" />
              </div>
              <div class="field">
                <label for="edit_email">Email Address</label>
                <input type="email" id="edit_email" name="email" class="form-control" />
              </div>
              <div class="field">
                <label for="edit_contact">Phone / Contact</label>
                <input type="text" id="edit_contact" name="contact" class="form-control" />
              </div>

              <div style="grid-column: span 2; margin-top:8px;">
                <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#127974; Bank &amp; Disbursement Account Details
                </h4>
              </div>

              <div class="field">
                <label for="edit_bank_code">Bank Name</label>
                <select id="edit_bank_code" name="bank_code" class="bank-select" data-searchable data-combo-placeholder="Search banks…">
                  <option value="">-- Select Bank --</option>
                  @foreach ($banks as $bank)
                    <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
                  @endforeach
                </select>
                <input type="hidden" id="edit_bank_name" name="bank_name" />
              </div>
              <div class="field">
                <label for="edit_bank_account">Bank Account Number (NUBAN)</label>
                <div style="position:relative">
                  <input type="text" id="edit_bank_account" name="bank_account" class="form-control" inputmode="numeric" maxlength="10" />
                  <span class="edit-bank-spinner" style="position:absolute;right:10px;top:8px;display:none;font-size:13px;color:var(--primary, #0284c7)">&#9203; Verifying...</span>
                </div>
                <div class="edit-bank-msg hint" style="margin-top:4px; font-size:0.75rem;"></div>
              </div>
              <div class="field" style="grid-column: span 2;">
                <label for="edit_account_name">Account Beneficiary Name</label>
                <input type="text" id="edit_account_name" name="account_name" class="form-control" readonly placeholder="Auto-retrieved upon account number entry" style="background:#f8fafc; font-weight:600; color:#0f172a; cursor:not-allowed;" />
                <div class="hint" style="font-size:0.75rem; color:#64748b;">Automatically verified via payment gateway.</div>
              </div>

              <div style="grid-column: span 2; margin-top:8px;">
                <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#128205; Billing &amp; Address Information
                </h4>
              </div>

              <div class="field">
                <label for="edit_billing_name">Billing Contact / Attention</label>
                <input type="text" id="edit_billing_name" name="billing_name" class="form-control" />
              </div>
              <div class="field">
                <label for="edit_billing_phone">Billing Phone</label>
                <input type="text" id="edit_billing_phone" name="billing_phone" class="form-control" />
              </div>
              <div class="field" style="grid-column: span 2;">
                <label for="edit_billing_address">Street Address</label>
                <textarea id="edit_billing_address" name="billing_address" rows="2" class="form-control"></textarea>
              </div>
              <div class="field">
                <label for="edit_billing_city">City</label>
                <input type="text" id="edit_billing_city" name="billing_city" class="form-control" />
              </div>
              <div class="field">
                <label for="edit_billing_state">State / Province</label>
                <input type="text" id="edit_billing_state" name="billing_state" class="form-control" />
              </div>

              <div class="field">
                <label for="edit_image">Logo / Image</label>
                <input type="file" id="edit_image" name="image" accept="image/*" class="form-control" />
              </div>
              <div class="field" style="display:flex; align-items:center; gap:8px; margin-top:22px;">
                <input type="checkbox" id="edit_is_active" name="is_active" value="1" style="width:18px; height:18px;" />
                <label for="edit_is_active" style="margin:0; font-weight:600; cursor:pointer;">Active Service Provider</label>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  <script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    function setupBankVerification(prefix) {
      const bankSelect = document.getElementById(prefix + '_bank_code');
      const bankNameInput = document.getElementById(prefix + '_bank_name');
      const accountInput = document.getElementById(prefix + '_bank_account');
      const nameInput = document.getElementById(prefix + '_account_name');
      const spinner = document.querySelector('.' + prefix + '-bank-spinner');
      const msg = document.querySelector('.' + prefix + '-bank-msg');

      if (!bankSelect || !accountInput || !nameInput) return;

      function updateBankName() {
        const selected = bankSelect.options[bankSelect.selectedIndex];
        if (selected && selected.dataset.name) {
          bankNameInput.value = selected.dataset.name;
        } else {
          bankNameInput.value = '';
        }
      }

      async function verifyAccount() {
        const bankCode = bankSelect.value.trim();
        const accountNo = accountInput.value.trim();

        if (!bankCode) {
          if (msg) msg.innerHTML = '<span style="color:#dc2626">Please select a bank first.</span>';
          return;
        }

        if (accountNo.length !== 10) {
          if (msg) msg.innerHTML = '<span style="color:#dc2626">NUBAN account number must be 10 digits.</span>';
          return;
        }

        updateBankName();
        if (spinner) spinner.style.display = 'inline';
        if (msg) msg.innerHTML = '<span style="color:#0284c7">Verifying account with payment gateway...</span>';

        try {
          const res = await fetch('{{ route('service-providers.verify-bank') }}', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ bank_code: bankCode, account_number: accountNo }),
          });

          const data = await res.json();

          if (res.ok && (data.success || data.status) && data.account_name) {
            nameInput.value = data.account_name;
            if (data.bank_name && !bankNameInput.value) {
              bankNameInput.value = data.bank_name;
            }
            if (msg) msg.innerHTML = '<span style="color:#15803d; font-weight:600">&#10003; Verified: ' + data.account_name + '</span>';
          } else {
            if (msg) msg.innerHTML = '<span style="color:#dc2626; font-weight:600">&#9888; ' + (data.message || 'Verification failed. Check bank and account number.') + '</span>';
          }
        } catch (err) {
          if (msg) msg.innerHTML = '<span style="color:#dc2626">&#9888; Network error during verification.</span>';
        } finally {
          if (spinner) spinner.style.display = 'none';
        }
      }

      bankSelect.addEventListener('change', function() {
        updateBankName();
        if (accountInput.value.trim().length === 10) {
          verifyAccount();
        }
      });

      accountInput.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
        if (this.value.length === 10) {
          verifyAccount();
        } else {
          if (msg) msg.innerHTML = '<span class="text-muted">Enter a 10-digit NUBAN account number</span>';
        }
      });

      accountInput.addEventListener('blur', function() {
        if (this.value.trim().length === 10) {
          verifyAccount();
        }
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      setupBankVerification('create');
      setupBankVerification('edit');
    });

    function openEditProviderModal(provider) {
      const form = document.getElementById('edit-provider-form');
      form.action = `/purchases/service-providers/${provider.id}`;

      document.getElementById('edit_name').value = provider.name || '';
      document.getElementById('edit_email').value = provider.email || '';
      document.getElementById('edit_contact').value = provider.contact || '';
      document.getElementById('edit_bank_name').value = provider.bank_name || '';
      document.getElementById('edit_bank_account').value = provider.bank_account || '';
      document.getElementById('edit_account_name').value = provider.account_name || '';

      const bankSelect = document.getElementById('edit_bank_code');
      if (bankSelect) {
        bankSelect.value = provider.bank_code || '';
        if (!bankSelect.value && provider.bank_name) {
          for (let opt of bankSelect.options) {
            if (opt.text.toLowerCase().includes(provider.bank_name.toLowerCase()) || provider.bank_name.toLowerCase().includes(opt.text.toLowerCase())) {
              bankSelect.value = opt.value;
              break;
            }
          }
        }
        bankSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }

      document.getElementById('edit_billing_name').value = provider.billing_name || '';
      document.getElementById('edit_billing_phone').value = provider.billing_phone || '';
      document.getElementById('edit_billing_address').value = provider.billing_address || '';
      document.getElementById('edit_billing_city').value = provider.billing_city || '';
      document.getElementById('edit_billing_state').value = provider.billing_state || '';
      document.getElementById('edit_is_active').checked = !!provider.is_active;

      const msg = document.querySelector('.edit-bank-msg');
      if (msg) {
        if (provider.account_name) {
          msg.innerHTML = '<span style="color:#15803d; font-weight:600">&#10003; Current Beneficiary: ' + provider.account_name + '</span>';
        } else {
          msg.innerHTML = '';
        }
      }

      window.location.hash = 'modal-edit-provider';
    }
  </script>
@endsection
