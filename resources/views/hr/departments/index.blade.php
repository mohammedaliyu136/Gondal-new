@extends('layouts.app')
@section('title', 'Departments')

@section('content')
  <div class="page-head">
    <div>
      <h1>Departments</h1>
      <p>{{ number_format($departments->total()) }} departments</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('employees.index') }}" class="btn btn-outline">Employees</a>
      @if ($canManage)
        <a href="#modal-department" class="btn btn-primary">+ Add department</a>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Departments</h3></div></div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Department</th><th>Head</th><th>Cost centre</th>
            <th class="num">Employees</th><th class="num">Requisitions</th><th>Status</th>
            @if ($canManage)<th class="actions">Actions</th>@endif</tr></thead>
          <tbody>
            @forelse ($departments as $department)
              <tr>
                <td class="font-bold">{{ $department->name }}</td>
                <td>{{ $department->head?->name ?? '—' }}</td>
                <td class="mono">{{ $department->cost_centre ?? '—' }}</td>
                <td class="num">{{ $department->employees_count }}</td>
                <td class="num">{{ $department->requisitions_count }}</td>
                <td><span class="badge {{ $department->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($department->status) }}</span></td>
                @if ($canManage)
                  <td class="actions"><a href="#modal-edit-dep-{{ $department->id }}" class="btn btn-ghost btn-sm">Edit</a></td>
                @endif
              </tr>
            @empty
              <tr><td colspan="{{ $canManage ? 7 : 6 }}">@include('partials.empty', ['title' => 'No departments yet', 'icon' => '&#127970;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $departments, 'noun' => 'departments'])
  </div>

  @if ($canManage)
    <div id="modal-department" class="modal @if (old('_modal') === 'modal-department') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Add department</h3><p>Departments group staff and route requisition approvals</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('departments.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-department" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-department'])
            <div class="field"><label for="dep-name">Name <span class="req">*</span></label>
              <input type="text" id="dep-name" name="name" value="{{ old('name') }}" required /></div>
            <div class="field"><label for="dep-cost">Cost centre</label>
              <input type="text" id="dep-cost" name="cost_centre" value="{{ old('cost_centre') }}" /></div>
            <div class="field"><label for="dep-head">Department head</label>
              <select id="dep-head" data-searchable data-combo-placeholder="Search staff by name or email…" name="head_user_id">
                <option value="">&mdash;</option>
                @foreach ($heads as $head)
                  {{--
                    The email is part of the label, not decoration: several people
                    hold two accounts, and without it two options read identically
                    and the wrong one is chosen silently.
                  --}}
                  <option value="{{ $head->id }}" @selected(old('head_user_id') == $head->id)>{{ $head->name }} &mdash; {{ $head->email }}</option>
                @endforeach
              </select>
              <div class="hint">The head approves requisitions raised by this department.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Add department</button>
          </div>
        </form>
      </div>
    </div>

    {{--
      `departments.update` was a live route no screen posted to, so a department's
      head and cost centre were fixed at creation. The head routes requisition
      approvals — when they leave, the queue keeps going to them.
    --}}
    @foreach ($departments as $department)
      <div id="modal-edit-dep-{{ $department->id }}" class="modal @if (old('_modal') === 'modal-edit-dep-'.$department->id) open @endif">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog narrow">
          <div class="modal-head">
            <div><h3>Edit {{ $department->name }}</h3>
              <p>{{ number_format($department->employees_count) }} staff &middot;
                 {{ number_format($department->requisitions_count) }} requisitions</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('departments.update', $department) }}">
            @csrf
            <input type="hidden" name="_modal" value="modal-edit-dep-{{ $department->id }}" /> @method('PUT')
            <div class="modal-body">
              @include('partials.modal-errors', ['modal' => 'modal-edit-dep-'.$department->id])
              <div class="field"><label for="ed-name-{{ $department->id }}">Name <span class="req">*</span></label>
                <input type="text" id="ed-name-{{ $department->id }}" name="name" value="{{ $department->name }}" required /></div>
              <div class="field"><label for="ed-cost-{{ $department->id }}">Cost centre</label>
                <input type="text" id="ed-cost-{{ $department->id }}" name="cost_centre" value="{{ $department->cost_centre }}" /></div>
              <div class="field"><label for="ed-head-{{ $department->id }}">Department head</label>
                <select id="ed-head-{{ $department->id }}" data-searchable data-combo-placeholder="Search staff by name or email…" name="head_user_id">
                  <option value="">&mdash;</option>
                  @foreach ($heads as $head)
                    <option value="{{ $head->id }}" @selected($department->head_user_id == $head->id)>{{ $head->name }} &mdash; {{ $head->email }}</option>
                  @endforeach
                </select>
                <div class="hint">Changing the head changes who approves this department's requisitions from now on.</div></div>
              <div class="field"><label for="ed-status-{{ $department->id }}">Status</label>
                <select id="ed-status-{{ $department->id }}" name="status">
                  @foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $value => $label)
                    <option value="{{ $value }}" @selected($department->status === $value)>{{ $label }}</option>
                  @endforeach
                </select>
                <div class="hint">An inactive department stays on its historical records; it is only withdrawn from the pickers.</div></div>
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
