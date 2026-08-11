{{-- ابزار بررسی IP --}}
@php
    $flagCodes = flag_codes();
    $flagBase = rtrim(asset('assets/flags'), '/').'/';
    $ipI18n = [
        'fa'          => $isFa,
        'invalid'     => __('ui.tl_ip_invalid'),
        'generic'     => __('ui.chat_error'),
        'country'     => __('ui.tl_ip_country'),
        'continent'   => __('ui.tl_ip_continent'),
        'region'      => __('ui.tl_ip_region'),
        'city'        => __('ui.tl_ip_city'),
        'zip'         => __('ui.tl_ip_zip'),
        'timezone'    => __('ui.tl_ip_tz'),
        'isp'         => __('ui.tl_ip_isp'),
        'org'         => __('ui.tl_wk_org'),
        'asn'         => __('ui.tl_ip_asn'),
        'reverse'     => __('ui.tl_ip_reverse'),
        'hosting'     => __('ui.tl_ip_hosting'),
        'proxy'       => __('ui.tl_ip_proxy'),
        'mobile'      => __('ui.tl_ip_mobile'),
        'residential' => __('ui.tl_ip_residential'),
        'yourIp'      => __('ui.tl_ip_addr'),
        'localTime'   => __('ui.tl_ip_localtime'),
        'secGeo'      => __('ui.tl_ip_sec_geo'),
        'secNet'      => __('ui.tl_ip_sec_net'),
        'mapTitle'    => __('ui.tl_ip_map'),
        'copy'        => __('ui.tl_ip_copy'),
        'flagBase'    => $flagBase,
        'flags'       => $flagCodes,
    ];
@endphp

<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:760px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_ip_h1a') }} <span class="grad">{{ __('ui.tl_ip_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_ip_lead') }}</p>
      <form class="tool-search reveal" id="ip-form" style="transition-delay:.24s"
            data-endpoint="{{ route($routePrefix.'api.ip') }}" data-auto="1">
        <svg class="icon"><use href="#i-globe"/></svg>
        <input type="text" id="ip-input" placeholder="8.8.8.8 {{ $isFa ? 'یا example.com' : 'or example.com' }}" autocomplete="off" spellcheck="false" dir="ltr" value="{{ $prefill }}">
        <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ __('ui.tl_ip_btn') }}</span><span class="dr-spin" hidden></span></button>
      </form>
      <div class="tool-hint reveal" style="transition-delay:.3s">
        <span><svg class="icon"><use href="#i-zap"/></svg>{{ __('ui.tl_ip_hint1') }}</span>
        <span><svg class="icon"><use href="#i-lock"/></svg>{{ __('ui.tl_ip_hint2') }}</span>
        <span><svg class="icon"><use href="#i-globe"/></svg>{{ __('ui.tl_ip_hint3') }}</span>
      </div>
      <div class="tool-error" id="ip-error" hidden></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div class="ip-card" id="ip-result" hidden></div>

      {{-- فروشِ نرم: کسی که IP دیتاسنتر را بررسی می‌کند، احتمالاً خودش سرور می‌خواهد --}}
      <div class="ipr-cta reveal">
        <div class="ipr-cta-txt">
          <b>{{ __('ui.tl_ip_cta_t') }}</b>
          <span>{{ __('ui.tl_ip_cta_d') }}</span>
        </div>
        <div class="ipr-cta-act">
          <a class="btn btn-primary" href="{{ lroute('cloud.index') }}">{{ __('ui.tl_ip_cta_btn') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
          <a class="btn btn-glass" href="{{ lroute('lookup.index') }}">{{ __('ui.tl_ip_cta_alt') }}</a>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
window.TOOL_I18N = @json($ipI18n);
</script>
