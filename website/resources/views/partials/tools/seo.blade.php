{{-- ابزار بررسی سئو و سلامت سایت --}}
@php
    $cats = config('tools.categories');
    $checkMeta = collect(config('tools.checks'))->map(fn ($m) => lc($m))->all();
    $catMeta = collect($cats)->map(fn ($c) => ['icon' => $c['icon']] + ['t' => lc($c)])->all();
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
      <div class="tool-hint reveal" style="transition-delay:.3s">
        @foreach($catMeta as $c)
        <span><svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>{{ $c['t'] }}</span>
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
          <button class="btn btn-glass" type="button" id="seo-rescan">{{ __('ui.tl_seo_again') }}</button>
        </div>
      </div>
    </div>

    {{-- امتیاز دسته‌ها --}}
    <div class="audit-cats" id="audit-cats"></div>

    {{-- Core Web Vitals (اگر PageSpeed فعال باشد) --}}
    <div class="audit-vitals" id="audit-vitals" hidden></div>

    {{-- جزئیات چک‌ها --}}
    <div class="audit-detail" id="audit-detail"></div>
  </div>
</section>

<div class="tool-error" id="seo-error" hidden></div>

<script>
window.SEO_META = {
  cats: @json($catMeta),
  checks: @json($checkMeta),
  i18n: {
    pass: @json(__('ui.tl_pass')), warn: @json(__('ui.tl_warn')), fail: @json(__('ui.tl_fail')),
    weight: @json(__('ui.tl_weight')), errUnreachable: @json(__('ui.tl_err_unreach')),
    errInvalid: @json(__('ui.tl_err_invalid')), errGeneric: @json(__('ui.chat_error')),
    passes: @json(__('ui.tl_passes')), warns: @json(__('ui.tl_warns')), fails: @json(__('ui.tl_fails')),
    ip: @json(__('ui.tl_f_ip')), size: @json(__('ui.tl_f_size')), load: @json(__('ui.tl_f_load')),
    server: @json(__('ui.tl_f_server')), code: @json(__('ui.tl_f_code')),
    vitals: @json(__('ui.tl_vitals')),
  },
  fa: {{ $isFa ? 'true' : 'false' }},
};
</script>
