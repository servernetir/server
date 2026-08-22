@extends('layouts.site')

@section('title', $page['title'])
@section('description', $page['desc'])
@section('faOnly', '1')

@section('content')

{{--
  صفحهٔ خدمتِ محلی ارومیه — /urmia/{slug}
  کاملاً config-driven (config/urmia.php). فقط فارسی — faOnly در بالا،
  hreflangِ en/tr را خاموش می‌کند (layout).
  ⚠️ هیچ کلاسِ CSS تازه‌ای ندارد: sla-doc / lk-faq / sol-* / btn از site.css.
  ⚠️ padding-top ندارد — #main جبرانِ هدر را سراسری می‌دهد.
--}}

@php
  $md  = fn ($s) => preg_replace('~\*\*(.+?)\*\*~us', '<b>$1</b>', e($s));
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone']) : null;
@endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $identity['brand'] }} · {{ $identity['city'] }} · از سال {{ $identity['since'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $page['h1'] }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $page['lead'] }}</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>تماس با دفتر ارومیه — {{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>درخواست مشاوره رایگان</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ route('urmia.hub') }}">همه خدمات ما در ارومیه</a>
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
      <span class="kicker">پرسش‌های پرتکرار</span>
      <h2>سؤال‌هایی که پیش از شروع می‌پرسند</h2>
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
      <h2>یک شرکت واقعی در {{ $identity['city'] }}</h2>
      <p>
        «{{ $identity['brand'] }}» برند تجاری شرکت <b>{{ $identity['company'] }}</b> است؛
        ثبت‌شده در {{ $identity['city'] }} به شمارهٔ ثبت {{ $identity['reg_no'] }}، فعال از سال {{ $identity['since'] }}
        در حوزهٔ طراحی سایت، نرم‌افزار و زیرساخت میزبانی.
        @if($identity['address'])
        دفتر ما: {{ $identity['address'] }}.
        @endif
        @if($identity['phone'])
        تلفن ثابت دفتر: <span dir="ltr">{{ fa_num($identity['phone']) }}</span>.
        @endif
        جلسهٔ اول شناخت، حضوری و رایگان است.
      </p>
    </div>
  </div>
</section>

{{-- ═══ خدمات مرتبط ═══ --}}
@if(!empty($page['related']))
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">مرتبط</span>
      <h2>خدمات مرتبط در ارومیه</h2>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($page['related'] as $rel)
        @if(isset($pages[$rel]))
        <a class="btn btn-glass" href="{{ route('urmia.page', $rel) }}">{{ $pages[$rel]['h1'] }}</a>
        @endif
      @endforeach
      <a class="btn btn-glass" href="{{ route('urmia.hub') }}">هاب ارومیه</a>
    </div>
  </div>
</section>
@endif

{{-- ═══ فراخوان پایانی ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>پروژه‌تان را با یک جلسهٔ بی‌تعهد شروع کنید</h2>
      <p>در یک جلسهٔ حضوری در ارومیه — یا تماس تصویری — نیازتان را می‌شنویم و پیشنهاد شفاف فنی و مالی می‌دهیم.</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <span>درخواست جلسه</span>
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
          [$T => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => url('/')],
          [$T => 'ListItem', 'position' => 2, 'name' => 'خدمات ارومیه', 'item' => route('urmia.hub')],
          [$T => 'ListItem', 'position' => 3, 'name' => $page['h1'], 'item' => url()->current()],
      ],
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldFaq, 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldCrumb, 'BreadcrumbList') !!}</script>

@endsection
