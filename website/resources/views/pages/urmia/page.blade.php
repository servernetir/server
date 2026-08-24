@extends('layouts.site')

@section('title', $page['title'])
@section('description', $page['desc'])

@section('content')

{{--
  صفحهٔ خدمتِ محلی ارومیه — /urmia/{slug} (سه‌زبانه از مرداد ۱۴۰۵)
  کاملاً config-driven: فارسی از config/urmia.php، ترجمه‌ها از urmia_i18n.php
  که UrmiaController به زبانِ جاری overlay کرده — این view زبان نمی‌فهمد.
  ⚠️ هیچ کلاسِ CSS تازه‌ای ندارد: sla-doc / lk-faq / sol-* / btn از site.css.
  ⚠️ padding-top ندارد — #main جبرانِ هدر را سراسری می‌دهد.
--}}

@php
  $md  = fn ($s) => preg_replace('~\*\*(.+?)\*\*~us', '<b>$1</b>', e($s));
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone_link'] ?? $identity['phone']) : null;
  $idBody = str_replace(
      ['%BRAND%', '%COMPANY%', '%CITY%', '%REG%', '%SINCE%'],
      [$identity['brand'], $identity['company'], $identity['city'], $identity['reg_no'], $identity['since']],
      config('urmia_i18n.identity_body.'.app()->getLocale()) ?? config('urmia_i18n.identity_body.fa'));
@endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ sprintf($ui['badge_page'], $identity['brand'], $identity['city'], $identity['since']) }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $page['h1'] }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $page['lead'] }}</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>{{ $ui['call_office'] }} — {{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ $ui['free_consult'] }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ lroute('urmia.hub') }}">{{ $ui['all_services'] }}</a>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:20px">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      @foreach($page['sections'] as $sec)
        <h2>{{ $sec['h'] }}</h2>
        @foreach(($sec['p'] ?? []) as $para)
          <p>{!! $md($para) !!}</p>
        @endforeach
        @if(!empty($sec['list']))
        <ul>
          @foreach($sec['list'] as $li)
          <li>{!! $md($li) !!}</li>
          @endforeach
        </ul>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ پرسش‌های پرتکرار ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">{{ $ui['faq_kicker'] }}</span>
      <h2>{{ $ui['faq_h2'] }}</h2>
    </div>
    <div class="lk-faq reveal">
      @foreach($page['faq'] as $f)
      <details class="lk-faq-item"><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ هویت محلی — جایگزینِ اعتبارِ دامنهٔ .ir ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      <h2>{{ sprintf($ui['identity_h2'], $identity['city']) }}</h2>
      <p>
        {!! $idBody !!}
        @if($identity['address'])
        {{ sprintf($ui['office_line'], $identity['address']) }}
        @endif
        @if($identity['phone'])
        {{ $ui['phone_line'] }} <span dir="ltr">{{ fa_num($identity['phone']) }}</span>.
        @endif
        {{ $ui['first_free'] }}
      </p>
    </div>
  </div>
</section>

{{-- ═══ خدمات مرتبط ═══ --}}
@if(!empty($page['related']))
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">{{ $ui['related_kicker'] }}</span>
      <h2>{{ $ui['related_h2'] }}</h2>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($page['related'] as $rel)
        @if(isset($pages[$rel]))
        <a class="btn btn-glass" href="{{ lroute('urmia.page', $rel) }}">{{ $pages[$rel]['h1'] }}</a>
        @endif
      @endforeach
      <a class="btn btn-glass" href="{{ lroute('urmia.hub') }}">{{ $ui['hub_link'] }}</a>
    </div>
  </div>
</section>
@endif

{{-- ═══ فراخوان پایانی ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>{{ $ui['cta_h2'] }}</h2>
      <p>{{ $ui['cta_p'] }}</p>
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

{{-- ═══ دادهٔ ساختاریافته ═══
  ⚠️ @context را Blade می‌بلعد — فقط از schema_ld(). فیلدهای خالی (تلفن/آدرس)
     عمداً حذف می‌شوند، نه اینکه با جای‌نگهدار پر شوند. --}}
@php
  $T = '@'.'type';

  $ldService = array_filter([
      'name'        => $identity['brand'].' — '.$page['h1'],
      'description' => $page['desc'],
      'url'         => url()->current(),
      'telephone'   => $identity['phone'] ?: null,
      'areaServed'  => [
          [$T => 'City', 'name' => $identity['city']],
          [$T => 'State', 'name' => $identity['province']],
      ],
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

  $ldFaq = [
      'mainEntity' => array_map(fn ($f) => [
          $T => 'Question',
          'name' => $f['q'],
          'acceptedAnswer' => [$T => 'Answer', 'text' => $f['a']],
      ], $page['faq']),
  ];

  $ldCrumb = [
      'itemListElement' => [
          [$T => 'ListItem', 'position' => 1, 'name' => $ui['crumb_home'], 'item' => url('/')],
          [$T => 'ListItem', 'position' => 2, 'name' => $ui['crumb_hub'], 'item' => lroute('urmia.hub')],
          [$T => 'ListItem', 'position' => 3, 'name' => $page['h1'], 'item' => url()->current()],
      ],
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldFaq, 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldCrumb, 'BreadcrumbList') !!}</script>

@endsection
