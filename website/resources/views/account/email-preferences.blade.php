@extends('panel.layout')
@section('title', __('email-marketing.account_title'))
@section('panel')
<div class="pnl-page-head"><h1>{{ __('email-marketing.account_title') }}</h1></div>
<section class="pnl-sec"><div class="pnl-sec-b">
  @if(session('ok'))<p role="status">{{ session('ok') }}</p>@endif
  @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
  <p>{{ __('email-marketing.account_intro') }}</p>
  <p><strong>{{ __('email-marketing.state') }}:</strong> {{ __('email-marketing.'.($eligible ? 'allowed' : 'blocked')) }}</p>
  @if(!$emailVerified)<p>{{ __('email-marketing.verify_hint') }}</p>@endif
  @if($mayOptIn && !$eligible)
    <form method="post" action="{{ lroute('account.email-preferences.update') }}" style="margin:18px 0">
      @csrf
      <input type="hidden" name="action" value="subscribe">
      <label style="display:flex;gap:10px;align-items:start"><input type="checkbox" name="consent" value="1" required><span>{{ __('email-marketing.explicit_consent') }}</span></label>
      <button class="pnl-btn" type="submit" style="margin-top:12px">{{ __('email-marketing.subscribe') }}</button>
    </form>
  @endif
  @if($canWithdraw)
    <form method="post" action="{{ lroute('account.email-preferences.update') }}" style="margin:18px 0">
      @csrf
      <input type="hidden" name="action" value="unsubscribe">
      <button class="pnl-btn" type="submit">{{ __('email-marketing.unsubscribe') }}</button>
    </form>
  @endif
  <p>{{ __('email-marketing.transactional_note') }}</p>
</div></section>
@endsection
