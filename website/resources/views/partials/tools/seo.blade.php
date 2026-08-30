{{-- ابزار بررسی سئو و سلامت سایت --}}
@php
    /*
     * دادهٔ رابط از `App\Support\AuditViewData` می‌آید، نه از همین‌جا.
     * صفحهٔ عمومیِ گزارش (`/report/{token}`) هم دقیقاً همین را می‌خواند؛ دو
     * نسخهٔ موازی یعنی روزی گزارشِ فرستاده‌شده با گزارشِ روی سایت فرق کند.
     */
    $seoMeta = \App\Support\AuditViewData::meta();
    $catMeta = $seoMeta['cats'];
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

@include('partials.tools._audit-results', ['reportMode' => false])

<script>
window.SEO_META = @json($seoMeta);
</script>
