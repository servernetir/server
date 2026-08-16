@extends('layouts.site')

@php
    $c = lc($cfg);                          // محتوای زبان جاری
    $types = config('lookup.types');
    $groups = config('lookup.groups');
    $loc = app()->getLocale();
@endphp

@section('title', $c['meta_t'].' — '.__('ui.brand'))
@section('description', $c['meta_d'])

{{-- `/lookup` همان `/lookup/a` را رندر می‌کند؛ بی‌این خط هر دو خودشان را
     canonical اعلام می‌کردند و برای یک کوئری رقابت می‌کردند. چرایی در کنترلر. --}}
@section('canonical', $canonical)

@section('content')

{{-- ============ HERO + FORM ============ --}}
<section class="hero hero-sub" style="padding-bottom:36px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:820px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.lk_badge') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $c['h1a'] }} <span class="grad">{{ $c['h1b'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $c['lead'] }}</p>

      <form class="lk-form reveal" id="lk-form" style="transition-delay:.24s"
            data-endpoint="{{ lroute('api.lookup') }}" data-type="{{ $type }}"
            data-kind="{{ $cfg['kind'] }}" data-auto="{{ $cfg['kind'] === 'reverse' && $prefill ? '1' : '' }}">
        <div class="lk-select-wrap">
          <svg class="icon lk-select-ico"><use href="#i-{{ $cfg['icon'] }}"/></svg>
          <select id="lk-type" class="lk-select" aria-label="{{ __('ui.lk_type') }}">
            @foreach($groups as $gk => $gl)
              <optgroup label="{{ lc($gl) }}">
                @foreach($types as $tk => $tc)
                  @if(($tc['group'] ?? '') === $gk)
                    <option value="{{ lroute('lookup', $tk) }}" @selected($tk === $type)>{{ lc($tc)['t'] }}</option>
                  @endif
                @endforeach
              </optgroup>
            @endforeach
          </select>
          <svg class="icon lk-select-chev"><use href="#i-chev"/></svg>
        </div>
        <div class="lk-input-wrap">
          <input type="text" id="lk-input" placeholder="{{ $c['placeholder'] }}" autocomplete="off"
                 spellcheck="false" dir="ltr" value="{{ $prefill }}" @if($cfg['input']!=='ip') required @endif>
          <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ __('ui.lk_check') }}</span><span class="dr-spin" hidden></span></button>
        </div>

        {{-- فقط برای اسکن پورت: فهرست دلخواه کاربر --}}
        @if($cfg['kind'] === 'ports')
        <div class="lk-ports-in">
          <label for="lk-ports">{{ __('ui.lk_ports_custom') }}</label>
          <input type="text" id="lk-ports" dir="ltr" autocomplete="off" spellcheck="false"
                 inputmode="numeric" placeholder="{{ __('ui.lk_ports_ph') }}">
          <span class="lk-ports-hint">{{ __('ui.lk_ports_hint') }}</span>
        </div>
        @endif
      </form>
      <div class="tool-error" id="lk-error" hidden></div>
    </div>
  </div>
</section>

{{-- ============ RESULT ============ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div class="lk-result" id="lk-result" hidden></div>
    </div>
  </div>
</section>

{{-- ============ SEO: intro + FAQ ============ --}}
<section class="section lk-seo" style="padding-top:10px">
  <div class="container" style="max-width:820px">
    <div class="lk-prose reveal">
      <h2>{{ __('ui.lk_about') }}</h2>
      <p>{{ $c['intro'] }}</p>
    </div>

    @if(!empty($c['faq']))
    <div class="lk-faq reveal">
      <h2>{{ __('ui.lk_faq') }}</h2>
      @foreach($c['faq'] as $f)
        <details class="lk-faq-item">
          <summary>{{ $f['q'] }}</summary>
          <p>{{ $f['a'] }}</p>
        </details>
      @endforeach
    </div>
    @endif
  </div>
</section>

{{-- ============ CTA ============ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="lk-cta reveal">
      <div>
        <h3>{{ __('ui.lk_cta_t') }}</h3>
        <p>{{ __('ui.lk_cta_d') }}</p>
      </div>
      <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ __('ui.lk_cta_btn') }}</span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
    </div>
  </div>
</section>

{{-- ============ CROSS-SELL: همه‌ی ابزارهای lookup ============ --}}
<section class="section" style="padding-top:6px;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:30px"><h2 style="font-size:24px">{{ __('ui.lk_more') }}</h2></div>
    <div class="lk-grid reveal">
      @foreach($types as $tk => $tc)
        @if($tk !== $type)
        <a class="lk-tile" href="{{ lroute('lookup', $tk) }}">
          <svg class="icon"><use href="#i-{{ $tc['icon'] }}"/></svg>
          <span>{{ lc($tc)['t'] }}</span>
        </a>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- ============ JSON-LD schema ============ --}}
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => $c['meta_t'],
    'url' => url()->current(),
    'applicationCategory' => 'UtilitiesApplication',
    'operatingSystem' => 'Any',
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    'provider' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@if(!empty($c['faq']))
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($f) => [
        '@type' => 'Question',
        'name' => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ], $c['faq']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

