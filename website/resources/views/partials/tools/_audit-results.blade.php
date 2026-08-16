{{-- ============================================================================
     بدنهٔ گزارشِ بررسیِ سایت — مشترکِ ابزار و صفحهٔ عمومیِ گزارش.

     جاوااسکریپت (`tools.js`) دقیقاً همین شناسه‌ها را پر می‌کند، پس هر دو صفحه
     خروجیِ یکسانی نشان می‌دهند: چیزی که برای مشتری می‌فرستیم همان چیزی است که
     خودمان روی ابزار می‌بینیم.

     $reportMode = true  ⇒ صفحهٔ عمومیِ گزارش (بی‌فرم؛ دکمهٔ «بررسی دوباره» به
                           خودِ ابزار می‌رود، و لینکِ اشتراک معنی ندارد)
     ============================================================================ --}}
@php $reportMode = $reportMode ?? false; @endphp

<section class="section" id="seo-results" style="padding-top:10px;display:{{ $reportMode ? 'block' : 'none' }}">
  <div class="container">
    {{-- کارت امتیاز کل --}}
    <div class="audit-hero">
      <div class="audit-gauge">
        <svg viewBox="0 0 120 120">
          <circle class="ag-bg" cx="60" cy="60" r="52"/>
          <circle class="ag-fg" cx="60" cy="60" r="52" id="ag-ring"/>
        </svg>
        <div class="ag-center"><b id="ag-score">0</b><span id="ag-grade">—</span></div>
      </div>
      <div class="audit-summary">
        <span class="badge" id="au-badge"></span>
        <h2 id="au-host">—</h2>
        <p id="au-title" class="au-title"></p>
        <div class="au-facts" id="au-facts"></div>
        <div class="au-cta">
          <a class="btn btn-primary" href="{{ lroute('catalog', ['category' => 'services', 'slug' => 'security']) }}"><span>{{ __('ui.tl_seo_fix') }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
          <button class="btn btn-glass" type="button" id="seo-print">{{ __('ui.au_print') }}</button>
          @if($reportMode)
            <a class="btn btn-glass" href="{{ lroute('tools', 'seo') }}">{{ __('ui.au_rescan_own') }}</a>
          @else
            <button class="btn btn-glass" type="button" id="seo-rescan">{{ __('ui.tl_seo_again') }}</button>
          @endif
        </div>

        @unless($reportMode)
          {{-- 🔴 لینکِ اشتراک، نه فیلدِ ایمیل.
               اگر این‌جا فیلدِ ایمیل بگذاریم، سرورِ ما به دستورِ هر ناشناسی به
               هر نشانی‌ای ایمیل می‌فرستد — یعنی یک ابزارِ اسپم و فیشینگ با نامِ
               دامنهٔ خودمان. لینک همان کار را می‌کند بی‌آن خطر؛ ارسالِ ایمیل در
               پنلِ مدیریت است. --}}
          <div class="au-share" id="au-share" hidden>
            <label>{{ __('ui.au_share_lbl') }}</label>
            <div class="au-share-row">
              <input type="text" id="au-share-url" readonly dir="ltr" onclick="this.select()">
              <button class="btn btn-glass" type="button" id="au-share-copy">{{ __('ui.au_copy') }}</button>
            </div>
            <small>{{ __('ui.au_share_hint') }}</small>
          </div>
        @endunless
      </div>
    </div>

    {{-- 🔴 برنامهٔ اقدام — قلبِ این بازطراحی.
         گزارشِ قبلی می‌گفت «۱۷ چک قرمز است» و کاربر را با ۱۷ تصمیم تنها
         می‌گذاشت. این بخش می‌گوید **اول کدام** و **دقیقاً چه کار کن**. --}}
    <div class="audit-plan" id="audit-plan" hidden></div>

    {{-- امتیاز دسته‌ها --}}
    <div class="audit-cats" id="audit-cats"></div>

    {{-- Core Web Vitals (اگر PageSpeed فعال باشد) --}}
    <div class="audit-vitals" id="audit-vitals" hidden></div>

    {{-- فیلترِ شدت — روی گزارشی با ۶۱ چک، «فقط خطاها» پرکاربردترین نماست --}}
    <div class="audit-filter" id="audit-filter" hidden>
      <button type="button" class="on" data-f="all"></button>
      <button type="button" data-f="fail"></button>
      <button type="button" data-f="warn"></button>
    </div>

    {{-- جزئیات چک‌ها --}}
    <div class="audit-detail" id="audit-detail"></div>
  </div>
</section>

<div class="tool-error" id="seo-error" hidden></div>
