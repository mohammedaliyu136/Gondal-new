@extends('layouts.app')
@section('title', $point->name.' · Farmers')

@section('content')
  <div class="crumbs">
    <a href="{{ route('collection-points.index') }}">Collection points</a><span class="sep">/</span>
    <a href="{{ route('collection-points.show', $point) }}">{{ $point->name }}</a><span class="sep">/</span>
    <span class="here">Farmers</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Farmers at {{ $point->name }}</h1>
      <p>{{ number_format($farmers->total()) }} registered &middot;
         {{ $point->community?->name }}{{ $point->community?->lga?->name ? ', '.$point->community->lga->name : '' }}</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('collection-points.show', $point) }}" class="btn btn-outline">Back to point</a>
      @if ($canAssignFarmers)
        <a href="#modal-assign-farmer" class="btn btn-primary">Assign farmer</a>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Registered farmers</h3>
      <p>Each farmer delivers to one point</p></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" /></div>
        <div class="field"><label>&nbsp;</label>
          <button type="submit" class="btn btn-outline">Search</button></div>
        @if (request()->filled('q'))
          <div class="field"><label>&nbsp;</label>
            <a href="{{ route('collection-points.farmers', $point) }}" class="btn btn-ghost">Clear</a></div>
        @endif
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Farmer</th><th>Code</th><th>Cooperative</th>
            @if ($canAssignFarmers)<th class="actions">Actions</th>@endif
          </tr></thead>
          <tbody>
            @forelse ($farmers as $farmer)
              <tr>
                <td>
                  @can('community.farmers.view')
                    <a href="{{ route('farmers.show', $farmer) }}" class="text-primary">{{ $farmer->name }}</a>
                  @else
                    {{ $farmer->name }}
                  @endcan
                </td>
                <td class="perm-key">{{ $farmer->code }}</td>
                <td>{{ $farmer->cooperative?->name ?? '—' }}</td>
                @if ($canAssignFarmers)
                  <td class="actions">
                    <form method="POST" action="{{ route('collection-points.farmers.unassign', [$point, $farmer]) }}">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-ghost btn-sm text-danger">Remove</button>
                    </form>
                  </td>
                @endif
              </tr>
            @empty
              <tr><td colspan="{{ $canAssignFarmers ? 4 : 3 }}">@include('partials.empty', [
                'title' => request()->filled('q') ? 'No farmer matches that search' : 'No farmers assigned here yet',
                'message' => request()->filled('q')
                  ? 'Clear the search to see everyone registered at this point.'
                  : 'Assign the households that bring milk to this point.',
                'icon' => '&#128101;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $farmers, 'noun' => 'farmers'])
  </div>

  @if ($canAssignFarmers)
    {{-- Reopens after a search, so the two steps feel like one. --}}
    <div id="modal-assign-farmer" class="modal @if (old('_modal') === 'modal-assign-farmer' || $assignSearch !== '') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Assign a farmer</h3><p>To {{ $point->name }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        {{--
          A separate GET form, above the POST one and not inside it: nesting forms
          is invalid and the browser silently drops the inner one.
        --}}
        <div class="modal-body" style="padding-bottom:0">
          <form method="GET" action="{{ route('collection-points.farmers', $point) }}" class="flex">
            <div class="field" style="flex:1">
              <label for="af-search">Find a farmer</label>
              <input type="text" id="af-search" name="assign" value="{{ $assignSearch }}"
                     placeholder="Name or code" autocomplete="off" />
            </div>
            <div class="field"><label>&nbsp;</label>
              <button type="submit" class="btn btn-outline">Search</button></div>
          </form>
        </div>

        <form method="POST" action="{{ route('collection-points.farmers.assign', $point) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-assign-farmer" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-assign-farmer'])
            @if ($assignableFarmers->isEmpty())
              <div class="text-small text-muted mb-16">
                @if ($assignSearch === '')
                  Search for the farmer by name or code. The register is too large to scroll.
                @else
                  No farmer matches &ldquo;{{ $assignSearch }}&rdquo; who could be assigned here.
                @endif
              </div>
            @else
              <div class="field mb-16"><label for="af-farmer">Farmer <span class="req">*</span></label>
                <select id="af-farmer" name="farmer_id" data-searchable data-combo-placeholder="Narrow further…" required>
                  <option value="">&mdash;</option>
                  @foreach ($assignableFarmers as $candidate)
                    <option value="{{ $candidate->id }}" @selected(old('farmer_id') == $candidate->id)>
                      {{ $candidate->name }} &mdash; {{ $candidate->code }}@if ($candidate->default_collection_point_id) (moves from another point)@endif
                    </option>
                  @endforeach
                </select>
                <div class="hint">
                  {{ $assignableFarmers->count() }} match{{ $assignableFarmers->count() === 1 ? '' : 'es' }}.
                  A farmer delivers to one point, so assigning moves them from wherever they were.
                </div></div>
            @endif
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Assign farmer</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
