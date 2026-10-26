@extends('panel.layout')
@section('title', __('marketing.unsubscribe_title'))
@section('panel')
<div class="pnl-page-head"><h1>{{ __('marketing.unsubscribe_title') }}</h1></div>
<section class="pnl-sec"><div class="pnl-sec-b">
  @if(session('ok'))<p role="status">{{ session('ok') }}</p>@endif
  <p>{{ __('marketing.consent_help') }}</p>
  <form method="post" action="{{ lroute('account.marketing.unsubscribe') }}">
    @csrf
    <button class="pnl-btn" type="submit">{{ __('marketing.no_consent') }}</button>
  </form>
</div></section>
@endsection
