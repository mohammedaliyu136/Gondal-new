@extends('layouts.app')
@section('title', $requisition->reference)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('requisitions.index') }}">Requisitions</a><span class="sep">/</span>
    <span class="here">{{ $requisition->reference }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \App\Support\Money::compact($requisition->total_minor) }}</div>
    <div class="dh-main">
      <h1>{{ $requisition->reference }}</h1>
      <div class="dh-sub">
        {{ $requisition->title }} &middot; raised by {{ $requisition->requester?->name }}
        @if ($requisition->department) &middot; {{ $requisition->department->name }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ [
          'draft' => 'muted', 'in_review' => 'warning', 'approved' => 'success',
          'rejected' => 'danger', 'cancelled' => 'muted',
        ][$requisition->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($requisition->status) }}</span>
        @if ($instance?->band)<span class="pill">{{ $instance->band->name }} band</span>@endif
        @if ($requisition->revises)
          <span class="pill">revises {{ $requisition->revises->reference }}</span>
        @endif
      </div>
    </div>
    <div class="dh-actions">
      @if ($requisition->status === 'draft' && $isOwnSubmission)
        <form method="POST" action="{{ route('requisitions.submit', $requisition) }}">
          @csrf
          <button type="submit" class="btn btn-primary">Submit for approval</button>
        </form>
      @endif
      @if ($canResubmit)
        <a href="#modal-resubmit" class="btn btn-primary">Revise and resubmit</a>
      @endif
    </div>
  </div>

  @if ($isOwnSubmission && $requisition->status === 'in_review')
    {{-- BR-18 — say it plainly rather than only enforcing it. --}}
    <div class="alert info mb-16">
      <span>&#128274;</span>
      <div>
        <strong>This is your own submission.</strong>
        You cannot approve it yourself at any stage.
      </div>
    </div>
  @endif

  @if ($requisition->status === 'rejected')
    <div class="alert danger mb-16">
      <span>&#10060;</span>
      <div>
        <strong>Rejected.</strong>
        @php($rejection = $instance?->actions?->firstWhere('action', 'reject'))
        @if ($rejection)
          {{ $rejection->stage?->name }} &mdash; &ldquo;{{ $rejection->comment }}&rdquo;
          ({{ $rejection->actor?->name }}, {{ \App\Support\Wat::relative($rejection->acted_at) }}).
        @endif
        Revising and resubmitting starts a new approval; this one is kept on record in full.
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Line Items</h3></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Item</th><th>Purpose</th><th class="num">Qty</th><th>Unit</th>
                <th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
              <tbody>
                @foreach ($requisition->items as $item)
                  <tr>
                    <td class="font-bold">{{ $item->item }}</td>
                    <td>{{ $item->purpose ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                    <td>{{ $item->unit ?? '—' }}</td>
                    <td class="num">{{ \App\Support\Money::format($item->unit_price_minor) }}</td>
                    <td class="num font-bold">{{ \App\Support\Money::format($item->amount_minor) }}</td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot>
                <tr><th colspan="5">Requested total</th>
                  <th class="num">{{ \App\Support\Money::format($requisition->total_minor) }}</th></tr>
                @if ($requisition->approved_total_minor !== null)
                  <tr><th colspan="5">Approved total</th>
                    <th class="num">{{ \App\Support\Money::format($requisition->approved_total_minor) }}</th></tr>
                @endif
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      @if ($instance)
        <div class="card">
          <div class="card-head">
            <div><h3>Approval Chain</h3>
              <p>Stage {{ $instance->stageNumber() ?? '—' }} of {{ $instance->stageCount() }} &middot; each stage is taken in turn</p></div>
            @if ($instance->isOverdue())<span class="badge danger">Overdue</span>@endif
          </div>
          <div class="card-body">
            <div class="flow mb-16">
              @php($currentIndex = $stages->search(fn ($s) => $s->id === $instance->current_stage_id))
              @foreach ($stages as $stage)
                <span class="step {{ $currentIndex !== false && $loop->index < $currentIndex ? 'done' : ($stage->id === $instance->current_stage_id ? 'current' : '') }}">
                  <span class="step-num">{{ $loop->iteration }}</span> {{ $stage->name }}
                </span>
                @if (! $loop->last)<span class="arrow">&rsaquo;</span>@endif
              @endforeach
            </div>

            <div class="table-wrap">
              <table>
                <thead><tr><th class="num">#</th><th>Stage</th><th>Approving role</th><th>Applies when</th>
                  <th class="num">SLA</th><th>Outcome</th></tr></thead>
                <tbody>
                  @foreach ($stages as $stage)
                    @php($action = $instance->actions->firstWhere('workflow_stage_id', $stage->id))
                    <tr>
                      <td class="num font-bold">{{ $loop->iteration }}</td>
                      <td>{{ $stage->name }}</td>
                      <td>{{ $stage->approvingRole?->name ?? 'Requester' }}</td>
                      <td>{{ $stage->describeCondition() }}</td>
                      <td class="num">{{ $stage->sla_hours ? $stage->sla_hours.'h' : '—' }}</td>
                      <td>
                        @if ($action === null)
                          <span class="badge muted">{{ $stage->id === $instance->current_stage_id ? 'Awaiting' : 'Not reached' }}</span>
                        @else
                          <span class="badge {{ ['approve' => 'success', 'reject' => 'danger', 'submit' => 'info', 'request_info' => 'warning'][$action->action] ?? 'muted' }}">
                            {{ \Illuminate\Support\Str::headline($action->action) }}
                          </span>
                          <div class="cell-sub">
                            {{ $action->actor?->name }}
                            @if ($action->onBehalfOf) on behalf of {{ $action->onBehalfOf->name }} @endif
                            &middot; {{ \App\Support\Wat::relative($action->acted_at) }}
                          </div>
                        @endif
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>Action Trail</h3><p>Every action, in order</p></div></div>
          <div class="card-body">
            <div class="timeline">
              @foreach ($instance->actions as $action)
                <div class="tl-item {{ $action->action === 'reject' ? 'red' : ($action->action === 'request_info' ? 'amber' : '') }}">
                  <div class="tl-title">
                    {{ \Illuminate\Support\Str::headline($action->action) }} &mdash; {{ $action->stage?->name ?? 'submission' }}
                  </div>
                  <div class="tl-sub">
                    {{ $action->actor?->name }}
                    @if ($action->onBehalfOf) on behalf of {{ $action->onBehalfOf->name }} @endif
                    @if ($action->amount_minor !== null) &middot; {{ \App\Support\Money::format($action->amount_minor) }} @endif
                    @if ($action->comment) &middot; &ldquo;{{ $action->comment }}&rdquo; @endif
                  </div>
                  <div class="tl-time">{{ \App\Support\Wat::relative($action->acted_at) }}</div>
                </div>
              @endforeach
            </div>
          </div>
        </div>
      @endif

      @if ($previousInstances->count() > 1)
        {{-- BR-20 — the old chains are retained and readable. --}}
        <div class="card">
          <div class="card-head"><div><h3>Earlier Approval Attempts</h3>
            <p>Kept on record when this requisition was revised</p></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Started</th><th>Status</th><th class="num">Actions</th><th>Completed</th></tr></thead>
                <tbody>
                  @foreach ($previousInstances as $previous)
                    <tr>
                      <td>{{ \App\Support\Wat::dateTime($previous->started_at) }}</td>
                      <td><span class="badge {{ $previous->status === 'approved' ? 'success' : ($previous->status === 'rejected' ? 'danger' : 'warning') }}">
                        {{ \Illuminate\Support\Str::headline($previous->status) }}</span></td>
                      <td class="num">{{ $previous->actions->count() }}</td>
                      <td>{{ \App\Support\Wat::dateTime($previous->completed_at) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @endif
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Detail</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Urgency</div>
              <div class="meta-value">{{ ucfirst($requisition->urgency) }}</div></div>
            <div class="meta-item"><div class="meta-label">Needed by</div>
              <div class="meta-value">{{ \App\Support\Wat::date($requisition->needed_by) }}</div></div>
            <div class="meta-item"><div class="meta-label">Category</div>
              <div class="meta-value">{{ $requisition->category ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Suggested vendor</div>
              <div class="meta-value">{{ $requisition->suggested_vendor ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Submitted</div>
              <div class="meta-value">{{ \App\Support\Wat::dateTime($requisition->submitted_at) }}</div></div>
            <div class="meta-item"><div class="meta-label">Decided</div>
              <div class="meta-value">{{ \App\Support\Wat::dateTime($requisition->decided_at) }}</div></div>
          </div>
        </div>
      </div>

      @if ($canAct)
        <div class="card">
          <div class="card-head"><div><h3>Your Action</h3>
            <p>{{ $instance->currentStage?->name }} &middot; {{ $instance->currentStage?->approvingRole?->name }}</p></div></div>
          <div class="card-body">
            <a href="{{ route('approvals.index') }}" class="btn btn-primary btn-block">Act in My Approvals</a>
            <div class="hint mt-16">Approve, reduce the amount, request information, or reject with a reason.</div>
          </div>
        </div>
      @endif

      <div class="card">
        <div class="card-head"><div><h3>Discussion</h3></div></div>
        <div class="card-body">
          @forelse ($requisition->comments as $comment)
            <div class="queue-item">
              <div class="qi-ic">&#128172;</div>
              <div>
                <div class="qi-title">{{ $comment->createdBy?->name ?? 'System' }}</div>
                <div class="qi-sub">{{ $comment->body }}</div>
                <div class="tl-time">{{ \App\Support\Wat::relative($comment->created_at) }}</div>
              </div>
            </div>
          @empty
            <div class="text-muted text-small">No comments yet.</div>
          @endforelse

          <form method="POST" action="{{ route('requisitions.comment', $requisition) }}" class="mt-16">
            @csrf
            <div class="field mb-16">
              <label for="rc-body">Add a comment</label>
              <textarea id="rc-body" name="body" rows="2" required></textarea>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Comment</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  @if ($canResubmit)
    <div id="modal-resubmit" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Revise and Resubmit</h3>
            <p>This creates a new requisition and a new approval; {{ $requisition->reference }} is kept on record</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('requisitions.resubmit', $requisition) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-resubmit" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-resubmit'])
            <div class="form-grid">
              <div class="field full"><label for="rs-title">Title</label>
                <input type="text" id="rs-title" name="title" value="{{ $requisition->title }}" /></div>
              <div class="field"><label for="rs-urgency">Urgency</label>
                <select id="rs-urgency" name="urgency">
                  @foreach (['low', 'normal', 'high'] as $urgency)
                    <option value="{{ $urgency }}" @selected($requisition->urgency === $urgency)>{{ ucfirst($urgency) }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="rs-needed">Needed by</label>
                <input type="date" id="rs-needed" name="needed_by" value="{{ $requisition->needed_by?->toDateString() }}" /></div>
              <div class="field full"><label for="rs-vendor">Suggested vendor</label>
                <input type="text" id="rs-vendor" name="suggested_vendor" value="{{ $requisition->suggested_vendor }}" /></div>
            </div>

            <div class="divider"></div>
            <h3 class="mb-16">Line items</h3>
            <div class="hint mb-16">Leave these blank to carry the original lines across unchanged.</div>
            @foreach ($requisition->items as $index => $item)
              <div class="form-grid mb-16">
                <div class="field"><label for="rs-item-{{ $index }}">Item</label>
                  <input type="text" id="rs-item-{{ $index }}" name="items[{{ $index }}][item]" value="{{ $item->item }}" /></div>
                <div class="field"><label for="rs-qty-{{ $index }}">Quantity</label>
                  <input type="text" id="rs-qty-{{ $index }}" name="items[{{ $index }}][quantity]"
                         value="{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}" inputmode="decimal" /></div>
                <div class="field"><label for="rs-unit-{{ $index }}">Unit</label>
                  <input type="text" id="rs-unit-{{ $index }}" name="items[{{ $index }}][unit]" value="{{ $item->unit }}" /></div>
                <div class="field"><label for="rs-price-{{ $index }}">Unit price (₦)</label>
                  <input type="text" id="rs-price-{{ $index }}" name="items[{{ $index }}][unit_price]"
                         value="{{ \App\Support\Money::decimal($item->unit_price_minor) }}" inputmode="decimal" /></div>
              </div>
            @endforeach
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Resubmit as a new requisition</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
