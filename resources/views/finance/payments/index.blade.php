@extends('layouts.app')
@section('title', 'Farmer Payments')

@section('content')
  <div class="page-head">
    <div>
      <h1>Farmer Payments</h1>
      <p>What the network owes farmers for their milk, and what has been paid</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-new-run" class="btn btn-primary">+ Generate a run</a>@endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Payment runs</h3><p>Most recent first</p></div></div>
    <div class="card-body flush">
      @if ($runs->isEmpty())
        @include('partials.empty', [
          'title' => 'No payment runs yet',
          'message' => 'Generate one for a collection centre or a cooperative to see what is owed.',
          'icon' => '&#128176;',
        ])
      @else
        <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th>Reference</th><th>Scope</th><th>Period</th><th class="num">Farmers</th>
              <th class="num">Gross</th><th class="num">Net</th><th class="num">Cash required</th>
              <th>Status</th>
            </tr></thead>
            <tbody>
              @foreach ($runs as $run)
                <tr>
                  <td><a href="{{ route('payment-runs.show', $run) }}" class="perm-key">{{ $run->reference }}</a></td>
                  <td>{{ \Illuminate\Support\Str::headline($run->scope_type) }} #{{ $run->scope_id }}</td>
                  <td>{{ $run->period_start?->toDateString() }} &rarr; {{ $run->period_end?->toDateString() }}</td>
                  <td class="num">{{ number_format($run->farmer_count) }}</td>
                  <td class="num">{{ \App\Support\Money::format($run->gross_total_minor) }}</td>
                  <td class="num">{{ \App\Support\Money::format($run->net_total_minor) }}</td>
                  {{-- The number Accounts actually sends. Net includes BR-36 held
                       money, which must NOT be loaded into a vehicle. --}}
                  <td class="num font-bold">{{ \App\Support\Money::format($run->cash_required_minor) }}</td>
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
    <div id="modal-new-run" class="modal @if (old('_modal') === 'modal-new-run') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Generate a payment run</h3>
            <p>Every delivery in scope that has been confirmed, priced, and not yet paid</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('payment-runs.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-run" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-run'])
            <div class="form-grid">
              <div class="field"><label for="pr-scope">Pay for</label>
                <select id="pr-scope" name="scope_type" required>
                  <option value="collection_center">A collection centre</option>
                  <option value="cooperative">A cooperative</option>
                </select></div>
              <div class="field"><label for="pr-id">Which one</label>
                <select id="pr-id" name="scope_id" required data-searchable>
                  <optgroup label="Collection centres">
                    @foreach ($centers as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach
                  </optgroup>
                  <optgroup label="Cooperatives">
                    @foreach ($cooperatives as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach
                  </optgroup>
                </select>
                <div class="hint">Pick the id from the matching group — the two lists are numbered separately.</div></div>
              <div class="field"><label for="pr-from">Period from</label>
                <input type="date" id="pr-from" name="period_start" value="{{ old('period_start') }}" /></div>
              <div class="field"><label for="pr-to">Period to</label>
                <input type="date" id="pr-to" name="period_end" value="{{ old('period_end') }}" /></div>
              <div class="field full">
                <div class="hint">
                  The dates label the run. What it actually claims is every unpaid delivery in
                  scope &mdash; so a consignment confirmed after its month closed is picked up
                  here rather than lost, and a delivery can never be paid twice.
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
