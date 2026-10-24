@extends('layouts.site')
@section('title', __('shop_seo.part_title', ['name' => $part->label()]))
@section('description', $part->summary[app()->getLocale()] ?? $part->summary['fa'] ?? __('ui.parts_desc'))
@section('content')

@php
    $loc     = app()->getLocale();
    $label   = $meta[$loc] ?? $meta['fa'];
    $price   = $part->displayPrice();
    $eur     = $part->eurAmount();
    $gens    = (array) ($part->compat_gens ?? []);
    $condLbl = [
        'new'    => __('ui.parts_cond_new'),
        'refurb' => __('ui.parts_cond_refurb'),
        'used'   => __('ui.parts_cond_used'),
    ];
    $checks = (array) trans('shop_seo.checks.'.$part->category);
    $comparison = $related->first();

    /*
    | دادهٔ ساختاریافتهٔ محصول.
    |
    | 🔴 قیمت **همیشه یورو** اعلام می‌شود، حتی در صفحهٔ فارسی که تومان نشان
    | می‌دهد. عددِ تومانی از نرخِ لحظه‌ای ساخته می‌شود و فردا عوض است؛ اگر آن
    | را در schema می‌گذاشتیم، گوگل قیمتی را کش می‌کرد که در نتایج جستجو با
    | قیمتِ صفحه نمی‌خواند — و همین یکی از دلایلِ رایجِ رد شدنِ rich result است.
    |
    | ⚠️ بی‌قیمت، کلِ `offers` حذف می‌شود نه اینکه صفر بگیرد. `price: 0` یعنی
    | «رایگان»، و برای قطعهٔ سرور دقیقاً همان اشتباهی است که در این پروژه یک
    | بار گران تمام شده.
    */
    $ld = [
        '@'.'context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $part->label(),
        'category' => $label,
        'brand' => ['@type' => 'Brand', 'name' => $part->brand],
        'url' => url()->current(),
    ];

    // ⚠️ `image` فقط وقتی که واقعاً عکس داریم — آرایهٔ خالی در schema بدتر از
    //    نبودنش است و rich result را رد می‌کند.
    if ($imgs = array_values(array_filter(array_map('img_url', (array) ($part->gallery ?? []))))) {
        $ld['image'] = array_map(fn ($u) => url($u), $imgs);
    }

    if ($desc = ($part->summary[$loc] ?? $part->summary['fa'] ?? null)) {
        $ld['description'] = $desc;
    }
    if ($part->condition !== 'new') {
        $ld['itemCondition'] = 'https://schema.org/RefurbishedCondition';
    }
    if ($eur !== null) {
        $ld['offers'] = [
            '@type' => 'Offer',
            'price' => (string) $eur,
            'priceCurrency' => 'EUR',
            'availability' => $part->in_stock
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'seller' => ['@type' => 'Organization', 'name' => 'ServerNet'],
        ];
    }

    $crumbs = [
        '@'.'context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.parts_title'), 'item' => lroute('parts.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $label, 'item' => lroute('parts.category', $part->category)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $part->label()],
        ],
    ];
@endphp

@push('head')
{{-- Product without a real offer/review/rating is invalid for Product snippets. --}}
@if(isset($ld['offers']))
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
<script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
      <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a><span>/</span>
      <a href="{{ lroute('parts.category', $part->category) }}">{{ $label }}</a><span>/</span>
      <span>{{ $part->label() }}</span>
    </nav>
  </div>
</section>

