@extends('layouts.site')

@section('title', lc($product)['t'].' — '.__('ui.brand'))
@section('description', lc($product)['hero_d'])

@section('content')
@php
    $loc = app()->getLocale();
    $category = $category ?? 'hosting';
    $yearlyOnly = ($product['billing'] ?? null) === 'yearly';
    $priceUnit = $yearlyOnly ? (isset($product['unit']) ? lc($product['unit']) : __('ui.domain_year')) : __('ui.mo');
@endphp

{{-- ============ HERO ============ --}}
<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ lc($product)['tag'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ lc($product)['hero_t'] }} <span class="grad">{{ lc($product)['hero_g'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ lc($product)['hero_d'] }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-phone"/></svg>{{ __('ui.hp_consult') }}</a>
      </div>
      <div class="chip-row reveal" style="transition-delay:.32s">
        @foreach($product['chips'] as $chip)
        <span class="tech-chip"><svg class="icon"><use href="#i-check"/></svg>{{ $chip }}</span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ============ AI SITE BUILDER (المان اختصاصی صفحه سایت‌ساز) ============ --}}
@if(($product['signature']['type'] ?? '') === 'ai-builder')
  @include('partials.sig-ai-builder', ['product' => $product])
@endif

{{-- ============ PLANS ============ --}}
<section class="section" id="pricing" style="padding-top:30px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_plans_badge') }}</span>
      <h2>{{ __('ui.hp_plans_title') }}</h2>
      <p>{{ __('ui.hp_plans_sub') }}</p>
    </div>
    @unless($yearlyOnly)
    <div class="bill-toggle reveal" role="group" aria-label="Billing cycle">
      <button type="button" class="active" data-bill="monthly">{{ __('ui.bill_monthly') }}</button>
      <button type="button" data-bill="yearly">{{ __('ui.bill_yearly') }}<span class="save">{{ __('ui.bill_save', ['percent' => $isFa ? fa_num(config('servernet.yearly_discount')) : config('servernet.yearly_discount')]) }}</span></button>
    </div>
    @endunless
    <div class="plans {{ count($product['plans']) === 3 ? 'plans-3' : '' }} {{ count($product['plans']) >= 5 ? 'plans-many' : '' }}" id="plans">
      @foreach($product['plans'] as $i => $p)
      @php $isContact = $p['contact'] ?? false; @endphp
      <article class="plan {{ ($p['popular'] ?? false) ? 'popular' : '' }} reveal" style="transition-delay:{{ $i * 80 }}ms">
        @if($p['popular'] ?? false)<span class="pop-badge">{{ __('ui.popular') }}</span>@endif
        <h3>{{ $p['name'] }}</h3>
        <div class="p-price">
          @if($isContact)
          <span class="pr"><b style="font-size:23px">{{ __('ui.hp_contact_price') }}</b></span>
          @elseif($yearlyOnly)
          <span class="pr"><b>{{ site_price($p) }}</b><span>{{ $priceUnit }}</span></span>
          @else
          <span class="pr pr-m"><b>{{ site_price($p) }}</b><span>{{ __('ui.mo') }}</span></span>
          <span class="pr pr-y"><s>{{ site_price($p) }}</s><b>{{ site_price_yearly($p) }}</b><span>{{ __('ui.bill_yearly_note') }}</span></span>
          @endif
        </div>
        <ul>
          @foreach($p['specs'] as $j => $spec)
          <li @if($j === 0) class="hl" @endif><svg class="icon"><use href="#i-check"/></svg>@if(is_array($spec)){{ lc($spec) }}@else<span dir="ltr">{{ $spec }}</span>@endif</li>
          @endforeach
        </ul>
        @if($isContact)
        <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon" style="width:15px;height:15px"><use href="#i-phone"/></svg>{{ __('ui.hp_consult') }}</a>
        @elseif($yearlyOnly)
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }}"
           href="{{ isset($p['url']) ? whmcs_url($p['url']) : buy_url($p['pid']) }}"
           target="_blank" rel="noopener">{{ __('ui.choose') }}</a>
        @else
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }} plan-buy"
           href="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-m="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-y="{{ buy_url($p['pid']) }}&billingcycle=annually"
           target="_blank" rel="noopener">{{ __('ui.choose') }}</a>
        @endif
      </article>
      @endforeach
    </div>
    <div class="inc-strip reveal">
      <b>{{ __('ui.hp_inc_title') }}</b>
      <div class="inc-items">
        @foreach(['hp_inc1', 'hp_inc2', 'hp_inc3', 'hp_inc4', 'hp_inc5', 'hp_inc6'] as $inc)
        <span><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.'.$inc) }}</span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ============ FEATURES ============ --}}
