@extends('layouts.app')
@section('title', $farmer->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('farmers.index') }}">Farmers</a><span class="sep">/</span>
    <span class="here">{{ $farmer->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($farmer->name, 0, 2) }}</div>
    <div class="dh-main">
      <h1>{{ $farmer->name }}</h1>
      <div class="dh-sub">
        {{ $farmer->code }} &middot; {{ $farmer->community?->name }}, {{ $farmer->community?->lga?->name }}
        @if ($farmer->cooperative) &middot; {{ $farmer->cooperative->name }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ ['active' => 'success', 'dormant' => 'warning', 'exited' => 'muted'][$farmer->status] ?? 'muted' }}">
          {{ ucfirst($farmer->status) }}</span>
        @if ($farmer->herd_size)<span class="pill">{{ $farmer->herd_size }} cattle</span>@endif
        @if ($farmer->lactating_count)<span class="pill">{{ $farmer->lactating_count }} lactating</span>@endif
        <span class="badge muted plain">record, not an account</span>
      </div>
    </div>
    <div class="dh-actions">
      @can('finance.farmer_payments.view')
        {{-- USER-2 — a farmer has no login, so the statement is something an
             officer prints and hands over. --}}
        <a href="{{ route('farmers.statement', $farmer) }}" class="btn btn-outline">Statement</a>
      @endcan
      @can('community.farmers.edit')
        <a href="#modal-edit-farmer" class="btn btn-outline">&#9998; Edit Farmer</a>
      @endcan
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if ($openFollowups->isNotEmpty())
    {{-- BR-5 --}}
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>{{ $openFollowups->count() }} open quality follow-up(s), opened automatically.</strong>
        @foreach ($openFollowups as $followup)
          {{ $followup->rejectionReason?->name }}: {{ $followup->trigger_count }} rejections in
          {{ $followup->window_days }} days (threshold {{ $followup->threshold }}).
        @endforeach
        @can('community.extension.create')
          Closing one requires a logged
          <a href="{{ route('field-activities.index') }}" class="text-primary">field activity</a>.
        @endcan
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      @if ($seesVolumes)
        <div class="card">
          <div class="card-head">
            <div><h3>Delivery History</h3><p>Last 25 deliveries</p></div>
            <span class="pill green">30-day accepted: {{ \App\Support\Volume::format($thirtyDayLitres) }}</span>
          </div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Reference</th><th>Point</th><th class="num">Presented</th><th class="num">Rejected</th>
                  <th class="num">Accepted</th><th>Reason</th><th>Grade</th><th>When</th></tr></thead>
                <tbody>
                  @forelse ($deliveries as $delivery)
                    <tr>
                      <td><a href="{{ route('deliveries.show', $delivery) }}" class="perm-key">{{ $delivery->reference }}</a></td>
                      <td>{{ $delivery->collectionPoint?->name }}</td>
                      <td class="num">{{ \App\Support\Volume::format($delivery->litres_presented, false) }}</td>
                      <td class="num">{{ \App\Support\Volume::format($delivery->litres_rejected, false) }}</td>
                      <td class="num font-bold">{{ \App\Support\Volume::format($delivery->litres_accepted, false) }}</td>
                      <td>{{ $delivery->rejectionReason?->name ?? '—' }}</td>
                      <td>{{ $delivery->consignment?->grade?->name ?? '—' }}</td>
                      <td>{{ \App\Support\Wat::relative($delivery->delivered_at) }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="8">@include('partials.empty', ['title' => 'No deliveries recorded', 'icon' => '&#127869;'])</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @else
        {{-- §16 — the Extension Agent persona: "No volumes or payment figures". --}}
        <div class="card">
          <div class="card-head"><div><h3>Delivery History</h3><p>Not available to your role</p></div></div>
          <div class="card-body">
            @include('partials.empty', [
              'title' => 'Volumes are outside your role',
              'message' => 'Milk volumes are not shown to your role. You can see the farmer record itself.',
              'icon' => '&#128274;',
            ])
          </div>
        </div>
      @endif

      <div class="card">
        <div class="card-head"><div><h3>Extension Activity</h3><p>Visits, training and follow-ups</p></div></div>
        <div class="card-body">
          @forelse ($activities as $activity)
            <div class="queue-item">
              <div class="qi-ic">&#128100;</div>
              <div>
                <div class="qi-title">{{ $activity->activityType?->name }}
                  <span class="perm-key">{{ $activity->reference }}</span></div>
                <div class="qi-sub">
                  {{ $activity->extensionAgent?->user?->name }}
                  @if ($activity->topic) &middot; {{ $activity->topic }} @endif
                  @if ($activity->closes_followup_id) &middot; closed a quality follow-up @endif
                </div>
                <div class="tl-time">{{ \App\Support\Wat::date($activity->activity_date) }}</div>
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'No activity logged for this farmer', 'icon' => '&#128203;'])
          @endforelse
        </div>
      </div>
    </div>

    <div class="stack">
      {{-- Bank & Payout Destination Card --}}
      <div class="card">
        <div class="card-head" style="background:#eff6ff;">
          <div>
            <h3 style="color:#1e40af; margin:0;">&#127974; Bank &amp; Payout Destination</h3>
            <p style="margin:0;">Settlement account for milk delivery proceeds</p>
          </div>
          @can('community.farmers.edit')
            <a href="#modal-edit-farmer" class="btn btn-ghost btn-xs">Edit Account</a>
          @endcan
        </div>
        <div class="card-body">
          @if ($farmer->bank_name || $farmer->bank_account || $farmer->bank_account_masked)
            <div class="meta-grid cols-1" style="gap:10px;">
              <div class="meta-item">
                <div class="meta-label">Payout Method</div>
                <div class="meta-value">
                  <span class="badge success">{{ ucfirst(str_replace('_', ' ', $farmer->payout_method ?: 'Bank Transfer')) }}</span>
                </div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Bank Name</div>
                <div class="meta-value font-bold" style="color:#0f172a;">{{ $farmer->bank_name ?: 'Commercial Bank' }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">NUBAN Account Number</div>
                <div class="meta-value mono font-bold" style="color:#0b7d54; font-size:1.05rem;">
                  {{ $farmer->bank_account ?: $farmer->bank_account_masked }}
                </div>
              </div>
              @if ($farmer->account_name)
                <div class="meta-item">
                  <div class="meta-label">Verified Beneficiary Name</div>
                  <div class="meta-value font-bold" style="color:#1e40af;">
                    {{ $farmer->account_name }}
                  </div>
                </div>
              @endif
            </div>
          @else
            <div class="alert warn" style="margin:0;">
              <span>&#9888;</span>
              <div>
                <strong>No Bank Account Set.</strong>
                Default settlement method: <em>{{ ucfirst(str_replace('_', ' ', $farmer->payout_method ?: 'cash')) }}</em>.
                <div style="margin-top:4px;">
                  <a href="#modal-edit-farmer" class="text-primary font-bold">Add Bank Account &rarr;</a>
                </div>
              </div>
            </div>
          @endif
        </div>
      </div>

      {{-- Farmer Profile Card --}}
      <div class="card">
        <div class="card-head"><div><h3>Record</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Code</div><div class="meta-value mono">{{ $farmer->code }}</div></div>
            <div class="meta-item"><div class="meta-label">Phone</div><div class="meta-value">{{ $farmer->phone ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Gender</div><div class="meta-value">{{ $farmer->gender ? ucfirst($farmer->gender) : '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Age</div><div class="meta-value">{{ $farmer->age() ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Default point</div>
              <div class="meta-value">{{ $farmer->defaultCollectionPoint?->name ?? '—' }}</div>
              <div class="cell-sub">{{ $farmer->defaultCollectionPoint?->collectionCenter?->name }}</div></div>
            <div class="meta-item"><div class="meta-label">Enrolled</div>
              <div class="meta-value">{{ \App\Support\Wat::date($farmer->enrolled_on) }}</div>
              <div class="cell-sub">by {{ $farmer->enrolledBy?->name ?? 'unknown' }}</div></div>
          </div>
        </div>
      </div>

      @if ($pendingDeductions->isNotEmpty())
        <div class="card">
          <div class="card-head"><div><h3>Pending Deductions</h3>
            <p>Shop purchases to be taken from the next milk payment</p></div></div>
          <div class="card-body">
            @foreach ($pendingDeductions as $deduction)
              <div class="queue-item">
                <div class="qi-ic">&#128722;</div>
                <div>
                  <div class="qi-title">{{ \App\Support\Money::format($deduction->amount_minor) }}</div>
                  <div class="qi-sub">{{ $deduction->description }}</div>
                </div>
                <div class="qi-right"><span class="badge warning">Pending</span></div>
              </div>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>

  @can('community.farmers.edit')
    <div id="modal-edit-farmer" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog" style="max-width:700px;">
        <div class="modal-head">
          <div><h3>Edit Farmer</h3><p>{{ $farmer->code }} &middot; {{ $farmer->name }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('farmers.update', $farmer) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-farmer" />
          @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-farmer'])
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
              <div class="field"><label for="uf-name">Full Name <span class="req">*</span></label>
                <input type="text" id="uf-name" name="name" value="{{ old('name', $farmer->name) }}" required /></div>
              <div class="field"><label for="uf-phone">Phone</label>
                <input type="text" id="uf-phone" name="phone" value="{{ old('phone', $farmer->phone) }}" /></div>
              <div class="field"><label for="uf-memberno">Cooperative member no.</label>
                <input type="text" id="uf-memberno" name="cooperative_member_no" value="{{ old('cooperative_member_no', $farmer->cooperative_member_no) }}" /></div>
              <div class="field"><label for="uf-herd">Herd size</label>
                <input type="number" id="uf-herd" name="herd_size" value="{{ old('herd_size', $farmer->herd_size) }}" min="0" /></div>
              <div class="field"><label for="uf-lact">Lactating cows</label>
                <input type="number" id="uf-lact" name="lactating_count" value="{{ old('lactating_count', $farmer->lactating_count) }}" min="0" /></div>
              <div class="field"><label for="uf-status">Status <span class="req">*</span></label>
                <select id="uf-status" name="status" required>
                  @foreach (['active', 'dormant', 'exited'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $farmer->status) === $status)>{{ ucfirst($status) }}</option>
                  @endforeach
                </select></div>

              {{-- Payout & Bank Details Section --}}
              <div style="grid-column: span 2; margin-top:10px;">
                <h4 style="margin:0 0 8px; font-size:0.95rem; color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                  &#127974; Payout &amp; Bank Settlement Details
                </h4>
              </div>

              <div class="field">
                <label for="uf-payout-method">Payout Method</label>
                <select id="uf-payout-method" name="payout_method">
                  <option value="bank" @selected(old('payout_method', $farmer->payout_method ?: 'bank') === 'bank')>Direct Bank Transfer</option>
                  <option value="cash" @selected(old('payout_method', $farmer->payout_method) === 'cash')>Cash at Collection Centre</option>
                  <option value="mobile_money" @selected(old('payout_method', $farmer->payout_method) === 'mobile_money')>Mobile Money</option>
                  <option value="via_cooperative" @selected(old('payout_method', $farmer->payout_method) === 'via_cooperative')>Via Cooperative</option>
                </select>
              </div>

              <div class="field">
                <label for="uf-bank-code">Bank Name</label>
                <select id="uf-bank-code" name="bank_code" class="bank-select" data-searchable data-combo-placeholder="Search banks…">
                  <option value="">-- Select Bank --</option>
                  @foreach ($banks as $b)
                    <option value="{{ $b['code'] }}" data-name="{{ $b['name'] }}" @selected(old('bank_code', $farmer->bank_code) === $b['code'] || old('bank_name', $farmer->bank_name) === $b['name'])>
                      {{ $b['name'] }}
                    </option>
                  @endforeach
                </select>
                <input type="hidden" id="uf-bank-name" name="bank_name" value="{{ old('bank_name', $farmer->bank_name) }}" />
              </div>

              <div class="field">
                <label for="uf-bank-account">NUBAN Account Number</label>
                <div style="position:relative">
                  <input type="text" id="uf-bank-account" name="bank_account" value="{{ old('bank_account', $farmer->bank_account) }}" inputmode="numeric" maxlength="10" placeholder="10-digit account number" />
                  <span id="uf-bank-spinner" style="position:absolute; right:10px; top:9px; display:none; font-size:12px; color:#0284c7;">&#9203; Verifying...</span>
                </div>
                <div id="uf-bank-msg" class="hint" style="font-size:0.75rem; margin-top:3px;"></div>
              </div>

              <div class="field">
                <label for="uf-account-name">Account Beneficiary Name</label>
                <input type="text" id="uf-account-name" name="account_name" value="{{ old('account_name', $farmer->account_name) }}" placeholder="Auto-retrieved upon account entry" style="background:#f8fafc; font-weight:600; color:#0f172a;" />
                <div class="hint" style="font-size:0.75rem; color:#64748b;">Automatically verified via payment gateway.</div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save farmer</button>
          </div>
        </form>
      </div>
    </div>
  @endcan

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const bankSelect = document.getElementById('uf-bank-code');
      const bankNameInput = document.getElementById('uf-bank-name');
      const accountInput = document.getElementById('uf-bank-account');
      const accountNameInput = document.getElementById('uf-account-name');
      const spinner = document.getElementById('uf-bank-spinner');
      const msg = document.getElementById('uf-bank-msg');

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
