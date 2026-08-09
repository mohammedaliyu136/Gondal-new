@if ($errors->any())
  <div class="alert danger mb-16">
    <span>&#10060;</span>
    <div>
      @foreach ($errors->all() as $message)
        <div>{{ $message }}</div>
      @endforeach
    </div>
  </div>
@endif

@if (session('status'))
  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>{{ session('status') }}</div>
  </div>
@endif
