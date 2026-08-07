@extends('auth.shell')
@section('title', __('ui.auth_code_title').' — ServerNet')

@section('heading', __('ui.auth_code_title'))
@section('sub')
  {!! __('ui.auth_code_sub', ['dest' => '<b dir="ltr">'.e($masked).'</b>']) !!}
@endsection

@section('form')
<form method="POST" action="{{ lroute('register.verify') }}" class="auth-f" id="otp-form">
  @csrf
  <input type="hidden" name="code" id="code">

  <div class="auth-field">
    <label for="d1" class="visually-hidden">{{ __('ui.auth_code') }}</label>
    {{-- شش خانهٔ جدا: خواندن و تصحیح یک رقم اشتباه ساده‌تر از یک فیلد بلند است --}}
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

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_code_submit') }}</span></button>
</form>

<div class="auth-row">
  <form method="POST" action="{{ lroute('register.resend') }}">
    @csrf
    <button type="submit" class="auth-ghost" id="resend" disabled>
      {{ __('ui.auth_resend') }} <span id="cd">(۶۰)</span>
    </button>
  </form>
  <a class="auth-ghost" href="{{ lroute('register') }}">
    {{ $iranian ? __('ui.auth_change_mobile') : __('ui.auth_change_email') }}
  </a>
</div>

{{-- منطقِ مشترک با صفحهٔ ورود — همان باگِ پرکردنِ خودکارِ iOS این‌جا هم بود.
     رفعِ یک‌جا در `public/assets/js/otp-input.js`، با تستِ node. --}}
<script src="{{ asset('assets/js/otp-input.js') }}?v=2" defer></script>
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
