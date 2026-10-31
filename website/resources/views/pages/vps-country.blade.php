@extends('layouts.site')
@section('title', $seo['title'])
@section('description', $seo['description'])
@if(!$vpsRows)
@section('noindex', 'y')
@endif
@section('content')
<script type="application/ld+json">{!! schema_ld($ld['crumbs'], 'BreadcrumbList') !!}</script>
@if($ld['list']['itemListElement'])
<script type="application/ld+json">{!! schema_ld($ld['list'], 'ItemList') !!}</script>
@endif
<section class="section vpsc-hero">
  <div class="container">
    <nav class="vpsc-crumbs" aria-label="breadcrumb"><a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span><a href="{{ lroute('cloud.index') }}">{{ __('vps-catalog.catalog') }}</a><span>/</span><span>{{ $country }}</span></nav>
    <div class="sn-product-hero sn-product-polish">
      @include('partials.atlas-product-horizon')
      <span class="badge">{{ $flag }} {{ $country }}</span>
      <h1>{{ $seo['h1'] }}</h1>
      <p>{{ $seo['intro'] }}</p>
    </div>
    <div class="vpsc-cities">@foreach(collect($vpsRows)->pluck('city')->unique() as $city)<span>{{ $city }}</span>@endforeach</div>
  </div>
</section>
<section class="section vpsc-plans" id="pricing"><div class="container">
  @include('partials.vps-plan-table', ['hourlyPage' => false])
</div></section>
@include('partials.country-selection-guide', ['countrySeo' => $seo])
<section class="section"><div class="container vpsc-info">
  <h2>{{ __('vps-catalog.faq') }}</h2>
  @foreach($seo['faq'] as $faq)<details><summary>{{ $faq['q'] }}</summary><p>{{ $faq['a'] }}</p></details>@endforeach
  <script type="application/ld+json">{!! schema_ld($seo['faq_ld'], 'FAQPage') !!}</script>
</div></section>
@endsection
