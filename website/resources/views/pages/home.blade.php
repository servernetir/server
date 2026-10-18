@extends('layouts.site')
@include('partials.atlas-assets')
@include('partials.atlas-seo')
@section('content')
@php
  $loc = app()->getLocale();
  $atlasLead = __('ui.hero_lead');
  $atlasLeadBreak = match($loc) { 'en'=>' for enterprises', 'tr'=>' özel sunucu', default=>' برای سازمان‌ها' };
  $atlasLeadParts = explode($atlasLeadBreak, $atlasLead, 2);
  $rotators = array_values(array_filter([__('ui.hero_rot1'), __('ui.hero_rot2'), __('ui.hero_rot3'), __('ui.hero_rot4'), __('ui.hero_rot5')]));
@endphp
<div class="sn-atlas sn-home">
<section class="hero sn-hero">
  <div class="container">
    <div class="sn-display-top sn-hero-topline" aria-hidden="true"><span>SERVERNET ATLAS</span><span>GLOBAL INFRASTRUCTURE</span></div>
    <div class="sn-hero-grid">
    <div class="sn-hero-copy">
    <div class="sn-hero-heading"><span class="sn-eyebrow"><span></span>{{ __('atlas.eyebrow') }}</span><h1>{{ __('ui.hero_h1a') }} <span class="grad">{{ __('ui.hero_h1b') }}</span></h1></div>
      <p class="lead">@if(count($atlasLeadParts) === 2)<span class="sn-lead-line">{{ $atlasLeadParts[0] }}</span><span class="sn-lead-line">{{ $atlasLeadBreak.$atlasLeadParts[1] }}</span>@else{{ $atlasLead }}@endif</p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="#enterprise">{{ __('ui.hero_cta2') }}</a>
      </div>
      <div class="hero-rotator"><span class="dot"></span><span class="rot-txt" id="hero-rot">{{ $rotators[0] ?? '' }}</span></div>
      <a class="sn-discover" href="#products"><span class="sn-discover-line"></span>{{ __('atlas.scroll') }}<svg class="icon"><use href="#i-arrow"/></svg></a>
    </div>
    @include('partials.atlas-network')
    </div>
  </div>
</section>
<div class="sn-proof-rail">
  <div class="container sn-proof-grid">
    <div><b>{{ fa_num('15') }}<span>+</span></b><span>{{ __('ui.stat_years') }}</span></div>
    <div><b>{{ fa_num('2000') }}<span>+</span></b><span>{{ __('ui.stat_services') }}</span></div>
    <div><b>{{ fa_num('11') }}<span>+</span></b><span>{{ __('ui.stat_dc') }}</span></div>
    <div><b>{{ fa_num('99.9') }}<span>%</span></b><span>{{ __('ui.stat_uptime') }}</span></div>
  </div>
