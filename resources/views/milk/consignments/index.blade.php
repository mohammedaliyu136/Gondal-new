@extends('layouts.app')
@section('title', 'Consignments')

@section('content')
  <div class="page-head">
    <div>
      <h1>Consignments</h1>
      <p>Point &rarr; center &middot; a consignment carries the accepted litres of the deliveries on it</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('deliveries.index') }}" class="btn btn-outline">Deliveries</a>
      @if ($canRegrade)
        {{-- BR-4 — the control is only a control if somebody can read it. --}}
        <a href="{{ route('consignments.regrades') }}" class="btn btn-outline">Re-grades</a>
      @endif
      @if ($canDispatch)
        <a href="#modal-dispatch" class="btn btn-primary">+ Dispatch Consignment</a>
      @endif
    </div>
  </div>

  @if ($awaitingCount > 0)
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div><strong>{{ $awaitingCount }} awaiting confirmation.</strong>
        Confirming assigns the grade and saves the rate that will be paid.</div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>All Consignments</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach ([
              'awaiting_confirmation' => 'Awaiting confirmation',
              'confirmed' => 'Confirmed',
              'adjusted' => 'Adjusted',
              'partly_rejected' => 'Partly rejected',
            ] as $value => $label)
              <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
          </select></div>
        <div class="field"><label for="center">Center</label>
          <select id="center" name="center">
            <option value="">All centers</option>
            @foreach ($centers as $center)
              <option value="{{ $center->id }}" @selected(request('center') == $center->id)>{{ $center->name }}</option>
            @endforeach
          </select></div>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <a href="{{ route('consignments.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Reference</th><th>Point</th><th>Center</th><th class="num">Dispatched</th>
            <th class="num">Adjustments</th><th class="num">Rejected</th><th class="num">Confirmed</th>
            <th>Grade</th><th>Status</th><th>Batch</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($consignments as $consignment)
              <tr>
                <td class="perm-key">{{ $consignment->reference }}</td>
                <td>{{ $consignment->collectionPoint?->name }}</td>
                <td>{{ $consignment->collectionCenter?->name }}</td>
                <td class="num">{{ \App\Support\Volume::format($consignment->litres_dispatched, false) }}</td>
                <td class="num">{{ \App\Support\Volume::format($consignment->adjustmentTotal(), false) }}</td>
                <td class="num">{{ \App\Support\Volume::format($consignment->litres_rejected_at_center, false) }}</td>
                <td class="num font-bold">
                  {{ $consignment->litres_confirmed === null ? '—' : \App\Support\Volume::format($consignment->litres_confirmed, false) }}
                </td>
                <td>
                  @if ($consignment->grade)
                    <span class="badge {{ $consignment->grade->is_rejection ? 'danger' : 'success' }}">{{ $consignment->grade->name }}</span>
                  @else
                    <span class="badge muted">&mdash;</span>
                  @endif
                </td>
                <td><span class="badge {{ [
                  'awaiting_confirmation' => 'warning',
                  'confirmed' => 'success',
                  'adjusted' => 'info',
                  'partly_rejected' => 'danger',
                ][$consignment->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($consignment->status) }}</span></td>
                <td>{{ $consignment->batch?->reference ?? '—' }}</td>
                <td class="actions">
                  @if (! $consignment->isConfirmed())
                    @if ($canConfirm)
                      <a href="#modal-confirm-{{ $consignment->id }}" class="btn btn-primary btn-sm">Confirm</a>
                    @endif
                    @if ($canAdjust)
                      <a href="#modal-adjust-{{ $consignment->id }}" class="btn btn-ghost btn-sm">Adjust</a>
                    @endif
                  @else
                    @if ($canGrade && $consignment->grade_id === null && $consignment->batch_id === null)
                      <a href="#modal-grade-{{ $consignment->id }}" class="btn btn-outline btn-sm">Assign grade</a>
                    @elseif ($canRegrade && $consignment->grade_id !== null)
                      {{-- BR-4 — changing an assigned grade, held apart from assigning one. --}}
                      <a href="#modal-regrade-{{ $consignment->id }}" class="btn btn-ghost btn-sm">Re-grade</a>
                    @else
                      <span class="text-muted text-small">{{ \App\Support\Wat::relative($consignment->confirmed_at) }}</span>
                    @endif
                    @if ($consignment->wasRegraded())
                      <span class="badge warning" title="{{ $consignment->regrade_reason }}">re-graded</span>
                    @endif
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="11">@include('partials.empty', ['title' => 'No consignments in your scope', 'icon' => '&#128666;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $consignments, 'noun' => 'consignments'])
  </div>

  @if ($canConfirm)
    @foreach ($consignments->reject(fn ($c) => $c->isConfirmed()) as $consignment)
      <div id="modal-confirm-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Confirm {{ $consignment->reference }}</h3>
              <p>{{ $consignment->collectionPoint?->name }} &rarr; {{ $consignment->collectionCenter?->name }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.confirm', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-confirm-{{ $consignment->id }}" />
            {{-- qualityTests is eager-loaded by the controller; loading it here
                 issued one query per unconfirmed row. --}}
            @include('partials.confirm-form', [
              'consignment' => $consignment,
              'grades' => $grades,
              'centerReasons' => $centerReasons,
              'qualityTests' => $qualityTests,
              'canGrade' => auth()->user()->hasPermission('milk.grade.create'),
            ])
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Confirm consignment</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  {{--
    The adjustment route existed from the start; this modal is what makes it
    reachable. Adjustments apply only BEFORE confirmation — the service refuses
    them afterwards, because the confirmed volume is computed once.
  --}}
  @if ($canAdjust)
    @foreach ($consignments->reject(fn ($c) => $c->isConfirmed()) as $consignment)
      <div id="modal-adjust-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Adjust {{ $consignment->reference }}</h3>
              <p>{{ \App\Support\Volume::format($consignment->litres_dispatched) }} dispatched from {{ $consignment->collectionPoint?->name }}</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.adjust', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-adjust-{{ $consignment->id }}" />
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-adjust-'.$consignment->id.''])
              <div class="form-grid">
                <div class="field">
                  <label for="adj-{{ $consignment->id }}-delta">Litres (+/&minus;) <span class="req">*</span></label>
                  <input type="text" id="adj-{{ $consignment->id }}-delta" name="litres_delta" inputmode="decimal"
                         placeholder="-2.00" required />
                  <div class="hint">Use a minus sign for a shortfall. The adjustment is applied when the consignment is confirmed.</div>
                </div>
                <div class="field">
                  <label for="adj-{{ $consignment->id }}-reason">Reason <span class="req">*</span></label>
                  <select id="adj-{{ $consignment->id }}-reason" name="adjustment_reason_id" required>
                    @foreach ($adjustmentReasons as $reason)
                      <option value="{{ $reason->id }}">{{ $reason->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="field full">
                  <label for="adj-{{ $consignment->id }}-why">Explanation <span class="req">*</span></label>
                  <textarea id="adj-{{ $consignment->id }}-why" name="explanation" rows="2" required></textarea>
                </div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Record adjustment</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  {{-- Grade a consignment that was confirmed without one, before it can batch. --}}
  @if ($canGrade)
    @foreach ($consignments->filter(fn ($c) => $c->isConfirmed() && $c->grade_id === null && $c->batch_id === null) as $consignment)
      <div id="modal-grade-{{ $consignment->id }}" class="modal">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Grade {{ $consignment->reference }}</h3>
              <p>Confirmed {{ \App\Support\Wat::date($consignment->rateAnchor()) }} &middot; the rate applied is the one in force that day</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.grade', $consignment) }}">
            @csrf
          <input type="hidden" name="_modal" value="modal-grade-{{ $consignment->id }}" />
            <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-grade-'.$consignment->id.''])
              <div class="field">
                <label for="gr-{{ $consignment->id }}-grade">Grade <span class="req">*</span></label>
                <select id="gr-{{ $consignment->id }}-grade" name="grade_id" required>
                  @foreach ($grades as $grade)
                    <option value="{{ $grade->id }}">
                      {{ $grade->name }}
                      @php($rate = $grade->rateOn($consignment->rateAnchor()))
                      @if ($rate) &mdash; {{ \App\Support\Money::format($rate->rate_per_litre_minor) }}/L @endif
                    </option>
                  @endforeach
                </select>
                <div class="hint">Every required quality test must be recorded first.</div>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Assign grade</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  {{--
    BR-4 — the re-grade control break.

    Only offered to a holder of `milk.grade.edit`, and only for a consignment that
    already HAS a grade. The reason is required because this list is read weekly,
    and a row that says only "B was changed to A" tells the reader nothing.
  --}}
  @if ($canRegrade)
    @foreach ($consignments->filter(fn ($c) => $c->grade_id !== null) as $consignment)
      <div id="modal-regrade-{{ $consignment->id }}" class="modal @if (old('_modal') === 'modal-regrade-'.$consignment->id) open @endif">
        <a href="#" class="modal-overlay"></a>
        <div class="modal-dialog">
          <div class="modal-head">
            <div><h3>Re-grade {{ $consignment->reference }}</h3>
              <p>Currently {{ $consignment->grade?->name }} at
                 {{ \App\Support\Money::format((int) $consignment->rate_per_litre_minor) }}/L</p></div>
            <a href="#" class="modal-close">&times;</a>
          </div>
          <form method="POST" action="{{ route('consignments.regrade', $consignment) }}">
            @csrf
            <input type="hidden" name="_modal" value="modal-regrade-{{ $consignment->id }}" />
            <div class="modal-body">
              @include('partials.modal-errors', ['modal' => 'modal-regrade-'.$consignment->id])
              <div class="alert warn mb-16">
                <span>&#9888;&#65039;</span>
                <div>This changes what the farmer is paid for milk already accepted.
                  It is recorded against your name on the re-grade exceptions list.</div>
              </div>
              <div class="field mb-16">
                <label for="rg-{{ $consignment->id }}-grade">New grade <span class="req">*</span></label>
                <select id="rg-{{ $consignment->id }}-grade" name="grade_id" required>
                  @foreach ($grades as $grade)
                    <option value="{{ $grade->id }}" @selected(old('grade_id') == $grade->id)>
                      {{ $grade->name }}
                      @php($rate = $grade->rateOn($consignment->rateAnchor()))
                      @if ($rate) &mdash; {{ \App\Support\Money::format($rate->rate_per_litre_minor) }}/L @endif
                    </option>
                  @endforeach
                </select>
                <div class="hint">The rate applied is the one in force on
                  {{ \App\Support\Wat::date($consignment->rateAnchor()) }}, the day it was confirmed &mdash;
                  not today's.</div>
              </div>
              <div class="field">
                <label for="rg-{{ $consignment->id }}-reason">Reason <span class="req">*</span></label>
                <textarea id="rg-{{ $consignment->id }}-reason" name="regrade_reason" rows="3" required
                          placeholder="Lab re-test returned a different result; the first grade was entered against the wrong consignment&hellip;">{{ old('regrade_reason') }}</textarea>
              </div>
            </div>
            <div class="modal-foot">
              <a href="#" class="btn btn-ghost">Cancel</a>
              <button type="submit" class="btn btn-primary">Re-grade</button>
            </div>
          </form>
        </div>
      </div>
    @endforeach
  @endif

  @if ($canDispatch)
    <div id="modal-dispatch" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Dispatch a Consignment</h3>
            <p>Select the point, then the deliveries travelling. Fully rejected deliveries are not listed.</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('consignments.store') }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-dispatch" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-dispatch'])
            <div class="field mb-16"><label for="dc-point">Collection point <span class="req">*</span></label>
              <select id="dc-point" name="collection_point_id" required>
                @foreach ($points as $point)
                  <option value="{{ $point->id }}">{{ $point->name }}</option>
                @endforeach
              </select></div>

            <div class="field mb-16">
              <label>Deliveries awaiting dispatch</label>
              <div class="stack" style="gap:8px;margin-top:6px">
                @php($pending = \App\Models\Delivery::query()->awaitingDispatch()->with('farmer', 'collectionPoint')->latest('delivered_at')->limit(60)->get())
                @forelse ($pending as $delivery)
                  <label class="check-label">
                    <input type="checkbox" name="delivery_ids[]" value="{{ $delivery->id }}" />
                    <span class="perm-key">{{ $delivery->reference }}</span>
                    &middot; {{ $delivery->collectionPoint?->name }}
                    &middot; {{ $delivery->farmer?->name }}
                    &middot; {{ \App\Support\Volume::format($delivery->litres_accepted) }} accepted
                  </label>
                @empty
                  <div class="text-muted text-small">Nothing awaiting dispatch in your scope.</div>
                @endforelse
              </div>
              <div class="hint">The consignment volume is the total accepted litres of the deliveries you tick.</div>
            </div>

            <div class="form-grid">
              <div class="field"><label for="dc-containers">Containers</label>
                <input type="number" id="dc-containers" name="containers" min="0" /></div>
              <div class="field"><label for="dc-at">Dispatched at</label>
                <input type="datetime-local" id="dc-at" name="dispatched_at"
                       value="{{ \App\Support\Wat::forInput() }}" /></div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Dispatch</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
