@extends('auth.shell')
@section('title', __('ui.auth_login_title').' — ServerNet')

@section('heading', __('ui.auth_login_title'))
@section('sub', __('ui.auth_login_sub'))

@section('form')
<form method="POST" action="{{ lroute('login.start') }}" class="auth-f" id="lf">
  @csrf
  <input type="hidden" name="method" id="lmethod" value="{{ old('method', 'mobile') }}">

  {{-- یک فیلد که بین موبایل و ایمیل جابه‌جا می‌شود — نامش همیشه identifier --}}
  <div class="auth-field">
    <label for="identifier" id="lid-label">{{ old('method') === 'email' ? __('ui.auth_email') : __('ui.auth_mobile') }}</label>
    <input type="{{ old('method') === 'email' ? 'email' : 'tel' }}" id="identifier" name="identifier" dir="ltr"
           inputmode="{{ old('method') === 'email' ? 'email' : 'numeric' }}"
           autocomplete="{{ old('method') === 'email' ? 'email' : 'tel' }}"
           placeholder="{{ old('method') === 'email' ? 'you@example.com' : __('ui.auth_mobile_ph') }}"
           value="{{ old('identifier') }}" required autofocus>
  </div>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_login_send_code') }}</span></button>

  <button type="button" class="auth-ghost" id="toggle" style="margin-top:4px">
    {{ old('method') === 'email' ? __('ui.auth_login_via_mobile') : __('ui.auth_login_via_email') }}
  </button>
</form>

<script>
(function () {
  var method = document.getElementById('lmethod'),
      input = document.getElementById('identifier'),
      label = document.getElementById('lid-label'),
      toggle = document.getElementById('toggle'),
      form = document.getElementById('lf');

  var T = {
    mobileLabel: @json(__('ui.auth_mobile')),
    emailLabel:  @json(__('ui.auth_email')),
    mobilePh:    @json(__('ui.auth_mobile_ph')),
    viaEmail:    @json(__('ui.auth_login_via_email')),
    viaMobile:   @json(__('ui.auth_login_via_mobile'))
  };

  function apply() {
    var isEmail = method.value === 'email';
    input.type = isEmail ? 'email' : 'tel';
    input.inputMode = isEmail ? 'email' : 'numeric';
    input.autocomplete = isEmail ? 'email' : 'tel';
    input.placeholder = isEmail ? 'you@example.com' : T.mobilePh;
    label.textContent = isEmail ? T.emailLabel : T.mobileLabel;
    toggle.textContent = isEmail ? T.viaMobile : T.viaEmail;
    input.focus();
  }

  toggle.addEventListener('click', function () {
    method.value = method.value === 'email' ? 'mobile' : 'email';
    input.value = '';
    apply();
  });

  form.addEventListener('submit', function () {
    var b = this.querySelector('.auth-btn');
    b.classList.add('busy'); b.disabled = true;
  });
})();
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>{!! __('ui.auth_code_nostore') !!}</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>{!! __('ui.auth_secure') !!}</span>
  </div>
@endsection

@section('aside')
  {{ __('ui.auth_no_account') }} <a href="{{ lroute('register') }}">{{ __('ui.auth_do_register') }}</a>
@endsection
