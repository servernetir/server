{{-- ابزار بررسی سئو و سلامت سایت --}}
@php
    $cats = config('tools.categories');
    $audience = config('tools.audience', []);
    $checkMeta = collect(config('tools.checks'))->map(fn ($m) => lc($m))->all();
    $catMeta = collect($cats)->map(fn ($c, $k) => [
        'icon' => $c['icon'],
        't'    => lc($c),
        'who'  => isset($audience[$k]) ? lc($audience[$k]) : null,
    ])->all();

    /*
     * راهکارها **این‌جا** به صفحه می‌آیند، نه در پاسخِ API.
     *
     * متنِ ثابتی است که به نتیجهٔ بررسی ربط ندارد؛ فرستادنش در هر پاسخِ audit
     * یعنی چند ده کیلوبایت تکراری روی هر درخواست. یک بار با صفحه می‌آید و
     * مرورگر کشش می‌کند.
     */
    $fixFile = resource_path('content/audit-fixes.php');
    $allFixes = is_file($fixFile) ? (array) require $fixFile : [];
    $loc = app()->getLocale();
    $fixes = [];
    foreach ($allFixes as $key => $byLocale) {
        $entry = $byLocale[$loc] ?? $byLocale['fa'] ?? null;
        if ($entry && ! empty($entry['fix'])) {
            $fixes[$key] = array_filter([
                'fix'  => $entry['fix'],
                'code' => $entry['code'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }
    }

    $seoMeta = [
        'cats'   => $catMeta,
        'checks' => $checkMeta,
        'fixes'  => $fixes,
        'fa'     => $isFa,
        // مرحله‌های تخمینیِ نوارِ پیشرفت — بررسیِ هفت‌بُعدی چند ثانیه طول می‌کشد
        'stages' => [
            __('ui.au_s1'), __('ui.au_s2'), __('ui.au_s3'), __('ui.au_s4'), __('ui.au_s5'),
        ],
        'i18n'   => [
            'pass' => __('ui.tl_pass'), 'warn' => __('ui.tl_warn'), 'fail' => __('ui.tl_fail'),
            'weight' => __('ui.tl_weight'), 'errUnreachable' => __('ui.tl_err_unreach'),
            'errInvalid' => __('ui.tl_err_invalid'), 'errGeneric' => __('ui.chat_error'),
            'passes' => __('ui.tl_passes'), 'warns' => __('ui.tl_warns'), 'fails' => __('ui.tl_fails'),
            'ip' => __('ui.tl_f_ip'), 'size' => __('ui.tl_f_size'), 'load' => __('ui.tl_f_load'),
            'server' => __('ui.tl_f_server'), 'code' => __('ui.tl_f_code'), 'vitals' => __('ui.tl_vitals'),
            'planTitle' => __('ui.au_plan_t'), 'planLead' => __('ui.au_plan_d'),
            'planNone' => __('ui.au_plan_none'), 'howFix' => __('ui.au_howfix'),
            'copy' => __('ui.au_copy'), 'copied' => __('ui.au_copied'),
            'fAll' => __('ui.au_f_all'), 'fFail' => __('ui.au_f_fail'), 'fWarn' => __('ui.au_f_warn'),
            'impact' => __('ui.au_impact'), 'print' => __('ui.au_print'),
            'who' => __('ui.au_who'), 'jump' => __('ui.au_jump'),
        ],
    ];
@endphp

<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:820px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_seo_h1a') }} <span class="grad">{{ __('ui.tl_seo_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_seo_lead') }}</p>
      <form class="tool-search reveal" id="seo-form" style="transition-delay:.24s"
            data-endpoint="{{ route($routePrefix.'api.audit') }}">
        <svg class="icon"><use href="#i-globe"/></svg>
        <input type="text" id="seo-input" placeholder="{{ __('ui.tl_seo_ph') }}" autocomplete="off" spellcheck="false" required dir="ltr">
        <button class="btn btn-primary" type="submit"><span class="tsb-label">{{ __('ui.tl_seo_btn') }}</span><span class="dr-spin" hidden></span></button>
      </form>

      <div class="aud-stage" id="seo-stage" hidden></div>

      {{-- هفت بُعد + مخاطبِ هرکدام. کسی که وارد می‌شود باید در ۲ ثانیه بفهمد
           این ابزار چیزی برای **او** دارد، نه فقط برای سئوکار. --}}
      <div class="aud-dims reveal" style="transition-delay:.3s">
        @foreach($catMeta as $c)
        <span class="aud-dim">
          <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>
          <b>{{ $c['t'] }}</b>
          @if($c['who'])<small>{{ $c['who'] }}</small>@endif
        </span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- نتیجه --}}
<section class="section" id="seo-results" style="padding-top:10px;display:none">
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
          <button class="btn btn-glass" type="button" id="seo-rescan">{{ __('ui.tl_seo_again') }}</button>
        </div>
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

<script>
window.SEO_META = @json($seoMeta);
</script>
