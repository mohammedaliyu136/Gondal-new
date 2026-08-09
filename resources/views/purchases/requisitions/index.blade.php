@extends('layouts.app')
@section('title', 'Requisitions')

@section('content')
  <div class="page-head">
    <div>
      <h1>Requisitions</h1>
      <p>{{ number_format($requisitions->total()) }} in your scope &middot; {{ number_format($awaitingCount) }} in review</p>
    </div>
    <div class="page-actions">
      @can('purchase.approve.*')
        <a href="{{ route('approvals.index') }}" class="btn btn-outline">My Approvals</a>
      @endcan
      @if ($canCreate)
        <a href="#modal-new-req" class="btn btn-primary">+ New Requisition</a>
      @endif
    </div>
  </div>

  @if ($workflow)
    {{-- BR-19 — show where a total will route before anyone submits. --}}
    <div class="alert info mb-16">
      <span>&#8505;&#65039;</span>
      <div>
        <strong>Routing is by amount band.</strong>
        @foreach ($workflow->bands as $band)
          {{ $band->name }}: {{ $band->describeRange() }} &rarr;
          {{ $band->stages->pluck('name')->implode(' &rarr; ') }}@if (! $loop->last);@endif
        @endforeach
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>All Requisitions</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Reference or title" /></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['draft', 'in_review', 'approved', 'rejected', 'cancelled'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="department">Department</label>
          <select id="department" name="department">
            <option value="">All</option>
            @foreach ($departments as $department)
              <option value="{{ $department->id }}" @selected(request('department') == $department->id)>{{ $department->name }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('requisitions.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Reference</th><th>Item</th><th>Requester</th><th>Department</th>
            <th class="num">Amount</th><th>Stage</th><th>Urgency</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($requisitions as $requisition)
              <tr>
                <td><a href="{{ route('requisitions.show', $requisition) }}" class="perm-key">{{ $requisition->reference }}</a>
                  @if ($requisition->revises_requisition_id)<div class="cell-sub">revision</div>@endif</td>
                <td>{{ $requisition->title }}
                  @if ($requisition->category)<div class="cell-sub">{{ $requisition->category }}</div>@endif</td>
                <td>{{ $requisition->requester?->name }}</td>
                <td>{{ $requisition->department?->name ?? '—' }}</td>
                <td class="num font-bold">{{ \App\Support\Money::format($requisition->total_minor) }}
                  @if ($requisition->approved_total_minor !== null && $requisition->approved_total_minor !== $requisition->total_minor)
                    <div class="cell-sub">approved {{ \App\Support\Money::format($requisition->approved_total_minor) }}</div>
                  @endif</td>
                <td>
                  @if ($requisition->workflowInstance?->currentStage)
                    {{ $requisition->workflowInstance->currentStage->name }}
                    <div class="cell-sub">{{ $requisition->workflowInstance->stageNumber() }} of {{ $requisition->workflowInstance->stageCount() }}</div>
                  @else
                    &mdash;
                  @endif
                </td>
                <td><span class="badge {{ ['low' => 'muted', 'normal' => 'info', 'high' => 'danger'][$requisition->urgency] ?? 'muted' }}">
                  {{ ucfirst($requisition->urgency) }}</span></td>
                <td><span class="badge {{ [
                  'draft' => 'muted', 'in_review' => 'warning', 'approved' => 'success',
                  'rejected' => 'danger', 'cancelled' => 'muted',
                ][$requisition->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($requisition->status) }}</span></td>
                <td class="actions"><a href="{{ route('requisitions.show', $requisition) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', ['title' => 'No requisitions in your scope', 'icon' => '&#128221;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $requisitions, 'noun' => 'requisitions'])
  </div>

  @if ($canCreate)
    <div id="modal-new-req" class="modal @if (old('_modal') === 'modal-new-req') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>New Requisition</h3><p>The total decides which approval route it follows</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('requisitions.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-req" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-req'])
            <div class="form-grid">
              <div class="field full"><label for="nr-title">Title <span class="req">*</span></label>
                <input type="text" id="nr-title" name="title" required /></div>
              <div class="field"><label for="nr-department">Department</label>
                <select id="nr-department" name="department_id">
                  <option value="">My department</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="nr-category">Category</label>
                <input type="text" id="nr-category" name="category" /></div>
              <div class="field"><label for="nr-urgency">Urgency <span class="req">*</span></label>
                <select id="nr-urgency" name="urgency" required>
                  <option value="low">Low</option>
                  <option value="normal" selected>Normal</option>
                  <option value="high">High</option>
                </select></div>
              <div class="field"><label for="nr-needed">Needed by</label>
                <input type="date" id="nr-needed" name="needed_by" /></div>
              <div class="field full"><label for="nr-vendor">Suggested vendor</label>
                <input type="text" id="nr-vendor" name="suggested_vendor" />
                <div class="hint">Type the vendor name.</div></div>
            </div>

            <div class="divider"></div>
            <h3 class="mb-16">Line items</h3>
            @for ($i = 0; $i < 3; $i++)
              <div class="form-grid mb-16">
                <div class="field"><label for="nr-item-{{ $i }}">Item{!! $i === 0 ? ' <span class="req">*</span>' : '' !!}</label>
                  <input type="text" id="nr-item-{{ $i }}" name="items[{{ $i }}][item]" @required($i === 0) /></div>
                <div class="field"><label for="nr-purpose-{{ $i }}">Purpose</label>
                  <input type="text" id="nr-purpose-{{ $i }}" name="items[{{ $i }}][purpose]" /></div>
                <div class="field"><label for="nr-qty-{{ $i }}">Quantity{!! $i === 0 ? ' <span class="req">*</span>' : '' !!}</label>
                  <input type="text" id="nr-qty-{{ $i }}" name="items[{{ $i }}][quantity]" inputmode="decimal"
                         value="{{ $i === 0 ? '1' : '' }}" @required($i === 0) /></div>
                <div class="field"><label for="nr-unit-{{ $i }}">Unit</label>
                  <input type="text" id="nr-unit-{{ $i }}" name="items[{{ $i }}][unit]" /></div>
                <div class="field"><label for="nr-price-{{ $i }}">Unit price (₦){!! $i === 0 ? ' <span class="req">*</span>' : '' !!}</label>
                  <input type="text" id="nr-price-{{ $i }}" name="items[{{ $i }}][unit_price]" inputmode="decimal"
                         value="{{ $i === 0 ? '0' : '' }}" @required($i === 0) /></div>
              </div>
            @endfor
            <div class="hint">Enter unit prices in naira and kobo, for example 1500.50.</div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-outline">Save draft</button>
            <button type="submit" name="submit" value="1" class="btn btn-primary">Save and submit</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
