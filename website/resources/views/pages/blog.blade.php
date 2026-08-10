@extends('layouts.site')

@php
    $cats = config('blog.categories');
    $covers = config('blog.covers');
    $htitle = __('ui.bl_title');
    $hsub = __('ui.bl_sub');
    if ($heading) {
        if ($heading['type'] === 'cat') { $htitle = lc($cats[$heading['value']]); $hsub = __('ui.bl_in_category'); }
        elseif ($heading['type'] === 'tag') { $htitle = '#'.$heading['value']; $hsub = __('ui.bl_tagged'); }
        elseif ($heading['type'] === 'search') { $htitle = '«'.$heading['value'].'»'; $hsub = __('ui.bl_results_for'); }
    }
@endphp

@section('title', ($heading ? $htitle.' — ' : '').__('ui.bl_title').' — '.__('ui.brand'))
@section('description', __('ui.bl_meta_d'))

@section('content')

{{-- ============ HERO ============ --}}
<section class="hero hero-sub blog-hero" style="padding-bottom:30px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:820px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.bl_badge') }}</span></span>
      @if($heading)
      <h1 class="reveal" style="transition-delay:.08s">{{ $hsub }} <span class="grad">{{ $htitle }}</span></h1>
      @else
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.bl_h1a') }} <span class="grad">{{ __('ui.bl_h1b') }}</span></h1>
      @endif
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.bl_sub') }}</p>
      <form class="blog-hero-search reveal" style="transition-delay:.24s" action="{{ lroute('blog.index') }}" method="get">
        <svg class="icon"><use href="#i-search"/></svg>
        <input type="text" name="q" value="{{ $q }}" placeholder="{{ __('ui.bl_search_ph') }}" autocomplete="off">
        <button class="btn btn-primary" type="submit">{{ __('ui.bl_search_btn') }}</button>
      </form>
    </div>
  </div>
</section>

{{-- ============ CATEGORY CHIPS ============ --}}
<section class="container" style="margin-bottom:8px">
  <div class="blog-chips reveal">
    <a class="blog-chip @if(!$heading) active @endif" href="{{ lroute('blog.index') }}">{{ __('ui.bl_all') }}</a>
    @foreach($cats as $key => $c)
      @if(($repo = app(\App\Services\BlogRepository::class)) && count($repo->byCategory($key)))
      <a class="blog-chip @if($heading && $heading['type']==='cat' && $heading['value']===$key) active @endif" href="{{ lroute('blog.index') }}?cat={{ $key }}">
        <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>{{ lc($c) }}
      </a>
      @endif
    @endforeach
  </div>
</section>

{{-- ============ LAYOUT ============ --}}
<section class="section blog-layout-sec" style="padding-top:14px;padding-bottom:70px">
  <div class="container">
    <div class="blog-layout">
      <div class="blog-main">
        @if(count($paged['items']))
        <div class="blog-grid">
          @foreach($paged['items'] as $i => $p)
          {{-- ⚠️ `img_url()` و نه `!empty()`: ستونی که رشتهٔ «null» دارد از
               `!empty()` رد می‌شود و `src="null"` می‌سازد — که مرورگر نسبی
               حلش می‌کند و از `/blog?tag=…` یک ۴۰۴ روی `/null` می‌سازد. --}}
          @php $c = $cats[$p['category']] ?? null; $pImg = img_url($p['image'] ?? null); @endphp
          <article class="blog-card reveal" style="transition-delay:{{ $i * 50 }}ms">
            <a class="blog-card-cover {{ $pImg ? 'has-img' : '' }}" href="{{ lroute('blog', $p['slug']) }}" @unless($pImg) style="background:{{ $covers[$p['cover'] ?? 'a'] ?? '' }}" @endunless>
              @if($pImg)
                <img src="{{ $pImg }}" alt="{{ $p['title'] }}" loading="lazy" decoding="async">
              @else
                <svg class="icon"><use href="#i-{{ $p['icon'] ?? 'book' }}"/></svg>
              @endif
              @if($c)<span class="blog-card-cat">{{ lc($c) }}</span>@endif
            </a>
            <div class="blog-card-body">
              <div class="blog-card-meta"><span>{{ blog_date($p['date'] ?? '') }}</span><span>·</span><span>{{ $isFa ? fa_num($p['reading']) : $p['reading'] }} {{ __('ui.bl_min') }}</span></div>
              <h2><a href="{{ lroute('blog', $p['slug']) }}">{{ $p['title'] }}</a></h2>
              <p>{{ $p['excerpt'] ?? '' }}</p>
              <a class="blog-card-more" href="{{ lroute('blog', $p['slug']) }}">{{ __('ui.bl_read_more') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
            </div>
          </article>
          @endforeach
        </div>

        {{-- pagination --}}
        @if($paged['pages'] > 1)
        <nav class="blog-pager reveal" aria-label="pagination">
          @php $qs = request()->except('page'); @endphp
          @if($paged['page'] > 1)<a href="{{ lroute('blog.index') }}?{{ http_build_query(array_merge($qs, ['page'=>$paged['page']-1])) }}">‹ {{ __('ui.bl_prev') }}</a>@endif
          @for($n = 1; $n <= $paged['pages']; $n++)
          <a href="{{ lroute('blog.index') }}?{{ http_build_query(array_merge($qs, ['page'=>$n])) }}" class="@if($n===$paged['page']) active @endif">{{ $isFa ? fa_num($n) : $n }}</a>
          @endfor
          @if($paged['page'] < $paged['pages'])<a href="{{ lroute('blog.index') }}?{{ http_build_query(array_merge($qs, ['page'=>$paged['page']+1])) }}">{{ __('ui.bl_next') }} ›</a>@endif
        </nav>
        @endif

        @else
        <div class="blog-empty reveal"><svg class="icon"><use href="#i-search"/></svg><p>{{ __('ui.bl_none') }}</p><a class="btn btn-glass" href="{{ lroute('blog.index') }}">{{ __('ui.bl_all') }}</a></div>
        @endif
      </div>

      @include('partials.blog-sidebar')
    </div>
  </div>
</section>
@endsection
