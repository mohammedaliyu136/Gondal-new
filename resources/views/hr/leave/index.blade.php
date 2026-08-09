@extends('layouts.app')
@section('title', 'Leave')

@section('content')
  <div class="page-head">
    <div>
      <h1>Leave</h1>
      <p>{{ $seesAll ? number_format($requests->total()).' requests across the company' : 'Your own requests' }}</p>
    </div>
    <div class="page-actions">
      <a href="#modal-leave" class="btn btn-primary">+ Request Leave</a>
    </div>
  </div>

  @if ($employee === null)
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>Your account is not linked to an employee record, so you cannot raise leave for yourself yet.
        Ask HR to link it.</div>
    </div>
  @endif

  <div class="card">
    <div class="card-head">
      <div><h3>Requests</h3>
        <p>{{ number_format($awaitingCount) }} awaiting a decision</p></div>
      <form method="GET" class="flex">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          @foreach (['draft', 'in_review', 'approved', 'rejected', 'cancelled'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
          @endforeach
        </select>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Employee</th><th>Type</th><th>From</th><th>To</th><th class="num">Days</th>
            <th>Stage</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($requests as $request)
              <tr>
                <td>{{ $request->employee?->name }}
                  <div class="cell-sub">{{ $request->employee?->department?->name }}</div></td>
                <td>{{ $request->leaveType?->name }}</td>
                <td>{{ \App\Support\Wat::date($request->starts_on) }}</td>
                <td>{{ \App\Support\Wat::date($request->ends_on) }}</td>
                <td class="num font-bold">{{ $request->days }}</td>
                <td>
                  {{ $request->workflowInstance?->currentStage?->name ?? '—' }}
                  @if ($request->workflowInstance)
                    <div class="cell-sub">{{ $request->workflowInstance->currentStage?->approvingRole?->name }}</div>
                  @endif
                </td>
                <td><span class="badge {{ [
                  'approved' => 'success', 'in_review' => 'warning', 'rejected' => 'danger',
                  'draft' => 'muted', 'cancelled' => 'muted',
                ][$request->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($request->status) }}</span></td>
                <td class="actions">
                  @if ($request->status === 'draft')
                    <form method="POST" action="{{ route('leave.submit', $request) }}">
                      @csrf
                      <button type="submit" class="btn btn-outline btn-sm">Submit</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="8">@include('partials.empty', [
                'title' => 'No leave requests',
                'message' => $seesAll ? 'Nothing has been raised yet.' : 'You have not raised any leave.',
                'icon' => '&#127958;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $requests, 'noun' => 'requests'])
  </div>

  <div id="modal-leave" class="modal @if (old('_modal') === 'modal-leave') open @endif">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog narrow">
      <div class="modal-head">
        <div><h3>Request Leave</h3><p>Requests over 5 days also go to HR for approval</p></div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('leave.store') }}">
        @csrf
          <input type="hidden" name="_modal" value="modal-leave" />
        <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-leave'])
          @if ($seesAll)
            <div class="field mb-16"><label for="lv-employee">Employee</label>
              <select id="lv-employee" name="employee_id"
                      data-searchable data-combo-placeholder="Myself, or search staff by name or number…">
                <option value="">Myself</option>
                @foreach ($employees as $option)
                  <option value="{{ $option->id }}" @selected(old('employee_id') == $option->id)>{{ $option->name }} — {{ $option->code }}</option>
                @endforeach
              </select>
              <div class="hint">Leave the choice on &ldquo;Myself&rdquo; to raise your own leave.</div></div>
          @endif
          <div class="field mb-16"><label for="lv-type">Leave type <span class="req">*</span></label>
            <select id="lv-type" name="leave_type_id" required>
              @foreach ($leaveTypes as $type)
                <option value="{{ $type->id }}">
                  {{ $type->name }} ({{ $type->annual_entitlement_days }} days/year)
                  @if ($type->requires_document) — document required @endif
                </option>
              @endforeach
            </select></div>
          <div class="field mb-16"><label for="lv-from">From <span class="req">*</span></label>
            <input type="date" id="lv-from" name="starts_on" required /></div>
          <div class="field mb-16"><label for="lv-to">To <span class="req">*</span></label>
            <input type="date" id="lv-to" name="ends_on" required /></div>
          <div class="field"><label for="lv-reason">Reason</label>
            <textarea id="lv-reason" name="reason" rows="3"></textarea></div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-outline">Save draft</button>
          <button type="submit" name="submit" value="1" class="btn btn-primary">Save and submit</button>
        </div>
      </form>
    </div>
  </div>
@endsection
