<div class="empty">
  <div class="empty-ic">{!! $icon ?? '&#128230;' !!}</div>
  <div class="font-bold">{{ $title }}</div>
  @isset($message)
    <div class="text-muted text-small mt-16">{{ $message }}</div>
  @endisset
</div>
