{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup, so a dynamic id here would make every
     link to this modal look like a link to a modal nobody defines. --}}
<a href="#" class="modal-overlay"></a>
<div class="modal-dialog">
    <div class="modal-head">
      <div>
        <h3>{{ $vehicle ? 'Edit '.$vehicle->registration : 'Add a vehicle' }}</h3>
      </div>
      <a href="#" class="modal-close">&times;</a>
    </div>
    <form method="POST"
          action="{{ $vehicle ? route('fleet.vehicles.update', $vehicle) : route('fleet.vehicles.store') }}">
      @csrf
      @if ($vehicle)@method('PUT')@endif
      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-reg">Registration</label>
            <input type="text" id="{{ $id }}-reg" name="registration" required
                   value="{{ old('registration', $vehicle?->registration) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-type">Type</label>
            <input type="text" id="{{ $id }}-type" name="type" required
                   value="{{ old('type', $vehicle?->type) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-cap">Capacity (L)</label>
            <input type="text" id="{{ $id }}-cap" name="capacity_litres" inputmode="decimal"
                   value="{{ old('capacity_litres', $vehicle?->capacity_litres) }}" />
          </div>
        </div>
        <div class="field">
          <label for="{{ $id }}-status">Status</label>
          <select id="{{ $id }}-status" name="status" required>
            @foreach (['active', 'inactive'] as $status)
              <option value="{{ $status }}" @selected(old('status', $vehicle?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <a href="#" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $vehicle ? 'Save' : 'Add vehicle' }}</button>
      </div>
    </form>
  </div>
