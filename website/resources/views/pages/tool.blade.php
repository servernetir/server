@extends('layouts.site')

@php
    $meta = [
        'seo'         => ['t' => __('ui.tl_seo_t'),   'd' => __('ui.tl_seo_d')],
        'whois'       => ['t' => __('ui.tl_whois_t'), 'd' => __('ui.tl_whois_d')],
        'ip'          => ['t' => __('ui.tl_ip_t'),    'd' => __('ui.tl_ip_d')],
        'meet'        => ['t' => __('ui.tl_meet_t'),  'd' => __('ui.tl_meet_d')],
        'app-builder' => ['t' => __('ui.tl_app_t'),   'd' => __('ui.tl_app_d')],
    ][$slug];
@endphp

@section('title', $meta['t'].' — '.__('ui.brand'))
@section('description', $meta['d'])

@section('content')
@php $loc = app()->getLocale(); @endphp

@include('partials.tools.'.$slug)

{{-- ============ CROSS-SELL: سایر ابزارها ============ --}}
<section class="section" style="padding-top:20px;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:34px">
      <h2 style="font-size:26px">{{ __('ui.tl_more') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach(['seo' => 'gauge', 'whois' => 'search', 'ip' => 'globe', 'meet' => 'video', 'app-builder' => 'smartphone'] as $s => $ic)
        @if($s !== $slug)
        <a class="loc" href="{{ lroute('tools', $s) }}"><svg class="icon"><use href="#i-{{ $ic }}"/></svg>{{ [
          'seo' => __('ui.tl_seo_t'), 'whois' => __('ui.tl_whois_t'), 'ip' => __('ui.tl_ip_t'),
          'meet' => __('ui.tl_meet_t'), 'app-builder' => __('ui.tl_app_t')][$s] }}</a>
        @endif
      @endforeach
    </div>
  </div>
</section>

<script src="{{ asset_ver('assets/js/tools.js') }}" defer></script>
@endsection
