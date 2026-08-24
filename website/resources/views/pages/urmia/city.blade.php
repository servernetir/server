@extends('layouts.site')

@section('title', sprintf($ui['city_title'], $city['name']))
@section('description', sprintf($ui['city_desc'], $city['name']))

@section('content')

{{--
  صفحهٔ شهری — /urmia/cities/{slug} (سه‌زبانه از مرداد ۱۴۰۵)
  fa: متنِ معرفیِ یکتای هر شهر از config/urmia.php؛ en/tr: قالبِ مشترکِ
  city_body با نامِ لاتینِ شهر (UrmiaController). فهرستِ خدمات و روالِ
  همکاری از urmia_i18n (city_services / city_flow) می‌آید و %CITY% همین‌جا
  جایگزین می‌شود — جانشینِ صفحات «سرورنت طراحی سایت در …»ی servernet.ir.
--}}

@php
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone_link'] ?? $identity['phone']) : null;
  $others = collect($cities)->except($slug)->take(8);
  $inCity = fn ($s) => str_replace(['%CITY%', '%SINCE%'], [$city['name'], $identity['since']], $s);
@endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $identity['brand'] }} · {{ $identity['province'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $ui['webdesign_in'] }} {{ $city['name'] }}</h1>
      {{-- اول %SINCE%/%CITY% جایگزین شود، بعد sprintf — وگرنه %S برای sprintf خطاست --}}
      <p class="lead reveal" style="transition-delay:.16s">{{ sprintf($inCity($ui['city_lead']), $city['name']) }}</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>{{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ $ui['consult_short'] }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ lroute('urmia.hub') }}">{{ $ui['all_services_s'] }}</a>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:20px">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      <h2>{{ sprintf($ui['city_why_h2'], $city['name']) }}</h2>
      @foreach($city['p'] as $para)
      <p>{{ $para }}</p>
      @endforeach

      <h2>{{ sprintf($ui['city_srv_h2'], $city['name']) }}</h2>
      <ul>
        @foreach($services as $sslug => $s)
        <li><a href="{{ lroute('urmia.page', $sslug) }}">{{ $s['t'] }}</a> — {{ $inCity($s['d']) }}</li>
        @endforeach
      </ul>

      <h2>{{ $ui['city_flow_h2'] }}</h2>
      @foreach($flow as $para)
      <p>{{ $inCity($para) }}</p>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ شهرهای دیگر ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">{{ $identity['province'] }}</span>
      <h2>{{ $ui['other_cities'] }}</h2>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($others as $oslug => $o)
      <a class="btn btn-glass" href="{{ lroute('urmia.city', $oslug) }}">{{ $o['name'] }}</a>
      @endforeach
      <a class="btn btn-glass" href="{{ lroute('urmia.hub') }}">{{ $ui['hub_urmia'] }}</a>
    </div>
  </div>
</section>

{{-- ═══ فراخوان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>{{ sprintf($ui['city_cta_h2'], $city['name']) }}</h2>
      <p>{{ $ui['city_cta_p'] }}</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <span>{{ $ui['start_talk'] }}</span>
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
      'name'        => $identity['brand'].' — '.$ui['webdesign_in'].' '.$city['name'],
      'description' => sprintf($ui['city_ld_desc'], $city['name']),
      'url'         => url()->current(),
      'telephone'   => $identity['phone'] ?: null,
      'areaServed'  => [
          [$T => 'City', 'name' => $city['name']],
          [$T => 'State', 'name' => $identity['province']],
      ],
      'address' => [
          $T                => 'PostalAddress',
          'addressLocality' => $identity['city'],
          'addressRegion'   => $identity['province'],
          'addressCountry'  => 'IR',
      ],
      'parentOrganization' => [$T => 'Organization', 'name' => $identity['company']],
  ]);

  $ldCrumb = [
      'itemListElement' => [
          [$T => 'ListItem', 'position' => 1, 'name' => $ui['crumb_home'], 'item' => url('/')],
          [$T => 'ListItem', 'position' => 2, 'name' => $ui['crumb_hub'], 'item' => lroute('urmia.hub')],
          [$T => 'ListItem', 'position' => 3, 'name' => $ui['webdesign_in'].' '.$city['name'], 'item' => url()->current()],
      ],
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldCrumb, 'BreadcrumbList') !!}</script>

@endsection
