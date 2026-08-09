@extends('layouts.auth')

@section('title', 'Choose a password')
@section('heading', 'Choose your password')
@section('subheading', 'Only you will know it')

@section('content')
  {{-- AUTH-5 — policy is described from config, never duplicated in copy. --}}
  <form class="login-form" method="POST" action="{{ route('password.reset.store') }}">
    @csrf
    @include('partials.auth-errors')

    <div class="field mb-16">
      <label for="password">New password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required autofocus />
      <div class="hint">{{ $policyDescription }}</div>
    </div>
    <div class="field mb-16">
      <label for="password_confirmation">Confirm new password</label>
      <input type="password" id="password_confirmation" name="password_confirmation"
             autocomplete="new-password" required />
    </div>

    <button type="submit" class="btn btn-primary btn-block">Set password and sign in</button>

    <div class="alert info mt-16">
      <span>&#128274;</span>
      <div>Setting a new password signs out every other session on your account.</div>
    </div>
  </form>
@endsection
