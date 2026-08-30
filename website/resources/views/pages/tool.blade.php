@extends('layouts.site')

@php
    $meta = [
        'seo'         => ['t' => __('ui.tl_seo_t'),   'd' => __('ui.tl_seo_d')],
        'whois'       => ['t' => __('ui.tl_whois_t'), 'd' => __('ui.tl_whois_d')],
        'ip'          => ['t' => __('ui.tl_ip_t'),    'd' => __('ui.tl_ip_d')],
        'meet'        => ['t' => __('ui.tl_meet_t'),  'd' => __('ui.tl_meet_d')],
        'app-builder' => ['t' => __('ui.tl_app_t'),   'd' => __('ui.tl_app_d')],
        'domain-ideas' => ['t' => __('ui.tl_ideas_t'), 'd' => __('ui.tl_ideas_d')],
        'speedtest'   => ['t' => __('ui.tl_spt_t'),   'd' => __('ui.tl_spt_d')],
    ][$slug];
    $seo = $seo ?? ['intro' => '', 'steps' => [], 'faq' => []];
    $toolUrl = url()->current();
@endphp

@section('title', $meta['t'].' — '.__('ui.brand'))
@section('description', $meta['d'])

@section('content')
@php $loc = app()->getLocale(); @endphp

@include('partials.tools.'.$slug)

{{-- ============ محتوای صفحه — هم برای کاربر، هم برای موتورهای جستجو ============
     تا امروز این صفحات جز خودِ ویجت هیچ متنی نداشتند؛ یعنی برای گوگل صفحهٔ
     «نازک» بودند و برای کاربری که جواب را نمی‌فهمید هیچ توضیحی نبود. --}}
@if($seo['intro'] || count($seo['steps']) || count($seo['faq']))
<section class="section tl-content" style="padding-top:0">
  <div class="container">
    <div class="tool-result-wrap">

      @if($seo['intro'])
      <div class="tl-sec reveal">
        <h2>{{ __('ui.tl_about', ['tool' => $meta['t']]) }}</h2>
        <p>{{ $seo['intro'] }}</p>
      </div>
      @endif

      @if(count($seo['steps']))
      <div class="tl-sec reveal">
        <h2>{{ __('ui.tl_howto', ['tool' => $meta['t']]) }}</h2>
        <ol class="tl-steps">
          @foreach($seo['steps'] as $step)
          <li><span>{{ fa_num($loop->iteration) }}</span><p>{{ $step }}</p></li>
          @endforeach
        </ol>
      </div>
      @endif

      @if(count($seo['faq']))
      <div class="tl-sec reveal">
        <h2>{{ __('ui.tl_faq') }}</h2>
        <div class="tl-faq">
          @foreach($seo['faq'] as $item)
          <details>
            <summary>{{ $item['q'] }}<svg class="icon"><use href="#i-chev"/></svg></summary>
            <p>{{ $item['a'] }}</p>
          </details>
          @endforeach
        </div>
      </div>
      @endif

    </div>
  </div>
</section>
@endif

{{-- ============ CROSS-SELL: سایر ابزارها ============ --}}
<section class="section" style="padding-top:20px;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:34px">
      <h2 style="font-size:26px">{{ __('ui.tl_more') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach(['seo' => 'gauge', 'whois' => 'search', 'ip' => 'globe', 'meet' => 'video', 'app-builder' => 'smartphone', 'domain-ideas' => 'search', 'speedtest' => 'zap'] as $s => $ic)
        @if($s !== $slug)
        <a class="loc" href="{{ lroute('tools', $s) }}"><svg class="icon"><use href="#i-{{ $ic }}"/></svg>{{ [
          'seo' => __('ui.tl_seo_t'), 'whois' => __('ui.tl_whois_t'), 'ip' => __('ui.tl_ip_t'),
          'meet' => __('ui.tl_meet_t'), 'app-builder' => __('ui.tl_app_t'), 'domain-ideas' => __('ui.tl_ideas_t'),
          'speedtest' => __('ui.tl_spt_t')][$s] }}</a>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- ============ دادهٔ ساختاریافته ============ --}}
@php
    $ldApp = [
        '@'.'context'         => 'https://schema.org',
        '@type'               => 'WebApplication',
        'name'                => $meta['t'],
        'description'         => $meta['d'],
        'url'                 => $toolUrl,
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem'     => 'Any',
        'inLanguage'          => $loc,
        'browserRequirements' => 'Requires JavaScript',
        'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'IRR'],
        'publisher'           => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
    ];
    $ldCrumbs = [
        '@'.'context'       => 'https://schema.org',
        '@type'             => 'BreadcrumbList',
        'itemListElement'   => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.bl_home'), 'item' => lroute('home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $meta['t'], 'item' => $toolUrl],
        ],
    ];
    $ldFaq = count($seo['faq']) ? [
        '@'.'context' => 'https://schema.org',
        '@type'       => 'FAQPage',
        'mainEntity'  => array_map(fn ($f) => [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $seo['faq']),
    ] : null;
    $ldHowTo = count($seo['steps']) ? [
        '@'.'context' => 'https://schema.org',
        '@type'       => 'HowTo',
        'name'        => __('ui.tl_howto', ['tool' => $meta['t']]),
        'totalTime'   => 'PT1M',
        'step'        => array_map(fn ($s, $i) => [
            '@type' => 'HowToStep', 'position' => $i + 1, 'text' => $s,
        ], $seo['steps'], array_keys($seo['steps'])),
    ] : null;
    $ldFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<script type="application/ld+json">{!! json_encode($ldApp, $ldFlags) !!}</script>
<script type="application/ld+json">{!! json_encode($ldCrumbs, $ldFlags) !!}</script>
@if($ldFaq)
<script type="application/ld+json">{!! json_encode($ldFaq, $ldFlags) !!}</script>
@endif
@if($ldHowTo)
<script type="application/ld+json">{!! json_encode($ldHowTo, $ldFlags) !!}</script>
@endif

<script src="{{ asset_ver('assets/js/tools.js') }}" defer></script>
@endsection
