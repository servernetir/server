@extends('layouts.site')
@section('title', __('ui.parts_title').' — '.__('ui.brand'))
@section('description', __('ui.parts_desc'))
@section('content')

<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
      <a href="{{ lroute('servers.index') }}">{{ __('ui.srv_title') }}</a><span>/</span>
      <span>{{ __('ui.parts_title') }}</span>
    </nav>
    <h1>{{ __('ui.parts_h1') }}</h1>
    <p class="sp-lead">{{ __('ui.parts_lead') }}</p>
  </div>
</section>

<div class="container sp-shell">
  @include('partials.parts-sidebar')

  <div class="sp-main">

    <h2 class="sp-h2">{{ __('ui.parts_browse') }}</h2>
    <div class="sp-cat-grid">
      @foreach($categories as $key => $c)
        <a class="sp-cat-card" href="{{ lroute('parts.category', $key) }}">
          <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>
          <h3>{{ $c['label'] }}</h3>
          @if($c['count'] > 0)
            <span>{{ __('ui.parts_count', ['count' => fa_num($c['count'])]) }}</span>
          @else
            <span class="muted">{{ __('ui.parts_quote') }}</span>
          @endif
        </a>
      @endforeach
    </div>

    <h2 class="sp-h2">{{ __('ui.parts_gens') }}</h2>
    <div class="sp-gen-grid">
      @foreach($generations as $key => $g)
        <a class="sp-gen-card" href="{{ lroute('servers.generation', $key) }}">
          <div class="sp-gen-top">
            <b>{{ lc($g['name']) }}</b>
            <span class="sp-gen-years" dir="ltr">{{ fa_num($g['years']) }}</span>
          </div>
          <p>{{ lc($g['headline']) }}</p>
          <span class="sp-gen-go">{{ __('ui.parts_view') }} <svg class="icon dir"><use href="#i-arrow"/></svg></span>
        </a>
      @endforeach
    </div>

    @if($popular->isNotEmpty())
      <h2 class="sp-h2">{{ __('ui.parts_popular') }}</h2>
      <div class="sp-grid">
        @foreach($popular as $part)
          @include('partials.part-card', ['part' => $part, 'compare' => false])
        @endforeach
      </div>
    @elseif(! $available)
      {{-- جدول هنوز مهاجرت نشده. صفحهٔ «به‌زودی» صادقانه است؛ ۵۰۰ نه. --}}
      <p class="sp-empty">{{ __('ui.parts_soon') }}</p>
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
