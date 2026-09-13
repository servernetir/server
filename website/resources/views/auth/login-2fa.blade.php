@extends('auth.shell')
@section('title', __('ui.tfa_login_h').' — ServerNet')

@section('heading', __('ui.tfa_login_h'))
@section('sub', __('ui.tfa_login_note'))

@section('form')
@if($errors->any())<div class="auth-note err" style="margin-bottom:14px">{{ $errors->first() }}</div>@endif

{{--
  ⚠️ عمداً یک ورودیِ واحد است و نه شش خانهٔ جدا مثلِ صفحهٔ کدِ پیامکی.

  از همین فرم **کدِ بازیابی** هم پذیرفته می‌شود (`xxxxx-xxxxx`) و آن در شش
  خانهٔ تک‌رقمی اصلاً جا نمی‌شود. کسی که این فرم را می‌بیند یا گوشی‌اش دستش
  است یا گمش کرده — و دقیقاً برای حالتِ دوم است که این ورودی نباید فقط رقم
  بگیرد.
--}}
<form method="POST" action="{{ lroute('login.2fa.verify') }}" class="auth-f">
  @csrf

  <div class="auth-field">
    <label for="tfa-code">{{ __('ui.tfa_code') }}</label>
    <input type="text" id="tfa-code" name="code" dir="ltr" required autofocus
           autocomplete="one-time-code" maxlength="24" inputmode="numeric"
           style="text-align:center;letter-spacing:6px;font-size:20px;font-weight:700">
    <small>{{ __('ui.tfa_login_recovery') }}</small>
  </div>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.tfa_login_submit') }}</span></button>
</form>

<div class="auth-row">
  <a class="auth-ghost" href="{{ lroute('login') }}">{{ __('ui.tfa_login_back') }}</a>
</div>

<script>
(function () {
  var input = document.getElementById('tfa-code');
  if (!input) return;

  // ارقام فارسی/عربی به لاتین — کاربرِ ایرانی روی صفحه‌کلیدِ فارسی «۱۲۳۴۵۶»
  // می‌زند و بدونِ این تبدیل، کدِ کاملاً درستش «نادرست» شمرده می‌شود.
  // حروف دست نمی‌خورند چون کدِ بازیابی حرف دارد.
  input.addEventListener('input', function () {
    this.value = this.value
      .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
      .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
  });
})();
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>{{ __('ui.tfa_sub') }}</span>
  </div>
@endsection