</div>
<section class="sn-domain" aria-labelledby="sn-domain-title">
  <div class="container sn-domain-layout">
    <div class="sn-domain-intro"><span class="sn-eyebrow" dir="ltr">YOUR NEXT ADDRESS</span><h2 id="sn-domain-title">{{ __('ui.domain_title') }}</h2></div>
    <div class="sn-domain-controls">
      <div class="domain-box reveal" style="transition-delay:.32s">
        <p class="domain-title">{{ __('ui.domain_title') }}</p>
        <form class="domain-search" id="domain-form"
              data-endpoint="{{ route($routePrefix.'domain.check') }}"
              {{-- 🔴 مسیرِ بدون‌جاوااسکریپت هم باید در سامانهٔ خودمان تمام شود.
                   تا امروز این فرم به سبدِ WHMCSِ بیرونی می‌رفت، در حالی که کنترلر
                   ماه‌ها بود از WHMCS جدا شده بود — یعنی هر کاربری که جاوااسکریپتش
                   نمی‌آمد (یا پیش از بارِ اسکریپت Enter می‌زد) به سیستمِ قدیمی و
                   قیمتِ دیگری پرتاب می‌شد. --}}
              action="{{ lroute('domain.search') }}" method="get">
          <svg class="icon"><use href="#i-search"/></svg>
          <input name="q" id="domain-input" type="text" placeholder="{{ __('ui.domain_ph') }}" aria-label="Domain" required
                 autocomplete="off" spellcheck="false"
                 data-i18n-checking="{{ __('ui.domain_checking') }}"
                 data-i18n-free="{{ __('ui.domain_free') }}"
                 data-i18n-taken="{{ __('ui.domain_taken') }}"
                 data-i18n-suggest="{{ __('ui.domain_suggest') }}"
                 data-i18n-cart="{{ __('ui.domain_cart') }}"
                 data-i18n-year="{{ __('ui.domain_year') }}"
                 data-i18n-error="{{ __('ui.domain_error') }}"
                 {{-- سه حالتِ «نمی‌دانیم». بی اینها جاوااسکریپت رشتهٔ undefined چاپ می‌کند --}}
                 data-i18n-unchecked="{{ __('ui.dsr_unchecked_note') }}"
                 data-i18n-unsupported="{{ __('ui.dsr_unsupported_note') }}"
                 data-i18n-noprice="{{ __('ui.dsr_no_price_pill') }}">
          <button class="btn btn-primary" type="submit">{{ __('ui.domain_btn') }}</button>
        </form>
        <div class="domain-result" id="domain-result" hidden></div>
        <div class="tld-strip" id="tld-strip">
          @foreach($tlds as $t)
          <button type="button" class="tld-chip" data-tld="{{ $t['tld'] }}"><b>{{ $t['tld'] }}</b><i>{{ site_price($t) }}@if(($t['min_years'] ?? 1) > 1) <small>· {{ __('ui.domain_period_years', ['years' => fa_num((string) $t['min_years'])]) }}</small>@endif</i></button>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>
{{-- ============ MARQUEE ============
     ⚠️ تا وقتی مشتریِ واقعی با اجازهٔ کتبی نداریم، این بلوک **رندر نمی‌شود**.
     نامِ شرکت‌های واقعی زیرِ «مورد اعتماد» یک ادعای قابلِ ابطال است، و بلوکِ
     خالی از بلوکِ دروغ بهتر. توضیحِ کامل کنارِ `brands` در config/servernet.php.
--}}
@if(! empty($brands))
<div class="marquee-wrap reveal">
  <div class="marquee-label">{{ __('ui.logos_label') }}</div>
  <div class="marquee">
    <div class="track">@foreach($brands as $b)<span>{{ $b }}</span>@endforeach</div>
    <div class="track" aria-hidden="true">@foreach($brands as $b)<span>{{ $b }}</span>@endforeach</div>
  </div>
</div>
@endif

@include('partials.seo-hourly-choice')

{{-- ============ PRODUCTS ============ --}}
<section class="section" id="products">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('atlas.service_eyebrow') }}</span>
      <h2>{{ __('atlas.service_title') }}</h2>
      <p>{{ __('atlas.service_sub') }}</p>
    </div>
    <div class="products-grid sn-product-grid">
      @foreach($products as $i => $p)
       @php
         $pHref = isset($p['link']) ? lroute($p['link'][0], $p['link'][1] ?? []) : buy_url($p['pid']);
         if (! \App\Services\Customer\IranSalesVisibility::allowsUrl($pHref)) {
             $pHref = lroute('cloud.index');
         }
       @endphp
      <a class="pcard pcard-link reveal" href="{{ $pHref }}" style="transition-delay:{{ $i * 70 }}ms">
        @include('partials.atlas-product-art', ['type' => $p['icon']])
        <div class="picon"><svg class="icon"><use href="#i-{{ $p['icon'] }}"/></svg></div>
        <h3>{{ lc($p)['t'] }}</h3>
        <p>{{ lc($p)['d'] }}</p>
        <div class="price">{{ __('ui.from') }}<b>{{ site_price($p) }}</b>{{ __('ui.mo') }}</div>
        <span class="buy">{{ __('ui.prod_view') }} <svg class="icon dir"><use href="#i-arrow"/></svg></span>
      </a>
      @endforeach
    </div>
  </div>
</section>

