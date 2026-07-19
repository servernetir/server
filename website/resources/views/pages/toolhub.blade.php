@extends('layouts.site')

@php
    $c = lc($cfg);
    $mode = $cfg['mode'];
    $lkTypes = config('lookup.types');
    // تب‌های حالت شبکه (از config/lookup)
    $tabs = [];
    if ($mode === 'network') {
        foreach ($cfg['checks'] as $ck) {
            if (isset($lkTypes[$ck])) {
                $tabs[$ck] = ['label' => lc($lkTypes[$ck])['t'], 'kind' => $lkTypes[$ck]['kind'], 'input' => $lkTypes[$ck]['input'] ?? 'domain'];
            }
        }
    }
@endphp

@section('title', $c['meta_t'].' — '.__('ui.brand'))
@section('description', $c['meta_d'])

@section('content')

{{-- ============ HERO + FORM ============ --}}
<section class="hero hero-sub" style="padding-bottom:36px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:840px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.lk_badge') }} · {{ __('ui.nav_new') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $c['h1a'] }} <span class="grad">{{ $c['h1b'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $c['lead'] }}</p>

      <form class="lk-form reveal" id="hub-form" style="transition-delay:.24s">
        @if($mode === 'network')
        <div class="hub-tabs" id="hub-tabs">
          @foreach($tabs as $ck => $t)
          <button type="button" class="hub-tab @if($loop->first) active @endif" data-check="{{ $ck }}" data-kind="{{ $t['kind'] }}" data-input="{{ $t['input'] }}">{{ $t['label'] }}</button>
          @endforeach
        </div>
        @endif
        <div class="lk-input-wrap">
          <input type="text" id="hub-input" placeholder="{{ $c['placeholder'] }}" autocomplete="off" spellcheck="false" dir="ltr" required>
          <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ $c['btn'] }}</span><span class="dr-spin" hidden></span></button>
        </div>
      </form>
      <div class="tool-error" id="hub-error" hidden></div>
    </div>
  </div>
</section>

{{-- ============ RESULT ============ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap"><div class="lk-result" id="hub-result" hidden></div></div>
  </div>
</section>

{{-- ============ SEO intro + FAQ ============ --}}
<section class="section lk-seo" style="padding-top:10px">
  <div class="container" style="max-width:820px">
    <div class="lk-prose reveal"><h2>{{ __('ui.lk_about') }}</h2><p>{{ $c['intro'] }}</p></div>
    @if(!empty($c['faq']))
    <div class="lk-faq reveal">
      <h2>{{ __('ui.lk_faq') }}</h2>
      @foreach($c['faq'] as $f)<details class="lk-faq-item"><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
    </div>
    @endif
  </div>
</section>

{{-- ============ CROSS-SELL: صفحات تکی + ابزار دیگر ============ --}}
<section class="section" style="padding-top:6px;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:26px"><h2 style="font-size:22px">{{ __('ui.hub_individual') }}</h2></div>
    <div class="lk-grid reveal">
      @php $group = $mode === 'dns' ? 'records' : 'network'; @endphp
      @foreach($lkTypes as $tk => $tc)
        @if(($tc['group'] ?? '') === $group)
        <a class="lk-tile" href="{{ lroute('lookup', $tk) }}"><svg class="icon"><use href="#i-{{ $tc['icon'] }}"/></svg><span>{{ lc($tc)['t'] }}</span></a>
        @endif
      @endforeach
      <a class="lk-tile" href="{{ $mode === 'dns' ? lroute('hub.network') : lroute('hub.dns') }}" style="border-color:var(--cyan)">
        <svg class="icon"><use href="#i-{{ $mode === 'dns' ? 'shield' : 'db' }}"/></svg><span>{{ $mode === 'dns' ? config('toolhub.network.'.app()->getLocale().'.t', 'Network') : config('toolhub.dns.'.app()->getLocale().'.t', 'DNS') }}</span>
      </a>
    </div>
  </div>
</section>

{{-- ============ JSON-LD ============ --}}
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'WebApplication',
    'name' => $c['meta_t'], 'url' => url()->current(),
    'applicationCategory' => 'UtilitiesApplication', 'operatingSystem' => 'Any',
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    'provider' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@if(!empty($c['faq']))
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $c['faq']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

<script>
window.LOOKUP = {
  fa: {{ $isFa ? 'true' : 'false' }},
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
    dnssec_on: @json(__('ui.lk_dnssec_on')), dnssec_off: @json(__('ui.lk_dnssec_off')), dnssec_auth: @json(__('ui.lk_dnssec_auth')), dnssec_ds: @json(__('ui.lk_dnssec_ds')), dnssec_key: @json(__('ui.lk_dnssec_key')), yes: @json(__('ui.lk_yes')), no: @json(__('ui.lk_no')),
    prop_consistent: @json(__('ui.lk_prop_consistent')), prop_pending: @json(__('ui.lk_prop_pending')), prop_resolver: @json(__('ui.lk_prop_resolver')), prop_noanswer: @json(__('ui.lk_prop_noanswer')),
    rev_ptr: @json(__('ui.lk_rev_ptr')), rev_names: @json(__('ui.lk_rev_names')), rev_none: @json(__('ui.lk_rev_none')),
  },
};
window.TOOLHUB = { mode: @json($mode), dnsEndpoint: @json(lroute('api.dnsreport')), lookupEndpoint: @json(lroute('api.lookup')), dnsAll: @json(__('ui.hub_dns_all')) };
</script>
<script src="{{ asset('assets/js/lookup.js') }}?v={{ filemtime(public_path('assets/js/lookup.js')) }}" defer></script>
<script src="{{ asset('assets/js/toolhub.js') }}?v={{ filemtime(public_path('assets/js/toolhub.js')) }}" defer></script>
@endsection
