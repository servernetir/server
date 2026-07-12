@extends('layouts.site')

@section('content')
@php $loc = app()->getLocale(); @endphp

{{-- ============ HERO ============ --}}
<section class="hero">
  <canvas id="net" aria-hidden="true"></canvas>
  <div class="container">
    <div>
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.hero_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.hero_h1a') }}<br><span class="grad">{{ __('ui.hero_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.hero_lead') }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="#enterprise">{{ __('ui.hero_cta2') }}</a>
      </div>
      <div class="domain-box reveal" style="transition-delay:.32s">
        <p class="domain-title">{{ __('ui.domain_title') }}</p>
        <form class="domain-search" id="domain-form"
              data-endpoint="{{ route($routePrefix.'domain.check') }}"
              action="{{ whmcs_url('cart.php') }}" method="get" target="_blank" rel="noopener">
          <input type="hidden" name="a" value="add">
          <input type="hidden" name="domain" value="register">
          <svg class="icon"><use href="#i-search"/></svg>
          <input name="query" id="domain-input" type="text" placeholder="{{ __('ui.domain_ph') }}" aria-label="Domain" required
                 autocomplete="off" spellcheck="false"
                 data-i18n-checking="{{ __('ui.domain_checking') }}"
                 data-i18n-free="{{ __('ui.domain_free') }}"
                 data-i18n-taken="{{ __('ui.domain_taken') }}"
                 data-i18n-suggest="{{ __('ui.domain_suggest') }}"
                 data-i18n-cart="{{ __('ui.domain_cart') }}"
                 data-i18n-year="{{ __('ui.domain_year') }}"
                 data-i18n-error="{{ __('ui.domain_error') }}">
          <button class="btn btn-primary" type="submit">{{ __('ui.domain_btn') }}</button>
        </form>
        <div class="domain-result" id="domain-result" hidden></div>
        <div class="tld-strip" id="tld-strip">
          @foreach($tlds as $t)
          <button type="button" class="tld-chip" data-tld="{{ $t['tld'] }}"><b>{{ $t['tld'] }}</b><i>{{ $t['display'] ?? site_price($t) }}</i></button>
          @endforeach
        </div>
      </div>
      <div class="hero-stats reveal" style="transition-delay:.4s">
        <div class="stat"><b class="count" data-to="15" data-suffix="+">0</b><span>{{ __('ui.stat_years') }}</span></div>
        <div class="stat"><b class="count" data-to="2000" data-suffix="+">0</b><span>{{ __('ui.stat_services') }}</span></div>
        <div class="stat"><b class="count" data-to="11" data-suffix="+">0</b><span>{{ __('ui.stat_dc') }}</span></div>
        <div class="stat"><b class="count" data-to="99.9" data-suffix="%" data-dec="1">0</b><span>{{ __('ui.stat_uptime') }}</span></div>
      </div>
    </div>
    <div class="terminal reveal" style="transition-delay:.2s" aria-hidden="true">
      <div class="bar"><i class="r"></i><i class="y"></i><i class="g"></i><span>root@servernet ~ </span></div>
      <div class="body" id="term-body"></div>
      <div class="term-status">
        <div><b>● ONLINE</b>Tehran · IR</div>
        <div><b>● ONLINE</b>Frankfurt · DE</div>
        <div><b>● ONLINE</b>Amsterdam · NL</div>
      </div>
    </div>
  </div>
</section>

{{-- ============ MARQUEE ============ --}}
<div class="marquee-wrap reveal">
  <div class="marquee-label">{{ __('ui.logos_label') }}</div>
  <div class="marquee">
    <div class="track">@foreach($brands as $b)<span>{{ $b }}</span>@endforeach</div>
    <div class="track" aria-hidden="true">@foreach($brands as $b)<span>{{ $b }}</span>@endforeach</div>
  </div>
</div>

{{-- ============ PRODUCTS ============ --}}
<section class="section" id="products">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.prod_badge') }}</span>
      <h2>{{ __('ui.prod_title') }}</h2>
      <p>{{ __('ui.prod_sub') }}</p>
    </div>
    <div class="products-grid">
      @foreach($products as $i => $p)
      <article class="pcard reveal" style="transition-delay:{{ $i * 70 }}ms">
        <div class="picon"><svg class="icon"><use href="#i-{{ $p['icon'] }}"/></svg></div>
        <h3>{{ lc($p)['t'] }}</h3>
        <p>{{ lc($p)['d'] }}</p>
        <div class="price">{{ __('ui.from') }}<b>{{ site_price($p) }}</b>{{ __('ui.mo') }}</div>
        <a class="buy" href="{{ buy_url($p['pid']) }}" target="_blank" rel="noopener">{{ __('ui.order') }} <svg class="icon dir"><use href="#i-arrow"/></svg></a>
      </article>
      @endforeach
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
      <button type="button" data-bill="yearly">{{ __('ui.bill_yearly') }}<span class="save">{{ __('ui.bill_save', ['percent' => $isFa ? fa_num(config('servernet.yearly_discount')) : config('servernet.yearly_discount')]) }}</span></button>
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
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }} plan-buy"
           href="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-m="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-y="{{ buy_url($p['pid']) }}&billingcycle=annually"
           target="_blank" rel="noopener">{{ __('ui.choose') }}</a>
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
      <h2>{{ __('ui.ent_title') }}</h2>
      <p>{{ __('ui.ent_sub') }}</p>
    </div>
    <div class="bento">
      @foreach($enterprise as $i => $e)
      <article class="bcard {{ ($e['wide'] ?? false) ? 'wide' : '' }} reveal" style="transition-delay:{{ $i * 60 }}ms">
        <div class="glow"></div>
        @if($e['tag'] ?? false)<span class="tag">{{ $e['tag'] }}</span>@endif
        <div class="bicon"><svg class="icon"><use href="#i-{{ $e['icon'] }}"/></svg></div>
        <h3>{{ lc($e)['t'] }}</h3>
        <p>{{ lc($e)['d'] }}</p>
      </article>
      @endforeach
    </div>
    <div style="text-align:center;margin-top:44px" class="reveal">
      <a class="btn btn-primary" href="#contact"><span>{{ __('ui.ent_cta') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
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
        <div><h4>{{ lc($w)['t'] }}</h4><p>{{ lc($w)['d'] }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ LOCATIONS ============ --}}
<section class="section" style="padding-top:0;padding-bottom:60px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:36px">
      <h2 style="font-size:27px">{{ __('ui.loc_title') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach($locations as $l)
      <span class="loc"><svg class="icon"><use href="#i-pin"/></svg>{{ lc($l) }}</span>
      @endforeach
    </div>
  </div>
</section>

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

{{-- ============ CTA + CONTACT ============ --}}
<section class="cta-wrap reveal" id="contact">
  <div class="cta">
    <h2>{{ __('ui.cta_title') }}</h2>
    <p>{{ __('ui.cta_sub') }}</p>
    <a class="btn btn-primary" href="{{ whmcs_url() }}"><span>{{ __('ui.cta_btn') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
    <div class="cta-contacts">
      <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
      <a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
      <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg>WhatsApp</a>
    </div>
  </div>
</section>
@endsection
