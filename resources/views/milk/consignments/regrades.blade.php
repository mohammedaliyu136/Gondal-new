@extends('layouts.app')
@section('title', 'Re-grade Exceptions')

@section('content')
  <div class="breadcrumb">
    <a href="{{ route('consignments.index') }}">Consignments</a><span class="sep">/</span>
    <span>Re-grades</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Re-grade Exceptions</h1>
      <p>Grades changed after they were assigned &middot; last {{ $days }} days</p>
    </div>
    <div class="page-actions">
      <form method="GET" class="flex">
        <select name="days" onchange="this.form.submit()">
          @foreach ([7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $value => $label)
            <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </form>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Assigning a grade is routine; changing one is not.</strong>
      A re-grade moves what a farmer is paid for milk already accepted, so it is
      held apart from ordinary grading and every one of them is listed here with
      its reason. Read this weekly &mdash; a control nobody reads is not a control.
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>Re-grades</h3>
        <p>{{ number_format($consignments->total()) }} in the period</p></div>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Consignment</th><th>Point</th><th>Now graded</th>
            <th class="num">Rate</th><th>Re-graded by</th><th>When</th><th>Reason</th>
          </tr></thead>
          <tbody>
            @forelse ($consignments as $consignment)
              <tr>
                <td><a href="{{ route('consignments.index') }}" class="perm-key">{{ $consignment->reference }}</a>
                  <div class="cell-sub">{{ $consignment->collectionCenter?->name }}</div></td>
                <td>{{ $consignment->collectionPoint?->name ?? '—' }}</td>
                <td><span class="badge info">{{ $consignment->grade?->name ?? '—' }}</span></td>
                <td class="num font-bold">{{ \App\Support\Money::format((int) $consignment->rate_per_litre_minor) }}</td>
                <td>{{ $consignment->regradedBy?->name ?? '—' }}</td>
                <td>{{ \App\Support\Wat::dateTime($consignment->regraded_at) }}
                  <div class="cell-sub">{{ \App\Support\Wat::relative($consignment->regraded_at) }}</div></td>
                <td>{{ $consignment->regrade_reason }}</td>
              </tr>
            @empty
              <tr><td colspan="7">@include('partials.empty', [
                'title' => 'No re-grades in this period',
                'message' => 'Every grade assigned in the last '.$days.' days has stood. That is the expected state.',
                'icon' => '&#9989;',
              ])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $consignments, 'noun' => 're-grades'])
  </div>
@endsection
