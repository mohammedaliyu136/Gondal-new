@extends('layouts.app')
@section('title', 'Requisition Payments & Disbursement')

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <span class="here">Requisition Payments</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Requisition Payments &amp; Disbursement</h1>
      <p>Manage and disburse approved purchase requisitions in batches via payment gateways and bank transfers</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('requisitions.index') }}" class="btn btn-outline">Purchasing Dashboard</a>
      <a href="{{ route('service-providers.index') }}" class="btn btn-outline">&#127970; Service Providers</a>
    </div>
  </div>

  @if (session('success'))
    <div class="alert success mb-16">
      <span>&#9989;</span>
      <div>{{ session('success') }}</div>
    </div>
  @endif

  @if (session('warning'))
    <div class="alert warn mb-16">
      <span>&#9888;</span>
      <div>{{ session('warning') }}</div>
    </div>
  @endif

  @if (session('error'))
    <div class="alert danger mb-16">
      <span>&#9888;</span>
      <div>{{ session('error') }}</div>
    </div>
  @endif

  {{-- Summary Metrics --}}
  <div class="grid grid-4 mb-16">
    <div class="stat blue">
      <div class="stat-label">Total Approved</div>
      <div class="stat-value">{{ \App\Support\Money::compact($stats['total_approved_minor']) }}</div>
      <div class="stat-foot">{{ $stats['count_total'] }} approved requisitions</div>
    </div>
    <div class="stat green">
      <div class="stat-label">Total Disbursed</div>
      <div class="stat-value" style="color:var(--primary-dark)">{{ \App\Support\Money::compact($stats['total_disbursed_minor']) }}</div>
      <div class="stat-foot">{{ \App\Support\Money::format($stats['total_disbursed_minor']) }} settled</div>
    </div>
    <div class="stat amber">
      <div class="stat-label">Remaining Payable</div>
      <div class="stat-value" style="color:#b45309">{{ \App\Support\Money::compact($stats['total_remaining_minor']) }}</div>
      <div class="stat-foot">{{ \App\Support\Money::format($stats['total_remaining_minor']) }} outstanding</div>
    </div>
    <div class="stat">
      <div class="stat-label">Pending Payouts</div>
      <div class="stat-value" style="font-size:1.5rem">
        <span style="color:#dc2626">{{ $stats['count_pending'] }}</span>
        @if ($stats['count_partial'] > 0)
          <span style="font-size:1rem; color:#d97706; font-weight:normal;">+ {{ $stats['count_partial'] }} partial</span>
        @endif
      </div>
      <div class="stat-foot">
        @if ($pendingBatchesCount > 0)
          <strong style="color:#d97706;">{{ $pendingBatchesCount }} batch(es) awaiting OTP</strong>
        @else
          {{ $stats['count_paid'] }} fully settled
        @endif
      </div>
    </div>
  </div>

  {{-- Main Navigation Tabs (Requisitions vs Batches) --}}
  <div class="tabs mb-16" style="display:flex; gap:10px; border-bottom:2px solid #e2e8f0; padding-bottom:4px;">
    <a href="{{ route('requisition-payments.index', ['tab' => 'requisitions']) }}"
       style="font-size:0.95rem; font-weight:700; padding:8px 16px; text-decoration:none; border-bottom:3px solid {{ $activeTab === 'requisitions' ? '#0284c7' : 'transparent' }}; color:{{ $activeTab === 'requisitions' ? '#0284c7' : '#64748b' }};">
      &#128179; Approved Requisitions ({{ $stats['count_total'] }})
    </a>
    <a href="{{ route('requisition-payments.index', ['tab' => 'batches']) }}"
       style="font-size:0.95rem; font-weight:700; padding:8px 16px; text-decoration:none; border-bottom:3px solid {{ $activeTab === 'batches' ? '#0284c7' : 'transparent' }}; color:{{ $activeTab === 'batches' ? '#0284c7' : '#64748b' }}; display:flex; align-items:center; gap:8px;">
      <span>&#128225; Payment Batches ({{ $batches->total() }})</span>
      @if ($pendingBatchesCount > 0)
        <span class="badge warning" style="font-size:0.7rem; padding:2px 6px;">{{ $pendingBatchesCount }} Awaiting OTP</span>
      @endif
    </a>
  </div>

  @if ($activeTab === 'batches')
    {{-- PAYMENT BATCHES VIEW --}}
    <div class="card">
      <div class="card-head">
        <div>
          <h3>Requisition Payment Batches</h3>
          <p>Historical and active gateway / treasury batch payouts</p>
        </div>
      </div>
      <div class="card-body flush">
        @if ($batches->isEmpty())
          <div style="text-align:center; padding: 48px 16px; color:#64748b;">
            <div style="font-size:2.5rem; margin-bottom:8px;">&#128230;</div>
            <h3 style="margin:0 0 4px; color:#1e293b;">No Payment Batches Recorded Yet</h3>
            <p style="margin:0; font-size:0.9rem;">Once you initiate a requisition payout, the batch record will appear here.</p>
          </div>
        @else
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr style="background:#f8fafc;">
                  <th style="width:20%;">Batch Reference</th>
                  <th style="width:14%;">Initiated On</th>
                  <th style="width:14%;">Channel / Gateway</th>
                  <th style="width:12%; text-align:right;">Total Amount</th>
                  <th style="width:10%; text-align:center;">Items</th>
                  <th style="width:14%; text-align:center;">Status</th>
                  <th style="width:16%; text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($batches as $b)
                  <tr>
                    <td>
                      <div style="font-weight:700; font-family:monospace; font-size:0.9rem;">
                        <a href="{{ route('requisition-payments.batch', $b) }}" style="color:#0284c7; text-decoration:none;">
                          {{ $b->batch_reference }}
                        </a>
                      </div>
                      @if ($b->gateway_batch_reference)
                        <div style="font-size:0.75rem; color:#64748b; font-family:monospace;">
                          GW: {{ $b->gateway_batch_reference }}
                        </div>
                      @endif
                    </td>
                    <td>
                      <div style="font-size:0.85rem; color:#1e293b;">
                        {{ $b->disbursed_at ? $b->disbursed_at->format('M d, Y') : '—' }}
                      </div>
                      <div style="font-size:0.75rem; color:#64748b;">
                        By {{ $b->initiatedBy?->name ?? 'System' }}
                      </div>
                    </td>
                    <td>
                      <span class="badge {{ in_array($b->gateway, ['paystack', 'monnify', 'zainpay']) ? 'info' : 'plain' }}">
                        {{ ucfirst(str_replace('_', ' ', $b->gateway)) }}
                      </span>
                    </td>
                    <td style="text-align:right; font-weight:700; color:#1e293b;">
                      {{ \App\Support\Money::format((int) $b->total_amount_minor) }}
                    </td>
                    <td style="text-align:center; font-weight:600;">
                      {{ $b->total_items_count }}
                    </td>
                    <td style="text-align:center;">
                      @if ($b->status === 'completed')
                        <span class="badge success">Completed</span>
                      @elseif (in_array($b->status, ['processing', 'initialized']))
                        <span class="badge warning" style="animation: pulse 2s infinite;">Awaiting OTP</span>
                      @elseif ($b->status === 'failed')
                        <span class="badge danger">Failed</span>
                      @else
                        <span class="badge plain">{{ ucfirst($b->status) }}</span>
                      @endif
                    </td>
                    <td style="text-align:right;">
                      @if (in_array($b->status, ['processing', 'initialized']))
                        <a href="{{ route('requisition-payments.batch', $b) }}#otp-card" class="btn btn-warning btn-xs" style="font-weight:700;">
                          &#9889; Authorize OTP &rarr;
                        </a>
                      @else
                        <a href="{{ route('requisition-payments.batch', $b) }}" class="btn btn-ghost btn-xs">
                          View Receipt &rarr;
                        </a>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div style="padding: 12px 16px;">
            {{ $batches->appends(['tab' => 'batches'])->links() }}
          </div>
        @endif
      </div>
    </div>
  @else
    {{-- APPROVED REQUISITIONS VIEW --}}

    {{-- Sub Status Tabs --}}
    <div class="tabs mb-16" style="display:flex; gap:8px; border-bottom:1px solid #cbd5e1; padding-bottom:8px;">
      <a href="{{ route('requisition-payments.index', array_merge(request()->query(), ['tab' => 'requisitions', 'payment_status' => 'pending', 'page' => 1])) }}"
         class="btn btn-sm {{ $statusFilter === 'pending' ? 'btn-primary' : 'btn-ghost' }}">
        Pending Disbursement ({{ $stats['count_pending'] }})
      </a>
      <a href="{{ route('requisition-payments.index', array_merge(request()->query(), ['tab' => 'requisitions', 'payment_status' => 'partial', 'page' => 1])) }}"
         class="btn btn-sm {{ $statusFilter === 'partial' ? 'btn-primary' : 'btn-ghost' }}">
        Partially Paid ({{ $stats['count_partial'] }})
      </a>
      <a href="{{ route('requisition-payments.index', array_merge(request()->query(), ['tab' => 'requisitions', 'payment_status' => 'paid', 'page' => 1])) }}"
         class="btn btn-sm {{ $statusFilter === 'paid' ? 'btn-primary' : 'btn-ghost' }}">
        Fully Paid ({{ $stats['count_paid'] }})
      </a>
      <a href="{{ route('requisition-payments.index', array_merge(request()->query(), ['tab' => 'requisitions', 'payment_status' => 'all', 'page' => 1])) }}"
         class="btn btn-sm {{ $statusFilter === 'all' ? 'btn-primary' : 'btn-ghost' }}">
        All Approved ({{ $stats['count_total'] }})
      </a>
    </div>

    {{-- Filters Card --}}
    <div class="card mb-16">
      <div class="card-body">
        <form method="GET" action="{{ route('requisition-payments.index') }}" class="form-grid" style="grid-template-columns: 1fr 220px auto; gap: 12px; align-items:end;">
          <input type="hidden" name="tab" value="requisitions" />
          <input type="hidden" name="payment_status" value="{{ $statusFilter }}" />
          <div class="field" style="margin:0;">
            <label for="search">Search Requisitions / Providers</label>
            <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Search by REQ ref, title, requester, vendor name..." class="form-control" />
          </div>
          <div class="field" style="margin:0;">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id" class="form-control">
              <option value="">All Departments</option>
              @foreach ($departments as $dept)
                <option value="{{ $dept->id }}" {{ $selectedDepartment == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-primary">Filter</button>
            @if ($search || $selectedDepartment)
              <a href="{{ route('requisition-payments.index', ['tab' => 'requisitions', 'payment_status' => $statusFilter]) }}" class="btn btn-ghost">Reset</a>
            @endif
          </div>
        </form>
      </div>
    </div>

    {{-- Floating Batch Action Bar --}}
    <div id="batch-action-bar" style="display:none; position:sticky; top:12px; z-index:100; background:#1e293b; color:#fff; padding:12px 20px; border-radius:10px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.3); margin-bottom:16px; align-items:center; justify-content:space-between;">
      <div style="display:flex; align-items:center; gap:16px;">
        <span style="font-size:1.2rem;">&#9889;</span>
        <div>
          <strong id="batch-count-text" style="font-size:1rem; color:#38bdf8;">0 Requisitions Selected</strong>
          <div id="batch-total-text" style="font-size:0.8rem; color:#94a3b8;">Total: ₦0.00</div>
        </div>
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="clearAllSelections()" style="color:#cbd5e1;">Clear</button>
        <button type="button" class="btn btn-primary" onclick="openBatchModal()" style="background:#0284c7; font-weight:700; padding:8px 18px;">
          Process Batch Payment &rarr;
        </button>
      </div>
    </div>

    {{-- Requisitions Table --}}
    <div class="card">
      <div class="card-body flush">
        @if ($requisitions->isEmpty())
          <div style="text-align:center; padding: 48px 16px; color:#64748b;">
            <div style="font-size:2.5rem; margin-bottom:8px;">&#128179;</div>
            <h3 style="margin:0 0 4px; color:#1e293b;">No Approved Requisitions in this View</h3>
            <p style="margin:0; font-size:0.9rem;">
              @if ($statusFilter === 'pending')
                No requisitions are currently waiting for payment disbursement.
              @elseif ($statusFilter === 'partial')
                No partially disbursed requisitions found.
              @elseif ($statusFilter === 'paid')
                No settled requisitions found.
              @else
                Approved requisitions will automatically appear here once their approval workflow completes.
              @endif
            </p>
          </div>
        @else
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr style="background:#f8fafc;">
                  <th style="width:3%; text-align:center;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" style="width:16px; height:16px; cursor:pointer;" />
                  </th>
                  <th style="width:14%;">Requisition</th>
                  <th style="width:14%;">Department &amp; Requester</th>
                  <th style="width:24%;">Assigned Provider &amp; Bank Details</th>
                  <th style="width:12%; text-align:right;">Approved Total</th>
                  <th style="width:11%; text-align:right;">Disbursed</th>
                  <th style="width:11%; text-align:right;">Balance Left</th>
                  <th style="width:11%; text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($requisitions as $req)
                  @php
                    $authMinor = $spendService->authorisedMinor($req);
                    $spentMinor = $spendService->spentMinor($req);
                    $remMinor = $spendService->remainingMinor($req);
                    $sp = $req->serviceProvider;
                    $providerName = $sp?->account_name ?: ($sp?->name ?: ($req->suggested_vendor ?: $req->requester?->name ?: 'Vendor'));
                    $bankName = $sp?->bank_name ?: 'Bank';
                    $bankAccount = $sp?->bank_account ?: '—';
                  @endphp
                  <tr>
                    <td style="text-align:center;">
                      @if ($remMinor > 0 && $canDisburse)
                        <input type="checkbox" class="req-checkbox"
                               value="{{ $req->id }}"
                               data-id="{{ $req->id }}"
                               data-ref="{{ $req->reference }}"
                               data-title="{{ $req->title }}"
                               data-provider="{{ $providerName }}"
                               data-bank="{{ $bankName }}"
                               data-account="{{ $bankAccount }}"
                               data-balance="{{ $remMinor }}"
                               onchange="onCheckboxChanged()"
                               style="width:16px; height:16px; cursor:pointer;" />
                      @else
                        <span class="text-muted" style="font-size:0.8rem;">—</span>
                      @endif
                    </td>
                    <td>
                      <div style="font-weight:700;">
                        <a href="{{ route('requisition-payments.show', $req) }}" style="color:#0284c7; text-decoration:none;">
                          {{ $req->reference }}
                        </a>
                      </div>
                      <div style="font-size:0.8rem; color:#334155; margin-top:2px;">
                        {{ \Illuminate\Support\Str::limit($req->title, 32) }}
                      </div>
                      <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                        Approved {{ $req->decided_at ? $req->decided_at->format('M d, Y') : '' }}
                      </div>
                    </td>
                    <td>
                      <div style="font-weight:600; color:#1e293b;">{{ $req->department?->name ?? 'General' }}</div>
                      <div style="font-size:0.75rem; color:#64748b;">
                        Req: {{ $req->requester?->name ?? 'User' }}
                      </div>
                    </td>
                    <td>
                      @if ($sp)
                        <div style="font-weight:700; color:#0f172a;">{{ $sp->name }}</div>
                        @if ($sp->bank_name || $sp->bank_account)
                          <div style="font-size:0.8rem; color:#0b7d54; font-family:monospace; margin-top:2px;">
                            <strong>{{ $sp->bank_name }}</strong>: {{ $sp->bank_account }}
                          </div>
                          @if ($sp->account_name)
                            <div style="font-size:0.75rem; color:#64748b;">
                              A/C: {{ $sp->account_name }}
                            </div>
                          @endif
                        @else
                          <span class="badge warn plain" style="font-size:0.7rem;">No Bank Account Set</span>
                        @endif
                      @elseif ($req->suggested_vendor)
                        <div style="font-weight:600; color:#334155;">{{ $req->suggested_vendor }}</div>
                        <span class="badge plain" style="font-size:0.7rem;">Suggested Vendor</span>
                      @else
                        <span class="text-muted hint">— No Provider Assigned —</span>
                      @endif
                    </td>
                    <td style="text-align:right; font-weight:700; color:#1e293b;">
                      {{ \App\Support\Money::format($authMinor) }}
                    </td>
                    <td style="text-align:right; color:#059669; font-weight:600;">
                      {{ \App\Support\Money::format($spentMinor) }}
                    </td>
                    <td style="text-align:right;">
                      @if ($remMinor > 0)
                        <strong style="color:#b45309;">{{ \App\Support\Money::format($remMinor) }}</strong>
                      @else
                        <span class="badge success">Fully Settled</span>
                      @endif
                    </td>
                    <td style="text-align:right;">
                      <div style="display:flex; justify-content:flex-end; gap:6px;">
                        @if ($remMinor > 0 && $canDisburse)
                          <button type="button" class="btn btn-primary btn-xs" onclick="paySingleRequisition({{ $req->id }})">
                            Disburse &rarr;
                          </button>
                        @else
                          <a href="{{ route('requisition-payments.show', $req) }}" class="btn btn-ghost btn-xs">
                            View Details
                          </a>
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div style="padding: 12px 16px;">
            {{ $requisitions->appends(['tab' => 'requisitions', 'payment_status' => $statusFilter])->links() }}
          </div>
        @endif
      </div>
    </div>
  @endif

  {{-- Batch Disbursement Modal --}}
  <div class="modal" id="modal-batch-disburse" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.6); overflow-y:auto;">
    <div style="max-width:850px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden;">
      <div style="padding:16px 20px; background:#eff6ff; border-bottom:1px solid #bfdbfe; display:flex; justify-content:space-between; align-items:center;">
        <div>
          <h3 style="margin:0; font-size:1.15rem; color:#1e40af;">
            &#9889; Process Batch Payment Disbursement
          </h3>
          <p style="margin:2px 0 0; font-size:0.8rem; color:#475569;">
            Review payout amounts for each selected requisition before confirming disbursement.
          </p>
        </div>
        <button type="button" onclick="closeBatchModal()" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>

      <form method="POST" action="{{ route('requisition-payments.disburse-batch') }}" style="padding:20px;">
        @csrf

        {{-- Items Mini Table --}}
        <div class="table-wrap mb-16" style="border:1px solid #e2e8f0; border-radius:8px; max-height:300px; overflow-y:auto;">
          <table class="table">
            <thead>
              <tr style="background:#f8fafc; position:sticky; top:0; z-index:2;">
                <th style="width:25%;">Requisition</th>
                <th style="width:30%;">Beneficiary &amp; Account</th>
                <th style="width:20%; text-align:right;">Balance Left</th>
                <th style="width:25%; text-align:right;">Payout Amount (&#8358;)</th>
              </tr>
            </thead>
            <tbody id="batch-modal-items-tbody">
              {{-- Populated dynamically via JS --}}
            </tbody>
          </table>
        </div>

        {{-- Total Calculation Banner --}}
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <div style="font-size:0.8rem; color:#166534; font-weight:600; text-transform:uppercase;">Total Batch Disbursement</div>
            <div style="font-size:0.75rem; color:#475569;" id="batch-modal-summary-count">0 items included</div>
          </div>
          <div id="batch-modal-total-display" style="font-size:1.4rem; font-weight:800; color:#15803d;">
            &#8358;0.00
          </div>
        </div>

        {{-- Payment Channel & Notes --}}
        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
          <div class="field" style="grid-column: span 2;">
            <label for="modal_payment_method" style="font-weight:700;">Payment Channel / Gateway <span class="req">*</span></label>
            <select id="modal_payment_method" name="payment_method" class="form-control" required style="font-weight:600;">
              <option value="bank_transfer">Direct Bank Settlement (Company Treasury / Bulk Transfer)</option>
              @foreach ($gateways as $gKey => $gw)
                @if ($gw['is_enabled'])
                  <option value="{{ $gKey }}">
                    {{ $gw['label'] }} (Online Gateway Bulk Transfer)
                  </option>
                @endif
              @endforeach
              <option value="cash">Petty Cash / Cash Float</option>
            </select>
          </div>

          <div class="field" style="grid-column: span 2;">
            <label for="modal_notes">Batch Narration / Reference Notes (Optional)</label>
            <input type="text" id="modal_notes" name="notes" class="form-control"
                   placeholder="e.g. Weekly approved supplier batch disbursement via Treasury" />
          </div>
        </div>

        <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" style="background:#0284c7; padding:10px 24px; font-weight:700;">
            Confirm &amp; Disburse Batch Payment &rarr;
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let selectedRequisitions = [];

    function formatMoney(amountMinor) {
      return '₦' + (amountMinor / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function onCheckboxChanged() {
      selectedRequisitions = [];
      const checkboxes = document.querySelectorAll('.req-checkbox:checked');
      checkboxes.forEach(cb => {
        selectedRequisitions.push({
          id: cb.dataset.id,
          ref: cb.dataset.ref,
          title: cb.dataset.title,
          provider: cb.dataset.provider,
          bank: cb.dataset.bank,
          account: cb.dataset.account,
          balance: parseInt(cb.dataset.balance, 10),
        });
      });

      updateActionBar();
    }

    function toggleSelectAll(selectAllCheckbox) {
      const checkboxes = document.querySelectorAll('.req-checkbox');
      checkboxes.forEach(cb => {
        cb.checked = selectAllCheckbox.checked;
      });
      onCheckboxChanged();
    }

    function clearAllSelections() {
      const checkboxes = document.querySelectorAll('.req-checkbox');
      checkboxes.forEach(cb => cb.checked = false);
      const selectAll = document.getElementById('select-all-checkbox');
      if (selectAll) selectAll.checked = false;
      onCheckboxChanged();
    }

    function updateActionBar() {
      const actionBar = document.getElementById('batch-action-bar');
      const countText = document.getElementById('batch-count-text');
      const totalText = document.getElementById('batch-total-text');

      if (selectedRequisitions.length > 0) {
        const totalMinor = selectedRequisitions.reduce((sum, item) => sum + item.balance, 0);
        countText.textContent = `${selectedRequisitions.length} Requisition${selectedRequisitions.length > 1 ? 's' : ''} Selected`;
        totalText.textContent = `Total Balance: ${formatMoney(totalMinor)}`;
        actionBar.style.display = 'flex';
      } else {
        actionBar.style.display = 'none';
      }
    }

    function paySingleRequisition(reqId) {
      clearAllSelections();
      const targetCb = document.querySelector(`.req-checkbox[data-id="${reqId}"]`);
      if (targetCb) {
        targetCb.checked = true;
        onCheckboxChanged();
      }
      openBatchModal();
    }

    function openBatchModal() {
      if (selectedRequisitions.length === 0) {
        alert('Please select at least one requisition to disburse payment.');
        return;
      }

      const tbody = document.getElementById('batch-modal-items-tbody');
      tbody.innerHTML = '';

      selectedRequisitions.forEach((item, index) => {
        const maxMajor = (item.balance / 100).toFixed(2);
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>
            <div style="font-weight:700; color:#0284c7;">${item.ref}</div>
            <div style="font-size:0.75rem; color:#475569;">${item.title.substring(0, 30)}</div>
            <input type="hidden" name="items[${index}][requisition_id]" value="${item.id}" />
          </td>
          <td>
            <div style="font-weight:700; color:#0f172a;">${item.provider}</div>
            <div style="font-size:0.75rem; color:#0b7d54; font-family:monospace;">${item.bank}: ${item.account}</div>
          </td>
          <td style="text-align:right; font-weight:600; color:#475569;">
            ${formatMoney(item.balance)}
          </td>
          <td style="text-align:right;">
            <input type="number" step="0.01" min="0.01" max="${maxMajor}"
                   name="items[${index}][amount]"
                   value="${maxMajor}"
                   class="form-control batch-item-amount-input"
                   oninput="recalculateModalTotal()"
                   style="text-align:right; font-weight:700; width:130px; display:inline-block;" />
          </td>
        `;
        tbody.appendChild(tr);
      });

      recalculateModalTotal();
      document.getElementById('modal-batch-disburse').style.display = 'block';
    }

    function recalculateModalTotal() {
      const inputs = document.querySelectorAll('.batch-item-amount-input');
      let totalMajor = 0;
      let count = 0;

      inputs.forEach(input => {
        const val = parseFloat(input.value) || 0;
        totalMajor += val;
        count++;
      });

      const totalMinor = Math.round(totalMajor * 100);
      document.getElementById('batch-modal-total-display').textContent = formatMoney(totalMinor);
      document.getElementById('batch-modal-summary-count').textContent = `${count} requisition payout${count > 1 ? 's' : ''} in this batch`;
    }

    function closeBatchModal() {
      document.getElementById('modal-batch-disburse').style.display = 'none';
    }

    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
    };
  </script>
@endsection
