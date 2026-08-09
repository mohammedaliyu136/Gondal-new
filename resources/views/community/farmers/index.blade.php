@extends('layouts.app')
@section('title', 'Farmers')

@section('content')
  <div class="page-head">
    <div>
      <h1>Farmers</h1>
      <p>{{ number_format($farmers->total()) }} in your scope &middot; {{ number_format($activeCount) }} active</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-enrol" class="btn btn-primary">+ Enrol Farmer</a>@endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Register</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name, code or phone" /></div>
        <div class="field"><label for="lga">LGA</label>
          <select id="lga" name="lga">
            <option value="">All</option>
            @foreach ($lgas as $lga)
              <option value="{{ $lga->id }}" @selected(request('lga') == $lga->id)>{{ $lga->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="cooperative">Cooperative</label>
          <select id="cooperative" name="cooperative">
            <option value="">All</option>
            @foreach ($cooperatives as $cooperative)
              <option value="{{ $cooperative->id }}" @selected(request('cooperative') == $cooperative->id)>{{ $cooperative->name }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach (['active', 'dormant', 'exited'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('farmers.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Farmer</th><th>Community</th><th>Cooperative</th><th>Default point</th>
            <th class="num">Herd</th><th class="num">Lactating</th><th>Phone</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($farmers as $farmer)
              <tr>
                <td><div class="font-bold">{{ $farmer->name }}</div><div class="cell-sub perm-key">{{ $farmer->code }}</div></td>
                <td>{{ $farmer->community?->name }}<div class="cell-sub">{{ $farmer->community?->lga?->name }}</div></td>
                <td>{{ $farmer->cooperative?->name ?? '—' }}
                  @if ($farmer->cooperative_member_no)<div class="cell-sub">{{ $farmer->cooperative_member_no }}</div>@endif</td>
                <td>{{ $farmer->defaultCollectionPoint?->name ?? '—' }}</td>
                <td class="num">{{ $farmer->herd_size ?? '—' }}</td>
                <td class="num">{{ $farmer->lactating_count ?? '—' }}</td>
                <td>{{ $farmer->phone ?? '—' }}</td>
                <td><span class="badge {{ ['active' => 'success', 'dormant' => 'warning', 'exited' => 'muted'][$farmer->status] ?? 'muted' }}">
                  {{ ucfirst($farmer->status) }}</span></td>
                <td class="actions"><a href="{{ route('farmers.show', $farmer) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', [
                'title' => 'No farmers in your scope',
                'message' => 'Your data scope is '.auth()->user()->overallScopeDescription().'.',
                'icon' => '&#127806;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $farmers, 'noun' => 'farmers'])
  </div>

  @if ($canCreate)
    <div id="modal-enrol" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Enrol Farmer</h3><p>Farmers do not sign in &mdash; staff keep this record on their behalf</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('farmers.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-enrol" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-enrol'])
            <div class="form-grid">
              <div class="field"><label for="ef-code">Farmer code <span class="req">*</span></label>
                <input type="text" id="ef-code" name="code" required /></div>
              <div class="field"><label for="ef-name">Name <span class="req">*</span></label>
                <input type="text" id="ef-name" name="name" required /></div>
              <div class="field"><label for="ef-gender">Gender</label>
                <select id="ef-gender" name="gender">
                  <option value="">Not stated</option>
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                </select></div>
              <div class="field"><label for="ef-yob">Year of birth</label>
                <input type="number" id="ef-yob" name="year_of_birth" min="1900" max="{{ \App\Support\Wat::local()->format('Y') }}" /></div>
              <div class="field"><label for="ef-phone">Phone</label>
                <input type="text" id="ef-phone" name="phone" /></div>
              <div class="field"><label for="ef-community">Community <span class="req">*</span></label>
                <select id="ef-community" name="community_id" required>
                  @foreach ($communities as $community)
                    <option value="{{ $community->id }}">{{ $community->name }} ({{ $community->lga?->name }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-coop">Cooperative</label>
                <select id="ef-coop" name="cooperative_id">
                  <option value="">None</option>
                  @foreach ($cooperatives as $cooperative)
                    <option value="{{ $cooperative->id }}">{{ $cooperative->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-memberno">Cooperative member no.</label>
                <input type="text" id="ef-memberno" name="cooperative_member_no" /></div>
              <div class="field"><label for="ef-point">Default collection point</label>
                <select id="ef-point" name="default_collection_point_id">
                  <option value="">None</option>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}">{{ $point->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="ef-herd">Herd size</label>
                <input type="number" id="ef-herd" name="herd_size" min="0" /></div>
              <div class="field"><label for="ef-lact">Lactating cows</label>
                <input type="number" id="ef-lact" name="lactating_count" min="0" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Enrol farmer</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
