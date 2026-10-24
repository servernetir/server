@extends('panel.layout')
@section('title', __('dns.claim.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
<div class="dns">
  <div>
    <a class="dns-back" href="{{ lroute('account.dns') }}">{{ __('dns.ui.back') }}</a>
    <div class="pnl-head" style="margin-top:8px">
      <div>
        <h1 class="dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($claim->domain) }}</h1>
        <p>{{ __('dns.claim.intro') }}</p>
      </div>
    </div>
  </div>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @if($errors->has('claim'))<div class="dns-note danger" role="alert">{{ $errors->first('claim') }}</div>@endif

  @if(! $claim->isPending())
    <div class="dns-note warn">{{ __('dns.claim.'.($claim->isExpired() ? 'expired' : 'not_pending')) }}</div>
  @else
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('dns.claim.step_add') }}</h2></div>
    <div class="pnl-sec-b">
      <p class="dns-t-dim dns-small">{{ __('dns.claim.where') }}</p>
      <div class="dns-kv">
        <span>{{ __('dns.form.type') }}</span><div class="dns-copy"><code>TXT</code></div>
        <span>{{ __('dns.form.name') }}</span>
        <div class="dns-copy"><code>{{ $challenge['name'] }}</code>
          <button type="button" data-copy="{{ $challenge['name'] }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
        <span>{{ __('dns.form.value') }}</span>
        <div class="dns-copy"><code>{{ $challenge['value'] }}</code>
          <button type="button" data-copy="{{ $challenge['value'] }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
      </div>
      <p class="dns-t-dim dns-small">{{ __('dns.claim.relative_hint', ['label' => '_servernet-challenge']) }}</p>
    </div>
  </section>

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('dns.claim.step_verify') }}</h2></div>
    <div class="pnl-sec-b">
      <p class="dns-t-dim dns-small">{{ __('dns.claim.wait_hint') }}</p>
      @if($claim->last_checked_at)
        <p class="dns-small">{{ __('dns.claim.last_check', ['time' => sdate($claim->last_checked_at, true)]) }}
          — <b class="{{ $claim->last_result === 'lookup_failed' ? 'dns-t-warn' : 'dns-t-bad' }}">{{ __('dns.claim.'.$claim->last_result) }}</b></p>
      @endif
      <div class="dns-row-actions">
        <form method="POST" action="{{ lroute('account.dns.claim.verify', $claim->id) }}">@csrf
          <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>{{ __('dns.claim.verify_btn') }}</button>
        </form>
        <form method="POST" action="{{ lroute('account.dns.claim.cancel', $claim->id) }}">@csrf
          <button class="pnl-btn" type="submit">{{ __('dns.claim.cancel_btn') }}</button>
        </form>
      </div>
      <p class="dns-t-dim dns-small">{{ __('dns.claim.expires', ['date' => sdate($claim->expires_at)]) }} · {{ __('dns.claim.no_lock') }}</p>
    </div>
  </section>
  @endif
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection
