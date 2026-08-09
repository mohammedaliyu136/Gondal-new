@extends('layouts.auth')

@section('title', 'Verify reset code')
@section('heading', 'Enter your reset code')
@section('subheading', 'Sent to ' . $maskedEmail)

@section('content')
  <form class="login-form" method="POST" action="{{ route('password.verify.store') }}">
    @csrf
    @include('partials.auth-errors')

    <div class="field mb-16">
      <label for="code">Reset code</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
             maxlength="{{ config('gondal.auth.code_length') }}" pattern="[0-9]*"
             placeholder="{{ str_repeat('0', config('gondal.auth.code_length')) }}" required autofocus />
      <div class="hint">Expires in {{ config('gondal.auth.reset_code_ttl_minutes') }} minutes.</div>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Continue</button>

    <div class="login-note">
      <a href="{{ route('password.forgot') }}" class="text-primary font-bold">Send a new code</a>
    </div>
  </form>
@endsection
