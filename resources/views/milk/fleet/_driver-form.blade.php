{{-- The caller owns the outer <div id="modal-…" class="modal">: ViewIntegrityTest
     collects modal ids from LITERAL markup, so a dynamic id here would make every
     link to this modal look like a link to a modal nobody defines. --}}
<div class="modal-card">
    <div class="modal-head">
      <h3>{{ $driver ? 'Edit '.$driver->name : 'Add a rider or driver' }}</h3>
      {{-- USER-1 — no credential field here, and there is no login to create. --}}
      <p>A record, not an account.</p>
    </div>
    <form method="POST"
          action="{{ $driver ? route('fleet.drivers.update', $driver) : route('fleet.drivers.store') }}">
      @csrf
      @if ($driver)@method('PUT')@endif
      <div class="modal-body">
        <div class="field">
          <label for="{{ $id }}-name">Name</label>
          <input type="text" id="{{ $id }}-name" name="name" required
                 value="{{ old('name', $driver?->name) }}" />
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="{{ $id }}-phone">Phone</label>
            <input type="text" id="{{ $id }}-phone" name="phone" value="{{ old('phone', $driver?->phone) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-licence">Licence no.</label>
            <input type="text" id="{{ $id }}-licence" name="licence_no" value="{{ old('licence_no', $driver?->licence_no) }}" />
          </div>
          <div class="field">
            <label for="{{ $id }}-type">Type</label>
            <input type="text" id="{{ $id }}-type" name="type" required
                   value="{{ old('type', $driver?->type ?? 'rider') }}" />
          </div>
        </div>
        <div class="field">
          <label for="{{ $id }}-status">Status</label>
          <select id="{{ $id }}-status" name="status" required>
            @foreach (['active', 'inactive'] as $status)
              <option value="{{ $status }}" @selected(old('status', $driver?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <a href="#" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $driver ? 'Save' : 'Add rider' }}</button>
      </div>
    </form>
  </div>
