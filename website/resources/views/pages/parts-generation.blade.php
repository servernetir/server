@extends('layouts.site')
@section('title', lc($data['name']).' — '.__('ui.parts_title').' — '.__('ui.brand'))
@section('description', lc($data['summary']))
@section('content')

@php
    $statusLbl = __('ui.parts_status_'.$data['status']);
    $facts = array_filter([
        __('ui.parts_gen_years')  => fa_num($data['years']),
        __('ui.parts_gen_cpu')    => $data['cpu_family'],
        __('ui.parts_gen_ram')    => $data['ram_type'].' · '.fa_num($data['ram_speed']),
        __('ui.parts_gen_ilo')    => $data['ilo'],
        __('ui.parts_gen_nvme')   => $data['nvme'] ? __('ui.parts_yes') : __('ui.parts_no'),
        __('ui.parts_gen_status') => $statusLbl,
    ]);

    $crumbs = [
        '@'.'context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.parts_title'), 'item' => lroute('parts.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => lc($data['name'])],
        ],
    ];
@endphp

@push('head')
<script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
      <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a><span>/</span>
      <span>{{ lc($data['name']) }}</span>
    </nav>
    <h1>{{ lc($data['name']) }}</h1>
    <p class="sp-lead">{{ lc($data['headline']) }}</p>
    <span class="sp-status {{ $data['status'] }}">{{ $statusLbl }}</span>
  </div>
</section>

<div class="container sp-shell">
  @include('partials.parts-sidebar', ['activeGen' => $gen])

  <div class="sp-main">

    <p class="sp-gen-sum">{{ lc($data['summary']) }}</p>

    <div class="sp-facts">
      @foreach($facts as $k => $v)
        {{-- ⚠️ `dir="ltr"` روی مقدار: بازهٔ «۲۰۱۲–۲۰۱۴» در بندِ راست‌به‌چپ
             وارونه دیده می‌شد («۲۰۱۴–۲۰۱۲») چون رقم و خط‌تیره هر دو ضعیف‌اند
             و جهتِ بند تعیینشان می‌کرد. --}}
        <div class="sp-fact"><span>{{ $k }}</span><b dir="ltr">{{ $v }}</b></div>
      @endforeach
      {{-- ⚠️ نوعِ حافظه این‌جا تکرار **نمی‌شود** — کارتِ «حافظه» بالاتر
           گفته‌اش. نسخهٔ اول هر دو را می‌آورد و رشتهٔ درهمِ
           «۲۴ × DDR4 ECC (RDIMM / LRDIMM) · ۳٬۰۷۲ GB» زیرِ راست‌به‌چپ عملاً
           ناخوانا می‌شد. این‌جا فقط ظرفیت است، با جهتِ صریح. --}}
      <div class="sp-fact">
        <span>{{ __('ui.parts_gen_capacity') }}</span>
        <b dir="ltr">{{ fa_num($data['ram_slots']) }} DIMM · {{ fa_num(number_format($data['ram_max_gb'])) }} GB</b>
      </div>
    </div>

    {{-- 🔴 «به چه درد می‌خورد» و «مراقب چه باشید» کنارِ هم و هم‌وزن.
         فروشگاهی که فقط نقطهٔ قوت می‌گوید، خریدارِ فنی را از دست می‌دهد؛ او
         محدودیت را جای دیگر پیدا می‌کند و بعد به کلِ صفحه بی‌اعتماد می‌شود. --}}
    <div class="sp-pros">
      <div class="sp-pro good">
        <h3><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.parts_gen_good') }}</h3>
        {{-- ⚠️ `good_for` فهرستِ بولت است نه پاراگراف — در هر سه زبان آرایه است.
             چاپِ مستقیمش `htmlspecialchars(array)` می‌داد و صفحهٔ نسل را ۵۰۰
             می‌کرد. `(array)` هم گذاشته شده تا اگر روزی یکی از نسل‌ها را
             تک‌جمله‌ای نوشتیم، صفحه نشکند. --}}
        <ul class="sp-pro-list">
          @foreach((array) lc($data['good_for']) as $item)
            <li>{{ $item }}</li>
          @endforeach
        </ul>
      </div>
      <div class="sp-pro warn">
        <h3><svg class="icon"><use href="#i-info"/></svg>{{ __('ui.parts_gen_watch') }}</h3>
        <p>{{ lc($data['watch_out']) }}</p>
      </div>
    </div>

    <h2 class="sp-h2">{{ __('ui.parts_gen_parts') }}</h2>

    @if($byCategory->isEmpty())
      <p class="sp-empty">{{ __('ui.parts_soon') }}</p>
    @else
      @foreach($categories as $key => $c)
        @continue(! $byCategory->has($key))
        <h3 class="sp-h3">
          <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>
          <a href="{{ lroute('parts.category', $key).'?gen='.$gen }}">{{ $c['label'] }}</a>
        </h3>
        <div class="sp-grid">
          @foreach($byCategory[$key]->take(6) as $part)
            @include('partials.part-card', ['part' => $part])
          @endforeach
        </div>
      @endforeach
      <p class="sp-note">{{ __('ui.parts_eur_note') }}</p>
    @endif

    <section class="sp-ask">
      <div>
        <h2>{{ __('ui.parts_ask') }}</h2>
        <p>{{ __('ui.parts_ask_sub') }}</p>
      </div>
      <a class="btn btn-primary" href="{{ lroute('contact') }}">
        <svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.srv_cta_btn') }}
      </a>
    </section>

  </div>
</div>

@include('partials.parts-compare-bar')
@endsection
