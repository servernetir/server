@extends('layouts.site')

{{-- 🔴 ممیزی نهم: نسخهٔ en/tr این بخش با ۴۱۰ برداشته شد، پس این صفحه
     نباید alternate به آن‌ها بدهد — hreflangِ زنده به سمتِ ۴۱۰ یک حلقهٔ
     خطای خزش می‌سازد. `faOnly` دقیقاً برای همین ساخته شده بود. --}}
@section('faOnly', true)

@section('title', $hub['title'])
@section('description', $hub['desc'])

@section('content')

{{--
  هابِ بخشِ محلی ارومیه — /urmia (سه‌زبانه از مرداد ۱۴۰۵)
  مقصدِ اصلیِ ۳۰۱های «طراحی سایت در ارومیه»ی servernet.ir و نقطهٔ لینکِ
  داخلی به همهٔ زیرصفحات. متن‌ها از UrmiaController (config/urmia_i18n.php)
  به زبانِ جاری حل شده‌اند؛ %BRAND%/… را همین‌جا جایگزین می‌کنیم.
--}}

@php
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone_link'] ?? $identity['phone']) : null;
  $fill = fn ($s) => str_replace(
      ['%BRAND%', '%COMPANY%', '%CITY%', '%REG%', '%SINCE%'],
      [$identity['brand'], $identity['company'], $identity['city'], $identity['reg_no'], $identity['since']],
      $s);

  // ترتیبِ نمایش: پرتقاضاترین خدمات اول
  $order = ['web-design', 'ecommerce-website', 'corporate-website', 'web-design-price',
            'seo', 'app-development', 'software-company', 'office-automation',
            'erp', 'support', 'portfolio'];
  $icons = ['web-design' => 'i-globe', 'ecommerce-website' => 'i-box', 'corporate-website' => 'i-factory',
            'web-design-price' => 'i-coins', 'seo' => 'i-trend', 'app-development' => 'i-smartphone',
            'software-company' => 'i-code', 'office-automation' => 'i-file', 'erp' => 'i-flow',
            'support' => 'i-shield', 'portfolio' => 'i-sparkles'];
@endphp

<section class="hero hero-sub sol-hero sol-cyan">
  <div class="sol-hero-glow"></div>
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ sprintf($ui['badge_hub'], $identity['city'], $identity['reg_no'], $identity['since']) }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $hub['h1'] }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $fill($hub['lead']) }}</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>{{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ $ui['consult_short'] }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ lroute('urmia.page', 'portfolio') }}">{{ $ui['portfolio_btn'] }}</a>
      </div>
    </div>
  </div>
</section>

{{-- ═══ خدمات ═══ --}}
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $ui['services_kicker'] }}</span>
      <h2>{{ $ui['services_h2'] }}</h2>
      <p>{{ $ui['services_p'] }}</p>
    </div>
    <div class="sol-feat-grid cols-4">
      @foreach($order as $slug)
        @if(isset($pages[$slug]))
        <a class="sol-feat reveal" href="{{ lroute('urmia.page', $slug) }}" style="text-decoration:none;color:inherit">
          <span class="sol-feat-ic"><svg class="icon"><use href="#{{ $icons[$slug] ?? 'i-check' }}"/></svg></span>
          <h3>{{ $pages[$slug]['h1'] }}</h3>
          <p>{{ \Illuminate\Support\Str::limit($pages[$slug]['lead'], 110) }}</p>
        </a>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ تمایز: زیرساخت خودمان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      <h2>{{ $hub['infra_h2'] }}</h2>
      <p>{!! $fill($hub['infra_p1']) !!}</p>
      <p>{!! $fill($hub['infra_p2']) !!}</p>
      <h2>{{ $hub['years_h2'] }}</h2>
      <p>{!! $fill($hub['years_p']) !!}
      @if($identity['address'])
      {{ sprintf($ui['office_line'], $identity['address']) }}
      @endif
      </p>
    </div>
  </div>
</section>

{{-- ═══ شهرستان‌ها ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">{{ $ui['cities_kicker'] }}</span>
      <h2>{{ $ui['cities_h2'] }}</h2>
      <p>{{ $ui['cities_p'] }}</p>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($cities as $slug => $c)
      <a class="btn btn-glass" href="{{ lroute('urmia.city', $slug) }}">{{ $ui['webdesign_in'] }} {{ $c['name'] }}</a>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ فراخوان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>{{ $hub['cta_h2'] }}</h2>
      <p>{{ $hub['cta_p'] }}</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <span>{{ $ui['cta_btn'] }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
        </a>
        @if($tel)
        <a class="btn btn-glass" href="{{ $tel }}">{{ fa_num($identity['phone']) }}</a>
        @endif
      </div>
    </div>
  </div>
</section>

@php
  $T = '@'.'type';

  $ldService = array_filter([
      'name'        => $identity['brand'].' — '.$hub['ld_name'],
      'description' => $hub['ld_desc'],
      'url'         => url()->current(),
      'telephone'   => $identity['phone'] ?: null,
      'foundingDate' => '2009',
      'areaServed'  => array_merge(
          [[$T => 'City', 'name' => $identity['city']], [$T => 'State', 'name' => $identity['province']]],
          array_map(fn ($c) => [$T => 'City', 'name' => $c['name']], array_values($cities))
      ),
      'address' => array_filter([
          $T                => 'PostalAddress',
          'addressLocality' => $identity['city'],
          'addressRegion'   => $identity['province'],
          'addressCountry'  => 'IR',
          'streetAddress'   => $identity['address'] ?: null,
      ]),
      'geo' => [$T => 'GeoCoordinates', 'latitude' => $identity['geo']['lat'], 'longitude' => $identity['geo']['lng']],
      'parentOrganization' => [$T => 'Organization', 'name' => $identity['company']],
  ]);
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>

@endsection
