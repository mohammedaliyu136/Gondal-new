@extends('layouts.app')
@section('title', 'Fleet & Routes')

@section('content')
  <div class="page-head">
    <div>
      <h1>Fleet &amp; Routes</h1>
      <p>{{ $routes->count() }} route(s) &middot; {{ $vehicles->count() }} vehicle(s) &middot;
         {{ $drivers->count() }} rider(s)</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('logistics.index') }}" class="btn btn-outline">Trips</a>
    </div>
  </div>

  @if ($routes->where('status', 'active')->isEmpty())
    {{-- The state a fresh install starts in, and the reason this screen exists:
         the trip form's route select is required, so with no routes no trip can
         be logged and no transport fee is ever captured. --}}
    <div class="card mb-16">
      <div class="empty">
        <h3>No active routes</h3>
        <p>A trip cannot be logged until at least one route exists — the fee a rider
           is paid comes from the route's tariff.</p>
      </div>
    </div>
  @endif

  {{-- ------------------------------ Routes ------------------------------ --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Routes</h3><p>A leg and what it pays.</p></div>
      <div>
        @if ($canEdit)
          {{-- The figures are already on the centre records; this copies them
               into the shape the trip form needs rather than asking for them
               a second time. --}}
          <form method="POST" action="{{ route('fleet.routes.generate') }}" class="inline">
            @csrf
            <button type="submit" class="btn btn-outline">Generate centre → factory routes</button>
          </form>
          <a href="#modal-route" class="btn btn-primary">+ Add route</a>
        @endif
      </div>
    </div>
    @if ($routes->isEmpty())
      <div class="empty"><h3>No routes yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Route</th><th>Distance</th><th>Tariff</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($routes as $r)
              <tr>
                <td>{{ $r->name }}</td>
                <td>{{ $r->distance_km }} km</td>
                <td>{{ $r->formattedTariff() }}</td>
                <td><span class="badge {{ $r->status === 'active' ? '' : 'muted' }}">{{ $r->status }}</span></td>
                <td class="row-actions">
                  @if ($canEdit)<a href="#modal-route-{{ $r->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  {{-- ----------------------------- Vehicles ----------------------------- --}}
  <div class="card mb-16">
    <div class="card-head">
      <div><h3>Vehicles</h3></div>
      @if ($canEdit)<a href="#modal-vehicle" class="btn btn-primary">+ Add vehicle</a>@endif
    </div>
    @if ($vehicles->isEmpty())
      <div class="empty"><h3>No vehicles yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Registration</th><th>Type</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($vehicles as $v)
              <tr>
                <td>{{ $v->registration }}</td>
                <td>{{ $v->type }}</td>
                <td>{{ $v->capacity_litres ? $v->capacity_litres.' L' : '—' }}</td>
                <td><span class="badge {{ $v->status === 'active' ? '' : 'muted' }}">{{ $v->status }}</span></td>
                <td class="row-actions">
                  @if ($canEdit)<a href="#modal-vehicle-{{ $v->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  {{-- ------------------------- Riders and drivers ------------------------ --}}
  <div class="card">
    <div class="card-head">
      <div>
        <h3>Riders &amp; drivers</h3>
        {{-- USER-1, said on the screen so nobody looks for a login for them. --}}
        <p>Records, not accounts. Named on a trip so they can be paid.</p>
      </div>
      @if ($canEdit)<a href="#modal-driver" class="btn btn-primary">+ Add rider</a>@endif
    </div>
    @if ($drivers->isEmpty())
      <div class="empty"><h3>No riders yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Rider / driver</th><th>Phone</th><th>Licence</th><th>Type</th><th>Wallet balance</th><th>Disbursement account</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach ($drivers as $d)
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:10px;">
                    @if ($d->image)
                      <img src="{{ $d->image_url }}" alt="{{ $d->name }}" style="width:36px; height:36px; border-radius:6px; object-fit:cover; border:1px solid var(--border);" />
                    @else
                      <div style="width:36px; height:36px; border-radius:6px; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; border:1px solid var(--border);">
                        {{ strtoupper(substr($d->name, 0, 2)) }}
                      </div>
                    @endif
                    <div>
                      <div style="font-weight:600;">{{ $d->name }}</div>
                    </div>
                  </div>
                </td>
                <td>{{ $d->phone ?? '—' }}</td>
                <td>{{ $d->licence_no ?? '—' }}</td>
                <td><span class="badge">{{ ucfirst($d->type) }}</span></td>
                <td>
                  <a href="#modal-driver-wallet-{{ $d->id }}" style="text-decoration:none; display:inline-block;">
                    <div style="font-weight:700; color:#0b7d54; font-size:0.95rem;">
                      {{ $d->formattedWalletBalance() }}
                    </div>
                    <div class="cell-sub" style="color:var(--primary, #0b7d54); font-size:0.75rem; display:flex; align-items:center; gap:3px;">
                      <span>&#128181;</span> View ledger
                    </div>
                  </a>
                </td>
                <td>
                  @if ($d->bank_account)
                    <div style="display:flex; align-items:center; gap:6px;">
                      <span style="font-size:0.85rem;">&#127974;</span>
                      <strong>{{ $d->bank_name ?: 'Bank' }}</strong>
                    </div>
                    <div style="font-family:monospace; font-weight:700; color:#0b7d54; font-size:0.9rem;">
                      {{ $d->bank_account }}
                    </div>
                    @if ($d->account_name)
                      <div class="cell-sub">{{ $d->account_name }}</div>
                    @endif
                  @else
                    <span class="muted hint">— Not set —</span>
                  @endif
                </td>
                <td><span class="badge {{ $d->status === 'active' ? '' : 'muted' }}">{{ $d->status }}</span></td>
                <td class="row-actions">
                  <a href="#modal-driver-wallet-{{ $d->id }}" class="btn btn-sm btn-ghost" title="View Wallet Ledger">&#128181; Wallet</a>
                  @if ($canEdit)<a href="#modal-driver-{{ $d->id }}" class="btn btn-sm btn-outline">Edit</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if ($canEdit)
    <div id="modal-route" class="modal">
      @include('milk.fleet._route-form', ['id' => 'modal-route', 'route' => null, 'centers' => $centers, 'points' => $points])
    </div>
    @foreach ($routes as $r)
      <div id="modal-route-{{ $r->id }}" class="modal">
        @include('milk.fleet._route-form', ['id' => 'modal-route-'.$r->id, 'route' => $r, 'centers' => $centers, 'points' => $points])
      </div>
    @endforeach

    <div id="modal-vehicle" class="modal">
      @include('milk.fleet._vehicle-form', ['id' => 'modal-vehicle', 'vehicle' => null])
    </div>
    @foreach ($vehicles as $v)
      <div id="modal-vehicle-{{ $v->id }}" class="modal">
        @include('milk.fleet._vehicle-form', ['id' => 'modal-vehicle-'.$v->id, 'vehicle' => $v])
      </div>
    @endforeach

    <div id="modal-driver" class="modal">
      @include('milk.fleet._driver-form', ['id' => 'modal-driver', 'driver' => null, 'banks' => $banks])
    </div>
    @foreach ($drivers as $d)
      <div id="modal-driver-{{ $d->id }}" class="modal">
        @include('milk.fleet._driver-form', ['id' => 'modal-driver-'.$d->id, 'driver' => $d, 'banks' => $banks])
      </div>
    @endforeach
  @endif

  {{-- Driver / Rider Wallet Ledgers --}}
  @foreach ($drivers as $d)
    @php
      $wallet = $d->wallet ?? $d->getOrCreateWallet();
      $transactions = $d->wallet?->transactions ?? collect();
    @endphp
    <div id="modal-driver-wallet-{{ $d->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog" style="max-width:760px; width:95%;">
        <div class="modal-head">
          <div style="display:flex; align-items:center; gap:12px;">
            @if ($d->image)
              <img src="{{ $d->image_url }}" alt="{{ $d->name }}" style="width:44px; height:44px; border-radius:50%; object-fit:cover; border:2px solid var(--border);" />
            @else
              <div style="width:44px; height:44px; border-radius:50%; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; border:2px solid var(--border);">
                {{ strtoupper(substr($d->name, 0, 2)) }}
              </div>
            @endif
            <div>
              <h3 style="margin:0;">{{ $d->name }} &mdash; Wallet Ledger</h3>
              <p style="margin:0; font-size:0.85rem; color:var(--text-muted);">
                {{ ucfirst($d->type) }} &bull; Phone: {{ $d->phone ?? '—' }} &bull; Account: {{ $d->bank_account ? ($d->bank_name.' - '.$d->bank_account) : 'Not configured' }}
              </p>
            </div>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
          <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:16px;">
            <div style="background:var(--card, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:12px 14px;">
              <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Current Balance</div>
              <div style="font-size:1.3rem; font-weight:800; color:#0b7d54;">{{ $wallet->formattedBalance() }}</div>
            </div>
            <div style="background:var(--card, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:12px 14px;">
              <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Lifetime Credited</div>
              <div style="font-size:1.15rem; font-weight:700; color:#0f172a;">{{ $wallet->formattedTotalCredited() }}</div>
            </div>
            <div style="background:var(--card, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:12px 14px;">
              <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Total Paid Out</div>
              <div style="font-size:1.15rem; font-weight:700; color:#475569;">{{ $wallet->formattedTotalDebited() }}</div>
            </div>
          </div>

          <h4 style="margin:0 0 10px 0; font-size:0.95rem; color:var(--text-bright);">&#128220; Transaction History &amp; Trip Credits</h4>
          @if ($transactions->isEmpty())
            <div style="padding:28px 16px; text-align:center; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b;">
              <div style="font-size:1.6rem; margin-bottom:6px;">&#128181;</div>
              <div style="font-weight:600;">No transactions recorded yet</div>
              <div style="font-size:0.8rem; margin-top:2px;">Trip earnings and adjustments are credited here automatically when trips are logged.</div>
            </div>
          @else
            <div class="table-wrap" style="max-height:300px; overflow-y:auto; border:1px solid var(--border); border-radius:6px;">
              <table class="table" style="font-size:0.85rem; margin:0;">
                <thead>
                  <tr style="position:sticky; top:0; background:var(--card, #f8fafc);">
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Activity / Breakdown</th>
                    <th class="num">Amount</th>
                    <th class="num">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($transactions as $txn)
                    <tr>
                      <td style="white-space:nowrap;">{{ \App\Support\Wat::dateTime($txn->created_at) }}</td>
                      <td class="mono" style="font-size:0.78rem; font-weight:600;">{{ $txn->reference }}</td>
                      <td>
                        <span class="badge {{ $txn->type === 'credit' ? 'success' : ($txn->type === 'debit' ? 'warning' : 'info') }}">
                          {{ ucfirst($txn->type) }}
                        </span>
                      </td>
                      <td>
                        <div style="font-weight:600; color:var(--text-bright);">{{ $txn->description }}</div>
                        @if ($txn->source_type && $txn->source_id)
                          <div class="cell-sub perm-key">{{ class_basename($txn->source_type) }} #{{ $txn->source_id }}</div>
                        @endif
                      </td>
                      <td class="num font-bold" style="color:{{ $txn->type === 'credit' ? '#0b7d54' : '#dc2626' }};">
                        {{ $txn->type === 'credit' ? '+' : '-' }}{{ $txn->formattedAmount() }}
                      </td>
                      <td class="num font-bold mono">{{ $txn->formattedBalanceAfter() }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-primary">Close</a>
        </div>
      </div>
    </div>
  @endforeach

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const verifyUrl = '{{ route('fleet.drivers.verify-bank') }}';

      function setupDriverBankVerification(id) {
        const bankSelect = document.getElementById(id + '-bank-code');
        const bankNameInput = document.getElementById(id + '-bank-name');
        const accountInput = document.getElementById(id + '-bank-account');
        const nameInput = document.getElementById(id + '-account-name');
        const spinner = document.getElementById(id + '-bank-spinner');
        const msg = document.getElementById(id + '-bank-msg');

        if (!bankSelect || !accountInput || !nameInput) return;

        function updateBankName() {
          const opt = bankSelect.options[bankSelect.selectedIndex];
          if (opt && opt.dataset.name) {
            bankNameInput.value = opt.dataset.name;
          } else if (!bankSelect.value) {
            bankNameInput.value = '';
          }
        }

        async function verify() {
          const bankCode = bankSelect.value.trim();
          const accountNo = accountInput.value.trim();

          if (!bankCode) {
            if (msg) msg.innerHTML = '<span style="color:var(--danger)">Please select a bank.</span>';
            return;
          }
          if (accountNo.length !== 10) {
            if (msg) msg.innerHTML = '<span style="color:var(--danger)">NUBAN account must be 10 digits.</span>';
            return;
          }

          updateBankName();
          if (spinner) spinner.style.display = 'inline';
          if (msg) msg.innerHTML = '<span style="color:var(--primary)">Verifying account with payment gateway...</span>';

          try {
            const res = await fetch(verifyUrl, {
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
              if (msg) msg.innerHTML = '<span style="color:var(--success, #15803d); font-weight:600">&#10003; Verified: ' + data.account_name + '</span>';
            } else {
              if (msg) msg.innerHTML = '<span style="color:var(--danger); font-weight:600">&#9888; ' + (data.message || 'Verification failed. Check bank and account number.') + '</span>';
            }
          } catch (e) {
            if (msg) msg.innerHTML = '<span style="color:var(--danger)">&#9888; Network error during verification.</span>';
          } finally {
            if (spinner) spinner.style.display = 'none';
          }
        }

        bankSelect.addEventListener('change', function() {
          updateBankName();
          if (accountInput.value.trim().length === 10) {
            verify();
          }
        });

        accountInput.addEventListener('input', function() {
          const val = accountInput.value.replace(/\D/g, '');
          accountInput.value = val;
          if (val.length === 10 && bankSelect.value.trim()) {
            verify();
          } else if (val.length < 10 && msg) {
            msg.innerHTML = '';
          }
        });
      }

      setupDriverBankVerification('modal-driver');
      @foreach ($drivers as $d)
        setupDriverBankVerification('modal-driver-{{ $d->id }}');
      @endforeach

      function setupRouteCategoryForm(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const categorySelect = modal.querySelector('.route-category-select');
        const nameInput = modal.querySelector('.route-name');
        const fromTypeInput = modal.querySelector('.route-from-type');
        const toTypeInput = modal.querySelector('.route-to-type');
        const fromPointWrap = modal.querySelector('.from-point-wrap');
        const fromCenterWrap = modal.querySelector('.from-center-wrap');
        const fromPointSelect = modal.querySelector('.from-point-select');
        const fromCenterSelect = modal.querySelector('.from-center-select');
        const toCenterWrap = modal.querySelector('.to-center-wrap');
        const toFactoryWrap = modal.querySelector('.to-factory-wrap');
        const toCenterSelect = modal.querySelector('.to-center-select');
        const toFactoryHidden = modal.querySelector('.to-factory-hidden');
        const distanceInput = modal.querySelector('.route-distance');
        const tariffInput = modal.querySelector('.route-tariff');
        const form = modal.querySelector('.route-form');

        if (!categorySelect) return;

        let nameManuallyEdited = form?.dataset.hasRoute === '1';

        if (nameInput) {
          nameInput.addEventListener('input', function() {
            nameManuallyEdited = true;
          });
        }

        function updateCategory(isInitial = false) {
          const cat = categorySelect.value;

          if (cat === 'point_center' || cat === 'point_factory') {
            fromTypeInput.value = 'collection_point';
            fromPointWrap.style.display = '';
            fromCenterWrap.style.display = 'none';
            fromPointSelect.name = 'from_id';
            fromPointSelect.disabled = false;
            fromCenterSelect.removeAttribute('name');
            fromCenterSelect.disabled = true;
          } else {
            fromTypeInput.value = 'collection_center';
            fromPointWrap.style.display = 'none';
            fromCenterWrap.style.display = '';
            fromCenterSelect.name = 'from_id';
            fromCenterSelect.disabled = false;
            fromPointSelect.removeAttribute('name');
            fromPointSelect.disabled = true;
          }

          if (cat === 'point_factory' || cat === 'center_factory') {
            toTypeInput.value = 'factory';
            toFactoryWrap.style.display = '';
            toCenterWrap.style.display = 'none';
            toCenterSelect.removeAttribute('name');
            toCenterSelect.disabled = true;
            toFactoryHidden.name = 'to_id';
            toFactoryHidden.disabled = false;
            toFactoryHidden.value = '';
          } else {
            toTypeInput.value = 'collection_center';
            toFactoryWrap.style.display = 'none';
            toCenterWrap.style.display = '';
            toCenterSelect.name = 'to_id';
            toCenterSelect.disabled = false;
            toFactoryHidden.removeAttribute('name');
            toFactoryHidden.disabled = true;
          }

          if (!isInitial) {
            updateAutoValues();
          }
        }

        function updateAutoValues() {
          const cat = categorySelect.value;
          let fromName = '';
          let toName = '';

          if (cat === 'point_center' || cat === 'point_factory') {
            const opt = fromPointSelect.options[fromPointSelect.selectedIndex];
            if (opt && opt.value) {
              fromName = (opt.dataset.name || opt.text).trim();
              if (cat === 'point_center' && opt.dataset.centerId && !toCenterSelect.value) {
                toCenterSelect.value = opt.dataset.centerId;
              }
              if (opt.dataset.tariff && (!tariffInput.value || form?.dataset.hasRoute !== '1')) {
                tariffInput.value = opt.dataset.tariff;
              }
            }
          } else {
            const opt = fromCenterSelect.options[fromCenterSelect.selectedIndex];
            if (opt && opt.value) {
              fromName = (opt.dataset.name || opt.text).trim();
              if (cat === 'center_factory') {
                if (opt.dataset.distance && (!distanceInput.value || form?.dataset.hasRoute !== '1')) {
                  distanceInput.value = opt.dataset.distance;
                }
                if (opt.dataset.tariff && (!tariffInput.value || form?.dataset.hasRoute !== '1')) {
                  tariffInput.value = opt.dataset.tariff;
                }
              }
            }
          }

          if (cat === 'point_factory' || cat === 'center_factory') {
            toName = 'Factory';
          } else {
            const toOpt = toCenterSelect.options[toCenterSelect.selectedIndex];
            if (toOpt && toOpt.value) {
              toName = (toOpt.dataset.name || toOpt.text).trim();
            }
          }

          if (!nameManuallyEdited && fromName && toName) {
            nameInput.value = `${fromName} → ${toName}`;
          }
        }

        categorySelect.addEventListener('change', () => updateCategory(false));
        fromPointSelect.addEventListener('change', updateAutoValues);
        fromCenterSelect.addEventListener('change', updateAutoValues);
        toCenterSelect.addEventListener('change', updateAutoValues);

        updateCategory(true);
      }

      setupRouteCategoryForm('modal-route');
      @foreach ($routes as $r)
        setupRouteCategoryForm('modal-route-{{ $r->id }}');
      @endforeach
    });
  </script>
@endsection
