{{-- ابزار بررسی IP --}}
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
      <div class="tool-error" id="ip-error" hidden></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div class="ip-card" id="ip-result" hidden></div>
    </div>
  </div>
</section>

<script>
window.TOOL_I18N = {
  fa: {{ $isFa ? 'true' : 'false' }},
  invalid: @json(__('ui.tl_ip_invalid')), generic: @json(__('ui.chat_error')),
  country: @json(__('ui.tl_ip_country')), region: @json(__('ui.tl_ip_region')), city: @json(__('ui.tl_ip_city')),
  zip: @json(__('ui.tl_ip_zip')), timezone: @json(__('ui.tl_ip_tz')), isp: @json(__('ui.tl_ip_isp')),
  org: @json(__('ui.tl_wk_org')), asn: @json(__('ui.tl_ip_asn')), reverse: @json(__('ui.tl_ip_reverse')),
  hosting: @json(__('ui.tl_ip_hosting')), proxy: @json(__('ui.tl_ip_proxy')), mobile: @json(__('ui.tl_ip_mobile')),
};
</script>
