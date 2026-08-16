{{-- تست سرعت اینترنت — سنجش اتصال کاربر به سرور سرورنت (سبک speedtest) --}}
<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:760px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_spt_h1a') }} <span class="grad">{{ __('ui.tl_spt_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_spt_lead') }}</p>

      <div class="reveal" style="transition-delay:.24s;margin-top:26px">
        <button class="btn btn-primary" id="spt-start" type="button" style="min-width:220px;justify-content:center"
                data-ping="{{ lroute('api.spt.ping') }}" data-down="{{ lroute('api.spt.down') }}" data-up="{{ lroute('api.spt.up') }}">
          <span class="tsb-label">{{ __('ui.tl_spt_btn') }}</span><span class="dr-spin" hidden></span>
        </button>
        <p class="co-note" id="spt-phase" style="min-height:22px;margin-top:12px"></p>
      </div>
      <div class="tool-error" id="spt-error" hidden></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div class="lkr-pings" id="spt-result" hidden>
        <div class="lkr-stat"><b dir="ltr" id="spt-ping">—</b><small>{{ __('ui.tl_spt_ping') }} ({{ __('ui.lk_ping_ms') }})</small></div>
        <div class="lkr-stat"><b dir="ltr" id="spt-jitter">—</b><small>{{ __('ui.tl_spt_jitter') }} ({{ __('ui.lk_ping_ms') }})</small></div>
        <div class="lkr-stat"><b dir="ltr" id="spt-down">—</b><small>{{ __('ui.tl_spt_down') }} (Mbps)</small></div>
        <div class="lkr-stat"><b dir="ltr" id="spt-up">—</b><small>{{ __('ui.tl_spt_up') }} (Mbps)</small></div>
      </div>
      <p class="lkr-note" id="spt-note" hidden>{{ __('ui.tl_spt_note') }}</p>
    </div>
  </div>
</section>

<script>
window.SPT_META = {
  fa: {{ $isFa ? 'true' : 'false' }},
  i18n: {
    again: @json(__('ui.tl_spt_again')), generic: @json(__('ui.chat_error')),
    phasePing: @json(__('ui.tl_spt_ph_ping')), phaseDown: @json(__('ui.tl_spt_ph_down')), phaseUp: @json(__('ui.tl_spt_ph_up')),
  },
};
</script>
