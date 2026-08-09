@extends('layouts.app')
@section('title', 'Farmer Revalidation')

@php
  use App\Services\Community\FarmerCohort;
@endphp

@section('content')
  <div class="page-head">
    <div>
      <h1>Farmer Revalidation</h1>
      {{-- BR-36 — who needs checking, who is doing it, and what came back. --}}
      <p>{{ number_format($counts['open']) }} open &middot;
         {{ number_format($counts['review']) }} awaiting review &middot;
         {{ number_format($counts['overdue']) }} overdue</p>
    </div>
    <div class="page-actions">
      @if ($canAssign)
        <a href="#modal-round" class="btn btn-primary">+ Open a round</a>
        <a href="#modal-assign" class="btn btn-outline">+ Assign a check</a>
      @endif
    </div>
  </div>

  @if (session('round_skipped'))
    {{-- No silent truncation: a round that left farmers behind says which. --}}
    <div class="alert warn mb-16">
      <strong>{{ count(session('round_skipped')) }} farmer(s) were skipped.</strong>
      <ul>
        @foreach (session('round_skipped') as $line)<li>{{ $line }}</li>@endforeach
      </ul>
    </div>
  @endif

  @if ($errors->any())
    <div class="alert warn mb-16">
      @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  <div class="chip-group mb-16">
    @foreach (['open' => 'Open', 'review' => 'Awaiting review', 'overdue' => 'Overdue', 'accepted' => 'Accepted', 'cancelled' => 'Cancelled'] as $key => $label)
      <a class="chip {{ $status === $key ? 'on' : '' }}"
         href="{{ route('validations.index', ['status' => $key]) }}">{{ $label }}</a>
    @endforeach
  </div>

  {{--
    Narrow the queue to what is actually being reviewed — one community, one
    agent's round — so that "accept everything shown" is a decision about a
    coherent batch rather than about whatever happened to be on page one.
  --}}
  <div class="card mb-16">
    <form method="GET" action="{{ route('validations.index') }}">
      <input type="hidden" name="status" value="{{ $status }}" />
      <div class="form-grid">
        <div class="field">
          <label for="f-community">Community</label>
          <select id="f-community" name="community_id" data-searchable>
            <option value="">All communities</option>
            @foreach ($cohortOptions[FarmerCohort::BY_COMMUNITY] as $option)
              <option value="{{ $option->id }}" @selected($filters['community_id'] === $option->id)>{{ $option->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="f-lga">LGA</label>
          <select id="f-lga" name="lga_id">
            <option value="">All LGAs</option>
            @foreach ($cohortOptions[FarmerCohort::BY_LGA] as $option)
              <option value="{{ $option->id }}" @selected($filters['lga_id'] === $option->id)>{{ $option->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="f-point">Collection point</label>
          <select id="f-point" name="collection_point_id">
            <option value="">All points</option>
            @foreach ($cohortOptions[FarmerCohort::BY_POINT] as $option)
              <option value="{{ $option->id }}" @selected($filters['collection_point_id'] === $option->id)>{{ $option->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="f-assignee">Assigned to</label>
          <select id="f-assignee" name="assigned_to_user_id">
            <option value="">Anyone</option>
            @foreach ($assignees as $assignee)
              <option value="{{ $assignee->id }}" @selected($filters['assigned_to_user_id'] === $assignee->id)>{{ $assignee->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field full">
          <button type="submit" class="btn btn-outline btn-sm">Filter</button>
          <a href="{{ route('validations.index', ['status' => $status]) }}" class="btn btn-ghost btn-sm">Clear</a>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    @if ($validations->isEmpty())
      <div class="empty">
        <h4>Nothing here</h4>
        <p>No revalidations matching this filter in your data scope.</p>
      </div>
    @else
      @if ($canReview && $validations->contains(fn ($v) => $v->isAwaitingReview()))
        {{--
          The bulk form is a SIBLING of the table, not a wrapper. Every row
          already carries its own single-record Accept form, and a form inside a
          form is invalid HTML that browsers resolve by dropping the inner one —
          which would silently break the per-row buttons. The checkboxes live in
          the table and point back here with `form="bulk-accept"`.
        --}}
        <form method="POST" action="{{ route('validations.accept-many') }}" id="bulk-accept">
          @csrf
          <div class="bulk-bar" data-bulk-bar hidden>
            <span data-bulk-count>0 selected</span>
            <input type="text" name="note" maxlength="500"
                   placeholder="Optional note, recorded on every one of them" />
            <button type="submit" class="btn btn-primary btn-sm">Accept selected</button>
          </div>
        </form>
      @endif

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              @if ($canReview)
                {{-- A UI control, deliberately not part of the form. --}}
                <th class="col-check"><input type="checkbox" data-bulk-all aria-label="Select all awaiting review" /></th>
              @endif
              <th>Reference</th><th>Farmer</th><th>Reason</th><th>Assigned to</th>
              <th>Due</th><th>Status</th><th>Outcome</th><th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($validations as $validation)
              <tr>
                @if ($canReview)
                  <td class="col-check">
                    @if ($validation->isAwaitingReview())
                      <input type="checkbox" form="bulk-accept"
                             name="validation_ids[]" value="{{ $validation->id }}"
                             data-bulk-item aria-label="Select {{ $validation->reference }}" />
                    @endif
                  </td>
                @endif
                <td>{{ $validation->reference }}</td>
                <td>
                  {{ $validation->farmer?->name }}
                  <div class="hint">{{ $validation->farmer?->community?->name }}</div>
                </td>
                <td>{{ $validation->reason?->name }}</td>
                <td>
                  {{-- Null is a pool assignment, and saying "Unassigned" is the
                       point: those are the ones nobody has picked up. --}}
                  {{ $validation->assignedTo?->name ?? 'Unassigned' }}
                </td>
                <td>
                  {{ $validation->due_on?->toDateString() ?? '—' }}
                  @if ($validation->isOverdue())<span class="badge danger">overdue</span>@endif
                </td>
                <td><span class="badge">{{ $validation->status }}</span></td>
                <td>{{ $validation->outcome ?? '—' }}</td>
                <td>
                  @if ($canReview && $validation->isAwaitingReview())
                    <form method="POST" action="{{ route('validations.accept', $validation) }}" class="inline">
                      @csrf
                      <button type="submit" class="btn btn-sm btn-primary">Accept</button>
                    </form>
                    <a href="#modal-return-{{ $validation->id }}" class="btn btn-sm btn-outline">Send back</a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      {{ $validations->links() }}
    @endif
  </div>

  {{-- Modals live outside the table: a <div> inside <tbody> is not valid markup. --}}
  @if ($canReview)
    @foreach ($validations as $validation)
      @if ($validation->isAwaitingReview())
        <div id="modal-return-{{ $validation->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><h3>Send {{ $validation->reference }} back</h3></div>
            <form method="POST" action="{{ route('validations.return', $validation) }}">
              @csrf
              <div class="modal-body">
                <p>{{ $validation->farmer?->name }} —
                   {{ $validation->submittedBy?->name }} reported
                   &ldquo;{{ $validation->outcome }}&rdquo;.</p>
                @if ($validation->findings)
                  <blockquote>{{ $validation->findings }}</blockquote>
                @endif
                <div class="field">
                  <label for="note-{{ $validation->id }}">Why is it going back?</label>
                  {{-- The field worker reads this before driving out again. --}}
                  <textarea id="note-{{ $validation->id }}" name="note" rows="3" required></textarea>
                </div>
              </div>
              <div class="modal-foot">
                <a href="#" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Send back</button>
              </div>
            </form>
          </div>
        </div>
      @endif
    @endforeach
  @endif

  @if ($canAssign)
    {{--
      One act of judgement over a cohort. The counts beside each option come
      from the scoped farmer table, so what is offered is what will be
      scheduled — an option reading 0 means the officer holds none of it.
    --}}
    <div id="modal-round" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <h3>Open a revalidation round</h3>
          <p>Schedule a check for every farmer in a community, an LGA, an agent&rsquo;s
             round, a collection point or a cooperative.</p>
        </div>
        <form method="POST" action="{{ route('validations.rounds.store') }}">
          @csrf
          <div class="modal-body">
            <div class="form-grid">
              <div class="field">
                <label for="r-type">Cohort</label>
                <select id="r-type" name="cohort_type" data-cohort-type required>
                  @foreach ($cohortOptions as $type => $options)
                    <option value="{{ $type }}" @selected($type === FarmerCohort::BY_COMMUNITY)>
                      {{ FarmerCohort::label($type) }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="r-reason">Reason</label>
                <select id="r-reason" name="validation_reason_id" required>
                  @foreach ($reasons as $reason)
                    <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                  @endforeach
                </select>
              </div>

              @foreach ($cohortOptions as $type => $options)
                <div class="field full" data-cohort-panel="{{ $type }}"
                     @if ($type !== FarmerCohort::BY_COMMUNITY) hidden @endif>
                  <label for="r-targets-{{ $type }}">
                    {{ FarmerCohort::label($type) }} — pick one or more
                  </label>
                  <select id="r-targets-{{ $type }}" name="cohort_target_ids[]" multiple size="8"
                          @disabled($type !== FarmerCohort::BY_COMMUNITY)>
                    @forelse ($options as $option)
                      <option value="{{ $option->id }}">
                        {{ $option->name }} — {{ number_format($option->farmers) }} farmer(s)
                      </option>
                    @empty
                      <option value="" disabled>None in your data scope</option>
                    @endforelse
                  </select>
                </div>
              @endforeach

              <div class="field full">
                <label class="check-label">
                  <input type="checkbox" name="overdue_only" value="1" checked />
                  Only farmers already past their revalidation date
                </label>
                <div class="hint">
                  Untick to revalidate the whole cohort regardless of when it was last
                  checked. A round is capped at {{ number_format($cohortMax) }} farmers.
                </div>
              </div>

              <div class="field">
                <label for="r-assignee">Assign every check to</label>
                <select id="r-assignee" name="assigned_to_user_id">
                  <option value="">Anyone who covers the farmer</option>
                  @foreach ($assignees as $assignee)
                    <option value="{{ $assignee->id }}">{{ $assignee->name }}</option>
                  @endforeach
                </select>
              </div>

              <div class="field">
                <label for="r-due">Due by</label>
                <input type="date" id="r-due" name="due_on" />
              </div>

              <div class="field full">
                <label for="r-name">Round name <span class="hint">(optional)</span></label>
                <input type="text" id="r-name" name="name" maxlength="120"
                       placeholder="Named from the cohort if you leave this blank" />
              </div>

              <div class="field full">
                <label class="check-label">
                  {{-- M&E's call per round; the Settings value is only the default. --}}
                  <input type="checkbox" name="auto_approve" value="1" />
                  Accept submissions automatically, without a second review
                </label>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Open round</button>
          </div>
        </form>
      </div>
    </div>

    <div id="modal-assign" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <h3>Assign a revalidation</h3>
          <p>Monitoring &amp; Evaluation decide who needs checking and who checks them.</p>
        </div>
        <form method="POST" action="{{ route('validations.store') }}">
          @csrf
          <div class="modal-body">
            <div class="field">
              <label for="a-farmer">Farmer</label>
              <select id="a-farmer" name="farmer_id" required data-searchable
                      data-combo-placeholder="Search farmers…">
                <option value="">—</option>
                {{-- The overdue list is what M&E is deciding FROM, so it leads. --}}
                @foreach ($overdueFarmers as $farmer)
                  <option value="{{ $farmer->id }}">
                    {{ $farmer->name }} ({{ $farmer->code }}) — {{ $farmer->community?->name }} — overdue
                  </option>
                @endforeach
              </select>
              <div class="hint">{{ $overdueFarmers->count() }} farmer(s) past their revalidation date.</div>
            </div>
            <div class="field">
              <label for="a-reason">Reason</label>
              <select id="a-reason" name="validation_reason_id" required>
                @foreach ($reasons as $reason)
                  <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="a-assignee">Assign to</label>
              <select id="a-assignee" name="assigned_to_user_id">
                {{-- Supported, but not the default: an unclaimed task is nobody's,
                     and the farmers hardest to reach are the ones that stay so. --}}
                <option value="">Anyone who covers this farmer</option>
                @foreach ($assignees as $assignee)
                  <option value="{{ $assignee->id }}">{{ $assignee->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="field">
              <label for="a-due">Due by</label>
              <input type="date" id="a-due" name="due_on" />
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Assign</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