<section class="section sn-ai-section" id="intelligence">
  <div class="container">
    <div class="sn-section-heading"><div><span class="sn-eyebrow" dir="ltr">INTELLIGENCE / ON DEMAND</span><h2>{{ __('atlas.ai_title') }}</h2></div><p>{{ __('atlas.ai_sub') }}</p></div>
    <div class="sn-ai-grid">
      <article class="sn-ai-card sn-gateway-card">
        <div class="sn-card-top"><span>AI GATEWAY</span><svg class="icon"><use href="#i-sparkles"/></svg></div>
        <div class="sn-ai-route" aria-hidden="true"><span class="sn-route-end"><svg class="icon"><use href="#i-code"/></svg>{{ __('atlas.application') }}</span><i class="sn-route-line"></i><span class="sn-route-core">S<span>N</span></span><i class="sn-route-line"></i><span class="sn-model-stack"><b>LLM</b><b>VISION</b><b>AUDIO</b></span></div>
        <h3>{{ __('atlas.ai_gateway') }}</h3><p>{{ __('atlas.ai_desc') }}</p>
        <a class="sn-text-link" href="{{ lroute('ai.home') }}">{{ __('atlas.ai_cta') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
      </article>
      <article class="sn-ai-card sn-gpu-card">
        <div class="sn-card-top"><span>GPU COMPUTE</span><svg class="icon"><use href="#i-cpu"/></svg></div>
        <div class="sn-chip-stage" aria-hidden="true"><div class="sn-chip-lines"></div><div class="sn-chip"><span>SERVERNET</span><b>GPU</b><small>COMPUTE</small></div><span class="sn-chip-caption">Ollama · ComfyUI · Jupyter</span></div>
        <h3>{{ __('atlas.gpu_title') }}</h3><p>{{ __('atlas.gpu_desc') }}</p>
        <a class="sn-text-link" href="{{ lroute('gpu') }}">{{ __('atlas.gpu_cta') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
      </article>
    </div>
  </div>
</section>

{{-- ============ PRICING ============ --}}
<section class="section" id="pricing" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.vps_badge') }}</span>
      <h2>{{ __('ui.vps_title') }}</h2>
      <p>{{ __('ui.vps_sub') }}</p>
    </div>
    <div class="bill-toggle reveal" role="group" aria-label="Billing cycle">
      <button type="button" class="active" data-bill="monthly">{{ __('ui.bill_monthly') }}</button>
      <button type="button" data-bill="yearly">{{ __('ui.bill_yearly') }}<span class="save">{{ __('ui.bill_save', ['percent' => $isFa ? fa_num(config('billing.cycles.yearly.discount_pct')) : config('billing.cycles.yearly.discount_pct')]) }}</span></button>
    </div>
    <div class="plans" id="plans">
      @foreach($plans as $i => $p)
      <article class="plan {{ ($p['popular'] ?? false) ? 'popular' : '' }} reveal" style="transition-delay:{{ $i * 80 }}ms">
        @if($p['popular'] ?? false)<span class="pop-badge">{{ __('ui.popular') }}</span>@endif
        <h3>{{ lc($p) }}</h3>
        <div class="p-price">
          <span class="pr pr-m"><b>{{ site_price($p) }}</b><span>{{ __('ui.mo') }}</span></span>
          <span class="pr pr-y"><s>{{ site_price($p) }}</s><b>{{ site_price_yearly($p) }}</b><span>{{ __('ui.bill_yearly_note') }}</span></span>
        </div>
        <ul>
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $p['cpu'] }}</li>
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $p['ram'] }}</li>
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $p['ssd'] }}</li>
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $p['tb'] }} {{ __('ui.traffic') }}</li>
        </ul>
        {{-- پلن‌های سرورِ مجازیِ صفحهٔ اصلی → فروشگاهِ **کنسولِ خودمان**.
             (قبلاً به WHMCSِ بیرونی می‌رفت؛ فروش و تحویل حالا کاملاً داخلی است.) --}}
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }}"
           href="{{ lroute('account.cloud.store') }}">{{ __('ui.choose') }}</a>
      </article>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ ENTERPRISE ============ --}}
