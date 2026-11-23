@extends('layouts.site')
@section('title', __('studio_landing.meta_title'))
@section('description', __('studio_landing.meta_description'))
@include('partials.home-discovery-style')
@push('head')
@php
    $studioUrl = lroute('ai.studio');
    $studioService = [
        '@id' => $studioUrl.'#service',
        'name' => __('studio_carousel.brand'),
        'description' => __('studio_landing.intro'),
        'url' => $studioUrl,
        'provider' => ['@id' => rtrim((string) config('app.url'), '/').'/#organization'],
    ];
@endphp
<script type="application/ld+json">{!! schema_ld($studioService, 'Service') !!}</script>
@endpush
@section('content')
<div class="sn-studio-landing">
  <section class="sn-discovery-hero">
    <div class="container">
      <div class="sn-discovery-head">
        <span class="sn-discovery-kicker">{{ __('studio_carousel.brand') }}</span>
        <h1>{{ __('studio_landing.title') }} <span class="grad">{{ __('studio_landing.accent') }}</span></h1>
        <p>{{ __('studio_landing.intro') }}</p>
        <div class="sn-discovery-actions">
          <a class="btn btn-primary" href="https://studio.servernet.cloud/">{{ __('studio_landing.open') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
          <a class="btn btn-glass" href="{{ lroute('ai.models') }}">{{ __('studio_landing.models') }}</a>
        </div>
      </div>
    </div>
  </section>
  <section class="sn-discovery" aria-labelledby="studio-use-title">
    <div class="container">
      <div class="sn-discovery-head"><h2 id="studio-use-title">{{ __('studio_landing.use_heading') }}</h2><p>{{ __('studio_landing.use_intro') }}</p></div>
      <div class="sn-discovery-grid">
        @foreach(['text'=>'message', 'image'=>'image', 'video'=>'video', 'audio'=>'mic'] as $mode => $icon)
        <article class="sn-discovery-card">
          <span class="sn-discovery-icon" aria-hidden="true"><svg class="icon"><use href="#i-{{ $icon }}"/></svg></span>
          <h3>{{ __('studio_landing.uses.'.$mode.'.title') }}</h3>
          <p>{{ __('studio_landing.uses.'.$mode.'.text') }}</p>
        </article>
        @endforeach
        <article class="sn-discovery-card">
          <span class="sn-discovery-icon" aria-hidden="true"><svg class="icon"><use href="#i-sparkles"/></svg></span>
          <h3>{{ __('studio_landing.billing_title') }}</h3>
          <p>{{ __('studio_landing.billing_text') }}</p>
          <a class="sn-discovery-link" href="{{ lroute('ai.models') }}">{{ __('studio_landing.models') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
        </article>
        <article class="sn-discovery-card">
          <span class="sn-discovery-icon" aria-hidden="true"><svg class="icon"><use href="#i-globe"/></svg></span>
          <h3>{{ __('studio_landing.browser_title') }}</h3>
          <p>{{ __('studio_landing.browser_text') }}</p>
          <a class="sn-discovery-link" href="{{ lroute('studio.download') }}#studio-web-install">{{ __('studio_carousel.web_guide') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
        </article>
      </div>
    </div>
  </section>
  <section class="sn-discovery" aria-labelledby="studio-choose-title">
    <div class="container">
      <div class="sn-discovery-head"><h2 id="studio-choose-title">{{ __('studio_landing.choose_heading') }}</h2><p>{{ __('studio_landing.choose_intro') }}</p></div>
      <div class="sn-discovery-grid">
        @foreach(['studio' => ['#studio-faq','sparkles'], 'api' => [lroute('ai.home'),'code'], 'gpu' => [lroute('gpu'),'cpu']] as $type => [$url,$icon])
        <article class="sn-discovery-card"><span class="sn-discovery-icon" aria-hidden="true"><svg class="icon"><use href="#i-{{ $icon }}"/></svg></span><h3>{{ __('studio_landing.choices.'.$type.'.title') }}</h3><p>{{ __('studio_landing.choices.'.$type.'.text') }}</p><a class="sn-discovery-link" href="{{ $url }}">{{ __('studio_landing.choices.'.$type.'.link') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a></article>
        @endforeach
      </div>
    </div>
  </section>
  <section class="sn-discovery" id="studio-faq" aria-labelledby="studio-faq-title">
    <div class="container">
      <div class="sn-discovery-head"><h2 id="studio-faq-title">{{ __('studio_landing.faq_heading') }}</h2></div>
      @foreach(trans('studio_landing.faqs') as $faq)
      <article class="sn-discovery-q"><h3>{{ $faq['q'] }}</h3><p>{{ $faq['a'] }}</p></article>
      @endforeach
      <div class="sn-discovery-actions"><a class="btn btn-primary" href="https://studio.servernet.cloud/">{{ __('studio_landing.open') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a><a class="btn btn-glass" href="{{ lroute('contact') }}">{{ __('home_discovery.contact') }}</a></div>
    </div>
  </section>
</div>
@endsection
