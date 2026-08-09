@extends('layouts.app')
@section('title', 'Open Positions')

@section('content')
  <div class="page-head">
    <div>
      <h1>Open Positions</h1>
      <p>{{ number_format($openCount) }} vacancies &middot; {{ number_format($openings) }} openings</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('employees.index') }}" class="btn btn-outline">Employees</a>
      @if ($canManage)
        <a href="#modal-position" class="btn btn-primary">+ Open a position</a>
      @endif
    </div>
  </div>

  {{-- §15.5 — stated openly rather than half-built. --}}
  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>This is the vacancy register only.</strong>
      Applications are not tracked here.
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>Vacancies</h3></div>
      <form method="GET" class="flex">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          @foreach (['open', 'closed', 'filled'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
          @endforeach
        </select>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Title</th><th>Department</th><th>Grade</th>
            <th class="num">Openings</th><th>Posted</th><th>Closes</th><th>Status</th>
            @if ($canManage)<th class="actions">Actions</th>@endif</tr></thead>
          <tbody>
            @forelse ($positions as $position)
              <tr>
                <td class="font-bold">{{ $position->title }}</td>
                <td>{{ $position->department?->name ?? '—' }}</td>
                <td>{{ $position->grade_level ?? '—' }}</td>
                <td class="num">{{ $position->openings }}</td>
                <td>{{ \App\Support\Wat::date($position->posted_on) }}</td>
                <td>{{ \App\Support\Wat::date($position->closes_on) }}</td>
                <td><span class="badge {{ ['open' => 'success', 'closed' => 'muted', 'filled' => 'info'][$position->status] ?? 'muted' }}">
                  {{ ucfirst($position->status) }}</span></td>
                @if ($canManage)
                  <td class="actions"><a href="#modal-edit-pos-{{ $position->id }}" class="btn btn-ghost btn-sm">Edit</a></td>
                @endif
              </tr>
            @empty
              <tr><td colspan="{{ $canManage ? 8 : 7 }}">@include('partials.empty', ['title' => 'No positions posted', 'icon' => '&#128188;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $positions, 'noun' => 'positions'])
  </div>

  @if ($canManage)
    <div id="modal-position" class="modal @if (old('_modal') === 'modal-position') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Open a position</h3><p>A vacancy to fill. Applicant tracking is not part of this system</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('positions.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-position" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-position'])
            <div class="form-grid">
              <div class="field"><label for="pos-title">Title <span class="req">*</span></label>
                <input type="text" id="pos-title" name="title" value="{{ old('title') }}" required /></div>
              <div class="field"><label for="pos-dept">Department</label>
                <select id="pos-dept" data-searchable data-combo-placeholder="Search departments…" name="department_id">
                  <option value="">&mdash;</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="pos-grade">Grade level</label>
                <input type="text" id="pos-grade" name="grade_level" value="{{ old('grade_level') }}" /></div>
              <div class="field"><label for="pos-openings">Openings</label>
                <input type="number" id="pos-openings" name="openings" min="1" value="{{ old('openings', 1) }}" /></div>
              <div class="field"><label for="pos-posted">Posted on</label>
                <input type="date" id="pos-posted" name="posted_on" value="{{ old('posted_on') }}" /></div>
              <div class="field"><label for="pos-closes">Closes on</label>
                <input type="date" id="pos-closes" name="closes_on" value="{{ old('closes_on') }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Open position</button>
          </div>
        </form>
      </div>
    </div>

    {{--
      `positions.update` was a live route no screen posted to, so a vacancy could
      never be closed or marked filled and the register only ever grew — the
      "open vacancies" count on this page counted posts that had been filled
      months earlier.
    --}}
    @foreach ($positions as $position)
      <div id="modal-edit-pos-{{ $position->id }}" class="modal @if (old('_modal') === 'modal-edit-pos-'.$position->id) open @endif">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Edit {{ $position->title }}</h3><p>Close it when it is filled, so the vacancy count stays true</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('positions.update', $position) }}">
            @csrf
            <input type="hidden" name="_modal" value="modal-edit-pos-{{ $position->id }}" /> @method('PUT')
            <div class="modal-body">
              @include('partials.modal-errors', ['modal' => 'modal-edit-pos-'.$position->id])
              <div class="form-grid">
                <div class="field"><label for="ep-title-{{ $position->id }}">Title <span class="req">*</span></label>
                  <input type="text" id="ep-title-{{ $position->id }}" name="title" value="{{ $position->title }}" required /></div>
                <div class="field"><label for="ep-dept-{{ $position->id }}">Department</label>
                  <select id="ep-dept-{{ $position->id }}" data-searchable data-combo-placeholder="Search departments…" name="department_id">
                    <option value="">&mdash;</option>
                    @foreach ($departments as $department)
                      <option value="{{ $department->id }}" @selected($position->department_id == $department->id)>{{ $department->name }}</option>
                    @endforeach
                  </select></div>
                <div class="field"><label for="ep-grade-{{ $position->id }}">Grade level</label>
                  <input type="text" id="ep-grade-{{ $position->id }}" name="grade_level" value="{{ $position->grade_level }}" /></div>
                <div class="field"><label for="ep-openings-{{ $position->id }}">Openings</label>
                  <input type="number" id="ep-openings-{{ $position->id }}" name="openings" min="1" value="{{ $position->openings }}" /></div>
                <div class="field"><label for="ep-posted-{{ $position->id }}">Posted on</label>
                  <input type="date" id="ep-posted-{{ $position->id }}" name="posted_on" value="{{ $position->posted_on?->toDateString() }}" /></div>
                <div class="field"><label for="ep-closes-{{ $position->id }}">Closes on</label>
                  <input type="date" id="ep-closes-{{ $position->id }}" name="closes_on" value="{{ $position->closes_on?->toDateString() }}" /></div>
                <div class="field"><label for="ep-status-{{ $position->id }}">Status</label>
                  <select id="ep-status-{{ $position->id }}" name="status">
                    @foreach (['open' => 'Open', 'closed' => 'Closed', 'filled' => 'Filled'] as $value => $label)
                      <option value="{{ $value }}" @selected($position->status === $value)>{{ $label }}</option>
                    @endforeach
                  </select></div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif
@endsection
