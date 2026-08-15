{{-- ابزار Whois --}}
<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:760px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_whois_h1a') }} <span class="grad">{{ __('ui.tl_whois_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_whois_lead') }}</p>
      <form class="tool-search reveal" id="whois-form" style="transition-delay:.24s"
            data-endpoint="{{ route($routePrefix.'api.whois') }}">
        <svg class="icon"><use href="#i-search"/></svg>
        <input type="text" id="whois-input" placeholder="example.com" autocomplete="off" spellcheck="false" required dir="ltr">
        <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ __('ui.tl_whois_btn') }}</span><span class="dr-spin" hidden></span></button>
      </form>
      <div class="tool-error" id="whois-error" hidden></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">
      <div class="wk-card" id="whois-result" hidden></div>
    </div>
  </div>
</section>

{{--
  🔴 «ثبت این دامنه» به فروشگاهِ **خودمان** می‌رود، نه WHMCSِ بیرونی.

  تا امروز این دکمه به سبدِ خریدِ WHMCS می‌رفت — یعنی درست در لحظه‌ای که
  بازدیدکننده بیشترین قصدِ خرید را دارد («این دامنه آزاد است!»)، از سایت بیرون
  پرتاب می‌شد به سامانه‌ای با ظاهر و حسابِ متفاوت. ابزارِ رایگانِ Whois یک قلابِ
  فروشِ دامنه است و انتهایش به سامانهٔ قدیمی می‌رسید، در حالی که مسیرِ
  درون‌خانگیِ ثبتِ دامنه (استعلامِ زنده، قیمتِ تومانی، پیش‌فاکتور) فعال است.

  `/domains?q=` از قبل پشتیبانی می‌شود و خودش جستجو را اجرا می‌کند، پس
  بازدیدکننده مستقیم روی نتیجهٔ همان دامنه می‌نشیند.

  ⚠️ این توضیح عمداً کامنتِ **Blade** است نه JS: کامنتِ JS داخلِ این بلوک به
  HTMLِ هر بازدید می‌رفت (چند صد بایتِ فارسی روی هر صفحه)، و از آن بدتر نامِ
  همان سامانهٔ قدیمی را در سورسِ صفحه چاپ می‌کرد — که تستِ نگهبان هم درست
  گیرش انداخت.
--}}
<script>
window.TOOL_I18N = {
  fa: {{ $isFa ? 'true' : 'false' }},
  invalid: @json(__('ui.tl_whois_invalid')), nodata: @json(__('ui.tl_whois_nodata')), generic: @json(__('ui.chat_error')),
  status: @json(__('ui.tl_wk_status')), taken: @json(__('ui.domain_taken')), free: @json(__('ui.domain_free')),
  registrar: @json(__('ui.tl_wk_registrar')), created: @json(__('ui.tl_wk_created')), updated: @json(__('ui.tl_wk_updated')),
  expires: @json(__('ui.tl_wk_expires')), org: @json(__('ui.tl_wk_org')), country: @json(__('ui.tl_ip_country')),
  dnssec: 'DNSSEC', ns: @json(__('ui.tl_wk_ns')), raw: @json(__('ui.tl_wk_raw')),
  register: @json(__('ui.domain_cart')), registerUrl: @json(lroute('domain.search').'?q='),
  similar: @json(__('ui.tl_wk_similar')),
};
</script>
