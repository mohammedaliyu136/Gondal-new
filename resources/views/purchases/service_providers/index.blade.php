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
        <button type="button" class="btn btn-primary" onclick="openCreateProviderModal()">
          + Add Service Provider
        </button>
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#10003;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if ($errors->any())
    <div class="alert danger mb-16">
      <span>&#10007;</span>
      <div>
        <strong>Please fix the errors below:</strong>
        <ul style="margin:4px 0 0 16px; padding:0;">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif

  <div class="card mb-16">
    <div class="card-body">
      <form method="GET" action="{{ route('service-providers.index') }}" class="form-grid" style="grid-template-columns: 1fr 180px auto; gap: 12px; align-items:end;">
        <div class="field" style="margin:0;">
          <label for="search">Search Providers</label>
          <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Search by name, email, contact, account number, bank..." class="form-control" />
        </div>
        <div class="field" style="margin:0;">
          <label for="status">Status</label>
          <select id="status" name="status" class="form-control">
            <option value="">All Statuses</option>
            <option value="1" {{ $statusFilter === '1' ? 'selected' : '' }}>Active Only</option>
            <option value="0" {{ $statusFilter === '0' ? 'selected' : '' }}>Inactive Only</option>
          </select>
        </div>
        <div>
          <button type="submit" class="btn btn-primary">Filter</button>
          @if ($search || $statusFilter !== '')
            <a href="{{ route('service-providers.index') }}" class="btn btn-ghost">Reset</a>
          @endif
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body flush">
      @if ($providers->isEmpty())
        <div style="text-align:center; padding: 48px 16px; color:#64748b;">
          <div style="font-size:2.5rem; margin-bottom:8px;">&#127970;</div>
          <h3 style="margin:0 0 4px; color:#1e293b;">No Service Providers Found</h3>
          <p style="margin:0; font-size:0.9rem;">
            @if ($search || $statusFilter !== '')
              Try adjusting your search query or filter.
            @else
              Get started by adding your first registered service provider or vendor.
            @endif
          </p>
          @if ($canCreate && ! $search && $statusFilter === '')
            <button type="button" class="btn btn-primary mt-16" onclick="openCreateProviderModal()">
              + Add Service Provider
            </button>
          @endif
        </div>
      @else
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="width:4%;">#</th>
                <th style="width:26%;">Service Provider / Vendor</th>
                <th style="width:28%;">Bank &amp; Account Details</th>
                <th style="width:24%;">Billing Address</th>
                <th style="width:8%;">Status</th>
                <th style="width:10%; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($providers as $idx => $provider)
                <tr>
                  <td class="text-muted" style="font-size:0.8rem;">
                    {{ $providers->firstItem() + $idx }}
                  </td>
                  <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                      @if ($provider->image)
                        <img src="{{ asset('storage/' . $provider->image) }}" alt="{{ $provider->name }}"
                             style="width:36px; height:36px; border-radius:6px; object-fit:cover; border:1px solid #e2e8f0;" />
                      @else
                        <div style="width:36px; height:36px; border-radius:6px; background:#e0f2fe; color:#0284c7; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:0.9rem;">
                          {{ strtoupper(substr($provider->name, 0, 2)) }}
                        </div>
                      @endif
                      <div>
                        <div style="font-weight:700; color:#0f172a;">{{ $provider->name }}</div>
                        <div style="font-size:0.75rem; color:#64748b;">
                          @if ($provider->email)<span>&#9993; {{ $provider->email }}</span>@endif
                          @if ($provider->contact)<span style="margin-left:6px;">&#9742; {{ $provider->contact }}</span>@endif
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    @if ($provider->bank_name || $provider->bank_account)
                      <div style="font-size:0.85rem; font-weight:600; color:#1e293b;">
                        {{ $provider->bank_name ?: 'Bank' }}
                        @if ($provider->bank_code) <span class="badge plain" style="font-size:0.7rem;">Code: {{ $provider->bank_code }}</span> @endif
                      </div>
                      <div style="font-size:0.8rem; color:#475569; font-family:monospace; margin-top:2px;">
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
  <div class="modal" id="modal-create-provider" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div style="max-width:650px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
      <div style="padding:16px 20px; background:#eff6ff; border-bottom:1px solid #bfdbfe; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:1.1rem; color:#1e40af;">+ Add New Service Provider</h3>
        <button type="button" onclick="closeModal('modal-create-provider')" style="background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form method="POST" action="{{ route('service-providers.store') }}" enctype="multipart/form-data" style="padding:20px;">
        @csrf
        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
          <div class="field" style="grid-column: span 2;">
            <label for="create_name">Company / Provider Name <span class="req">*</span></label>
            <input type="text" id="create_name" name="name" required class="form-control" placeholder="e.g. ABC Logistics &amp; Supplies Ltd" />
          </div>
          <div class="field">
            <label for="create_email">Email Address</label>
            <input type="email" id="create_email" name="email" class="form-control" placeholder="billing@provider.com" />
          </div>
          <div class="field">
            <label for="create_contact">Phone / Contact</label>
            <input type="text" id="create_contact" name="contact" class="form-control" placeholder="+234 801 234 5678" />
          </div>

          <div style="grid-column: span 2; margin-top:8px;">
            <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
              &#127974; Bank &amp; Disbursement Account Details
            </h4>
          </div>

          <div class="field">
            <label for="create_bank_code">Bank Name</label>
            <select id="create_bank_code" name="bank_code" class="form-control">
              <option value="">-- Select Bank --</option>
              @foreach ($banks as $bank)
                <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
              @endforeach
            </select>
            <input type="hidden" id="create_bank_name" name="bank_name" />
          </div>
          <div class="field">
            <label for="create_bank_account">Bank Account Number (NUBAN)</label>
            <div style="position:relative">
              <input type="text" id="create_bank_account" name="bank_account" class="form-control" inputmode="numeric" maxlength="10" placeholder="10-digit NUBAN account" />
              <span class="create-bank-spinner" style="position:absolute;right:10px;top:8px;display:none;font-size:13px;color:var(--primary, #0284c7)">&#9203; Verifying...</span>
            </div>
            <div class="create-bank-msg hint" style="margin-top:4px; font-size:0.75rem;"></div>
          </div>
          <div class="field" style="grid-column: span 2;">
            <label for="create_account_name">Account Beneficiary Name</label>
            <input type="text" id="create_account_name" name="account_name" class="form-control" placeholder="Auto-retrieved upon account number entry" style="background:#f8fafc; font-weight:600; color:#0f172a;" />
            <div class="hint" style="font-size:0.75rem; color:#64748b;">Automatically verified via payment gateway.</div>
          </div>

          <div style="grid-column: span 2; margin-top:8px;">
            <h4 style="margin:0 0 8px; font-size:0.9rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
              &#128205; Billing &amp; Address Information
            </h4>
          </div>

          <div class="field">
            <label for="create_billing_name">Billing Contact / Attention</label>
            <input type="text" id="create_billing_name" name="billing_name" class="form-control" placeholder="Accounts Department" />
          </div>
          <div class="field">
            <label for="create_billing_phone">Billing Phone</label>
            <input type="text" id="create_billing_phone" name="billing_phone" class="form-control" placeholder="Phone" />
          </div>
          <div class="field" style="grid-column: span 2;">
            <label for="create_billing_address">Street Address</label>
            <textarea id="create_billing_address" name="billing_address" rows="2" class="form-control" placeholder="Office / Billing address"></textarea>
          </div>
          <div class="field">
            <label for="create_billing_city">City</label>
            <input type="text" id="create_billing_city" name="billing_city" class="form-control" placeholder="e.g. Kano" />
          </div>
          <div class="field">
            <label for="create_billing_state">State / Province</label>
            <input type="text" id="create_billing_state" name="billing_state" class="form-control" placeholder="e.g. Kano State" />
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

        <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('modal-create-provider')">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Service Provider</button>
        </div>
      </form>
    </div>
  </div>

  {{-- Edit Provider Modal --}}
  <div class="modal" id="modal-edit-provider" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div style="max-width:650px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
      <div style="padding:16px 20px; background:#eff6ff; border-bottom:1px solid #bfdbfe; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:1.1rem; color:#1e40af;">Edit Service Provider</h3>
        <button type="button" onclick="closeModal('modal-edit-provider')" style="background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form id="edit-provider-form" method="POST" action="" enctype="multipart/form-data" style="padding:20px;">
        @csrf
        @method('PUT')
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
            <select id="edit_bank_code" name="bank_code" class="form-control">
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
            <input type="text" id="edit_account_name" name="account_name" class="form-control" style="background:#f8fafc; font-weight:600; color:#0f172a;" />
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
            <label for="edit_image">Replace Logo / Image</label>
            <input type="file" id="edit_image" name="image" accept="image/*" class="form-control" />
          </div>
          <div class="field" style="display:flex; align-items:center; gap:8px; margin-top:22px;">
            <input type="checkbox" id="edit_is_active" name="is_active" value="1" style="width:18px; height:18px;" />
            <label for="edit_is_active" style="margin:0; font-weight:600; cursor:pointer;">Active Service Provider</label>
          </div>
        </div>

        <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('modal-edit-provider')">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

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

          if (res.ok && data.success && data.account_name) {
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

    function openCreateProviderModal() {
      const msg = document.querySelector('.create-bank-msg');
      if (msg) msg.innerHTML = '';
      document.getElementById('modal-create-provider').style.display = 'block';
    }

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
        // If bank code is not set but bank name matches
        if (!bankSelect.value && provider.bank_name) {
          for (let opt of bankSelect.options) {
            if (opt.text.toLowerCase().includes(provider.bank_name.toLowerCase()) || provider.bank_name.toLowerCase().includes(opt.text.toLowerCase())) {
              bankSelect.value = opt.value;
              break;
            }
          }
        }
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

      document.getElementById('modal-edit-provider').style.display = 'block';
    }

    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
    }

    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
    };
  </script>
@endsection
