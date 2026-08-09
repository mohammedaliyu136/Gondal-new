{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup. --}}
<div class="modal-card">
  <div class="modal-head">
    <h3>{{ $row ? 'Edit '.$row->name : 'Add to '.$definition['label'] }}</h3>
    {{-- REF-1 — retiring is the only way out, because rows already pointing at
         this one must keep resolving. --}}
    @unless ($definition['statusless'] ?? false)
      <p>Retire a row rather than removing it — records already using it must keep resolving.</p>
    @endunless
  </div>
  <form method="POST"
        action="{{ $row
            ? route('admin.reference.update', ['register' => $selected, 'id' => $row->id])
            : route('admin.reference.store', ['register' => $selected]) }}">
    @csrf
    @if ($row)@method('PUT')@endif
    <div class="modal-body">
      @foreach ($definition['fields'] as $field => $spec)
        <div class="field">
          <label for="rf-{{ $field }}-{{ $row?->id ?? 'new' }}">{{ $spec['label'] }}</label>
          @if (($spec['type'] ?? null) === 'boolean')
            <label class="check">
              <input type="checkbox" id="rf-{{ $field }}-{{ $row?->id ?? 'new' }}" name="{{ $field }}"
                     value="1" @checked(old($field, $row?->{$field})) />
              <span>{{ $spec['label'] }}</span>
            </label>
          @elseif (isset($spec['relation']))
            <select id="rf-{{ $field }}-{{ $row?->id ?? 'new' }}" name="{{ $field }}" required>
              @foreach ($lgas as $lga)
                <option value="{{ $lga->id }}" @selected(old($field, $row?->{$field}) == $lga->id)>{{ $lga->name }}</option>
              @endforeach
            </select>
          @elseif (isset($spec['options']))
            <select id="rf-{{ $field }}-{{ $row?->id ?? 'new' }}" name="{{ $field }}" required>
              @foreach ($spec['options'] as $value => $label)
                <option value="{{ $value }}" @selected(old($field, $row?->{$field}) === $value)>{{ $label }}</option>
              @endforeach
            </select>
          @else
            <input type="text" id="rf-{{ $field }}-{{ $row?->id ?? 'new' }}" name="{{ $field }}"
                   value="{{ old($field, $row?->{$field}) }}" />
          @endif
        </div>
      @endforeach

      @unless ($definition['statusless'] ?? false)
        <div class="form-grid">
          <div class="field">
            <label for="rf-status-{{ $row?->id ?? 'new' }}">Status</label>
            <select id="rf-status-{{ $row?->id ?? 'new' }}" name="status" required>
              @foreach (['active' => 'Active', 'retired' => 'Retired'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $row?->status ?? 'active') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="rf-position-{{ $row?->id ?? 'new' }}">Order</label>
            <input type="text" id="rf-position-{{ $row?->id ?? 'new' }}" name="position" inputmode="numeric"
                   value="{{ old('position', $row?->position ?? 0) }}" />
          </div>
        </div>
      @endunless
    </div>
    <div class="modal-foot">
      <a href="#" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary">{{ $row ? 'Save' : 'Add' }}</button>
    </div>
  </form>
</div>
