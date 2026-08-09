@extends('layouts.app')
@section('title', 'Batches')

@section('content')
  <div class="page-head">
    <div>
      <h1>Batches</h1>
      <p>Center &rarr; factory &middot; only confirmed and graded consignments can join a batch</p>
    </div>
    <div class="page-actions">
      {{--
        A batch is dispatched FROM a centre, because it has to be attributed to
        one — so there is no "+ New Batch" here and there should not be. What
        there must be is a way to get to where the action lives: somebody holding
        milk.batch.dispatch.create who opens Batches, the obvious screen, was
        previously shown nothing at all and had to already know the answer.
      --}}
      @if ($canDispatch)
        <a href="{{ route('collection-centers.index') }}" class="btn btn-primary">Dispatch from a center</a>
      @endif
      @can('milk.reconciliation.view')
        <a href="{{ route('reconciliation.index') }}" class="btn btn-outline">Factory Reconciliation</a>
      @endcan
    </div>
  </div>

  @if ($batchable->isNotEmpty() && $canDispatch)
    <div class="alert info mb-16">
      <span>&#128230;</span>
      <div>
        <strong>{{ $batchable->count() }} consignment(s) ready to batch</strong>
        ({{ \App\Support\Volume::format(\App\Support\Volume::sum($batchable->pluck('litres_confirmed')->all())) }}).
        {{-- A link, not a direction: naming the screen without going there leaves
             the operator to hunt for it. --}}
        <a href="{{ route('collection-centers.index') }}" class="text-primary">Dispatch from the center screen</a>
        so the batch is attributed to the right center.
      </div>
    </div>
  @endif

  <div class="card">
    <div class="card-head"><div><h3>All Batches</h3></div></div>
    <div class="card-body">
      <form method="GET" class="table-tools">
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            @foreach ([
              'in_transit' => 'In transit', 'reconciled' => 'Reconciled',
              'discrepancy' => 'Discrepancy', 'released' => 'Released',
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
        <a href="{{ route('batches.index') }}" class="btn btn-ghost btn-sm">Clear</a>
      </form>
    </div>
    <div class="card-body flush">
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Reference</th><th>Center</th><th class="num">Dispatched</th><th class="num">Received</th>
            <th class="num">Variance</th><th>Cause</th><th>Trip</th><th>Status</th><th class="actions">Actions</th>
          </tr></thead>
          <tbody>
            @forelse ($batches as $batch)
              <tr>
                <td><a href="{{ route('batches.show', $batch) }}" class="perm-key">{{ $batch->reference }}</a></td>
                <td>{{ $batch->collectionCenter?->name }}</td>
                <td class="num">{{ \App\Support\Volume::format($batch->litres_dispatched, false) }}</td>
                <td class="num">{{ $batch->litres_received === null ? '—' : \App\Support\Volume::format($batch->litres_received, false) }}</td>
                <td class="num {{ $batch->exceedsTolerance() ? 'text-danger font-bold' : '' }}">
                  @if ($batch->discrepancy_litres === null)
                    &mdash;
                  @else
                    {{ $batch->discrepancy_litres }} L
                    <div class="cell-sub">{{ $batch->discrepancyPercentage() }}% of {{ $batch->tolerancePercentage() }}%</div>
                  @endif
                </td>
                <td>{{ $batch->discrepancyCause?->name ?? '—' }}</td>
                <td>{{ $batch->trip?->reference ?? '—' }}</td>
                <td><span class="badge {{ [
                  'in_transit' => 'info', 'reconciled' => 'success',
                  'discrepancy' => 'danger', 'released' => 'success',
                ][$batch->status] ?? 'muted' }}">{{ \Illuminate\Support\Str::headline($batch->status) }}</span></td>
                <td class="actions"><a href="{{ route('batches.show', $batch) }}" class="btn btn-ghost btn-sm">Open</a></td>
              </tr>
            @empty
              <tr><td colspan="9">@include('partials.empty', ['title' => 'No batches in your scope', 'icon' => '&#128230;'])</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @include('partials.pagination', ['paginator' => $batches, 'noun' => 'batches'])
  </div>
@endsection
