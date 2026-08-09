@extends('layouts.app')
@section('title', 'Reports')

@section('content')
  <div class="page-head">
    <div>
      <h1>Reports</h1>
      {{-- SCOPE-4 — whose figures these are. A centre officer's production report
           totals their centre, and saying so is the difference between a correct
           report and a misread one. --}}
      <p>{{ $scopeLabel }}</p>
    </div>
  </div>

  @if (empty($catalogue))
    <div class="card">
      <div class="empty">
        <h3>No reports are available to you</h3>
        <p>Reports are shown for the data your role may already see. Ask an administrator
           if you expected one here.</p>
      </div>
    </div>
  @else
    <div class="card mb-16">
      <form method="GET" action="{{ route('reports.index') }}" class="form-grid">
        <div class="field">
          <label for="report">Report</label>
          <select id="report" name="report">
            @foreach ($catalogue as $key => $report)
              <option value="{{ $key }}" @selected($selected === $key)>{{ $report['label'] }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="from">From</label>
          <input type="date" id="from" name="from" value="{{ $from }}" />
        </div>
        <div class="field">
          <label for="to">To</label>
          <input type="date" id="to" name="to" value="{{ $to }}" />
        </div>
        <div class="field">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary">Run</button>
        </div>
      </form>
      @if ($selected && isset($catalogue[$selected]))
        <p class="muted">{{ $catalogue[$selected]['description'] }}</p>
      @endif
    </div>

    @if ($result !== null)
      <div class="card">
        <div class="card-head">
          <div>
            <h3>{{ $catalogue[$selected]['label'] }}</h3>
            <p>{{ $from }} to {{ $to }} &middot; {{ count($result['rows']) }} row(s)</p>
          </div>
          @if (! empty($result['rows']))
            {{-- The export §15.5's own workaround assumed existed. --}}
            <a class="btn btn-outline"
               href="{{ route('reports.export', ['report' => $selected, 'from' => $from, 'to' => $to]) }}">
              Export CSV
            </a>
          @endif
        </div>

        @if (empty($result['rows']))
          <div class="empty">
            <h3>Nothing in this period</h3>
            <p>No records in your data scope between {{ $from }} and {{ $to }}.</p>
          </div>
        @else
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>@foreach ($result['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr>
              </thead>
              <tbody>
                @foreach ($result['rows'] as $row)
                  <tr>@foreach ($result['columns'] as $column)<td>{{ $row[$column] ?? '' }}</td>@endforeach</tr>
                @endforeach
              </tbody>
              @if (! empty($result['totals']))
                <tfoot>
                  <tr>
                    @foreach ($result['columns'] as $index => $column)
                      <th>{{ $index === 0 ? 'Total' : ($result['totals'][$column] ?? '') }}</th>
                    @endforeach
                  </tr>
                </tfoot>
              @endif
            </table>
          </div>
        @endif
      </div>
    @endif
  @endif
@endsection