<div class="container sp-shell">
  @include('partials.parts-sidebar', ['activeCat' => $part->category])

  <div class="sp-main">

    <div class="sp-detail-top">
      <div class="sp-detail-info">
        @php($shots = array_values(array_filter(array_map('img_url', (array) ($part->gallery ?? [])))))
        @if($shots)
          {{-- ⚠️ عکسِ اول `loading="eager"` است و بقیه lazy: عکسِ اصلی بالای
               صفحه است و lazy‌کردنش فقط LCP را بدتر می‌کند. --}}
          <div class="sp-shots">
            @foreach($shots as $i => $shot)
              <img src="{{ $shot }}" alt="{{ $part->label() }}"
                   loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                   @class(['on' => $i === 0])>
            @endforeach
          </div>
        @endif

        <div class="sp-card-head">
          <span class="sp-cat">{{ $part->brand }}</span>
          <span class="sp-cond {{ $part->condition }}">{{ $condLbl[$part->condition] ?? $part->condition }}</span>
          <span @class(['sp-stock', 'out' => ! $part->in_stock])>
            {{ $part->in_stock ? __('ui.parts_in_stock') : __('ui.parts_out_stock') }}
          </span>
        </div>

        <h1 class="sp-detail-name">{{ $part->label() }}</h1>

        @if($tag = ($part->tagline[$loc] ?? $part->tagline['fa'] ?? null))
          <p class="sp-detail-tag">{{ $tag }}</p>
        @endif

        @if($sum = ($part->summary[$loc] ?? $part->summary['fa'] ?? null))
          <p class="sp-detail-sum">{{ $sum }}</p>
        @endif

        @if($gens)
          <div class="sp-compat">
            <b>{{ __('ui.parts_compat') }}:</b>
            @foreach($gens as $g)
              <a class="sp-chip sm" href="{{ lroute('servers.generation', $g) }}">{{ str_replace('gen', 'Gen', $g) }}</a>
            @endforeach
          </div>
        @else
          <div class="sp-compat"><b>{{ __('ui.parts_compat') }}:</b> {{ __('ui.parts_compat_any') }}</div>
        @endif
      </div>

      <aside class="sp-buy">
        @if($price === null)
          <span class="sp-buy-price contact">{{ __('ui.parts_quote') }}</span>
        @else
          <span class="sp-buy-price">{{ $price }}</span>
          <span class="sp-buy-note">{{ __('ui.parts_eur_note') }}</span>
        @endif

        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.srv_cta_btn') }}
        </a>

        <label class="sp-cmp standalone">
          <input type="checkbox" class="sp-cmp-box" value="{{ $part->slug }}">
          <span>{{ __('ui.parts_compare_add') }}</span>
        </label>
      </aside>
    </div>

    @if($specs = (array) ($part->specs ?? []))
      <h2 class="sp-h2">{{ __('ui.parts_specs') }}</h2>
      <table class="sp-spec-table">
        <tbody>
        @foreach($specs as $row)
          <tr>
            <th>{{ lc((array) ($row['label'] ?? [])) }}</th>
            {{-- 🔴 این‌جا `fa_num()` **نباید** باشد.
                 مقدارِ مشخصات رشتهٔ فنی است نه کمیت: «LGA3647» را به
                 «LGA۳۶۴۷» و «PC4-2400T» را به «PC۴-۲۴۰۰T» تبدیل می‌کرد —
                 یعنی شمارهٔ فنی‌ای که خریدار می‌خواهد کپی کند و در گوگل بزند
                 دیگر با هیچ‌چیز نمی‌خواند. عددِ لاتین با واحد (۲٫۵ GHz) در
                 متنِ فنیِ فارسی کاملاً متعارف است؛ شمارهٔ فنیِ مخدوش نه. --}}
            <td dir="auto">{{ lc((array) ($row['value'] ?? [])) }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif

    @if($body = ($part->body[$loc] ?? $part->body['fa'] ?? null))
      <h2 class="sp-h2">{{ __('ui.parts_overview') }}</h2>
      {{-- ⚠️ `{{ }}` و نه `{!! !!}`: متن از پنلِ مدیریت می‌آید و یک `<script>`ِ
           چسبانده‌شده در توضیحِ محصول، XSS روی صفحهٔ عمومی است. پاراگراف‌ها را
           `nl2br` نمی‌کنیم؛ CSS با `white-space:pre-line` همان کار را بی‌خطر
           می‌کند. --}}
      <div class="sp-body">{{ $body }}</div>
    @endif

    <section class="sp-guide" aria-labelledby="part-check-title" style="margin-top:32px">
      <h2 class="sp-h2" id="part-check-title">{{ __('shop_seo.part_check_title', ['name' => $part->label()]) }}</h2>
      <p class="sp-detail-sum" style="margin-bottom:14px">{{ __('shop_seo.part_check_intro') }}</p>
      <div class="sp-pros">
        <div class="sp-pro good">
          <h3>{{ __('ui.parts_compat') }}</h3>
          <ul class="sp-pro-list">
            @foreach($checks as $check)<li>{{ $check }}</li>@endforeach
            <li>{{ __('shop_seo.part_compat_note') }}</li>
          </ul>
        </div>
        <div class="sp-pro warn">
          <h3>{{ __('ui.parts_compare') }}</h3>
          <p>{{ __('shop_seo.part_compare_note') }}</p>
          <div class="sp-compat">
            @if($comparison)
              <a class="sp-chip sm" href="{{ lroute('parts.compare').'?parts='.rawurlencode($part->slug.','.$comparison->slug) }}">{{ __('shop_seo.part_compare', ['name' => $part->label(), 'other' => $comparison->label()]) }}</a>
            @endif
            <a class="sp-chip sm" href="{{ lroute('parts.category', $part->category) }}">{{ __('shop_seo.part_family', ['category' => $label]) }}</a>
            <a class="sp-chip sm" href="{{ lroute('servers.index') }}">{{ __('shop_seo.part_servers') }}</a>
          </div>
        </div>
      </div>
    </section>

    @if($related->isNotEmpty())
      <h2 class="sp-h2">{{ __('ui.parts_related') }}</h2>
      <div class="sp-grid">
        @foreach($related as $r)
          @include('partials.part-card', ['part' => $r])
        @endforeach
      </div>
    @endif

  </div>
</div>

@include('partials.parts-compare-bar')
@endsection
