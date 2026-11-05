@extends('panel.layout')
@section('title', __('dns.claim.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php $back = preg_replace('/^[\x{2190}-\x{2192}\s]+/u', '', __('dns.ui.back')); @endphp
<div class="dns">
  <a class="dns-back" href="{{ lroute('account.dns') }}"><svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>{{ $back }}</a>

  <header class="dns-hero">
    <div>
      <p class="dns-eyebrow"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg>{{ __('dns.ux.claim_eyebrow') }}</p>
      <h1 class="dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($claim->domain) }}</h1>
      <p class="dns-lead">{{ __('dns.claim.intro') }}</p>
    </div>
    <div class="dns-orb" aria-hidden="true">
      <span class="dns-orb-track"></span>
      <span class="dns-orb-core"><svg class="icon"><use href="#i-shield"/></svg></span>
    </div>
  </header>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @if($errors->has('claim'))<div class="dns-note danger" role="alert">{{ $errors->first('claim') }}</div>@endif

  @if(! $claim->isPending())
    <div class="dns-note warn">{{ __('dns.claim.'.($claim->isExpired() ? 'expired' : 'not_pending')) }}</div>
  @else
  <div class="dns-grid">
    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-file"/></svg></span>
          <div><h2>{{ __('dns.claim.step_add') }}</h2><p>{{ __('dns.claim.where') }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        <div class="dns-kv">
          <span>{{ __('dns.form.type') }}</span><div class="dns-copy"><code>TXT</code></div>
          <span>{{ __('dns.form.name') }}</span>
          <div class="dns-copy"><code>{{ $challenge['name'] }}</code>
            <button type="button" data-copy="{{ $challenge['name'] }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
          <span>{{ __('dns.form.value') }}</span>
          <div class="dns-copy"><code>{{ $challenge['value'] }}</code>
            <button type="button" data-copy="{{ $challenge['value'] }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
        </div>
        <p class="dns-hint">{{ __('dns.claim.relative_hint', ['label' => '_servernet-challenge']) }}</p>
      </div>
    </section>

    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-check"/></svg></span>
          <div><h2>{{ __('dns.claim.step_verify') }}</h2><p>{{ __('dns.claim.expires', ['date' => sdate($claim->expires_at)]) }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        <p class="dns-t-dim dns-small">{{ __('dns.claim.wait_hint') }}</p>
        @if($claim->last_checked_at)
          <div class="dns-note {{ $claim->last_result === 'lookup_failed' ? 'warn' : 'danger' }}"><div>{{ __('dns.claim.last_check', ['time' => sdate($claim->last_checked_at, true)]) }}
            — <b>{{ __('dns.claim.'.$claim->last_result) }}</b></div></div>
        @endif
        <div class="dns-row-actions">
          <form method="POST" action="{{ lroute('account.dns.claim.verify', $claim->id) }}" data-busy>@csrf
            <button class="pnl-btn primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('dns.claim.verify_btn') }}</button>
          </form>
          <form method="POST" action="{{ lroute('account.dns.claim.cancel', $claim->id) }}">@csrf
            <button class="pnl-btn" type="submit">{{ __('dns.claim.cancel_btn') }}</button>
          </form>
        </div>
        <p class="dns-hint">{{ __('dns.claim.no_lock') }}</p>
      </div>
    </section>
  </div>
  @endif
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection
