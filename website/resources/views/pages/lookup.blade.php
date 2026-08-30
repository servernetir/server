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
    em_good: @json(__('ui.lk_em_good')), em_warn: @json(__('ui.lk_em_warn')), em_bad: @json(__('ui.lk_em_bad')),
    em_found: @json(__('ui.lk_em_found')), em_missing: @json(__('ui.lk_em_missing')), em_multi: @json(__('ui.lk_em_multi')),
    em_record: @json(__('ui.lk_em_record')), em_dkim_none: @json(__('ui.lk_em_dkim_none')),
    bl_zone: @json(__('ui.lk_bl_zone')), bl_state: @json(__('ui.lk_bl_state')), bl_reason: @json(__('ui.lk_bl_reason')),
    bl_listed: @json(__('ui.lk_bl_listed')), bl_clean: @json(__('ui.lk_bl_clean')), bl_unknown: @json(__('ui.lk_bl_unknown')),
    bl_all_clean: @json(__('ui.lk_bl_all_clean')), bl_some: @json(__('ui.lk_bl_some')),
    sp_ms: @json(__('ui.lk_sp_ms')), sp_connect: @json(__('ui.lk_sp_connect')), sp_total: @json(__('ui.lk_sp_total')),
    sp_eu: @json(__('ui.lk_sp_eu')), sp_iran: @json(__('ui.lk_sp_iran')),
    sp_noprobe: @json(__('ui.lk_sp_noprobe')), sp_probe_down: @json(__('ui.lk_sp_probe_down')),
    hd_grade: @json(__('ui.lk_hd_grade')), hd_present: @json(__('ui.lk_hd_present')), hd_absent: @json(__('ui.lk_hd_absent')), hd_frame: @json(__('ui.lk_hd_frame')),
    rd_hops: @json(__('ui.lk_rd_hops')), rd_none: @json(__('ui.lk_rd_none')), rd_loop: @json(__('ui.lk_rd_loop')),
    rd_blocked: @json(__('ui.lk_rd_blocked')), rd_https: @json(__('ui.lk_rd_https')), rd_status: @json(__('ui.lk_rd_status')), rd_final: @json(__('ui.lk_rd_final')),
    ac_filtered: @json(__('ui.lk_ac_filtered')), ac_accessible: @json(__('ui.lk_ac_accessible')), ac_likely: @json(__('ui.lk_ac_likely')),
    ac_unreach_iran: @json(__('ui.lk_ac_unreach_iran')), ac_unknown: @json(__('ui.lk_ac_unknown')), ac_noanswer: @json(__('ui.lk_ac_noanswer')),
    ac_block_ip: @json(__('ui.lk_ac_block_ip')), ac_dns_global: @json(__('ui.lk_ac_dns_global')), ac_dns_iran: @json(__('ui.lk_ac_dns_iran')),
    ac_http_world: @json(__('ui.lk_ac_http_world')), ac_http_iran: @json(__('ui.lk_ac_http_iran')),
    ch_loc: @json(__('ui.lk_ch_loc')), ch_avg: @json(__('ui.lk_ch_avg')), ch_minmax: @json(__('ui.lk_ch_minmax')), ch_loss: @json(__('ui.lk_ch_loss')),
    ch_time: @json(__('ui.lk_ch_time')), ch_pending: @json(__('ui.lk_ch_pending')), ch_timeout: @json(__('ui.lk_ch_timeout')), ch_err: @json(__('ui.lk_ch_err')), ch_down: @json(__('ui.lk_ch_down')),
    ch_ok_of: @json(__('ui.lk_ch_ok_of')), ch_iran_ok: @json(__('ui.lk_ch_iran_ok')), ch_iran_down: @json(__('ui.lk_ch_iran_down')),
    cwv_score: @json(__('ui.lk_cwv_score')), cwv_s: @json(__('ui.lk_cwv_s')), cwv_cached: @json(__('ui.lk_cwv_cached')),
    err_psi: @json(__('ui.lk_err_psi')),
  },
};
</script>
<script src="{{ asset_ver('assets/js/lookup.js') }}" defer></script>
@endsection
