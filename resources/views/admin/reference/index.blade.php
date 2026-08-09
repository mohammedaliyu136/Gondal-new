@extends('layouts.app')
@section('title', 'Reference Data')

@section('content')
  <div class="page-head">
    <div>
      <h1>Reference Data</h1>
      <p>The registers the rules read. Change one here rather than in the database.</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('admin.settings') }}" class="btn btn-outline">Settings</a>
    </div>
  </div>

  <div class="filters mb-16">
    @foreach ($registers as $key => $register)
      <a class="chip {{ $selected === $key ? 'active' : '' }}"
         href="{{ route('admin.reference.index', ['register' => $key]) }}">{{ $register['label'] }}</a>
    @endforeach
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>{{ $definition['label'] }}</h3><p>{{ $definition['help'] }}</p></div>
      @if ($canEdit)<a href="#modal-reference-add" class="btn btn-primary">+ Add</a>@endif
    </div>

    @if ($rows->isEmpty())
      <div class="empty"><h3>Nothing on this register yet</h3></div>
    @else
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              @foreach ($definition['fields'] as $spec)<th>{{ $spec['label'] }}</th>@endforeach
              @unless ($definition['statusless'] ?? false)<th>Status</th>@endunless
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $row)
              <tr>
                @foreach ($definition['fields'] as $field => $spec)
                  <td>
                    @if (($spec['type'] ?? null) === 'boolean')
                      {{ $row->{$field} ? 'Yes' : 'No' }}
                    @elseif (isset($spec['relation']))
                      {{ optional($lgas->firstWhere('id', $row->{$field}))->name ?? '—' }}
                    @elseif (isset($spec['options']))
                      {{ $spec['options'][$row->{$field}] ?? $row->{$field} }}
                    @else
                      {{ $row->{$field} ?? '—' }}
                    @endif
                  </td>
                @endforeach
                @unless ($definition['statusless'] ?? false)
                  <td><span class="badge {{ $row->status === 'active' ? '' : 'muted' }}">{{ $row->status }}</span></td>
                @endunless
                <td class="row-actions">
                  @if ($canEdit)
                    <a href="#modal-reference-{{ $row->id }}" class="btn btn-sm btn-outline">Edit</a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if ($canEdit)
    <div id="modal-reference-add" class="modal">
      @include('admin.reference._form', ['row' => null, 'definition' => $definition, 'selected' => $selected, 'lgas' => $lgas])
    </div>
    @foreach ($rows as $row)
      <div id="modal-reference-{{ $row->id }}" class="modal">
        @include('admin.reference._form', ['row' => $row, 'definition' => $definition, 'selected' => $selected, 'lgas' => $lgas])
      </div>
    @endforeach
  @endif
@endsection
