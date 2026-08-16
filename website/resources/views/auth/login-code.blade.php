@extends('auth.shell')
@section('title', __('ui.auth_code_title').' — ServerNet')

@section('heading', __('ui.auth_code_title'))
@section('sub')
  {!! __('ui.auth_code_sub', ['dest' => '<b dir="ltr">'.e($masked).'</b>']) !!}
@endsection

@section('form')
@if(session('ok'))<div class="auth-note ok" style="margin-bottom:14px">{{ session('ok') }}</div>@endif
@if(session('err'))<div class="auth-note err" style="margin-bottom:14px">{{ session('err') }}</div>@endif

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

{{--
  🔴 منطق در `public/assets/js/otp-input.js` است، نه این‌جا.

  همین کد قبلاً در این صفحه و در `register/verify` دو نسخهٔ جدا داشت و **هر دو
  همان باگ را داشتند**: iOS کدِ پیامک را یک‌جا در خانهٔ اول می‌ریزد و نسخهٔ
  درون‌خطی با `value.slice(-1)` فقط رقمِ آخر را نگه می‌داشت — یعنی پرکردنِ
  خودکار کد را نابود می‌کرد. روی دسکتاپ دیده نمی‌شد چون آن‌جا رقم‌به‌رقم تایپ
  می‌شود. حالا یک فایل، با تستِ node.
--}}
<script src="{{ asset_ver('assets/js/otp-input.js') }}" defer></script>
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
