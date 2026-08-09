@extends('layouts.auth')

@section('title', 'Sign in')
@section('heading', 'Welcome back')
@section('subheading', 'Sign in to Gondal ERP')

@section('content')
  {{-- AUTH-1 — email + password, then a 6-digit emailed code. --}}
  <form class="login-form" method="POST" action="{{ route('login.attempt') }}">
    @csrf
    @include('partials.auth-errors')

    <div class="field mb-16">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}"
             placeholder="you@gondalfulbe.ng" autocomplete="username" required autofocus />
    </div>
    <div class="field mb-16">
      <label for="password">Password</label>
      <input type="password" id="password" name="password"
             placeholder="Enter your password" autocomplete="current-password" required />
    </div>
    <div class="flex-between mb-16">
      {{-- AUTH-2 — trust this device for 30 days. --}}
      <label class="check-label" for="remember_device">
        <input type="checkbox" id="remember_device" name="remember_device" value="1"
               {{ old('remember_device', '1') ? 'checked' : '' }} />
        Remember this device for {{ config('gondal.auth.device_trust_days') }} days
      </label>
      <a href="{{ route('password.forgot') }}" class="text-primary font-bold text-small">Forgot password?</a>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Continue</button>

    <div class="alert info mt-16">
      <span>&#128274;</span>
      <div>A {{ config('gondal.auth.code_length') }}-digit verification code will be sent to your email to confirm this sign-in.</div>
    </div>

    {{-- AUTH-8 — there is no self-registration. --}}
    <div class="login-note">
      Accounts are set up by your system administrator.<br />
      Don&rsquo;t have access? Contact IT support.
    </div>
  </form>
@endsection
