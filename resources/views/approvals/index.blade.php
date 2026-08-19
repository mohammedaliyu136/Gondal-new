@extends('layouts.app')
@section('title', 'My Approvals')

@section('content')
  <div class="page-head">
    <div>
      <h1>My Approvals</h1>
      <p>Items waiting on your decision</p>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      Your own requests never appear here &mdash; nobody approves their own request, at any stage.
    </div>
  </div>

  @if ($delegations->isNotEmpty())
    {{-- BR-24 --}}
    <div class="alert warn mb-16">
      <span>&#128101;</span>
      <div>
        <strong>You are covering a delegation.</strong>
        @foreach ($delegations as $delegation)
          {{ $delegation->role?->name }} for {{ $delegation->fromUser?->name }}
          until {{ \App\Support\Wat::date($delegation->ends_on) }}@if (! $loop->last);@endif
        @endforeach
        &mdash; anything you approve records both names.
      </div>
    </div>
  @endif

  <div class="grid grid-4 mb-16">
    <div class="stat amber"><div class="stat-label">Waiting on you</div>
      <div class="stat-value">{{ number_format($queue->total()) }}</div>
      <div class="stat-foot">across every workflow</div></div>
    <div class="stat red"><div class="stat-label">Overdue</div>
      <div class="stat-value">{{ number_format($overdueCount) }}</div>
      <div class="stat-foot">past the stage SLA</div></div>
    <div class="stat blue"><div class="stat-label">Your approval roles</div>
      <div class="stat-value">{{ collect(auth()->user()->effectivePermissionKeys())->filter(fn ($k) => str_starts_with($k, 'purchase.approve.'))->count() }}</div>
      <div class="stat-foot">approval stages you cover</div></div>
    <div class="stat green"><div class="stat-label">Delegations covered</div>
      <div class="stat-value">{{ $delegations->count() }}</div>
      <div class="stat-foot">on behalf of others</div></div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Queue</h3><p>Oldest SLA first</p></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Item</th><th>Workflow</th><th>Stage</th><th class="num">Amount</th>
            <th>Requester</th><th>SLA</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($queue as $instance)
              <tr>
                <td>
                  @if ($instance->subject instanceof \App\Contracts\WorkflowSubjectInterface)
                    @if ($instance->subject->getApprovalUrl())
                      <a href="{{ $instance->subject->getApprovalUrl() }}" class="perm-key">{{ $instance->subject->getApprovalReference() }}</a>
                    @else
                      <span class="perm-key">{{ $instance->subject->getApprovalReference() }}</span>
                    @endif
                    <div class="cell-sub">{{ $instance->subject->getApprovalTitle() }}</div>
                  @elseif ($instance->subject instanceof \App\Models\Requisition)
                    <a href="{{ route('requisitions.show', $instance->subject) }}" class="perm-key">{{ $instance->subject->reference }}</a>
                    <div class="cell-sub">{{ $instance->subject->title }}</div>
                  @else
                    <span class="perm-key">{{ $instance->subject?->reference ?? class_basename($instance->subject_type) }}</span>
                  @endif
                </td>
                <td>{{ $instance->workflow->name }}
                  <div class="cell-sub">{{ $instance->band?->name }} band</div></td>
                <td>{{ $instance->currentStage?->name }}
                  <div class="cell-sub">stage {{ $instance->stageNumber() }} of {{ $instance->stageCount() }}
                    &middot; {{ $instance->currentStage?->approvingRole?->name }}</div></td>
                <td class="num font-bold">{{ \App\Support\Money::format($instance->amount_minor) }}</td>
                <td>{{ $instance->requester?->name }}</td>
                <td>
                  @if ($instance->current_stage_due_at === null)
                    <span class="badge muted">No SLA</span>
                  @elseif ($instance->isOverdue())
                    <span class="badge danger">Overdue</span>
                    <div class="cell-sub">{{ \App\Support\Wat::dateTime($instance->current_stage_due_at) }}</div>
                  @else
                    <span class="badge success">{{ $instance->hoursRemaining() }}h left</span>
                  @endif
                </td>
                <td class="actions">
                  <a href="#modal-act-{{ $instance->id }}" class="btn btn-primary btn-sm">Act</a>
                </td>
              </tr>
            @empty
              <tr><td colspan="7">@include('partials.empty', [
                'title' => 'Nothing waiting on you',
                'message' => 'Items appear here when a stage you hold the role for becomes actionable.',
                'icon' => '&#9989;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $queue, 'noun' => 'items'])
  </div>

  @foreach ($queue as $instance)
    <div id="modal-act-{{ $instance->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog {{ $instance->currentStage?->hasStageAction() ? 'wide' : '' }}">
        <div class="modal-head">
          <div>
            <h3>{{ $instance->subject?->reference ?? 'Approval' }} &mdash; {{ $instance->currentStage?->name }}</h3>
            <p>{{ $instance->workflow->name }} &middot; stage {{ $instance->stageNumber() }} of {{ $instance->stageCount() }}</p>
          </div>
          <a href="#" class="modal-close">&times;</a>
        </div>

        <div class="modal-body">
          <div class="flow mb-16">
            @foreach ($instance->applicableStages() as $stage)
              @php($isPast = $instance->applicableStages()->search(fn ($s) => $s->id === $instance->current_stage_id) > $loop->index)
              <span class="step {{ $isPast ? 'done' : ($stage->id === $instance->current_stage_id ? 'current' : '') }}">
                <span class="step-num">{{ $loop->iteration }}</span> {{ $stage->name }}
              </span>
              @if (! $loop->last)<span class="arrow">&rsaquo;</span>@endif
            @endforeach
          </div>

          <div class="meta-grid cols-3">
            <div class="meta-item"><div class="meta-label">Requested</div>
              <div class="meta-value">{{ \App\Support\Money::format($instance->amount_minor) }}</div></div>
            <div class="meta-item"><div class="meta-label">Approved so far</div>
              <div class="meta-value">{{ \App\Support\Money::format($instance->approved_amount_minor) }}</div></div>
            <div class="meta-item"><div class="meta-label">Requester</div>
              <div class="meta-value">{{ $instance->requester?->name }}</div></div>
          </div>
        </div>

        {{-- BR-22 — reduce, never raise. --}}
        <form method="POST" action="{{ route('approvals.approve', $instance) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-act-{{ $instance->id }}" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-act-'.$instance->id.''])

            @if ($instance->currentStage?->hasStageAction() && $instance->currentStage?->stageActionHandler())
              {!! $instance->currentStage->stageActionHandler()->renderForm($instance, $instance->currentStage) !!}
            @endif

            <div class="form-grid">
              <div class="field">
                <label for="ap-{{ $instance->id }}-amount">Approved amount (₦)</label>
                <input type="text" id="ap-{{ $instance->id }}-amount" name="approved_amount"
                       inputmode="decimal"
                       value="{{ \App\Support\Money::decimal($instance->approved_amount_minor) === '—' ? '' : \App\Support\Money::decimal($instance->approved_amount_minor) }}"
                       @disabled(! $instance->workflow->option('approver_may_reduce_amount', true)) />
                <div class="hint">
                  You may reduce this but never raise it above the requested
                  {{ \App\Support\Money::format($instance->amount_minor) }}.
                </div>
              </div>
              <div class="field full">
                <label for="ap-{{ $instance->id }}-comment">Comment</label>
                <textarea id="ap-{{ $instance->id }}-comment" name="comment" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Approve</button>
          </div>
        </form>

        <div class="card-body">
          <div class="divider"></div>
          <div class="grid grid-2">
            @if ($instance->workflow->option('allow_request_info', true))
              {{-- BR-21 — records and notifies without advancing or ending. --}}
              <form method="POST" action="{{ route('approvals.request-info', $instance) }}">
                @csrf
                <div class="field mb-16">
                  <label for="ri-{{ $instance->id }}">Request more information <span class="req">*</span></label>
                  <textarea id="ri-{{ $instance->id }}" name="comment" rows="2" required
                            placeholder="What do you need?"></textarea>
                  <div class="hint">This notifies the requester without advancing or ending the approval.</div>
                </div>
                <button type="submit" class="btn btn-outline btn-block">Request information</button>
              </form>
            @endif

            @if ($instance->currentStage?->can_reject)
              {{-- BR-20 — a rejection returns it to the requester. --}}
              <form method="POST" action="{{ route('approvals.reject', $instance) }}">
                @csrf
                <div class="field mb-16">
                  <label for="rj-{{ $instance->id }}">Reject with a reason <span class="req">*</span></label>
                  <textarea id="rj-{{ $instance->id }}" name="comment" rows="2" required
                            placeholder="The requester will see this"></textarea>
                  <div class="hint">If the requester revises and resubmits, that starts a fresh approval and this one is kept on record.</div>
                </div>
                <button type="submit" class="btn btn-danger btn-block">Reject</button>
              </form>
            @endif
          </div>
        </div>
      </div>
    </div>
  @endforeach
@endsection
