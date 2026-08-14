@extends('layouts.app')
@section('title', 'Transport Payments')

@section('content')
  <div class="page-head">
    <div>
      <h1>Transport Payments</h1>
      <p>Route fees owed to riders and drivers, and what has been paid</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-new-transport-run" class="btn btn-primary">+ Generate a run</a>@endif
    </div>
  </div>

  @if ($unclaimedTrips > 0)
    <div class="alert warn mb-16">
      <strong>{{ number_format($unclaimedTrips) }} completed trip(s) have never been paid for.</strong>
      Every leg carries the route fee that was in force when it was logged. Generating a run
      claims them; until then the fee sits against the trip and nobody is owed it on paper.
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>Payment runs</h3><p>Most recent first</p></div></div>
    <div class="card-body flush">
      @if ($runs->isEmpty())
        @include('partials.empty', [
          'title' => 'No transport payment runs yet',
          'message' => 'Generate one for a collection centre, or across the whole network.',
          'icon' => '&#128666;',
        ])
      @else
        <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th>Reference</th><th>Scope</th><th>Period</th>
              <th class="num">Drivers</th><th class="num">Trips</th><th class="num">Total</th><th>Status</th>
            </tr></thead>
            <tbody>
              @foreach ($runs as $run)
                <tr>
                  <td><a href="{{ route('transport-payments.show', $run) }}" class="perm-key">{{ $run->reference }}</a></td>
                  <td>
                    @if ($run->scope_type === 'network')
                      Whole network
                    @else
                      {{ $centers->firstWhere('id', $run->scope_id)?->name ?? 'Centre #'.$run->scope_id }}
                    @endif
                  </td>
                  <td>{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</td>
                  <td class="num">{{ number_format($run->driver_count) }}</td>
                  <td class="num">{{ number_format($run->trip_count) }}</td>
                  <td class="num font-bold">{{ \App\Support\Money::format($run->total_minor) }}</td>
                  <td><span class="badge {{ $run->status === 'paid' ? 'success' : ($run->status === 'cancelled' ? 'muted' : 'warning') }}">
                    {{ \Illuminate\Support\Str::headline($run->status) }}</span></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
    @if ($runs->hasPages())<div class="card-body">{{ $runs->links() }}</div>@endif
  </div>

  @if ($canCreate)
    <div id="modal-new-transport-run" class="modal @if (old('_modal') === 'modal-new-transport-run') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Generate a transport payment run</h3>
            <p>Every completed trip in scope that carries a fee and has not been paid</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('transport-payments.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-transport-run" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-transport-run'])
            <div class="form-grid">
              <div class="field full"><label for="tp-center">Pay for</label>
                <select id="tp-center" name="collection_center_id" data-searchable>
                  <option value="">The whole network</option>
                  @foreach ($centers as $c)<option value="{{ $c->id }}">{{ $c->code }} &middot; {{ $c->name }}</option>@endforeach
                </select>
                {{-- The network option is not a convenience. A trip whose centre
                     was never recorded can be reached no other way. --}}
                <div class="hint">A centre-scoped run can only reach trips whose centre was recorded.
                  Run the whole network to catch the rest.</div></div>
              <div class="field"><label for="tp-from">Period from</label>
                <input type="date" id="tp-from" name="period_start" value="{{ old('period_start') }}" /></div>
              <div class="field"><label for="tp-to">Period to</label>
                <input type="date" id="tp-to" name="period_end" value="{{ old('period_end') }}" /></div>
              <div class="field full">
                <div class="hint">
                  The dates label the run. What it actually claims is every unpaid trip that has
                  <em>arrived</em> &mdash; so a leg logged three days late is picked up here rather
                  than lost, and a trip can never be paid twice.
                </div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Generate</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
