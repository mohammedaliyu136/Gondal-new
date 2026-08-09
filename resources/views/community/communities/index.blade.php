@extends('layouts.app')
@section('title', 'Communities')

@section('content')
  <div class="page-head">
    <div>
      <h1>Communities</h1>
      <p>{{ number_format($communities->total()) }} settlements &middot; farmers belong to one, and collection points stand in one</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('farmers.index') }}" class="btn btn-outline">Farmers</a>
      @if ($canManage)
        <a href="#modal-community" class="btn btn-primary">+ Add community</a>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Communities</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Community name" /></div>
        <div class="field"><label for="lga">LGA</label>
          <select id="lga" name="lga" data-searchable data-combo-placeholder="Search LGAs…">
            <option value="">All</option>
            @foreach ($lgas as $lga)
              <option value="{{ $lga->id }}" @selected(request('lga') == $lga->id)>{{ $lga->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label>&nbsp;</label>
          <button type="submit" class="btn btn-outline">Filter</button></div>
        @if (request()->hasAny(['q', 'lga']))
          <div class="field"><label>&nbsp;</label>
            <a href="{{ route('communities.index') }}" class="btn btn-ghost">Clear</a></div>
        @endif
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Community</th><th>LGA</th>
            <th class="num">Farmers</th><th class="num">Collection points</th>
            @if ($canManage)<th class="actions">Actions</th>@endif
          </tr></thead>
          <tbody>
            @forelse ($communities as $community)
              <tr>
                <td class="font-bold">{{ $community->name }}</td>
                <td>{{ $community->lga?->name ?? '—' }}</td>
                <td class="num">{{ number_format($community->farmers_count) }}</td>
                <td class="num">{{ number_format($community->collection_points_count) }}</td>
                @if ($canManage)
                  <td class="actions">
                    <a href="#modal-edit-community-{{ $community->id }}" class="btn btn-ghost btn-sm">Edit</a>
                  </td>
                @endif
              </tr>
            @empty
              <tr><td colspan="{{ $canManage ? 5 : 4 }}">@include('partials.empty', [
                'title' => 'No communities match',
                'message' => 'Clear the filter, or add the settlement you are looking for.',
                'icon' => '&#127968;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $communities, 'noun' => 'communities'])
  </div>

  @if ($canManage)
    <div id="modal-community" class="modal @if (old('_modal') === 'modal-community') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Add community</h3>
            <p>A settlement farmers belong to and collection points stand in</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('communities.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-community" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-community'])
            <div class="field"><label for="com-lga">LGA <span class="req">*</span></label>
              <select id="com-lga" name="lga_id" data-searchable data-combo-placeholder="Search LGAs…" required>
                <option value="">&mdash;</option>
                @foreach ($lgas as $lga)
                  <option value="{{ $lga->id }}" @selected(old('lga_id') == $lga->id)>{{ $lga->name }}</option>
                @endforeach
              </select></div>
            <div class="field"><label for="com-name">Name <span class="req">*</span></label>
              <input type="text" id="com-name" name="name" value="{{ old('name') }}" required />
              <div class="hint">Two settlements may share a name across LGAs, but not within one.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Add community</button>
          </div>
        </form>
      </div>
    </div>

    @foreach ($communities as $community)
      <div id="modal-edit-community-{{ $community->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog narrow">
          <div class="modal-head">
            <div><h3>Edit {{ $community->name }}</h3>
              <p>{{ number_format($community->farmers_count) }} farmers &middot;
                 {{ number_format($community->collection_points_count) }} collection points</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('communities.update', $community) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-edit-community-{{ $community->id }}" /> @method('PUT')
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-community-'.$community->id.''])
              <div class="field"><label for="ec-lga-{{ $community->id }}">LGA <span class="req">*</span></label>
                <select id="ec-lga-{{ $community->id }}" name="lga_id" data-searchable data-combo-placeholder="Search LGAs…" required>
                  @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}" @selected($community->lga_id == $lga->id)>{{ $lga->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ec-name-{{ $community->id }}">Name <span class="req">*</span></label>
                <input type="text" id="ec-name-{{ $community->id }}" name="name" value="{{ $community->name }}" required /></div>
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
