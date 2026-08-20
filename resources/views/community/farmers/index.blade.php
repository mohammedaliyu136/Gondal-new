@extends('layouts.app')
@section('title', 'Farmers')

@section('content')
  <div class="page-head">
    <div>
      <h1>Farmers</h1>
      <p>{{ number_format($farmers->total()) }} in your scope &middot; {{ number_format($activeCount) }} active</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-enrol" class="btn btn-primary">+ Enrol Farmer</a>@endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>Register</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name, code or phone" /></div>
        <div class="field"><label for="lga">LGA</label>
          <select id="lga" name="lga">
            <option value="">All</option>
            @foreach ($lgas as $lga)
              <option value="{{ $lga->id }}" @selected(request('lga') == $lga->id)>{{ $lga->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="cooperative">Cooperative</label>
          <select id="cooperative" name="cooperative">
            <option value="">All</option>
            @foreach ($cooperatives as $cooperative)
              <option value="{{ $cooperative->id }}" @selected(request('cooperative') == $cooperative->id)>{{ $cooperative->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['active', 'dormant', 'exited'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('farmers.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Farmer</th><th>Community</th><th>Cooperative</th><th>Default point</th>
            <th class="num">Herd</th><th class="num">Lactating</th><th>Phone / Bank</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($farmers as $farmer)
              <tr>
                <td><div class="font-bold">{{ $farmer->name }}</div><div class="cell-sub perm-key">{{ $farmer->code }}</div></td>
                <td>{{ $farmer->community?->name }}<div class="cell-sub">{{ $farmer->community?->lga?->name }}</div></td>
                <td>{{ $farmer->cooperative?->name ?? '—' }}
                  @if ($farmer->cooperative_member_no)<div class="cell-sub">{{ $farmer->cooperative_member_no }}</div>@endif</td>
                <td>{{ $farmer->defaultCollectionPoint?->name ?? '—' }}</td>
                <td class="num">{{ $farmer->herd_size ?? '—' }}</td>
                <td class="num">{{ $farmer->lactating_count ?? '—' }}</td>
                <td>
                  <div>{{ $farmer->phone ?? '—' }}</div>
                  @if ($farmer->bank_name || $farmer->bank_account)
                    <div style="font-size:0.75rem; color:#0b7d54; font-family:monospace; margin-top:2px;">
                      {{ $farmer->bank_name }}: {{ $farmer->bank_account ?: $farmer->bank_account_masked }}
                    </div>
                  @endif
                </td>
                <td><span class="badge {{ ['active' => 'success', 'dormant' => 'warning', 'exited' => 'muted'][$farmer->status] ?? 'muted' }}">
                  {{ ucfirst($farmer->status) }}</span></td>
                <td class="actions"><a href="{{ route('farmers.show', $farmer) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', [
                'title' => 'No farmers in your scope',
                'message' => 'Your data scope is '.auth()->user()->overallScopeDescription().'.',
                'icon' => '&#127806;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $farmers, 'noun' => 'farmers'])
  </div>

  @if ($canCreate)
    <div id="modal-enrol" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog" style="max-width:700px;">
        <div class="modal-head">
          <div><h3>Enrol Farmer</h3><p>Farmers do not sign in &mdash; staff keep this record on their behalf</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('farmers.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-enrol" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-enrol'])
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
              <div class="field"><label for="ef-code">Farmer code <span class="req">*</span></label>
                <input type="text" id="ef-code" name="code" value="{{ old('code') }}" required /></div>
              <div class="field"><label for="ef-name">Full Name <span class="req">*</span></label>
                <input type="text" id="ef-name" name="name" value="{{ old('name') }}" required /></div>
              <div class="field"><label for="ef-gender">Gender</label>
                <select id="ef-gender" name="gender">
                  <option value="">Not stated</option>
                  <option value="female" @selected(old('gender') === 'female')>Female</option>
                  <option value="male" @selected(old('gender') === 'male')>Male</option>
                </select></div>
              <div class="field"><label for="ef-yob">Year of birth</label>
                <input type="number" id="ef-yob" name="year_of_birth" value="{{ old('year_of_birth') }}" min="1900" max="{{ \App\Support\Wat::local()->format('Y') }}" /></div>
              <div class="field"><label for="ef-phone">Phone Number</label>
                <input type="text" id="ef-phone" name="phone" value="{{ old('phone') }}" placeholder="+234..." /></div>
              <div class="field"><label for="ef-community">Community <span class="req">*</span></label>
                <select id="ef-community" name="community_id" required>
                  <option value="">-- Select Community --</option>
                  @foreach ($communities as $community)
                    <option value="{{ $community->id }}" @selected(old('community_id') == $community->id)>{{ $community->name }} ({{ $community->lga?->name }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-coop">Cooperative</label>
                <select id="ef-coop" name="cooperative_id">
                  <option value="">None</option>
                  @foreach ($cooperatives as $cooperative)
                    <option value="{{ $cooperative->id }}" @selected(old('cooperative_id') == $cooperative->id)>{{ $cooperative->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-memberno">Cooperative member no.</label>
                <input type="text" id="ef-memberno" name="cooperative_member_no" value="{{ old('cooperative_member_no') }}" /></div>
              <div class="field" style="grid-column: span 2;"><label for="ef-point">Default collection point</label>
                <select id="ef-point" name="default_collection_point_id">
                  <option value="">None</option>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}" @selected(old('default_collection_point_id') == $point->id)>{{ $point->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-herd">Herd size</label>
                <input type="number" id="ef-herd" name="herd_size" value="{{ old('herd_size') }}" min="0" /></div>
              <div class="field"><label for="ef-lact">Lactating cows</label>
                <input type="number" id="ef-lact" name="lactating_count" value="{{ old('lactating_count') }}" min="0" /></div>

              {{-- Payout & Bank Details Section --}}
              <div style="grid-column: span 2; margin-top:10px;">
                <h4 style="margin:0 0 8px; font-size:0.95rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#127974; Payout &amp; Bank Settlement Details
                </h4>
              </div>

              <div class="field">
                <label for="ef-payout-method">Preferred Payout Method</label>
                <select id="ef-payout-method" name="payout_method">
                  <option value="bank" @selected(old('payout_method', 'bank') === 'bank')>Direct Bank Transfer</option>
                  <option value="cash" @selected(old('payout_method') === 'cash')>Cash at Collection Centre</option>
                  <option value="mobile_money" @selected(old('payout_method') === 'mobile_money')>Mobile Money</option>
                  <option value="via_cooperative" @selected(old('payout_method') === 'via_cooperative')>Via Cooperative</option>
                </select>
              </div>

              <div class="field">
                <label for="ef-bank-code">Bank Name</label>
                <select id="ef-bank-code" name="bank_code" class="bank-select" data-searchable data-combo-placeholder="Search banks…">
                  <option value="">-- Select Bank --</option>
                  @foreach ($banks as $b)
                    <option value="{{ $b['code'] }}" data-name="{{ $b['name'] }}" @selected(old('bank_code') === $b['code'])>{{ $b['name'] }}</option>
                  @endforeach
                </select>
                <input type="hidden" id="ef-bank-name" name="bank_name" value="{{ old('bank_name') }}" />
              </div>

              <div class="field">
                <label for="ef-bank-account">NUBAN Account Number</label>
                <div style="position:relative">
                  <input type="text" id="ef-bank-account" name="bank_account" value="{{ old('bank_account') }}" inputmode="numeric" maxlength="10" placeholder="10-digit account number" />
                  <span id="ef-bank-spinner" style="position:absolute; right:10px; top:9px; display:none; font-size:12px; color:#0284c7;">&#9203; Verifying...</span>
                </div>
                <div id="ef-bank-msg" class="hint" style="font-size:0.75rem; margin-top:3px;"></div>
              </div>

              <div class="field">
                <label for="ef-account-name">Account Beneficiary Name</label>
                <input type="text" id="ef-account-name" name="account_name" value="{{ old('account_name') }}" placeholder="Auto-retrieved upon account entry" style="background:#f8fafc; font-weight:600; color:#0f172a;" />
                <div class="hint" style="font-size:0.75rem; color:#64748b;">Automatically verified via payment gateway.</div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Enrol farmer</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const bankSelect = document.getElementById('ef-bank-code');
      const bankNameInput = document.getElementById('ef-bank-name');
      const accountInput = document.getElementById('ef-bank-account');
      const accountNameInput = document.getElementById('ef-account-name');
      const spinner = document.getElementById('ef-bank-spinner');
      const msg = document.getElementById('ef-bank-msg');

      if (!bankSelect || !accountInput) return;

      function updateBankName() {
        const selected = bankSelect.options[bankSelect.selectedIndex];
        if (selected && selected.dataset.name) {
          bankNameInput.value = selected.dataset.name;
        } else {
          bankNameInput.value = '';
        }
      }

      bankSelect.addEventListener('change', function() {
        updateBankName();
        if (accountInput.value.length === 10) {
          verifyAccount();
        }
      });

      let timeout = null;
      accountInput.addEventListener('input', function() {
        clearTimeout(timeout);
        const val = this.value.replace(/\D/g, '');
        this.value = val;

        if (val.length === 10) {
          timeout = setTimeout(verifyAccount, 350);
        } else {
          msg.textContent = '';
          if (accountNameInput.dataset.manual !== 'true') {
            accountNameInput.value = '';
          }
        }
      });

      function verifyAccount() {
        const bankCode = bankSelect.value;
        const accountNumber = accountInput.value;

        if (!bankCode) {
          msg.textContent = 'Please select a bank first.';
          msg.style.color = '#d97706';
          return;
        }

        if (accountNumber.length !== 10) return;

        spinner.style.display = 'inline';
        msg.textContent = 'Resolving beneficiary name...';
        msg.style.color = '#0284c7';

        fetch("{{ route('farmers.verify-bank') }}", {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ bank_code: bankCode, account_number: accountNumber })
        })
        .then(res => res.json())
        .then(data => {
          spinner.style.display = 'none';
          if (data.status && data.account_name) {
            accountNameInput.value = data.account_name;
            msg.textContent = '✓ Verified: ' + data.account_name;
            msg.style.color = '#16a34a';
          } else {
            msg.textContent = '⚠ ' + (data.message || 'Could not verify account');
            msg.style.color = '#dc2626';
          }
        })
        .catch(err => {
          spinner.style.display = 'none';
          msg.textContent = '⚠ Verification error';
          msg.style.color = '#dc2626';
        });
      }
    });
  </script>
@endsection
