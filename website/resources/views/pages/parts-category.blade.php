@extends('layouts.site')
@section('title', $meta[app()->getLocale()].' — '.__('ui.parts_title').' — '.__('ui.brand'))
{{-- ⚠️ توضیحِ متا از محتوای **همین دسته** می‌آید. اگر جا افتاده باشد به متنِ
     عمومی برمی‌گردیم — که بد است ولی از توضیحِ خالی بهتر. --}}
@section('description', lc($content['meta'] ?? []) ?: ($meta[app()->getLocale()].' — '.__('ui.parts_desc')))
@section('content')

@php
    $label = $meta[app()->getLocale()] ?? $meta['fa'];
    $condLbl = [
        'new'    => __('ui.parts_cond_new'),
        'refurb' => __('ui.parts_cond_refurb'),
        'used'   => __('ui.parts_cond_used'),
    ];
    $sorts = [
        'popular'    => __('ui.parts_sort_popular'),
        'price_asc'  => __('ui.parts_sort_price_asc'),
        'price_desc' => __('ui.parts_sort_price_desc'),
        'name'       => __('ui.parts_sort_name'),
    ];
    $base = lroute('parts.category', $category);

    /*
    | لینکِ فیلتر — یک پارامتر عوض، بقیه دست‌نخورده.
    |
    | ⚠️ فیلترها باید **جمع‌شونده** باشند: کاربری که «Gen9» را زده و بعد
    | «بازسازی‌شده» می‌زند، انتظار دارد هر دو اعمال شود نه اینکه دومی اولی را
    | پاک کند. ساختنِ دستیِ آدرس در هر دکمه، دقیقاً همان‌جا خراب می‌شد.
    */
    $link = function (string $k, ?string $v) use ($base, $active) {
        $q = array_filter([
            'gen'       => $active['gen'],
            'condition' => $active['condition'],
            'q'         => $active['q'] !== '' ? $active['q'] : null,
            'max'       => $active['max'],
            'sort'      => $active['sort'] === 'popular' ? null : $active['sort'],
        ], fn ($v) => $v !== null && $v !== '');
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
        if (($q['sort'] ?? null) === 'popular') { unset($q['sort']); }

        return $q ? $base.'?'.http_build_query($q) : $base;
    };
    $hasFilters = $active['gen'] !== null || $active['condition'] !== null
        || $active['sort'] !== 'popular' || $active['q'] !== '' || $active['max'] !== null;

    /*
    | دادهٔ ساختاریافتهٔ پرسش‌های متداول.
    |
    | 🔴 فقط وقتی منتشر می‌شود که پرسش‌ها **واقعاً روی صفحه** باشند. schemaـی
    | که محتوایش در صفحه نیست، طبق قواعد گوگل تخلف است و می‌تواند کلِ دادهٔ
    | ساختاریافتهٔ دامنه را بی‌اعتبار کند — یعنی ریسکش خیلی بیشتر از سودش.
    */
    $faqLd = null;
    if ($faqRows = ($content['faq'] ?? [])) {
        $faqLd = [
            '@'.'context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($r) => [
                '@type' => 'Question',
                'name' => lc($r['q']),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => lc($r['a'])],
            ], $faqRows),
        ];
    }

    $crumbs = [
        '@'.'context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.parts_title'), 'item' => lroute('parts.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $label],
        ],
    ];
@endphp

