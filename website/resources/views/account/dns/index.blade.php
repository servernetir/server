@extends('panel.layout')
@section('title', __('dns.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
<div class="dns">

  <div class="pnl-head">
    <div>
      <h1>{{ __('dns.title') }}</h1>
      <p>{{ __('dns.intro') }}</p>
    </div>
  </div>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @if($errors->has('domain'))<div class="dns-note danger" role="alert">{{ $errors->first('domain') }}</div>@endif

  @unless($ready)
    <div class="dns-note warn"><b>{{ __('dns.coming_soon_title') }}</b><br>{{ __('dns.coming_soon_body') }}</div>
  @else

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('dns.add.title') }}</h2></div>
    <div class="pnl-sec-b">
      @unless($canAdd)
      <div class="dns-note warn" role="status">{{ __('dns.add.paused') }}</div>
      @else
      <form method="POST" action="{{ lroute('account.dns') }}" class="dns-add">
        @csrf
        <input class="dns-input dns-ltr" name="domain" dir="ltr" autocomplete="off" spellcheck="false" inputmode="url"
               placeholder="example.com" value="{{ old('domain') }}" required aria-label="{{ __('dns.add.domain') }}">
        <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>{{ __('dns.add.submit') }}</button>
      </form>
      <p class="dns-t-dim dns-small">{{ __('dns.add.hint') }}</p>
      @endunless
    </div>
  </section>

  @if($claims->count())
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('dns.claim.pending_title') }}</h2></div>
    <div class="pnl-sec-b">
      <div class="dns-zones">
        @foreach($claims as $c)
          <a class="dns-zone" href="{{ lroute('account.dns.claim', $c->id) }}">
            <span class="dns-zone-ic"><svg class="icon"><use href="#i-key"/></svg></span>
            <span>
              <span class="dns-zone-d dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($c->domain) }}</span>
              <span class="dns-zone-m">{{ __('dns.claim.expires', ['date' => sdate($c->expires_at)]) }}</span>
            </span>
            <span class="dns-zone-end"><span class="dns-pill wait">{{ __('dns.claim.awaiting') }}</span></span>
          </a>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('dns.list.title') }}</h2></div>
    <div class="pnl-sec-b">
      @forelse($zones as $z)
        @php
          $pill = match(true) {
            $z->status === 'deleting'  => ['off', __('dns.status.deleting')],
            $z->status === 'suspended' => ['off', __('dns.status.suspended')],
            $z->status === 'active' && in_array($z->delegation_state, ['not_delegated', 'partial'], true) => ['wait', __('dns.delegation.state_lost')],
            $z->status === 'active'    => ['on', __('dns.status.active')],
            default                    => ['wait', __('dns.status.pending')],
          };
        @endphp
        @if($z->status === 'deleting')
          <div class="dns-zone is-muted">
        @else
          <a class="dns-zone" href="{{ lroute('account.dns.zone', $z->id) }}">
        @endif
            <span class="dns-zone-ic"><svg class="icon"><use href="#i-globe"/></svg></span>
            <span>
              <span class="dns-zone-d dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($z->domain) }}</span>
              <span class="dns-zone-m">{{ trans_choice('dns.list.records', $z->records_count, ['n' => $z->records_count]) }}</span>
            </span>
            <span class="dns-zone-end"><span class="dns-pill {{ $pill[0] }}">{{ $pill[1] }}</span></span>
        @if($z->status === 'deleting')
          </div>
        @else
          </a>
        @endif
      @empty
        <div class="pnl-empty">
          <svg class="icon"><use href="#i-globe"/></svg>
          <b>{{ __('dns.list.empty_title') }}</b>
          <p class="dns-t-dim dns-small">{{ __('dns.list.empty_body') }}</p>
        </div>
      @endforelse
    </div>
  </section>

  @endunless
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection
