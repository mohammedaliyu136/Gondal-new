{{--
  Flash messages.

  A rule violation carries the rule ID that caused it, and that ID is deliberately
  NOT rendered here. An operator who has just been stopped mid-task needs to know
  what to do about it, not which clause of the specification stopped them. The ID
  is still written to the audit log, still returned on the API, and still put in
  the session as `rule_violation` for support tooling to read.
--}}
@if (session('status'))
  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>{{ session('status') }}</div>
  </div>
@endif

@if (session('success'))
  <div class="alert success mb-16">
    <span>&#9989;</span>
    <div>{{ session('success') }}</div>
  </div>
@endif

@if (session('warning'))
  <div class="alert warn mb-16">
    <span>&#9888;&#65039;</span>
    <div>{{ session('warning') }}</div>
  </div>
@endif

@if ($errors->any())
  <div class="alert danger mb-16">
    <span>&#10060;</span>
    <div>
      <ul style="margin:0;padding-left:16px">
        @foreach ($errors->all() as $message)
          <li>{{ $message }}</li>
        @endforeach
      </ul>
    </div>
  </div>
@endif
