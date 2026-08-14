@extends('layouts.app')
@section('title', 'Cash Book')

@section('content')
  <div class="page-head">
    <div>
      <h1>Cash Book</h1>
      <p>What was taken out of the safe, what reached a farmer, and what came back</p>
    </div>
    <div class="page-actions">
      @if ($canIssue)<a href="#modal-issue-float" class="btn btn-primary">+ Issue a float</a>@endif
    </div>
  </div>

  <div class="grid grid-4 mb-16">
    <div class="stat blue"><div class="stat-label">Open floats</div>
      <div class="stat-value">{{ number_format($outstanding['floats']) }}</div>
      <div class="stat-foot">people holding money right now</div></div>
    <div class="stat"><div class="stat-label">Drawn</div>
      <div class="stat-value">{{ \App\Support\Money::compact($outstanding['drawn']) }}</div>
      <div class="stat-foot">out of the safe and not back</div></div>
    <div class="stat green"><div class="stat-label">Recorded as paid out</div>
      <div class="stat-value">{{ \App\Support\Money::compact($outstanding['disbursed']) }}</div>
      <div class="stat-foot">against those floats</div></div>
    {{-- The number this whole screen exists for. --}}
    <div class="stat {{ $outstanding['unaccounted'] > 0 ? 'red' : 'green' }}">
      <div class="stat-label">Not yet accounted for</div>
      <div class="stat-value">{{ \App\Support\Money::compact($outstanding['unaccounted']) }}</div>
      <div class="stat-foot">drawn, not yet disbursed or returned</div></div>
  </div>

  <div class="alert warn mb-16">
    <strong>A variance is a question, not an accusation.</strong>
    A note that would not change, a farmer who did not come, a rider paid out of the same bag
    &mdash; all of these are real and none of them are theft. What matters is that the gap is
    written down and explained by the person who was there, rather than absorbed silently.
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Floats</h3><p>Most recent first</p></div></div>
    <div class="card-body flush">
      @if ($floats->isEmpty())
        @include('partials.empty', [
          'title' => 'No cash floats recorded',
          'message' => 'Issue one when an officer takes money to a collection point.',
          'icon' => '&#128176;',
        ])
      @else
        <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th>Reference</th><th>Held by</th><th>Issued by</th><th>For</th>
              <th class="num">Drawn</th><th class="num">Disbursed</th><th class="num">Returned</th>
              <th class="num">Variance</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
              @foreach ($floats as $float)
                @php
                  $disbursed = $float->isOpen()
                    ? ($disbursedByFloat[$float->id] ?? 0)
                    : (int) $float->disbursed_minor;
                  $variance = $float->isOpen()
                    ? $float->unaccountedMinor($disbursed)
                    : (int) $float->variance_minor;
                @endphp
                <tr>
                  <td><span class="perm-key">{{ $float->reference }}</span>
                    <small class="hint d-block">{{ \App\Support\Wat::dateTime($float->opened_at) }}</small></td>
                  <td>{{ $float->drawnBy?->name }}</td>
                  <td>{{ $float->issuedBy?->name }}</td>
                  <td>
                    {{ $float->purpose?->reference ?? '—' }}
                    @if ($float->collectionCenter)
                      <small class="hint d-block">{{ $float->collectionCenter->name }}</small>
                    @endif
                  </td>
                  <td class="num">{{ \App\Support\Money::format($float->amount_drawn_minor) }}</td>
                  <td class="num">{{ \App\Support\Money::format($disbursed) }}</td>
                  <td class="num">{{ $float->amount_returned_minor === null ? '—' : \App\Support\Money::format($float->amount_returned_minor) }}</td>
                  <td class="num {{ $variance > 0 ? 'font-bold' : '' }}">
                    {{ \App\Support\Money::format($variance) }}
                    @if (! $float->isOpen() && $float->variance_explanation)
                      <small class="hint d-block">{{ $float->variance_explanation }}</small>
                    @endif
                  </td>
                  <td><span class="badge {{ $float->isOpen() ? 'warning' : ($variance === 0 ? 'success' : 'danger') }}">
                    {{ $float->isOpen() ? 'Open' : 'Reconciled' }}</span></td>
                  <td class="actions">
                    @if ($canReconcile && $float->isOpen())
                      <a href="#modal-reconcile-{{ $float->id }}" class="btn btn-sm btn-primary">Sign back in</a>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
    @if ($floats->hasPages())<div class="card-body">{{ $floats->links() }}</div>@endif
  </div>

  @if ($canIssue)
    <div id="modal-issue-float" class="modal @if (old('_modal') === 'modal-issue-float') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Issue a cash float</h3><p>Money leaving the safe, into somebody's hands</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('cash-floats.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-issue-float" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-issue-float'])
            <div class="form-grid">
              <div class="field"><label for="cf-holder">Handed to</label>
                <select id="cf-holder" name="drawn_by_user_id" required data-searchable>
                  @foreach ($holders as $holder)
                    <option value="{{ $holder->id }}">{{ $holder->name }}</option>
                  @endforeach
                </select>
                {{-- BR-18's principle applied to cash: the issuer and the holder
                     are never the same person, enforced in the service. --}}
                <div class="hint">You cannot issue a float to yourself.</div></div>
              <div class="field"><label for="cf-amount">Amount (kobo)</label>
                <input type="number" id="cf-amount" name="amount_drawn_minor" min="1" required
                       value="{{ old('amount_drawn_minor') }}" /></div>
              <div class="field"><label for="cf-purpose">For</label>
                <select id="cf-purpose" name="purpose">
                  <option value="">General operating float</option>
                  @foreach ($runs as $run)
                    <option value="farmer:{{ $run->id }}">{{ $run->reference }} &middot; farmers &middot;
                      {{ \App\Support\Money::format($run->cash_required_minor) }}</option>
                  @endforeach
                  @foreach ($transportRuns as $run)
                    <option value="transport:{{ $run->id }}">{{ $run->reference }} &middot; transport &middot;
                      {{ \App\Support\Money::format($run->total_minor) }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="cf-center">Collection centre</label>
                <select id="cf-center" name="collection_center_id" data-searchable>
                  <option value="">—</option>
                  @foreach ($centers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select></div>
              <div class="field full"><label for="cf-notes">Notes</label>
                <input type="text" id="cf-notes" name="notes" value="{{ old('notes') }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Issue</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canReconcile)
    @foreach ($floats as $float)
      @if ($float->isOpen())
        @php
          $disbursed = $disbursedByFloat[$float->id] ?? 0;
          $expected = (int) $float->amount_drawn_minor - $disbursed;
        @endphp
        <div id="modal-reconcile-{{ $float->id }}" class="modal">
          <a href="#" class="modal-overlay"></a>
          <div class="modal-dialog narrow">
            <div class="modal-head"><div><h3>Sign {{ $float->reference }} back in</h3>
              <p>{{ $float->drawnBy?->name }} &middot; {{ \App\Support\Money::format($float->amount_drawn_minor) }} drawn</p></div>
              <a href="#" class="modal-close">&times;</a></div>
            <form method="POST" action="{{ route('cash-floats.reconcile', $float) }}">
              @csrf
              <div class="modal-body">
                <div class="grid grid-2 mb-16">
                  <div><div class="meta-label">Recorded as paid out</div>
                    <div class="meta-value">{{ \App\Support\Money::format($disbursed) }}</div></div>
                  <div><div class="meta-label">So this should come back</div>
                    <div class="meta-value">{{ \App\Support\Money::format($expected) }}</div></div>
                </div>
                <div class="field"><label for="rc-returned-{{ $float->id }}">Actually returned (kobo)</label>
                  <input type="number" id="rc-returned-{{ $float->id }}" name="amount_returned_minor"
                         min="0" value="{{ $expected }}" required /></div>
                <div class="field"><label for="rc-why-{{ $float->id }}">If it does not match, why?</label>
                  <input type="text" id="rc-why-{{ $float->id }}" name="variance_explanation" />
                  <div class="hint">Required when the figures differ. Written by whoever was there,
                    and read later by someone looking across many of these.</div></div>
              </div>
              <div class="modal-foot">
                <a href="#" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Reconcile</button>
              </div>
            </form>
          </div>
        </div>
      @endif
    @endforeach
  @endif
@endsection
