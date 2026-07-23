@extends('auth.shell')
@section('title', __('ui.auth_login_title').' — ServerNet')

@section('heading', __('ui.auth_login_title'))
@section('sub', __('ui.auth_login_sub'))

@section('form')
<form method="POST" action="{{ lroute('login') }}" class="auth-f" id="lf">
  @csrf

  <div class="auth-field">
    <label for="email">{{ __('ui.auth_email') }}</label>
    <input type="email" id="email" name="email" dir="ltr"
           autocomplete="username" placeholder="you@example.com"
           value="{{ old('email') }}" required autofocus>
  </div>

  <div class="auth-field">
    <label for="password">{{ __('ui.auth_password') }}</label>
    <input type="password" id="password" name="password" dir="ltr"
           autocomplete="current-password" required>
  </div>

  <label class="auth-check" style="align-items:center">
    <input type="checkbox" name="remember" value="1" style="margin-top:0">
    <span>{{ __('ui.auth_remember') }}</span>
  </label>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_login') }}</span></button>
</form>

<script>
document.getElementById('lf').addEventListener('submit', function () {
  var b = this.querySelector('.auth-btn');
  b.classList.add('busy');
  b.disabled = true;
});
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>{!! __('ui.auth_lockout') !!}</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>{!! __('ui.auth_secure') !!}</span>
  </div>
@endsection

@section('aside')
  {{ __('ui.auth_no_account') }} <a href="{{ lroute('register') }}">{{ __('ui.auth_do_register') }}</a>
@endsection
