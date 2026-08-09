{{-- NFR-2 — every list is paginated; the count is always stated. --}}
@if ($paginator->hasPages() || $paginator->total() > 0)
  <div class="pagination">
    <div>
      Showing {{ number_format($paginator->firstItem() ?? 0) }}&ndash;{{ number_format($paginator->lastItem() ?? 0) }}
      of {{ number_format($paginator->total()) }}{{ isset($noun) ? ' '.$noun : '' }}
    </div>
    @if ($paginator->hasPages())
      <div class="pages">
        @if ($paginator->onFirstPage())
          <span>&laquo;</span>
        @else
          <a href="{{ $paginator->previousPageUrl() }}">&laquo;</a>
        @endif

        @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
          <a href="{{ $url }}" @class(['active' => $page === $paginator->currentPage()])>{{ $page }}</a>
        @endforeach

        @if ($paginator->hasMorePages())
          <a href="{{ $paginator->nextPageUrl() }}">&raquo;</a>
        @else
          <span>&raquo;</span>
        @endif
      </div>
    @endif
  </div>
@endif
