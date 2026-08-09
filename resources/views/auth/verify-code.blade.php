@extends('layouts.auth')

@section('title', 'Verify sign-in')
@section('heading', 'Check your email')
@section('subheading', 'Enter the ' . config('gondal.auth.code_length') . '-digit code we sent to ' . $maskedEmail)

@section('content')
  {{-- AUTH-3 — 6 digits, 10-minute expiry, single use, 5 attempts. --}}
  <form class="login-form" method="POST" action="{{ route('login.verify.store') }}">
    @csrf
    @include('partials.auth-errors')

    <div class="field mb-16">
      <label for="code">Verification code</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
             maxlength="{{ config('gondal.auth.code_length') }}" pattern="[0-9]*"
             placeholder="{{ str_repeat('0', config('gondal.auth.code_length')) }}" required autofocus />
      <div class="hint">
        The code expires in {{ config('gondal.auth.signin_code_ttl_minutes') }} minutes and can be used once.
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Verify and sign in</button>
  </form>

  <form method="POST" action="{{ route('login.verify.resend') }}" class="mt-16">
    @csrf
    <button type="submit" class="btn btn-ghost btn-block">Send a new code</button>
  </form>

  <div class="login-note">
    Wrong account? <a href="{{ route('login') }}" class="text-primary font-bold">Start again</a>
  </div>
@endsection
