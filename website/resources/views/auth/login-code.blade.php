@extends('auth.shell')
@section('title', __('ui.auth_code_title').' — ServerNet')

@section('heading', __('ui.auth_code_title'))
@section('sub')
  {!! __('ui.auth_code_sub', ['dest' => '<b dir="ltr">'.e($masked).'</b>']) !!}
@endsection

@section('form')
@if(session('ok'))<div class="auth-note ok" style="margin-bottom:14px">{{ session('ok') }}</div>@endif

<form method="POST" action="{{ lroute('login.verify') }}" class="auth-f" id="otp-form">
  @csrf
  <input type="hidden" name="code" id="code">

  <div class="auth-field">
    <label for="d1" class="visually-hidden">{{ __('ui.auth_code') }}</label>
    <div class="otp" id="otp" dir="ltr">
      @for($i = 1; $i <= 6; $i++)
        <input type="text" id="d{{ $i }}" inputmode="numeric" maxlength="1"
               autocomplete="{{ $i === 1 ? 'one-time-code' : 'off' }}"
               aria-label="{{ __('ui.auth_code_digit', ['n' => fa_num($i)]) }}"
               {{ $i === 1 ? 'autofocus' : '' }}>
      @endfor
    </div>
    <small style="text-align:center">{{ __('ui.auth_code_ttl') }}</small>
  </div>

  {{-- کد هم‌زمان از پیامک، بله و ایمیل می‌رود. اگر پیامک نرسید (که گاهی
       اپراتور نمی‌رساند)، کاربر نباید پشتِ درِ بسته بماند. --}}
  @if($channel !== 'email')
  <div class="auth-bale">
    <svg class="icon"><use href="#i-message"/></svg>
    <span>
      {{ __('ui.auth_bale_hint') }}
      <a href="https://ble.ir/servernetbot" target="_blank" rel="noopener" dir="ltr">@servernetbot</a>
    </span>
  </div>
  @endif

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_code_submit') }}</span></button>
</form>

<div class="auth-row">
  <form method="POST" action="{{ lroute('login.resend') }}">
    @csrf
    <button type="submit" class="auth-ghost" id="resend" disabled>
      {{ __('ui.auth_resend') }} <span id="cd">(۶۰)</span>
    </button>
  </form>
  <a class="auth-ghost" href="{{ lroute('login') }}">
    {{ $channel === 'email' ? __('ui.auth_change_email') : __('ui.auth_change_mobile') }}
  </a>
</div>

<script>
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('#otp input')),
      hidden = document.getElementById('code'),
      form = document.getElementById('otp-form');

  function collect() { return boxes.map(function (b) { return b.value; }).join(''); }
  function sync() {
    hidden.value = collect();
    boxes.forEach(function (b) { b.classList.toggle('filled', b.value !== ''); });
  }

  boxes.forEach(function (box, i) {
    box.addEventListener('input', function () {
      var v = this.value
        .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
        .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; })
        .replace(/[^0-9]/g, '');
      this.value = v.slice(-1);
      sync();
      if (this.value && i < boxes.length - 1) boxes[i + 1].focus();
      if (collect().length === 6) form.requestSubmit();
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !this.value && i > 0) { boxes[i - 1].focus(); }
      if (e.key === 'ArrowLeft' && i > 0) { boxes[i - 1].focus(); e.preventDefault(); }
      if (e.key === 'ArrowRight' && i < boxes.length - 1) { boxes[i + 1].focus(); e.preventDefault(); }
    });
    box.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text')
        .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
        .replace(/[^0-9]/g, '').slice(0, 6);
      for (var k = 0; k < t.length && k < boxes.length; k++) boxes[k].value = t[k];
      sync();
      (boxes[Math.min(t.length, 5)] || boxes[5]).focus();
      if (collect().length === 6) form.requestSubmit();
    });
  });

  form.addEventListener('submit', function () {
    sync();
    var b = form.querySelector('.auth-btn');
    b.classList.add('busy'); b.disabled = true;
  });

  var btn = document.getElementById('resend'), cd = document.getElementById('cd'), n = 60;
  var LOCALE = @json(app()->getLocale());
  var fa = function (x) {
    return LOCALE === 'fa'
      ? String(x).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; })
      : String(x);
  };
  cd.textContent = '(' + fa(n) + ')';
  var t = setInterval(function () {
    if (--n <= 0) { clearInterval(t); btn.disabled = false; cd.textContent = ''; return; }
    cd.textContent = '(' + fa(n) + ')';
  }, 1000);
})();
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>{!! __('ui.auth_code_nostore') !!}</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-clock"/></svg>
    <span>{!! __('ui.auth_code_once') !!}</span>
  </div>
@endsection