<section class="section" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_feat_badge') }}</span>
      <h2>{{ __('ui.hp_feat_title', ['name' => lc($product)['t']]) }}</h2>
    </div>
    <div class="why-grid">
      @foreach($features as $i => $f)
      <div class="witem reveal" style="transition-delay:{{ $i * 50 }}ms">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $f['icon'] }}"/></svg></div>
        <div><h4>{{ lc($f)['t'] }}</h4><p>{{ lc($f)['d'] }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ BAND (پیشنهاد مرتبط، مثل ایمیل تراکنشی) ============ --}}
@isset($product['band'])
@php $band = $product['band']; $bl = lc($band); @endphp
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="hp-band reveal">
      <div class="hp-band-glow"></div>
      <div class="hp-band-main">
        <span class="hp-band-ic"><svg class="icon"><use href="#i-{{ $band['icon'] }}"/></svg></span>
        <div>
          @if(!empty($band['badge_key']))<span class="hp-band-badge">{{ $band['badge_key'] }}</span>@endif
          <h3>{{ $bl['t'] }}</h3>
          <p>{{ $bl['d'] }}</p>
        </div>
      </div>
      <ul class="hp-band-points">
        @foreach($bl['points'] as $pt)<li><svg class="icon"><use href="#i-check"/></svg>{{ $pt }}</li>@endforeach
      </ul>
      <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ $bl['cta'] }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
    </div>
  </div>
</section>
@endisset

{{-- ============ SIGNATURE (المان اختصاصی هر محصول) ============ --}}
@isset($product['signature'])
@if($product['signature']['type'] !== 'ai-builder')
<section class="section" style="padding-top:0">
  <div class="container">
    @includeIf('partials.sig-'.$product['signature']['type'], ['sig' => $product['signature']])
  </div>
</section>
@endif
@endisset

{{-- ============ INFRASTRUCTURE ============ --}}
<section class="section" style="padding-top:30px;padding-bottom:40px">
  <div class="container">
    <div class="infra-panel reveal">
      <div class="infra-head">
        <span class="badge">{{ __('ui.hp_infra_badge') }}</span>
        <h2>{{ __('ui.hp_infra_title') }}</h2>
        <p>{{ __('ui.hp_infra_sub') }}</p>
      </div>
      <div class="infra-stats">
        <div class="istat"><b>Tier III+</b><span>{{ __('ui.hp_infra1') }}</span></div>
        <div class="istat"><b>NVMe RAID-10</b><span>{{ __('ui.hp_infra2') }}</span></div>
        <div class="istat"><b>{{ $isFa ? fa_num('99.9') : '99.9' }}%</b><span>{{ __('ui.hp_infra3') }}</span></div>
        <div class="istat"><b>Anti-DDoS</b><span>{{ __('ui.hp_infra4') }}</span></div>
      </div>
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

{{-- ============ RELATED ============ --}}
<section class="section" style="padding-top:0;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:36px">
      <h2 style="font-size:27px">{{ __('ui.hp_related_title') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach($related as $rSlug => $r)
      <a class="loc" href="{{ $category === 'hosting' ? lroute('hosting', $rSlug) : lroute('catalog', ['category' => $category, 'slug' => $rSlug]) }}"><svg class="icon"><use href="#i-{{ $r['icon'] }}"/></svg>{{ lc($r)['t'] }}</a>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ CTA + CONTACT ============ --}}
<section class="cta-wrap reveal" id="contact">
  <div class="cta">
    <h2>{{ __('ui.cta_title') }}</h2>
    <p>{{ __('ui.cta_sub') }}</p>
    <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
    <div class="cta-contacts">
      <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
      <a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
      <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg>WhatsApp</a>
    </div>
  </div>
</section>
@endsection
