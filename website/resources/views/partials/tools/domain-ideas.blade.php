{{-- پیشنهادگر نام دامنه — توصیف کسب‌وکار → نام‌های برنددار + وضعیت ثبت --}}
<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:760px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_ideas_h1a') }} <span class="grad">{{ __('ui.tl_ideas_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_ideas_lead') }}</p>
      <form class="tool-search reveal" id="ideas-form" style="transition-delay:.24s"
            data-endpoint="{{ lroute('api.ideas') }}">
        <svg class="icon"><use href="#i-search"/></svg>
        <input type="text" id="ideas-input" placeholder="{{ __('ui.tl_ideas_ph') }}" autocomplete="off" maxlength="300" required>
        <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ __('ui.tl_ideas_btn') }}</span><span class="dr-spin" hidden></span></button>
      </form>
      <div class="tool-error" id="ideas-error" hidden></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div id="ideas-result" hidden>
        <div class="lk-grid" id="ideas-grid"></div>
        <p class="lkr-note">{{ __('ui.tl_ideas_note') }}</p>
      </div>
    </div>
  </div>
</section>

<script>
window.IDEAS_META = {
  fa: {{ $isFa ? 'true' : 'false' }},
  i18n: {
    short: @json(__('ui.tl_ideas_short')), empty: @json(__('ui.tl_ideas_empty')), generic: @json(__('ui.chat_error')),
    taken: @json(__('ui.tl_ideas_taken')), check: @json(__('ui.tl_ideas_check')),
    more: @json(__('ui.tl_ideas_again')),
    checkUrl: @json(lroute('domain.search').'?q='),
  },
};
</script>
