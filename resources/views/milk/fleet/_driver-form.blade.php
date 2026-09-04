{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup, so a dynamic id here would make every
     link to this modal look like a link to a modal nobody defines. --}}
<a href="#" class="modal-overlay"></a>
<div class="modal-dialog">
    <div class="modal-head">
      <div>
        <h3>{{ $driver ? 'Edit '.$driver->name : 'Add a rider or driver' }}</h3>
        {{-- USER-1 — no credential field here, and there is no login to create. --}}
        <p>A record, not an account.</p>
      </div>
      <a href="#" class="modal-close">&times;</a>
    </div>
    <form method="POST"
          action="{{ $driver ? route('fleet.drivers.update', $driver) : route('fleet.drivers.store') }}"
          enctype="multipart/form-data">
      @csrf
      @if ($driver)@method('PUT')@endif
      <div class="modal-body">
        <div class="field">
          <label for="{{ $id }}-name">Name <span class="req">*</span></label>
          <input type="text" id="{{ $id }}-name" name="name" required
                 value="{{ old('name', $driver?->name) }}" />
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-phone">Phone</label>
            <input type="text" id="{{ $id }}-phone" name="phone" value="{{ old('phone', $driver?->phone) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-licence">Licence no.</label>
            <input type="text" id="{{ $id }}-licence" name="licence_no" value="{{ old('licence_no', $driver?->licence_no) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-type">Type <span class="req">*</span></label>
            <select id="{{ $id }}-type" name="type" required>
              <option value="rider" @selected(old('type', $driver?->type ?? 'rider') === 'rider')>Rider</option>
              <option value="driver" @selected(old('type', $driver?->type ?? 'rider') === 'driver')>Driver</option>
            </select>
          </div>
          <div class="field">
            <label for="{{ $id }}-status">Status <span class="req">*</span></label>
            <select id="{{ $id }}-status" name="status" required>
              @foreach (['active', 'inactive'] as $status)
                <option value="{{ $status }}" @selected(old('status', $driver?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="field" style="margin-top:10px;">
          <label for="{{ $id }}-image">Photo / Image</label>
          @if ($driver?->image)
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
              <img src="{{ $driver->image_url }}" alt="{{ $driver->name }}" style="width:48px; height:48px; border-radius:8px; object-fit:cover; border:1px solid var(--border);" />
              <span class="hint" style="font-size:12px;">Upload a new image to replace current photo.</span>
            </div>
          @endif
          <input type="file" id="{{ $id }}-image" name="image" accept="image/*" />
          <div class="hint">JPG, PNG, WebP up to 2MB.</div>
        </div>

        <div style="margin-top:16px; margin-bottom:12px; padding-top:12px; border-top:1px solid var(--border);">
          <h4 style="margin:0 0 4px; font-size:13.5px; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:6px;">
            <span>&#127974;</span> Bank &amp; Disbursement Account Details
          </h4>
          <p style="margin:0; font-size:12px; color:var(--muted);">Used for settling transport fees and payouts.</p>
        </div>

        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-bank-code">Bank Name</label>
            <select id="{{ $id }}-bank-code" name="bank_code" class="driver-bank-select" data-id="{{ $id }}">
              <option value="">-- Select Bank --</option>
              @foreach ($banks ?? [] as $bank)
                <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}" @selected(old('bank_code', $driver?->bank_code) === $bank['code'])>{{ $bank['name'] }}</option>
              @endforeach
            </select>
            <input type="hidden" id="{{ $id }}-bank-name" name="bank_name" value="{{ old('bank_name', $driver?->bank_name) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-bank-account">Bank Account Number (NUBAN)</label>
            <div style="position:relative">
              <input type="text" id="{{ $id }}-bank-account" name="bank_account" class="driver-account-input" data-id="{{ $id }}"
                     value="{{ old('bank_account', $driver?->bank_account) }}" inputmode="numeric" maxlength="10" placeholder="10-digit NUBAN" />
              <span id="{{ $id }}-bank-spinner" style="position:absolute; right:10px; top:8px; display:none; font-size:13px; color:var(--primary);">&#9203; Verifying...</span>
            </div>
            <div id="{{ $id }}-bank-msg" class="hint" style="margin-top:4px; font-size:12px;"></div>
          </div>
        </div>

        <div class="field">
          <label for="{{ $id }}-account-name">Account Beneficiary Name</label>
          <input type="text" id="{{ $id }}-account-name" name="account_name"
                 value="{{ old('account_name', $driver?->account_name) }}" placeholder="Auto-retrieved upon account entry"
                 readonly
                 style="font-weight:600; background:var(--card, #f8fafc); cursor:not-allowed;" />
          <div class="hint">Automatically verified against payment gateway.</div>
        </div>
      </div>
      <div class="modal-foot">
        <a href="#" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $driver ? 'Save' : 'Add rider' }}</button>
      </div>
    </form>
  </div>
