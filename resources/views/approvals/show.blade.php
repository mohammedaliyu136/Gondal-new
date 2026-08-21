@extends('layouts.app')
@section('title', 'Review Approval — ' . ($subject?->reference ?? class_basename($instance->subject_type) . ' #' . $instance->subject_id))

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('approvals.index') }}">My Approvals</a><span class="sep">/</span>
    <span class="here">{{ $subject?->reference ?? 'Approval #' . $instance->id }}</span>
  </div>

  <div class="detail-head mb-16">
    <div class="avatar-lg" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:1.5rem; display:flex; align-items:center; justify-content:center; border-radius:12px; min-width:56px; height:56px;">
      @if ($subject instanceof \App\Models\Requisition)
        &#128221;
      @elseif ($subject instanceof \App\Models\LeaveRequest)
        &#128197;
      @elseif ($subject instanceof \App\Models\PayrollRun)
        &#128188;
      @elseif ($subject instanceof \App\Models\PaymentRun)
        &#128176;
      @elseif ($subject instanceof \App\Models\TransportPaymentRun)
        &#128666;
      @elseif ($subject instanceof \App\Models\Batch)
        &#127869;
      @else
        &#9989;
      @endif
    </div>

    <div class="dh-main">
      <h1>
        @if ($subject instanceof \App\Contracts\WorkflowSubjectInterface)
          {{ $subject->getApprovalReference() }}
        @elseif ($subject?->reference)
          {{ $subject->reference }}
        @elseif ($subject instanceof \App\Models\LeaveRequest)
          Leave Request #{{ $subject->id }}
        @elseif ($subject instanceof \App\Models\PayrollRun)
          Payroll &mdash; {{ $subject->periodLabel() }}
        @else
          {{ class_basename($instance->subject_type) }} #{{ $instance->subject_id }}
        @endif

        @if ($subject instanceof \App\Models\Requisition && $subject->title)
          <span style="font-size:1.1rem; font-weight:500; color:#475569; margin-left:8px;">&middot; {{ $subject->title }}</span>
        @elseif ($subject instanceof \App\Models\LeaveRequest && $subject->employee)
          <span style="font-size:1.1rem; font-weight:500; color:#475569; margin-left:8px;">&middot; {{ $subject->employee->name }} ({{ $subject->leaveType?->name }})</span>
        @endif
      </h1>

      <div class="dh-sub">
        <strong>Workflow:</strong> {{ $instance->workflow->name }} ({{ $instance->workflow->code }})
        &nbsp;&bull;&nbsp; <strong>Requester:</strong> {{ $instance->requester?->name ?? 'System' }}
        &nbsp;&bull;&nbsp; <strong>Submitted:</strong> {{ \App\Support\Wat::dateTime($instance->started_at) }}
      </div>

      <div class="dh-tags">
        <span class="badge {{ [
          'in_progress' => 'warning',
          'approved' => 'success',
          'rejected' => 'danger',
          'cancelled' => 'muted',
        ][$instance->status] ?? 'info' }}">
          {{ \Illuminate\Support\Str::headline($instance->status) }}
        </span>
        @if ($instance->band)
          <span class="pill">{{ $instance->band->name }} band</span>
        @endif
        @if ($instance->current_stage_due_at && $instance->isOpen())
          @if ($instance->isOverdue())
            <span class="badge danger">&#9888; Overdue (Due: {{ \App\Support\Wat::dateTime($instance->current_stage_due_at) }})</span>
          @else
            <span class="badge success">&#9203; {{ $instance->hoursRemaining() }}h SLA remaining</span>
          @endif
        @endif
      </div>
    </div>

    <div class="dh-actions">
      <a href="{{ route('approvals.index') }}" class="btn btn-outline">&larr; Back to Queue</a>
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

  {{-- Sequential Workflow Stage Progress Bar --}}
  <div class="card mb-16">
    <div class="card-head">
      <div>
        <h3 style="margin:0;">Approval Sequence &amp; Chain</h3>
        <p style="margin:0; font-size:0.85rem;">Stage {{ $instance->stageNumber() }} of {{ $instance->stageCount() }} &middot; Current Active Stage: <strong style="color:#0f172a;">{{ $instance->currentStage?->name ?? 'Completed' }}</strong></p>
      </div>
    </div>
    <div class="card-body">
      <div class="flow" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
        @foreach ($applicableStages as $s)
          @php($isPast = $applicableStages->search(fn ($item) => $item->id === $instance->current_stage_id) > $loop->index || $instance->status === 'approved')
          @php($isCurrent = $s->id === $instance->current_stage_id && $instance->isOpen())
          <div class="step {{ $isPast ? 'done' : ($isCurrent ? 'current' : '') }}" style="display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:6px; font-size:0.85rem; {{ $isCurrent ? 'background:#eff6ff; border:1px solid #3b82f6;' : ($isPast ? 'background:#f0fdf4; border:1px solid #bbf7d0;' : 'background:#f8fafc; border:1px solid #e2e8f0;') }}">
            <span class="step-num" style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; font-size:0.75rem; font-weight:700; {{ $isCurrent ? 'background:#2563eb; color:#fff;' : ($isPast ? 'background:#16a34a; color:#fff;' : 'background:#cbd5e1; color:#475569;') }}">
              {{ $isPast ? '✓' : $loop->iteration }}
            </span>
            <span>
              <strong>{{ $s->name }}</strong>
              @if ($s->approvingRole)
                <small class="cell-sub" style="display:block; font-size:0.75rem; color:#64748b;">({{ $s->approvingRole->name }})</small>
              @endif
            </span>
          </div>
          @if (! $loop->last)
            <span class="arrow" style="color:#94a3b8; font-size:1.2rem;">&rsaquo;</span>
          @endif
        @endforeach
      </div>
    </div>
  </div>

  <div class="split">
    {{-- Left / Main Column: Details of what is being approved --}}
    <div class="stack">
      
      {{-- 1. REQUISITION DETAILS --}}
      @if ($subject instanceof \App\Models\Requisition)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Requisition Details &amp; Line Items</h3>
              <p>{{ $subject->title }} &middot; Needed by: {{ $subject->needed_by?->toDateString() ?? 'Not specified' }}</p>
            </div>
            <div class="num font-bold" style="font-size:1.2rem; color:#0b7d54;">
              Total: {{ \App\Support\Money::format($subject->total_minor) }}
            </div>
          </div>
          <div class="card-body">
            <div class="meta-grid cols-2 mb-16" style="background:#f8fafc; padding:14px; border-radius:8px; border:1px solid #e2e8f0;">
              <div class="meta-item">
                <div class="meta-label">Department</div>
                <div class="meta-value font-bold">{{ $subject->department?->name ?? '—' }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Category</div>
                <div class="meta-value">{{ \Illuminate\Support\Str::headline($subject->category ?? 'General') }}</div>
              </div>
              <div class="meta-item" style="grid-column: span 2;">
                <div class="meta-label">Business Justification / Purpose</div>
                <div class="meta-value" style="font-style:italic; color:#334155; margin-top:2px;">{{ $subject->justification ?: 'No justification recorded.' }}</div>
              </div>
              @if ($subject->serviceProvider)
                <div class="meta-item" style="grid-column: span 2;">
                  <div class="meta-label">Assigned Vendor / Service Provider</div>
                  <div class="meta-value font-bold" style="color:#1e40af; margin-top:2px;">
                    &#127970; {{ $subject->serviceProvider->name }}
                    @if ($subject->serviceProvider->bank_account)
                      <span class="cell-sub" style="font-weight:normal; margin-left:6px; color:#475569;">
                        ({{ $subject->serviceProvider->bank_name }} - {{ $subject->serviceProvider->bank_account }})
                      </span>
                    @endif
                  </div>
                </div>
              @endif
            </div>

            <h4 style="margin:16px 0 8px; font-size:0.95rem; color:#0f172a;">Line Items Breakdown ({{ $subject->items->count() }})</h4>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr style="background:#f1f5f9;">
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">Item Description</th>
                    <th class="num" style="width:12%;">Qty</th>
                    <th style="width:10%;">Unit</th>
                    <th class="num" style="width:15%;">Unit Price</th>
                    <th class="num" style="width:18%;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($subject->items as $item)
                    <tr>
                      <td>{{ $loop->iteration }}</td>
                      <td>
                        <div style="font-weight:600; color:#0f172a;">{{ $item->item ?? $item->description }}</div>
                        @if ($item->purpose || $item->remarks)
                          <div class="cell-sub">{{ $item->purpose ?: $item->remarks }}</div>
                        @endif
                      </td>
                      <td class="num">{{ number_format($item->quantity, 2) }}</td>
                      <td>{{ $item->unit ?: 'units' }}</td>
                      <td class="num">{{ \App\Support\Money::format($item->unit_price_minor ?? $item->estimated_unit_price_minor) }}</td>
                      <td class="num font-bold">{{ \App\Support\Money::format($item->amount_minor ?? $item->estimated_total_minor) }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="6" class="text-muted">No line items in this requisition.</td></tr>
                  @endforelse
                </tbody>
                <tfoot>
                  <tr style="background:#f8fafc; font-weight:bold;">
                    <td colspan="5" style="text-align:right;">Grand Total:</td>
                    <td class="num" style="color:#0b7d54; font-size:1.05rem;">
                      {{ \App\Support\Money::format($subject->total_minor) }}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>

            @if ($subject->attachments && $subject->attachments->isNotEmpty())
              <h4 style="margin:20px 0 8px; font-size:0.95rem; color:#0f172a;">Attached Quotations &amp; Documents</h4>
              <div class="meta-grid cols-2">
                @foreach ($subject->attachments as $att)
                  <div class="meta-item" style="display:flex; align-items:center; gap:8px;">
                    <span>&#128206;</span>
                    <a href="{{ Storage::url($att->file_path) }}" target="_blank" class="text-primary font-bold">
                      {{ $att->file_name ?? 'Attachment #' . $att->id }}
                    </a>
                  </div>
                @endforeach
              </div>
            @endif
          </div>
        </div>

      {{-- 2. LEAVE REQUEST DETAILS --}}
      @elseif ($subject instanceof \App\Models\LeaveRequest)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Leave Request Application</h3>
              <p>{{ $subject->employee?->name }} &middot; {{ $subject->employee?->department?->name }}</p>
            </div>
            <span class="badge info font-bold">{{ $subject->days }} Days Requested</span>
          </div>
          <div class="card-body">
            <div class="meta-grid cols-2">
              <div class="meta-item">
                <div class="meta-label">Employee</div>
                <div class="meta-value font-bold">{{ $subject->employee?->name }} ({{ $subject->employee?->code }})</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Leave Type</div>
                <div class="meta-value font-bold" style="color:#1e40af;">{{ $subject->leaveType?->name }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Start Date</div>
                <div class="meta-value font-bold">{{ \App\Support\Wat::date($subject->starts_on) }}</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">End Date (Resumption)</div>
                <div class="meta-value font-bold">{{ \App\Support\Wat::date($subject->ends_on) }}</div>
              </div>
              <div class="meta-item" style="grid-column:span 2;">
                <div class="meta-label">Reason / Notes</div>
                <div class="meta-value" style="font-style:italic; margin-top:2px;">{{ $subject->reason ?: 'No notes provided.' }}</div>
              </div>
            </div>
          </div>
        </div>

      {{-- 3. PAYROLL RUN DETAILS --}}
      @elseif ($subject instanceof \App\Models\PayrollRun)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Payroll Run &mdash; {{ $subject->periodLabel() }}</h3>
              <p>Monthly staff salary &amp; allowances register</p>
            </div>
            <div class="num font-bold" style="color:#0b7d54; font-size:1.2rem;">
              Net: {{ \App\Support\Money::format($subject->net_total_minor) }}
            </div>
          </div>
          <div class="card-body">
            <div class="grid grid-3 mb-16">
              <div class="stat blue"><div class="stat-label">Employees</div><div class="stat-value">{{ $subject->employee_count }}</div></div>
              <div class="stat green"><div class="stat-label">Gross</div><div class="stat-value">{{ \App\Support\Money::compact($subject->gross_total_minor) }}</div></div>
              <div class="stat red"><div class="stat-label">Total Deductions</div><div class="stat-value">{{ \App\Support\Money::compact($subject->deductions_total_minor) }}</div></div>
            </div>
            <a href="{{ route('payroll.index') }}" class="btn btn-outline btn-sm">
              Open Full Payroll Register &rarr;
            </a>
          </div>
        </div>

      {{-- 4. FARMER PAYMENT RUN DETAILS --}}
      @elseif ($subject instanceof \App\Models\PaymentRun)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Farmer Milk Payment Run</h3>
              <p>{{ $subject->scope_type === 'collection_center' ? 'Center: ' : 'Cooperative: ' }} {{ $subject->scope?->name ?? 'Scope #' . $subject->scope_id }}</p>
            </div>
            <div class="num font-bold" style="color:#0b7d54; font-size:1.2rem;">
              Cash Required: {{ \App\Support\Money::format($subject->cash_required_minor) }}
            </div>
          </div>
          <div class="card-body">
            <div class="grid grid-4 mb-16">
              <div class="stat blue"><div class="stat-label">Farmers</div><div class="stat-value">{{ $subject->farmer_count }}</div></div>
              <div class="stat green"><div class="stat-label">Gross Milk Value</div><div class="stat-value">{{ \App\Support\Money::compact($subject->gross_total_minor) }}</div></div>
              <div class="stat red"><div class="stat-label">Total Deductions</div><div class="stat-value">{{ \App\Support\Money::compact($subject->deductions_total_minor) }}</div></div>
              <div class="stat amber"><div class="stat-label">Held (Unvalidated)</div><div class="stat-value">{{ \App\Support\Money::compact($subject->held_net_minor) }}</div></div>
            </div>
            <a href="{{ route('payment-runs.show', $subject) }}" class="btn btn-outline btn-sm" target="_blank">
              Open Detailed Payment Run Sheet &rarr;
            </a>
          </div>
        </div>

      {{-- 5. TRANSPORT PAYMENT RUN DETAILS --}}
      @elseif ($subject instanceof \App\Models\TransportPaymentRun)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Transport Logistics Payment Run</h3>
              <p>Period: {{ $subject->period_start?->toDateString() }} &rarr; {{ $subject->period_end?->toDateString() }}</p>
            </div>
            <div class="num font-bold" style="color:#0b7d54; font-size:1.2rem;">
              Total: {{ \App\Support\Money::format($subject->cash_required_minor) }}
            </div>
          </div>
          <div class="card-body">
            <div class="grid grid-3 mb-16">
              <div class="stat blue"><div class="stat-label">Transporters</div><div class="stat-value">{{ $subject->transporter_count }}</div></div>
              <div class="stat green"><div class="stat-label">Trips Covered</div><div class="stat-value">{{ $subject->trip_count }}</div></div>
              <div class="stat amber"><div class="stat-label">Total Tariff</div><div class="stat-value">{{ \App\Support\Money::format($subject->gross_total_minor) }}</div></div>
            </div>
            <a href="{{ route('transport-payments.show', $subject) }}" class="btn btn-outline btn-sm" target="_blank">
              Open Transport Run Details &rarr;
            </a>
          </div>
        </div>

      {{-- 6. BATCH DISCREPANCY DETAILS --}}
      @elseif ($subject instanceof \App\Models\Batch)
        <div class="card">
          <div class="card-head">
            <div>
              <h3>Milk Batch Intake Discrepancy</h3>
              <p>Center: {{ $subject->collectionCenter?->name }} &middot; Batch: {{ $subject->reference }}</p>
            </div>
            <span class="badge danger">{{ $subject->discrepancyPercentage() }}% Variance</span>
          </div>
          <div class="card-body">
            <div class="grid grid-3 mb-16">
              <div class="stat blue"><div class="stat-label">Dispatched</div><div class="stat-value">{{ \App\Support\Volume::format($subject->litres_dispatched) }}</div></div>
              <div class="stat amber"><div class="stat-label">Received at Factory</div><div class="stat-value">{{ \App\Support\Volume::format($subject->litres_received) }}</div></div>
              <div class="stat red"><div class="stat-label">Discrepancy (Shortfall)</div><div class="stat-value">{{ $subject->discrepancy_litres }} L</div></div>
            </div>
            <div class="meta-grid cols-2">
              <div class="meta-item"><div class="meta-label">Reported Cause</div><div class="meta-value font-bold">{{ $subject->discrepancyCause?->name ?? 'Unspecified' }}</div></div>
              <div class="meta-item"><div class="meta-label">Tolerance Configured</div><div class="meta-value">{{ $subject->tolerancePercentage() }}%</div></div>
              <div class="meta-item" style="grid-column:span 2;"><div class="meta-label">Supervisor Notes</div><div class="meta-value" style="font-style:italic;">{{ $subject->supervisor_notes ?: 'None' }}</div></div>
            </div>
          </div>
        </div>

      {{-- 7. GENERIC SUBJECT FALLBACK --}}
      @else
        <div class="card">
          <div class="card-head"><div><h3>Subject Details</h3><p>{{ class_basename($instance->subject_type) }} #{{ $instance->subject_id }}</p></div></div>
          <div class="card-body">
            <div class="meta-grid cols-2">
              <div class="meta-item"><div class="meta-label">Subject Type</div><div class="meta-value mono">{{ class_basename($instance->subject_type) }}</div></div>
              <div class="meta-item"><div class="meta-label">Amount / Value</div><div class="meta-value font-bold">{{ \App\Support\Money::format($instance->amount_minor) }}</div></div>
            </div>
          </div>
        </div>
      @endif

      {{-- Approval History & Decision Trail --}}
      <div class="card">
        <div class="card-head">
          <div>
            <h3>Approval Action History &amp; Decision Trail</h3>
            <p>Chronological record of reviews, comments and decisions</p>
          </div>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr style="background:#f8fafc;">
                  <th>Date &amp; Time</th>
                  <th>Stage</th>
                  <th>Action / Decision</th>
                  <th>Actor / Approver</th>
                  <th>Comments &amp; Stage Details</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($instance->actions as $action)
                  <tr>
                    <td style="white-space:nowrap; font-size:0.85rem;">
                      {{ \App\Support\Wat::dateTime($action->created_at) }}
                    </td>
                    <td>
                      <strong>{{ $action->stage?->name ?? 'Submission' }}</strong>
                      @if ($action->stage?->approvingRole)
                        <div class="cell-sub">{{ $action->stage->approvingRole->name }}</div>
                      @endif
                    </td>
                    <td>
                      <span class="badge {{ [
                        'approve' => 'success',
                        'reject' => 'danger',
                        'request_info' => 'warning',
                        'submit' => 'info',
                      ][$action->action] ?? 'muted' }}">
                        {{ ucfirst(str_replace('_', ' ', $action->action)) }}
                      </span>
                    </td>
                    <td>
                      <div style="font-weight:600; color:#0f172a;">{{ $action->actor?->name ?? ($action->user?->name ?? 'System') }}</div>
                      @if ($action->on_behalf_of_user_id)
                        <div class="cell-sub" style="color:#d97706;">(Covering for {{ $action->onBehalfOf?->name }})</div>
                      @endif
                    </td>
                    <td style="font-size:0.85rem;">
                      @if ($action->comment)
                        <div style="font-style:italic; color:#334155;">&ldquo;{{ $action->comment }}&rdquo;</div>
                      @endif
                      @if ($action->action_payload)
                        <div class="cell-sub" style="margin-top:2px;">
                          @if (isset($action->action_payload['service_provider_id']))
                            &#127970; Provider: <strong>{{ \App\Models\ServiceProvider::find($action->action_payload['service_provider_id'])?->name }}</strong>
                          @endif
                          @if (isset($action->action_payload['adjusted_items']))
                            &#128221; Adjusted {{ count($action->action_payload['adjusted_items']) }} item pricing line(s)
                          @endif
                        </div>
                      @endif
                      @if (!$action->comment && !$action->action_payload)
                        <span class="text-muted">—</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5" class="text-muted">No actions recorded yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    {{-- Right / Sidebar Column: Decision "Act" Card --}}
    <div class="stack" style="position:sticky; top:16px;">
      @if ($canAct)
        <div class="card" style="border: 2px solid #2563eb; box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2); overflow:hidden;">
          <div class="card-head" style="background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%); color:#fff; padding:16px 20px;">
            <div>
              <h3 style="color:#fff; margin:0; font-size:1.15rem; display:flex; align-items:center; gap:8px;">
                <span>&#9989;</span> Act on this Request
              </h3>
              <p style="color:#bfdbfe; margin:4px 0 0; font-size:0.85rem;">
                Stage: <strong>{{ $stage->name }}</strong> ({{ $stage->approvingRole?->name ?? 'Approver' }})
              </p>
            </div>
            @if ($instance->current_stage_due_at)
              <span class="badge {{ $instance->isOverdue() ? 'danger' : 'success' }}" style="font-size:0.75rem; background:#fff; color: {{ $instance->isOverdue() ? '#dc2626' : '#16a34a' }};">
                {{ $instance->isOverdue() ? 'Overdue' : $instance->hoursRemaining() . 'h SLA' }}
              </span>
            @endif
          </div>
          <div class="card-body" style="padding:20px;">
            @include('partials.modal-errors', ['modal' => 'approval-main-form'])

            {{-- Main Approval Form --}}
            <form method="POST" action="{{ route('approvals.approve', $instance) }}" id="approval-act-form">
              @csrf

              {{-- Dynamic Stage Action Form --}}
              @if ($stageActionHtml)
                <div class="stage-action-box mb-16" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px;">
                  {!! $stageActionHtml !!}
                </div>
              @endif

              {{-- Reduce Amount Option --}}
              @if ($instance->workflow->option('approver_may_reduce_amount') && $instance->amount_minor > 0)
                <div class="field mb-16">
                  <label for="approved_amount" style="font-weight:600; color:#1e293b;">Approved Amount (₦) <small class="hint">(May reduce, cannot increase)</small></label>
                  <input type="text" id="approved_amount" name="approved_amount"
                         value="{{ old('approved_amount', number_format(($instance->approved_amount_minor ?: $instance->amount_minor) / 100, 2, '.', '')) }}"
                         class="form-control font-bold" style="font-size:1.05rem; color:#0b7d54;" />
                </div>
              @endif

              <div class="field mb-16">
                <label for="approve_comment" style="font-weight:600; color:#1e293b;">Approval Decision Notes / Remarks</label>
                <textarea id="approve_comment" name="comment" rows="3" class="form-control" placeholder="Optional comments recorded in audit trail...">{{ old('comment') }}</textarea>
              </div>

              <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="submit" class="btn btn-primary" style="padding:12px; font-size:1rem; font-weight:700; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; background:#0b7d54; border-color:#0b7d54;">
                  <span>&#10003;</span> Approve Request
                </button>
              </div>
            </form>

            <div style="border-top:1px solid #e2e8f0; margin-top:16px; padding-top:14px; display:flex; gap:10px;">
              <button type="button" onclick="document.getElementById('modal-reject').style.display='block'" class="btn btn-outline danger btn-sm" style="flex:1; font-weight:600;">
                &#10007; Reject &amp; Return
              </button>
              <button type="button" onclick="document.getElementById('modal-request-info').style.display='block'" class="btn btn-outline btn-sm" style="flex:1; font-weight:600;">
                ? Request Info
              </button>
            </div>
          </div>
        </div>
      @else
        <div class="card">
          <div class="card-head"><div><h3>Status Overview</h3></div></div>
          <div class="card-body">
            @if ($instance->isOpen())
              <div class="alert info" style="margin:0;">
                <span>&#9203;</span>
                <div>
                  <strong>Currently at Stage {{ $instance->stageNumber() }}: {{ $instance->currentStage?->name }}</strong>
                  <div style="margin-top:4px; font-size:0.8rem; color:#475569;">
                    Awaiting decision by <strong>{{ $instance->currentStage?->approvingRole?->name ?? 'designated role' }}</strong>.
                  </div>
                </div>
              </div>
            @else
              <div class="alert {{ $instance->status === 'approved' ? 'success' : 'warn' }}" style="margin:0;">
                <span>{{ $instance->status === 'approved' ? '&#10003;' : '&#9888;' }}</span>
                <div>
                  <strong>Workflow is {{ ucfirst($instance->status) }}</strong>
                  <div style="margin-top:4px; font-size:0.8rem;">
                    Completed on {{ \App\Support\Wat::dateTime($instance->completed_at ?? $instance->updated_at) }}.
                  </div>
                </div>
              </div>
            @endif
          </div>
        </div>
      @endif

      {{-- Summary Meta Card --}}
      <div class="card">
        <div class="card-head"><div><h3>Request Summary</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-1" style="gap:12px;">
            <div class="meta-item">
              <div class="meta-label">Original Amount</div>
              <div class="meta-value font-bold" style="font-size:1.15rem; color:#0f172a;">{{ \App\Support\Money::format($instance->amount_minor) }}</div>
            </div>
            <div class="meta-item">
              <div class="meta-label">Approved Amount so far</div>
              <div class="meta-value font-bold" style="color:#0b7d54;">{{ \App\Support\Money::format($instance->approved_amount_minor) }}</div>
            </div>
            <div class="meta-item">
              <div class="meta-label">Requester</div>
              <div class="meta-value font-bold">{{ $instance->requester?->name }}</div>
              @if ($instance->requester?->email)
                <div class="cell-sub">{{ $instance->requester->email }}</div>
              @endif
            </div>
            <div class="meta-item">
              <div class="meta-label">Approval Workflow</div>
              <div class="meta-value font-bold">{{ $instance->workflow->name }} ({{ $instance->workflow->code }})</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Reject Modal --}}
  <div id="modal-reject" class="modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div style="max-width:500px; margin:60px auto; background:#fff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
      <div style="padding:16px 20px; background:#fef2f2; border-bottom:1px solid #fecaca; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:1.05rem; color:#b91c1c;">Reject &amp; Return Request</h3>
        <button type="button" onclick="document.getElementById('modal-reject').style.display='none'" style="background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form method="POST" action="{{ route('approvals.reject', $instance) }}" style="padding:20px;">
        @csrf
        <p style="margin:0 0 14px; font-size:0.85rem; color:#475569;">
          Rejecting will return the submission to the requester (<strong>{{ $instance->requester?->name }}</strong>). Please provide the specific reason.
        </p>
        <div class="field mb-16">
          <label for="reject_comment">Reason for Rejection <span class="req">*</span></label>
          <textarea id="reject_comment" name="comment" required rows="4" class="form-control" placeholder="Specify why this request was rejected..."></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="document.getElementById('modal-reject').style.display='none'" class="btn btn-ghost">Cancel</button>
          <button type="submit" class="btn btn-primary danger">Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>

  {{-- Request Info Modal --}}
  <div id="modal-request-info" class="modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div style="max-width:500px; margin:60px auto; background:#fff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden;">
      <div style="padding:16px 20px; background:#eff6ff; border-bottom:1px solid #bfdbfe; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:1.05rem; color:#1e40af;">Request More Information</h3>
        <button type="button" onclick="document.getElementById('modal-request-info').style.display='none'" style="background:transparent; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <form method="POST" action="{{ route('approvals.request-info', $instance) }}" style="padding:20px;">
        @csrf
        <p style="margin:0 0 14px; font-size:0.85rem; color:#475569;">
          Ask the requester (<strong>{{ $instance->requester?->name }}</strong>) for clarification. The request will remain open in the queue.
        </p>
        <div class="field mb-16">
          <label for="info_comment">Information Requested <span class="req">*</span></label>
          <textarea id="info_comment" name="comment" required rows="4" class="form-control" placeholder="What additional information or document is needed?"></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" onclick="document.getElementById('modal-request-info').style.display='none'" class="btn btn-ghost">Cancel</button>
          <button type="submit" class="btn btn-primary">Send Question to Requester</button>
        </div>
      </form>
    </div>
  </div>
@endsection
