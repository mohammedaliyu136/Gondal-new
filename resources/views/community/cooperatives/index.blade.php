@extends('layouts.app')
@section('title', 'Cooperatives')

@section('content')
  <div class="page-head">
    <div>
      <h1>Cooperatives</h1>
      <p>{{ number_format($cooperatives->total()) }} in your scope</p>
    </div>
    <div class="page-actions">
      @if ($canCreate)<a href="#modal-new-coop" class="btn btn-primary">+ Onboard Cooperative</a>@endif
    </div>
  </div>

  {{--
    §15.3 and NG-1, rendered rather than assumed. docs/OPEN-DECISIONS.md names
    this screen as one that states both; it stated neither, so the two people who
    could close the question had no way to know it was open.
  --}}
  <div class="alert info mb-16">
    <span>&#128203;</span>
    <div>
      <strong>The manual cooperative forms are still outstanding.</strong>
      These records hold the specified fields only; anything the forms turn out to need will be added when
      they arrive. Loans and investments are out of scope for this version &mdash; each cooperative
      has a general fund and a social fund, and no loan book.
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Register</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="q">Search</label>
          <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" /></div>
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="dormant" @selected(request('status') === 'dormant')>Dormant</option>
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('cooperatives.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Cooperative</th><th>Community</th><th>Chairman</th><th class="num">Members</th>
            <th class="num">Savings %</th><th class="num">Levy %</th>
            @if ($seesSavings)<th class="num">General fund</th><th class="num">Social fund</th>@endif
            <th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($cooperatives as $cooperative)
              <tr>
                <td><div class="font-bold">{{ $cooperative->name }}</div><div class="cell-sub perm-key">{{ $cooperative->code }}</div></td>
                <td>{{ $cooperative->community?->name }}<div class="cell-sub">{{ $cooperative->community?->lga?->name }}</div></td>
                <td>{{ $cooperative->chairman_name ?? '—' }}</td>
                <td class="num">{{ number_format($cooperative->members_count) }}</td>
                <td class="num">{{ rtrim(rtrim((string) $cooperative->savings_deduction_pct, '0'), '.') }}%</td>
                <td class="num">{{ rtrim(rtrim((string) $cooperative->levy_pct, '0'), '.') }}%</td>
                @if ($seesSavings)
                  <td class="num">{{ \App\Support\Money::format($cooperative->generalAccount()?->balance_minor ?? 0) }}</td>
                  <td class="num">{{ \App\Support\Money::format($cooperative->socialAccount()?->balance_minor ?? 0) }}</td>
                @endif
                <td><span class="badge {{ $cooperative->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($cooperative->status) }}</span></td>
                <td class="actions"><a href="{{ route('cooperatives.show', $cooperative) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="{{ $seesSavings ? 10 : 8 }}">
                @include('partials.empty', ['title' => 'No cooperatives in your scope', 'icon' => '&#127974;'])
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $cooperatives, 'noun' => 'cooperatives'])
  </div>

  @unless ($seesSavings)
    <div class="alert warn mt-16">
      <span>&#128274;</span>
      <div>Fund balances are not shown to your role. Ask your supervisor if you need them.</div>
    </div>
  @endunless

  @if ($canCreate)
    <div id="modal-new-coop" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Onboard Cooperative</h3>
            <p>Defaults come from Settings: {{ $defaults['savings_pct'] }}% savings,
               {{ $defaults['levy_pct'] }}% levy,
               {{ \App\Support\Money::format($defaults['social_minor']) }} social per member</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('cooperatives.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-new-coop" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-new-coop'])
            <div class="form-grid">
              <div class="field"><label for="nc-code">Code <span class="req">*</span></label>
                <input type="text" id="nc-code" name="code" required /></div>
              <div class="field"><label for="nc-name">Name <span class="req">*</span></label>
                <input type="text" id="nc-name" name="name" required /></div>
              <div class="field"><label for="nc-registered">Registered on</label>
                <input type="date" id="nc-registered" name="registered_on" /></div>
              <div class="field"><label for="nc-community">Community <span class="req">*</span></label>
                <select id="nc-community" name="community_id" required>
                  @foreach ($communities as $community)
                    <option value="{{ $community->id }}">{{ $community->name }} ({{ $community->lga?->name }})</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="nc-point">Collection point</label>
                <select id="nc-point" name="collection_point_id">
                  <option value="">None</option>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}">{{ $point->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="nc-phone">Contact phone</label>
                <input type="text" id="nc-phone" name="contact_phone" /></div>
              <div class="field"><label for="nc-chairman">Chairman</label>
                <input type="text" id="nc-chairman" name="chairman_name" /></div>
              <div class="field"><label for="nc-secretary">Secretary</label>
                <input type="text" id="nc-secretary" name="secretary_name" /></div>
              <div class="field"><label for="nc-treasurer">Treasurer</label>
                <input type="text" id="nc-treasurer" name="treasurer_name" /></div>
              <div class="field"><label for="nc-savings">Savings deduction (%)</label>
                <input type="text" id="nc-savings" name="savings_deduction_pct" inputmode="decimal"
                       value="{{ $defaults['savings_pct'] }}" /></div>
              <div class="field"><label for="nc-levy">Levy (%)</label>
                <input type="text" id="nc-levy" name="levy_pct" inputmode="decimal"
                       value="{{ $defaults['levy_pct'] }}" /></div>
              <div class="field"><label for="nc-social">Social contribution (₦ / member / month)</label>
                <input type="text" id="nc-social" name="social_contribution" inputmode="decimal"
                       value="{{ \App\Support\Money::decimal($defaults['social_minor']) }}" /></div>
            </div>
            {{--
              This used to claim the percentages were saved onto each payable as
              it was calculated. Nothing calculated a payable, and nothing saved
              them — they were plain columns overwritten in place. It now says
              what actually happens.
            --}}
            <div class="hint mt-16">
              Every change to a percentage is dated and kept, so the set in force on any past date can
              always be read back. Nothing calculates a farmer payable yet &mdash; where the payment module
              lives has not been decided.
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Onboard cooperative</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
