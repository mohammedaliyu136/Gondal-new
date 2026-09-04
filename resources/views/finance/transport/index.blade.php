@extends('layouts.app')
@section('title', 'Transport Payments')

@section('content')
  <div class="page-head">
    <div>
      <h1>Transport Payments</h1>
      <p>Route fees owed to riders and drivers, and what has been paid</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-new-transport-run" class="btn btn-primary">+ Generate a run</a>@endif
    </div>
  </div>

  @if ($eligibleCount > 0)
    <div class="alert warn mb-16" style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
      <div>
        <strong>{{ number_format($eligibleCount) }} rider(s) and driver(s) have payable wallet balances totaling {{ \App\Support\Money::format($eligibleTotalMinor) }}.</strong>
        <div style="font-size:0.85rem; margin-top:2px;">Earnings are credited directly to rider/driver wallets upon trip completion. Generate a payment run to review, approve, and disburse their funds.</div>
      </div>
      @if ($canCreate)
        <a href="#modal-new-transport-run" class="btn btn-primary" style="white-space:nowrap;">+ Generate a run</a>
      @endif
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>Payment runs</h3><p>Most recent first</p></div></div>
    <div class="card-body flush">
      @if ($runs->isEmpty())
        @include('partials.empty', [
          'title' => 'No transport payment runs yet',
          'message' => 'Generate one for drivers, riders, or all active recipients with positive wallet balance.',
          'icon' => '&#128666;',
        ])
      @else
        <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th>Reference</th><th>Category / Scope</th><th>Period</th>
              <th class="num">Drivers / Riders</th><th class="num">Trips</th><th class="num">Total</th><th>Status</th>
            </tr></thead>
            <tbody>
              @foreach ($runs as $run)
                <tr>
                  <td><a href="{{ route('transport-payments.show', $run) }}" class="perm-key">{{ $run->reference }}</a></td>
                  <td>
                    @if ($run->scope_type === 'network')
                      All (Network)
                    @elseif ($run->scope_type === 'driver')
                      Drivers only
                    @elseif ($run->scope_type === 'rider')
                      Riders only
                    @elseif ($run->scope_type === 'individual')
                      Selected individuals
                    @else
                      {{ $centers->firstWhere('id', $run->scope_id)?->name ?? 'Centre #'.$run->scope_id }}
                    @endif
                  </td>
                  <td>{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</td>
                  <td class="num">{{ number_format($run->driver_count) }}</td>
                  <td class="num">{{ number_format($run->trip_count) }}</td>
                  <td class="num font-bold">{{ \App\Support\Money::format($run->total_minor) }}</td>
                  <td><span class="badge {{ $run->status === 'paid' ? 'success' : ($run->status === 'cancelled' ? 'muted' : 'warning') }}">
                    {{ \Illuminate\Support\Str::headline($run->status) }}</span></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
    @if ($runs->hasPages())<div class="card-body">{{ $runs->links() }}</div>@endif
  </div>

  @if ($canCreate)
    <div id="modal-new-transport-run" class="modal @if (old('_modal') === 'modal-new-transport-run') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog" style="max-width:680px;">
        <div class="modal-head">
          <div>
            <h3>Generate a transport payment run</h3>
            <p>Disburse positive wallet balances to riders and drivers</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('transport-payments.store') }}" id="form-new-transport-run">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-transport-run" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-transport-run'])

            {{-- 1. Categorization Selector --}}
            <div style="margin-bottom:16px;">
              <label style="display:block; font-weight:700; font-size:0.88rem; margin-bottom:8px; color:var(--text-bright);">
                Categorization / Recipients to include:
              </label>
              <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:8px;" id="cat-selector">
                <label class="cat-pill-label" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border, #d9e2e8); border-radius:8px; cursor:pointer; background:var(--surface, #fff); transition:all 0.15s ease;">
                  <input type="radio" name="recipient_type" value="all" checked style="margin:0;" />
                  <div>
                    <div style="font-weight:700; font-size:0.85rem;">All</div>
                    <div style="font-size:0.75rem; color:var(--muted, #6b7c86);">{{ $eligibleCount }} recipient(s)</div>
                  </div>
                </label>

                <label class="cat-pill-label" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border, #d9e2e8); border-radius:8px; cursor:pointer; background:var(--surface, #fff); transition:all 0.15s ease;">
                  <input type="radio" name="recipient_type" value="driver" @checked(old('recipient_type') === 'driver') style="margin:0;" />
                  <div>
                    <div style="font-weight:700; font-size:0.85rem;">Drivers only</div>
                    <div style="font-size:0.75rem; color:var(--muted, #6b7c86);">{{ $driversCount }} driver(s)</div>
                  </div>
                </label>

                <label class="cat-pill-label" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border, #d9e2e8); border-radius:8px; cursor:pointer; background:var(--surface, #fff); transition:all 0.15s ease;">
                  <input type="radio" name="recipient_type" value="rider" @checked(old('recipient_type') === 'rider') style="margin:0;" />
                  <div>
                    <div style="font-weight:700; font-size:0.85rem;">Riders only</div>
                    <div style="font-size:0.75rem; color:var(--muted, #6b7c86);">{{ $ridersCount }} rider(s)</div>
                  </div>
                </label>

                <label class="cat-pill-label" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border, #d9e2e8); border-radius:8px; cursor:pointer; background:var(--surface, #fff); transition:all 0.15s ease;">
                  <input type="radio" name="recipient_type" value="individual" @checked(old('recipient_type') === 'individual') style="margin:0;" />
                  <div>
                    <div style="font-weight:700; font-size:0.85rem;">Select individual</div>
                    <div style="font-size:0.75rem; color:var(--muted, #6b7c86);">Pick specific</div>
                  </div>
                </label>
              </div>
            </div>

            {{-- 2. Recipient Checklist (shows recipients with positive wallet balance) --}}
            <div style="margin-bottom:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-weight:700; font-size:0.85rem; color:var(--text-bright);">Eligible Recipients (Positive Wallet Balance)</span>
                <div style="display:flex; gap:8px;">
                  <button type="button" id="btn-select-all" class="btn btn-ghost btn-sm" style="font-size:0.75rem; padding:2px 8px;">Select all</button>
                  <button type="button" id="btn-deselect-all" class="btn btn-ghost btn-sm" style="font-size:0.75rem; padding:2px 8px;">Deselect all</button>
                </div>
              </div>

              <div id="recipient-list-container" style="max-height:220px; overflow-y:auto; border:1px solid var(--border, #d9e2e8); border-radius:8px; background:var(--surface, #fff);">
                @if ($eligibleRecipients->isEmpty())
                  <div style="padding:24px; text-align:center; color:var(--muted, #6b7c86); font-size:0.88rem;">
                    &#128666; No riders or drivers currently have a positive wallet balance.
                  </div>
                @else
                  <table class="table" style="margin:0; font-size:0.84rem;">
                    <tbody>
                      @foreach ($eligibleRecipients as $item)
                        @php
                          $d = $item['driver'];
                          $bal = $item['available_minor'];
                        @endphp
                        <tr class="recipient-row" data-type="{{ $d->type }}" data-amount="{{ $bal }}">
                          <td style="width:36px; text-align:center; padding:8px 10px;">
                            <input type="checkbox" name="driver_ids[]" value="{{ $d->id }}" class="recipient-check"
                                   @checked(in_array($d->id, (array) old('driver_ids', $eligibleRecipients->pluck('driver.id')->all()))) />
                          </td>
                          <td style="padding:8px 10px;">
                            <div style="font-weight:700; color:var(--text-bright);">{{ $d->name }}</div>
                            <small class="hint" style="font-size:0.75rem;">{{ $d->phone ?? 'No phone' }}</small>
                          </td>
                          <td style="padding:8px 10px; width:90px;">
                            <span class="badge {{ $d->type === 'driver' ? 'blue' : 'success' }}" style="font-size:0.72rem;">
                              {{ ucfirst($d->type) }}
                            </span>
                          </td>
                          <td class="num font-bold" style="padding:8px 10px; width:130px; text-align:right; color:#0b7d54;">
                            {{ \App\Support\Money::format($bal) }}
                          </td>
                        </tr>
                      @endforeach
                    </tbody>
                  </table>
                @endif
              </div>
            </div>

            {{-- 3. Live Calculation Summary Card --}}
            <div style="background:var(--surface-alt, #f4f7f9); border:1px solid var(--border, #d9e2e8); border-radius:8px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center;">
              <div>
                <span style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--muted, #6b7c86); display:block;">Selected for Payout</span>
                <div id="summary-recipients-count" style="font-size:1rem; font-weight:700; color:var(--text-bright);">0 recipients</div>
              </div>
              <div style="text-align:right;">
                <span style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--muted, #6b7c86); display:block;">Total Payout Amount</span>
                <div id="summary-payout-total" style="font-size:1.15rem; font-weight:800; color:#0b7d54;">₦0.00</div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" id="btn-submit-run" @disabled($eligibleCount === 0)>Generate Run</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const radioButtons = document.querySelectorAll('input[name="recipient_type"]');
        const rows = document.querySelectorAll('.recipient-row');
        const checks = document.querySelectorAll('.recipient-check');
        const btnSelectAll = document.getElementById('btn-select-all');
        const btnDeselectAll = document.getElementById('btn-deselect-all');
        const countEl = document.getElementById('summary-recipients-count');
        const totalEl = document.getElementById('summary-payout-total');
        const submitBtn = document.getElementById('btn-submit-run');

        function formatNaira(kobo) {
          const naira = kobo / 100;
          return '₦' + naira.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function updateSummary() {
          let count = 0;
          let totalMinor = 0;

          checks.forEach(chk => {
            const row = chk.closest('tr');
            if (chk.checked && (!row || row.style.display !== 'none')) {
              count++;
              totalMinor += parseInt(row.dataset.amount || '0', 10);
            }
          });

          if (countEl) countEl.textContent = count + ' recipient' + (count === 1 ? '' : 's');
          if (totalEl) totalEl.textContent = formatNaira(totalMinor);
          if (submitBtn) {
            const selectedType = document.querySelector('input[name="recipient_type"]:checked')?.value;
            if (selectedType === 'individual') {
              submitBtn.disabled = count === 0;
            } else {
              submitBtn.disabled = count === 0 && totalMinor === 0;
            }
          }
        }

        function applyFilter(type) {
          rows.forEach(row => {
            const rowType = row.dataset.type;
            const chk = row.querySelector('.recipient-check');

            if (type === 'all') {
              row.style.display = '';
              if (chk) chk.checked = true;
            } else if (type === 'driver') {
              const matches = rowType === 'driver';
              row.style.display = matches ? '' : 'none';
              if (chk) chk.checked = matches;
            } else if (type === 'rider') {
              const matches = rowType === 'rider';
              row.style.display = matches ? '' : 'none';
              if (chk) chk.checked = matches;
            } else if (type === 'individual') {
              row.style.display = '';
              // keep current check selections
            }
          });

          updateSummary();
        }

        radioButtons.forEach(radio => {
          radio.addEventListener('change', function() {
            applyFilter(this.value);
          });
        });

        checks.forEach(chk => {
          chk.addEventListener('change', function() {
            const activeRadio = document.querySelector('input[name="recipient_type"]:checked')?.value;
            if (activeRadio !== 'individual') {
              const indRadio = document.querySelector('input[name="recipient_type"][value="individual"]');
              if (indRadio) indRadio.checked = true;
            }
            updateSummary();
          });
        });

        if (btnSelectAll) {
          btnSelectAll.addEventListener('click', function(e) {
            e.preventDefault();
            checks.forEach(chk => {
              const row = chk.closest('tr');
              if (!row || row.style.display !== 'none') {
                chk.checked = true;
              }
            });
            const indRadio = document.querySelector('input[name="recipient_type"][value="individual"]');
            if (indRadio) indRadio.checked = true;
            updateSummary();
          });
        }

        if (btnDeselectAll) {
          btnDeselectAll.addEventListener('click', function(e) {
            e.preventDefault();
            checks.forEach(chk => {
              chk.checked = false;
            });
            const indRadio = document.querySelector('input[name="recipient_type"][value="individual"]');
            if (indRadio) indRadio.checked = true;
            updateSummary();
          });
        }

        // Initialize
        const initialType = document.querySelector('input[name="recipient_type"]:checked')?.value || 'all';
        applyFilter(initialType);
      });
    </script>
  @endif
@endsection
