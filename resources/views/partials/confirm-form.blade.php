{{--
  The consignment confirmation form, shared by the center detail screen and the
  consignments list so the two cannot drift.

  RECORDING A QUALITY TEST FROM INSIDE THIS FORM

  This partial is rendered inside the confirmation <form>, and a quality test is a
  separate POST to a separate route. It used to open a nested <form> to do that,
  which HTML forbids: the browser silently discards the inner tag, so every
  "Record" button submitted the CONFIRMATION instead, and no quality test could
  ever be recorded through the interface.

  The fix uses the two attributes that exist for exactly this case. Each row's
  submit button carries `formaction`, which redirects that one submission to the
  quality-test route, and carries the test's id as its own name/value pair — only
  the clicked button's value is submitted, so the server learns which row was
  used. Readings are keyed by test id so the rows cannot collide.

  No nested form, no JavaScript, and the standalone route keeps working unchanged
  for the API and the tests that post to it directly.
--}}
@php($recorded = $consignment->qualityTests->pluck('quality_test_definition_id')->all())
@php($requiredTests = $qualityTests->where('is_required', true))
@php($missing = $requiredTests->reject(fn ($t) => in_array($t->id, $recorded, true)))

<div class="modal-body">
  @include('partials.modal-errors', ['modal' => 'modal-confirm-'.$consignment->id])
  <div class="meta-grid cols-3 mb-16">
    <div class="meta-item"><div class="meta-label">Dispatched</div>
      <div class="meta-value">{{ \App\Support\Volume::format($consignment->litres_dispatched) }}</div></div>
    <div class="meta-item"><div class="meta-label">Adjustments</div>
      <div class="meta-value">{{ \App\Support\Volume::format($consignment->adjustmentTotal()) }}</div></div>
    <div class="meta-item"><div class="meta-label">From</div>
      <div class="meta-value">{{ $consignment->collectionPoint?->name }}</div></div>
  </div>

  {{-- BR-4 — the tests, and what is still missing. --}}
  <div class="card mb-16">
    <div class="card-head"><div><h3>Quality tests</h3>
      <p>Required tests must be recorded before a grade can be assigned</p></div>
      @if ($missing->isEmpty())
        <span class="badge success">All required tests recorded</span>
      @else
        <span class="badge warning">{{ $missing->count() }} outstanding</span>
      @endif
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Test</th><th>Acceptable</th><th>Reading</th><th>Result</th><th class="actions"></th></tr></thead>
          <tbody>
            @foreach ($qualityTests as $definition)
              @php($result = $consignment->qualityTests->firstWhere('quality_test_definition_id', $definition->id))
              <tr>
                <td>{{ $definition->name }}
                  @if ($definition->is_required)<span class="req">*</span>@endif</td>
                <td class="text-small text-muted">{{ $definition->describeRange() }}</td>
                <td>{{ $result?->reading ?? '—' }}</td>
                <td>
                  @if ($result === null)
                    <span class="badge muted">Not recorded</span>
                  @else
                    <span class="badge {{ $result->passed ? 'success' : 'danger' }}">{{ $result->passed ? 'Pass' : 'Fail' }}</span>
                  @endif
                </td>
                <td class="actions">
                  @if ($canGrade ?? false)
                    <div class="flex">
                      <label class="sr-only" for="qt-{{ $consignment->id }}-{{ $definition->id }}" style="position:absolute;left:-9999px">Reading</label>
                      @if ($definition->kind === 'boolean')
                        <select id="qt-{{ $consignment->id }}-{{ $definition->id }}" name="readings[{{ $definition->id }}]">
                          <option value="1">{{ $definition->expected_boolean_label ?? 'Pass' }}</option>
                          <option value="0">Fail</option>
                        </select>
                      @else
                        <input type="text" id="qt-{{ $consignment->id }}-{{ $definition->id }}" name="readings[{{ $definition->id }}]"
                               inputmode="decimal" style="max-width:90px" value="{{ $result?->reading }}" />
                      @endif
                      <button type="submit" class="btn btn-outline btn-sm"
                              name="quality_test_definition_id" value="{{ $definition->id }}"
                              formaction="{{ route('consignments.quality-test', $consignment) }}"
                              formmethod="POST" formnovalidate>Record</button>
                    </div>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="form-grid">
    <div class="field">
      <label for="cf-{{ $consignment->id }}-rejected">Rejected at this center (L)</label>
      <input type="text" id="cf-{{ $consignment->id }}-rejected" name="litres_rejected_at_center"
             inputmode="decimal" value="0" />
      <div class="hint">Rejected volume is not paid for and is not carried to the factory.</div>
    </div>
    <div class="field">
      <label for="cf-{{ $consignment->id }}-reason">Rejection reason</label>
      {{-- BR-1 — the configured list for the CENTER stage only. --}}
      <select id="cf-{{ $consignment->id }}-reason" name="rejection_reason_id">
        <option value="">No rejection</option>
        @foreach ($centerReasons as $reason)
          <option value="{{ $reason->id }}">{{ $reason->name }}</option>
        @endforeach
      </select>
      <div class="hint">Choose from the configured reasons. Ask an administrator to add one if it is missing.</div>
    </div>
    <div class="field">
      <label for="cf-{{ $consignment->id }}-grade">Grade</label>
      <select id="cf-{{ $consignment->id }}-grade" name="grade_id" @disabled($missing->isNotEmpty() || ! ($canGrade ?? false))>
        <option value="">Not graded yet</option>
        @foreach ($grades as $grade)
          <option value="{{ $grade->id }}">
            {{ $grade->name }}
            @php($rate = $grade->currentRate())
            @if ($rate) &mdash; {{ \App\Support\Money::format($rate->rate_per_litre_minor) }}/L @endif
          </option>
        @endforeach
      </select>
      <div class="hint">
        @if ($missing->isNotEmpty())
          Record {{ $missing->pluck('name')->implode(', ') }} first.
        @elseif (! ($canGrade ?? false))
          You do not have permission to grade. Ask your supervisor.
        @else
          Today's rate is saved onto this consignment, so a later rate change will not alter what is owed.
        @endif
      </div>
    </div>
    <div class="field">
      <label for="cf-{{ $consignment->id }}-temp">Intake temperature (&deg;C)</label>
      <input type="text" id="cf-{{ $consignment->id }}-temp" name="intake_temperature_c" inputmode="decimal" />
    </div>
    <div class="field full">
      <label for="cf-{{ $consignment->id }}-notes">Officer notes</label>
      <textarea id="cf-{{ $consignment->id }}-notes" name="officer_notes" rows="2"></textarea>
    </div>
  </div>
</div>