<script>
window.LOOKUP = {
  fa: {{ $isFa ? 'true' : 'false' }},
  type: @json($type), kind: @json($cfg['kind']), input: @json($cfg['input']),
  i18n: {
    invalid_domain: @json(__('ui.lk_err_invalid_domain')), invalid_ip: @json(__('ui.lk_err_invalid_ip')),
    unreachable: @json(__('ui.lk_err_unreachable')), empty: @json(__('ui.lk_err_empty')),
    generic: @json(__('ui.lk_err_generic')), no_records: @json(__('ui.lk_err_no_records')), no_ssl: @json(__('ui.lk_err_no_ssl')),
    copy: @json(__('ui.lk_copy')), copied: @json(__('ui.lk_copied')), json: @json(__('ui.lk_json')), download: @json(__('ui.lk_download')),
    ttl: @json(__('ui.lk_ttl')), value: @json(__('ui.lk_value')), type_col: @json(__('ui.lk_type_col')), records: @json(__('ui.lk_records')), result: @json(__('ui.lk_result')), ip: @json(__('ui.lk_ip')),
    ssl_valid: @json(__('ui.lk_ssl_valid')), ssl_expired: @json(__('ui.lk_ssl_expired')), ssl_issuer: @json(__('ui.lk_ssl_issuer')), ssl_expires: @json(__('ui.lk_ssl_expires')),
    ssl_from: @json(__('ui.lk_ssl_from')), ssl_days: @json(__('ui.lk_ssl_days')), ssl_covers: @json(__('ui.lk_ssl_covers')), ssl_algo: @json(__('ui.lk_ssl_algo')), ssl_subject: @json(__('ui.lk_ssl_subject')),
    ping_min: @json(__('ui.lk_ping_min')), ping_avg: @json(__('ui.lk_ping_avg')), ping_max: @json(__('ui.lk_ping_max')), ping_loss: @json(__('ui.lk_ping_loss')), ping_port: @json(__('ui.lk_ping_port')), ping_ms: @json(__('ui.lk_ping_ms')),
    port_open: @json(__('ui.lk_port_open')), port_closed: @json(__('ui.lk_port_closed')), port_filtered: @json(__('ui.lk_port_filtered')), ports_open: @json(__('ui.lk_ports_open')),
    port_skipped: @json(__('ui.lk_port_skipped')), ports_skipped_note: @json(__('ui.lk_ports_skipped_note')), bad_ports: @json(__('ui.lk_bad_ports')),
    dnssec_on: @json(__('ui.lk_dnssec_on')), dnssec_off: @json(__('ui.lk_dnssec_off')), dnssec_auth: @json(__('ui.lk_dnssec_auth')), dnssec_ds: @json(__('ui.lk_dnssec_ds')), dnssec_key: @json(__('ui.lk_dnssec_key')), yes: @json(__('ui.lk_yes')), no: @json(__('ui.lk_no')),
    prop_consistent: @json(__('ui.lk_prop_consistent')), prop_pending: @json(__('ui.lk_prop_pending')), prop_resolver: @json(__('ui.lk_prop_resolver')), prop_noanswer: @json(__('ui.lk_prop_noanswer')),
    rev_ptr: @json(__('ui.lk_rev_ptr')), rev_names: @json(__('ui.lk_rev_names')), rev_none: @json(__('ui.lk_rev_none')),
  },
};
</script>
<script src="{{ asset_ver('assets/js/lookup.js') }}" defer></script>
@endsection