@push('head')
<script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@if($faqLd)
<script type="application/ld+json">{!! json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
@endpush

<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
      <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a><span>/</span>
      <span>{{ $label }}</span>
    </nav>
    <h1>{{ $label }}</h1>
    <p class="sp-lead">{{ lc($content['intro'] ?? []) ?: __('ui.parts_lead') }}</p>
  </div>
</section>

<div class="container sp-shell">
  @include('partials.parts-sidebar', ['activeCat' => $category])

  <div class="sp-main">

    <div class="sp-filters">
      {{-- 🔴 فرمِ GET و نه جستجوی زندهٔ جاوااسکریپتی: نتیجه یک آدرسِ واقعی
           می‌شود که کاربر می‌تواند بوکمارک کند یا بفرستد، و بی‌جاوااسکریپت هم
           کار می‌کند. فیلدهای مخفی بقیهٔ فیلترها را نگه می‌دارند، وگرنه جستجو
           انتخابِ نسل و وضعیت را بی‌صدا پاک می‌کرد. --}}
      <form method="get" action="{{ $base }}" class="sp-search" role="search">
        @foreach(['gen' => $active['gen'], 'condition' => $active['condition'], 'max' => $active['max']] as $k => $v)
          @if($v !== null)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
        @endforeach
        @if($active['sort'] !== 'popular')<input type="hidden" name="sort" value="{{ $active['sort'] }}">@endif

        <svg class="icon"><use href="#i-search"/></svg>
        <input type="search" name="q" value="{{ $active['q'] }}" maxlength="60"
               placeholder="{{ __('ui.parts_search_ph') }}" aria-label="{{ __('ui.parts_search_ph') }}">
        <button type="submit">{{ __('ui.parts_search') }}</button>
      </form>

      @if($facets['gens'])
        <div class="sp-filter-row">
          <span class="sp-filter-lbl">{{ __('ui.parts_filter_gen') }}</span>
          <a href="{{ $link('gen', null) }}" @class(['sp-chip', 'on' => $active['gen'] === null])>{{ __('ui.parts_gen_all') }}</a>
          @foreach($facets['gens'] as $g)
            <a href="{{ $link('gen', $g) }}" @class(['sp-chip', 'on' => $active['gen'] === $g])>{{ str_replace('gen', 'Gen', $g) }}</a>
          @endforeach
        </div>
      @endif

      @if(count($facets['conditions']) > 1)
        <div class="sp-filter-row">
          <span class="sp-filter-lbl">{{ __('ui.parts_filter_cond') }}</span>
          <a href="{{ $link('condition', null) }}" @class(['sp-chip', 'on' => $active['condition'] === null])>{{ __('ui.parts_gen_all') }}</a>
          @foreach($facets['conditions'] as $c)
            <a href="{{ $link('condition', $c) }}" @class(['sp-chip', 'on' => $active['condition'] === $c])>{{ $condLbl[$c] ?? $c }}</a>
          @endforeach
        </div>
      @endif

      @if($priceSteps)
        <div class="sp-filter-row">
          <span class="sp-filter-lbl">{{ __('ui.parts_filter_price') }}</span>
          <a href="{{ $link('max', null) }}" @class(['sp-chip', 'on' => $active['max'] === null])>{{ __('ui.parts_gen_all') }}</a>
          @foreach($priceSteps as $step)
            <a href="{{ $link('max', (string) $step) }}" @class(['sp-chip', 'on' => $active['max'] === $step])>
              {{ __('ui.parts_under', ['amount' => part_price($step * 100) ?? ('€'.$step)]) }}
            </a>
          @endforeach
        </div>
      @endif

      <div class="sp-filter-row">
        <span class="sp-filter-lbl">{{ __('ui.parts_sort') }}</span>
        @foreach($sorts as $key => $lbl)
          <a href="{{ $link('sort', $key) }}" @class(['sp-chip', 'on' => $active['sort'] === $key])>{{ $lbl }}</a>
        @endforeach

        @if($hasFilters)
          <a class="sp-clear" href="{{ $base }}">
            <svg class="icon"><use href="#i-x"/></svg>{{ __('ui.parts_clear') }}
          </a>
        @endif
      </div>
    </div>

    @if($parts->isEmpty())
      {{-- ⚠️ پیامِ جستجوی بی‌نتیجه با پیامِ فیلترِ بی‌نتیجه فرق دارد: کاربری که
           عبارت زده انتظار دارد عبارتش را ببیند، نه یک متنِ عمومی. --}}
      <p class="sp-empty">
        @if($active['q'] !== '')
          {{ __('ui.parts_no_match', ['q' => $active['q']]) }}
        @else
          {{ __('ui.parts_none') }}
        @endif
      </p>
    @else
      <p class="sp-count">
        {{ __('ui.parts_count', ['count' => fa_num($total)]) }}
        {{-- ⚠️ بریدنِ خاموش ممنوع: اگر سقف خورده، همین‌جا گفته می‌شود. --}}
        @if($total > $parts->count())
          <span class="sp-more">{{ __('ui.parts_more', ['count' => fa_num($total - $parts->count())]) }}</span>
        @endif
      </p>
      <div class="sp-grid">
        @foreach($parts as $part)
          @include('partials.part-card', ['part' => $part])
        @endforeach
      </div>
      <p class="sp-note">{{ __('ui.parts_eur_note') }}</p>
    @endif

    @if($guide = ($content['guide'] ?? []))
      <h2 class="sp-h2">{{ __('ui.parts_guide') }}</h2>
      <div class="sp-guide">
        @foreach($guide as $g)
          <section>
            <h3>{{ lc($g['h']) }}</h3>
            <p>{{ lc($g['p']) }}</p>
          </section>
        @endforeach
      </div>
    @endif

    @if($faq = ($content['faq'] ?? []))
      <h2 class="sp-h2">{{ __('ui.parts_faq') }}</h2>
      {{-- ⚠️ `<details>` و نه آکاردئونِ جاوااسکریپتی: بی‌اسکریپت هم باز و بسته
           می‌شود، و مهم‌تر اینکه متنِ پاسخ همیشه در DOM هست تا خزنده ببیندش. --}}
      <div class="sp-faq">
        @foreach($faq as $item)
          <details>
            <summary>{{ lc($item['q']) }}</summary>
            <p>{{ lc($item['a']) }}</p>
          </details>
        @endforeach
      </div>
    @endif

  </div>
</div>

@include('partials.parts-compare-bar')
@endsection
