@extends('layouts.app')
@section('title', $cooperative->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('cooperatives.index') }}">Cooperatives</a><span class="sep">/</span>
    <span class="here">{{ $cooperative->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ \Illuminate\Support\Str::substr($cooperative->code, -2) }}</div>
    <div class="dh-main">
      <h1>{{ $cooperative->name }}</h1>
      <div class="dh-sub">
        {{ $cooperative->code }} &middot; {{ $cooperative->community?->name }}, {{ $cooperative->community?->lga?->name }}
        &middot; {{ number_format($members->total()) }} members
      </div>
      <div class="dh-tags">
        <span class="badge {{ $cooperative->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($cooperative->status) }}</span>
        <span class="pill">{{ rtrim(rtrim((string) $cooperative->savings_deduction_pct, '0'), '.') }}% savings</span>
        <span class="pill">{{ rtrim(rtrim((string) $cooperative->levy_pct, '0'), '.') }}% levy</span>
      </div>
    </div>
    <div class="dh-actions">
      @if ($canPostEntry)
        <a href="#modal-entry" class="btn btn-primary">Post fund entry</a>
      @endif
      @if ($canEdit)
        <a href="#modal-edit-coop" class="btn btn-outline">Edit cooperative</a>
      @endif
    </div>
  </div>

  @if ($seesSavings)
    <div class="grid grid-3 mb-16">
      <div class="fund">
        <div class="fund-label">General cooperative fund</div>
        <div class="fund-value">{{ \App\Support\Money::format($cooperative->generalAccount()?->balance_minor ?? 0) }}</div>
        <div class="fund-foot">{{ $generalEntries->count() }} recent entries</div>
      </div>
      {{-- Members' money, kept off the general account so a purchase on credit
           cannot draw it down. Pooled, not per-member: the cooperative can be
           told what the pool holds, a member still cannot be told their share. --}}
      <div class="fund">
        <div class="fund-label">Members' savings held</div>
        <div class="fund-value">{{ \App\Support\Money::format($cooperative->savingsAccount()?->balance_minor ?? 0) }}</div>
        <div class="fund-foot">{{ $cooperative->savings_deduction_pct ? rtrim(rtrim((string) $cooperative->savings_deduction_pct, '0'), '.').'% of milk value' : 'no deduction set' }}</div>
      </div>
      <div class="fund">
        <div class="fund-label">Social fund</div>
        <div class="fund-value">{{ \App\Support\Money::format($cooperative->socialAccount()?->balance_minor ?? 0) }}</div>
        <div class="fund-foot">{{ \App\Support\Money::format($cooperative->social_contribution_minor) }} per member per month</div>
      </div>
    </div>
  @else
    <div class="alert warn mb-16">
      <span>&#128274;</span>
      <div>
        Fund balances and the ledger are not shown to your role. You can see the cooperative record but
        not its money.
      </div>
    </div>
  @endif

  {{--
    NG-1 and §15.3 — rendered, not left as a comment. The whole value of an open
    decision is that the people who can close it see it, and a Blade comment
    emits no HTML: this screen was cited in docs/OPEN-DECISIONS.md as stating
    both, and stated neither.
  --}}
  <div class="grid grid-2 mb-16">
    <div class="card">
      <div class="card-head"><div><h3>Loans &amp; Investments</h3><p>Not enabled in this version</p></div></div>
      <div class="card-body">
        <div class="hint">
          Cooperative loans and investments are out of scope for this version. Only the two funds above
          exist &mdash; there is no loan book to look for.
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><div><h3>Cooperative Forms</h3><p>Awaiting the forms</p></div></div>
      <div class="card-body">
        <div class="hint">
          The manual cooperative forms are still outstanding, so this record holds the specified fields and
          no more. Fields the forms turn out to require will be added then rather than guessed at now.
        </div>
      </div>
    </div>
  </div>

  <div class="split">
    <div class="stack">
      @if ($seesSavings)
        <div class="card">
          <div class="card-head"><div><h3>General Fund Ledger</h3><p>Each entry carries the balance it produced</p></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Date</th><th>Description</th><th>Direction</th>
                  <th class="num">Amount</th><th class="num">Balance after</th><th>By</th></tr></thead>
                <tbody>
                  @forelse ($generalEntries as $entry)
                    <tr>
                      <td>{{ \App\Support\Wat::date($entry->entry_date) }}</td>
                      <td>{{ $entry->description }}</td>
                      <td><span class="badge {{ $entry->direction === 'in' ? 'success' : 'danger' }}">
                        {{ $entry->direction === 'in' ? 'In' : 'Out' }}</span></td>
                      <td class="num">{{ \App\Support\Money::format($entry->amount_minor) }}</td>
                      <td class="num font-bold">{{ \App\Support\Money::format($entry->balance_after_minor) }}</td>
                      <td>{{ $entry->createdBy?->name ?? 'System' }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="6">@include('partials.empty', ['title' => 'No entries yet', 'icon' => '&#128181;'])</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h3>Social Fund Ledger</h3></div></div>
          <div class="card-body flush">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Date</th><th>Description</th><th>Direction</th>
                  <th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
                <tbody>
                  @forelse ($socialEntries as $entry)
                    <tr>
                      <td>{{ \App\Support\Wat::date($entry->entry_date) }}</td>
                      <td>{{ $entry->description }}</td>
                      <td><span class="badge {{ $entry->direction === 'in' ? 'success' : 'danger' }}">
                        {{ $entry->direction === 'in' ? 'In' : 'Out' }}</span></td>
                      <td class="num">{{ \App\Support\Money::format($entry->amount_minor) }}</td>
                      <td class="num font-bold">{{ \App\Support\Money::format($entry->balance_after_minor) }}</td>
                    </tr>
                  @empty
                    <tr><td colspan="5">@include('partials.empty', ['title' => 'No entries yet', 'icon' => '&#128181;'])</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      @endif

      <div class="card">
        <div class="card-head"><div><h3>Members</h3><p>Farmers linked to this cooperative</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Farmer</th><th>Member no.</th><th>Community</th><th class="num">Herd</th><th>Status</th></tr></thead>
              <tbody>
                @forelse ($members as $member)
                  <tr>
                    <td><a href="{{ route('farmers.show', $member) }}">{{ $member->name }}</a>
                      <div class="cell-sub perm-key">{{ $member->code }}</div></td>
                    <td>{{ $member->cooperative_member_no ?? '—' }}</td>
                    <td>{{ $member->community?->name }}</td>
                    <td class="num">{{ $member->herd_size ?? '—' }}</td>
                    <td><span class="badge {{ $member->status === 'active' ? 'success' : 'muted' }}">{{ ucfirst($member->status) }}</span></td>
                  </tr>
                @empty
                  <tr><td colspan="5">@include('partials.empty', ['title' => 'No members linked yet', 'icon' => '&#127806;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @include('partials.pagination', ['paginator' => $members, 'noun' => 'members'])
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Officials</h3><p>Contacts on the record &mdash; not accounts</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Chairman</div><div class="meta-value">{{ $cooperative->chairman_name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Secretary</div><div class="meta-value">{{ $cooperative->secretary_name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Treasurer</div><div class="meta-value">{{ $cooperative->treasurer_name ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Phone</div><div class="meta-value">{{ $cooperative->contact_phone ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Registered</div><div class="meta-value">{{ \App\Support\Wat::date($cooperative->registered_on) }}</div></div>
            <div class="meta-item"><div class="meta-label">Collection point</div><div class="meta-value">{{ $cooperative->collectionPoint?->name ?? '—' }}</div></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Deduction Rates</h3><p>In force today</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Savings deduction</div>
              <div class="meta-value big">{{ rtrim(rtrim((string) $cooperative->savings_deduction_pct, '0'), '.') }}%</div></div>
            <div class="meta-item"><div class="meta-label">Levy</div>
              <div class="meta-value big">{{ rtrim(rtrim((string) $cooperative->levy_pct, '0'), '.') }}%</div></div>
          </div>
          <div class="hint mt-16">
            A change is dated and takes effect from that date. Nothing already calculated moves, because the
            history below keeps every set of percentages that has been in force.
          </div>
        </div>
        {{--
          The screen used to promise that "past payables keep the rate that was
          in force at the time" with nothing behind it: the percentages were
          plain columns updated in place, so the evidence of August's rate was
          destroyed by September's change. This table is that evidence.
        --}}
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>From</th><th class="num">Savings</th><th class="num">Levy</th>
                <th class="num">Social</th><th>Set by</th></tr></thead>
              <tbody>
                @forelse ($cooperative->rates as $rate)
                  <tr>
                    <td>{{ \App\Support\Wat::date($rate->effective_from) }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $rate->savings_deduction_pct, '0'), '.') }}%</td>
                    <td class="num">{{ rtrim(rtrim((string) $rate->levy_pct, '0'), '.') }}%</td>
                    <td class="num">{{ \App\Support\Money::format($rate->social_contribution_minor) }}</td>
                    <td>{{ $rate->createdBy?->name ?? 'System' }}</td>
                  </tr>
                @empty
                  <tr><td colspan="5">@include('partials.empty', [
                    'title' => 'No rate history recorded',
                    'message' => 'The next change will open one.',
                    'icon' => '&#128203;',
                  ])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          {{--
            §15.1 — the honest state of BR-15, said where somebody who could
            close the question will read it.
          --}}
          <div class="hint">
            Where a payable amount is calculated, the percentages in force on that date must be saved onto
            it. Nothing calculates a farmer payable yet &mdash; where the payment module lives has
            not been decided &mdash; so no payable carries a snapshot today.
          </div>
        </div>
      </div>
    </div>
  </div>

  @if ($canPostEntry)
    <div id="modal-entry" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head"><div><h3>Post Fund Entry</h3><p>{{ $cooperative->name }}</p></div>
          <a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('cooperatives.entries.store', $cooperative) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-entry" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-entry'])
            <div class="field mb-16"><label for="fe-kind">Account <span class="req">*</span></label>
              <select id="fe-kind" name="kind" required>
                <option value="general">General cooperative fund</option>
                <option value="social">Social fund</option>
              </select></div>
            <div class="field mb-16"><label for="fe-date">Entry date <span class="req">*</span></label>
              <input type="date" id="fe-date" name="entry_date" value="{{ \App\Support\Wat::today()->toDateString() }}" required /></div>
            <div class="field mb-16"><label for="fe-direction">Direction <span class="req">*</span></label>
              <select id="fe-direction" name="direction" required>
                <option value="in">In (credit)</option>
                <option value="out">Out (debit)</option>
              </select></div>
            <div class="field mb-16"><label for="fe-amount">Amount (₦) <span class="req">*</span></label>
              <input type="text" id="fe-amount" name="amount" inputmode="decimal" required /></div>
            <div class="field"><label for="fe-description">Description <span class="req">*</span></label>
              <input type="text" id="fe-description" name="description" required /></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Post entry</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canEdit)
    <div id="modal-edit-coop" class="modal @if (old('_modal') === 'modal-edit-coop') open @endif">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog wide">
        <div class="modal-head">
          <div><h3>Edit {{ $cooperative->name }}</h3><p>{{ $cooperative->code }} &middot; committee, contact and deductions</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('cooperatives.update', $cooperative) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-coop" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-coop'])
            <div class="form-grid">
              <div class="field"><label for="ec-name">Name <span class="req">*</span></label>
                <input type="text" id="ec-name" name="name" value="{{ old('name', $cooperative->name) }}" required /></div>
              <div class="field"><label for="ec-point">Collection point</label>
                <select id="ec-point" data-searchable data-combo-placeholder="Search collection points…" name="collection_point_id">
                  <option value="">&mdash;</option>
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}" @selected(old('collection_point_id', $cooperative->collection_point_id) == $point->id)>{{ $point->name }}</option>
                  @endforeach
                </select></div>
              {{-- USER-1 — officials are names on the record, not accounts. --}}
              <div class="field"><label for="ec-chair">Chairman</label>
                <input type="text" id="ec-chair" name="chairman_name" value="{{ old('chairman_name', $cooperative->chairman_name) }}" /></div>
              <div class="field"><label for="ec-sec">Secretary</label>
                <input type="text" id="ec-sec" name="secretary_name" value="{{ old('secretary_name', $cooperative->secretary_name) }}" /></div>
              <div class="field"><label for="ec-treas">Treasurer</label>
                <input type="text" id="ec-treas" name="treasurer_name" value="{{ old('treasurer_name', $cooperative->treasurer_name) }}" /></div>
              <div class="field"><label for="ec-phone">Contact phone</label>
                <input type="text" id="ec-phone" name="contact_phone" inputmode="tel" value="{{ old('contact_phone', $cooperative->contact_phone) }}" /></div>
              <div class="field"><label for="ec-status">Status</label>
                <select id="ec-status" name="status">
                  @foreach (['active' => 'Active', 'dormant' => 'Dormant', 'inactive' => 'Inactive'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $cooperative->status) === $value)>{{ $label }}</option>
                  @endforeach
                </select></div>
            </div>

            <div class="divider"></div>

            <div class="alert info mb-16">
              <span>&#128197;</span>
              <div>
                Deduction percentages are dated. Leaving them alone changes nothing; changing one writes a
                new entry in the rate history from the date below, and no payable already calculated moves.
              </div>
            </div>
            <div class="form-grid">
              <div class="field"><label for="ec-savings">Savings deduction (%)</label>
                <input type="text" id="ec-savings" name="savings_deduction_pct" inputmode="decimal"
                       value="{{ old('savings_deduction_pct', rtrim(rtrim((string) $cooperative->savings_deduction_pct, '0'), '.')) }}" /></div>
              <div class="field"><label for="ec-levy">Levy (%)</label>
                <input type="text" id="ec-levy" name="levy_pct" inputmode="decimal"
                       value="{{ old('levy_pct', rtrim(rtrim((string) $cooperative->levy_pct, '0'), '.')) }}" /></div>
              <div class="field"><label for="ec-social">Social contribution (&#8358;)</label>
                <input type="text" id="ec-social" name="social_contribution" inputmode="decimal"
                       value="{{ old('social_contribution', \App\Support\Money::decimal((int) $cooperative->social_contribution_minor)) }}" /></div>
              <div class="field"><label for="ec-effective">Effective from</label>
                <input type="date" id="ec-effective" name="effective_from"
                       value="{{ old('effective_from', \App\Support\Wat::today()->toDateString()) }}"
                       min="{{ \App\Support\Wat::today()->toDateString() }}" />
                <div class="hint">Today or later. A percentage cannot be back-dated over a payable that has already been worked out.</div></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