<section class="section" id="enterprise" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.ent_badge') }}</span>
      <h2>{{ __('atlas.enterprise_title') }}</h2>
      <p>{{ __('atlas.enterprise_sub') }}</p>
    </div>
    <div class="bento sn-enterprise-grid">
      @foreach($enterprise as $i => $e)
      @php
        $solHref = null;
        if (!empty($e['hosting'])) $solHref = lroute('hosting', $e['hosting']);
        elseif (isset($e['slug']) && config('solutions.'.$e['slug'])) $solHref = lroute('solution', $e['slug']);
      @endphp
      <{{ $solHref ? 'a' : 'article' }} @if($solHref) href="{{ $solHref }}" @endif class="bcard {{ ($e['wide'] ?? false) ? 'wide' : '' }} {{ $solHref ? 'bcard-link' : '' }} reveal" style="transition-delay:{{ $i * 60 }}ms">
        <div class="glow"></div>
        @include('partials.atlas-service-art', ['icon' => $e['icon']])
        @if($e['tag'] ?? false)<span class="tag">{{ $e['tag'] }}</span>@endif
        <div class="bicon"><svg class="icon"><use href="#i-{{ $e['icon'] }}"/></svg></div>
        <h3>{{ lc($e)['t'] }}</h3>
        <p>{{ lc($e)['d'] }}</p>
        @if($solHref)<span class="bcard-more">{{ __('ui.ent_learn') }}<svg class="icon dir"><use href="#i-arrow"/></svg></span>@endif
      </{{ $solHref ? 'a' : 'article' }}>
      @endforeach
    </div>
    <div style="text-align:center;margin-top:44px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap" class="reveal">
      <a class="btn btn-primary" href="#contact"><span>{{ __('ui.ent_cta') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
      {{-- لینک به هابِ راهکارها: کاربری که کارت‌ها را دید و تصمیم نگرفت، مسیرِ
           بعدی‌اش فهرستِ کاملِ راهکارهاست، نه بن‌بست. --}}
      <a class="btn btn-glass" href="{{ lroute('solutions.index') }}">{{ __('ui.f_s_all') }}</a>
    </div>
  </div>
</section>

{{-- ============ WHY ============ --}}
<section class="section" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.why_badge') }}</span>
      <h2>{{ __('ui.why_title') }}</h2>
    </div>
    <div class="why-grid">
      @foreach($why as $i => $w)
      <div class="witem reveal" style="transition-delay:{{ $i * 50 }}ms">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $w['icon'] }}"/></svg></div>
        <div><h3>{{ lc($w)['t'] }}</h3><p>{{ lc($w)['d'] }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

<section class="section sn-network-section" id="regions">
  <div class="container">
    <div class="sn-section-heading"><div><span class="sn-eyebrow" dir="ltr">REGIONS / CONNECTED POSSIBILITIES</span><h2>{{ __('atlas.network') }}</h2></div><p>{{ __('atlas.network_sub') }}</p></div>
    <div class="sn-region-cards">
      @php
        $atlasTileDefault = match(app()->getLocale()) { 'tr'=>'TR', 'en'=>'US', default=>\App\Services\Customer\IranSalesVisibility::allowsCountry('IR') ? 'IR' : 'DE' };
        $atlasLabels = ['IR'=>'iran','TR'=>'turkey','US'=>'usa','DE'=>'germany','FR'=>'france','NL'=>'netherlands'];
        $atlasTileConfig = app(\App\Services\MenuManager::class)->mega(app(\App\Services\SiteMenu::class)->mega());
        $atlasTileMenu = app(\App\Services\ServerMenu::class)->build($atlasTileConfig['vps'] ?? []);
        $atlasPicker = array_values(array_unique(array_merge([$atlasTileDefault], ['US','TR','DE','FR','NL','IR'])));
        $atlasPicker = array_values(array_filter($atlasPicker, fn ($iso) => \App\Services\Customer\IranSalesVisibility::allowsCountry($iso)));
        $atlasNodes = $atlasTileMenu['cloud'];
        @endphp
        @foreach($atlasPicker as $iso)
        @php $region = $atlasLabels[$iso]; @endphp
      <a href="{{ $atlasNodes[$iso]['url'] ?? lroute('cloud.index') }}" class="sn-region-card"><span class="sn-region-dot" aria-hidden="true"></span><h3>{{ __('atlas.'.$region) }}</h3><p>{{ __('atlas.region_'.$region) }}</p><span class="sn-country-code" aria-hidden="true">{{ $iso }}</span><svg class="icon dir"><use href="#i-arrow"/></svg></a>
      @endforeach
    </div>
    <a class="sn-text-link" href="{{ lroute('cloud.index') }}">{{ __('atlas.all_regions') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
  </div>
</section>
{{-- ============ پلِ متنی به بخشِ محلی ارومیه — فقط فارسی ============
     لینکِ **متنی** (نه منو): ممیزیِ مهاجرت نشان داد صفحاتی که فقط از منو
     لینک دارند ایندکس نمی‌شوند. مقصد، هابِ /urmia است (routes/web.php). --}}
@if($isFa)
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <p class="reveal" style="text-align:center;opacity:.9">
      ریشهٔ سرورنت در ارومیه است — از سال ۱۳۸۸ برای کسب‌وکارهای آذربایجان غربی سایت و نرم‌افزار ساخته‌ایم.
      اگر کسب‌وکارتان در استان است، صفحهٔ <a href="{{ route('urmia.hub') }}"><b>طراحی سایت در ارومیه</b></a> را ببینید.
    </p>
  </div>
</section>
@endif

{{-- ============ FAQ ============ --}}
<section class="section" id="faq" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.faq_badge') }}</span>
      <h2>{{ __('ui.faq_title') }}</h2>
    </div>
    <div class="faq-list reveal">
      @foreach($faqs as $f)
      <details class="faq">
        <summary>{{ lc($f)['q'] }}<svg class="icon"><use href="#i-plus"/></svg></summary>
        <div class="body">{{ lc($f)['a'] }}</div>
      </details>
      @endforeach
    </div>
  </div>
</section>

<section class="sn-home-contact" id="contact">
  <div class="container sn-home-contact-grid">
    <div class="sn-home-contact-copy"><span class="sn-eyebrow">{{ __('atlas.contact_eyebrow') }}</span><h2>{{ __('atlas.contact_title') }}</h2><p>{{ __('atlas.contact_sub') }}</p><a class="btn btn-primary" href="{{ lroute('contact') }}#form">{{ __('atlas.contact_cta') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a><div class="cta-contacts"><a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg><span dir="ltr">{{ $contact['phone'] }}</span></a><a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a><a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></div></div>
    <div class="sn-home-contact-art" aria-hidden="true"><img src="{{ asset_ver('assets/img/atlas-earth.svg') }}" width="680" height="680" loading="lazy" decoding="async" alt=""><span>LET'S BUILD<br><b>WHAT'S NEXT.</b></span></div>
  </div>
</section>
{{-- چرخشِ شعارِ دیتاسنتر — سبک، بی‌کتابخانه (CSP هر منبعِ بیرونی را می‌بندد).
     جمله‌ها از data-rot خوانده می‌شوند که Blade سه‌زبانه پرش کرده. --}}
<script>
(function(){
  'use strict';
  var el = document.getElementById('hero-rot');
  if (!el) { return; }

  // ⚠️ لیست را با Js::from می‌دهیم نه data-attribute: کدگذاریِ دستیِ JSON در
  // یک صفت، با نقل‌قول‌های فارسی و entityها دردسر می‌شود. Js::from خودش امن و
  // درست escape می‌کند.
  var list = {{ Illuminate\Support\Js::from($rotators) }};
  if (!Array.isArray(list) || list.length < 2) { return; }

  // احترام به کاربری که انیمیشن نمی‌خواهد
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

  var i = 0;
  setInterval(function(){
    el.classList.add('out');                 // محو شو
    setTimeout(function(){
      i = (i + 1) % list.length;
      el.textContent = list[i];
      el.classList.remove('out');            // با متنِ تازه ظاهر شو
    }, 450);                                  // برابرِ مدتِ transition در CSS
  }, 3500);
})();
</script>
</div>
@endsection
