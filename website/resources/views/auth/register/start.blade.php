@extends('auth.shell')
@section('title', __('ui.auth_reg_title').' — ServerNet')

@section('heading', __('ui.auth_reg_title'))
@section('sub', __('ui.auth_reg_sub'))

@section('form')
<form method="POST" action="{{ lroute('register.start') }}" class="auth-f" novalidate>
  @csrf

  <div class="auth-field">
    <label id="lbl-type">{{ __('ui.auth_acct_type') }}</label>
    <div class="auth-pick" role="radiogroup" aria-labelledby="lbl-type">
      <input type="radio" name="type" id="t-ind" value="individual" {{ old('type', 'individual') === 'individual' ? 'checked' : '' }}>
      <label for="t-ind"><b>{{ __('ui.auth_individual') }}</b><span>{{ __('ui.auth_individual_d') }}</span></label>

      <input type="radio" name="type" id="t-co" value="company" {{ old('type') === 'company' ? 'checked' : '' }}>
      <label for="t-co"><b>{{ __('ui.auth_company') }}</b><span>{{ __('ui.auth_company_d') }}</span></label>
    </div>
  </div>

  @if($iranian)
  <div class="auth-field" data-check="mobile">
    <label for="phone">{{ __('ui.auth_mobile') }}</label>
    <input type="tel" id="phone" name="phone" dir="ltr" inputmode="numeric"
           autocomplete="tel" placeholder="{{ __('ui.auth_mobile_ph') }}" value="{{ old('phone') }}"
           required aria-describedby="hint-phone">
    <span class="msg">{{ __('ui.auth_mobile_bad') }}</span>
    <small id="hint-phone">{{ __('ui.auth_mobile_hint') }}</small>
  </div>
  @endif

  <div class="auth-field" data-check="email">
    <label for="email">{{ __('ui.auth_email') }}</label>
    <input type="email" id="email" name="email" dir="ltr"
           autocomplete="email" placeholder="you@example.com" value="{{ old('email') }}"
           required aria-describedby="hint-email">
    <span class="msg">{{ __('ui.auth_email_bad') }}</span>
    <small id="hint-email">{{ __('ui.auth_email_hint') }}</small>
  </div>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_send_code') }}</span></button>
</form>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>{!! __('ui.auth_secure') !!}</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-mail"/></svg>
    <span>{!! __('ui.auth_email_private') !!}</span>
  </div>
@endsection

@section('aside')
  {{ __('ui.auth_have_account') }} <a href="{{ lroute('login') }}">{{ __('ui.auth_do_login') }}</a>
@endsection
