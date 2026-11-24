@extends('layouts.site')
@section('title', lc($data['title']).' | '.__('ui.brand'))
@section('description', \App\Services\Shop\HardwarePresentation::description(lc($data['intro'])))
@include('partials.hardware-assets')
@section('content')
@php
    $category = $data['category'];
    $filter = array_intersect_key($data, array_flip(['gen','kind']));
    $browseUrl = lroute('parts.category', $category).'?'.http_build_query($filter);
    $crumbs = [
        '@'.'context' => 'https://schema.org', '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.parts_title'), 'item' => lroute('parts.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $categories[$category]['label'], 'item' => lroute('parts.category', $category)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => lc($data['title'])],
        ],
    ];
@endphp
@push('head')
<script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush
<section class="sp-hero">
    <div class="container">
        <nav class="blog-crumbs">
            <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
            <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a><span>/</span>
            <a href="{{ lroute('parts.category', $category) }}">{{ $categories[$category]['label'] }}</a><span>/</span>
            <span>{{ lc($data['title']) }}</span>
        </nav>
        <h1>{{ lc($data['title']) }}</h1>
        <p class="sp-lead">{{ lc($data['intro']) }}</p>
    </div>
</section>
<div class="container sp-shell">
    @include('partials.parts-sidebar', ['activeCat' => $category])
    <div class="sp-main">
        <h2 class="sp-h2">{{ __('ui.parts_guide') }}</h2>
        <p class="sp-body">{{ lc($data['advice']) }}</p>
        @if(isset($data['gen']))
            <p><a href="{{ lroute('servers.generation', $data['gen']) }}">{{ lc($generations[$data['gen']]['name']) }}</a></p>
        @endif
        <h2 class="sp-h2">{{ __('shop_seo.topic_products') }}</h2>
        @if($parts->isNotEmpty())
            <p class="sp-count">{{ __('shop_seo.topic_count', ['shown' => fa_num($parts->count()), 'total' => fa_num($total)]) }}</p>
            <div class="sp-grid">
                @foreach($parts as $part)
                    @include('partials.part-card', ['part' => $part])
                @endforeach
            </div>
        @else
            <p class="sp-empty">{{ __('shop_seo.topic_empty') }}</p>
        @endif
        <p><a class="btn btn-glass" href="{{ $browseUrl }}">{{ __('shop_seo.topic_browse_all') }}</a></p>
        @include('partials.hardware-stock-quote')
        @include('partials.hardware-topic-links', ['topicCategory' => $category, 'currentTopic' => $topic])
        <nav class="hw-related-links">
            @if($topic === 'enterprise-storage')
              <a href="{{ lroute('parts.category', 'storage') }}">{{ lc(\App\Models\ServerPart::EQUIPMENT_CATEGORIES['storage']) }}</a>
            @endif
            <a href="{{ lroute('parts.planner') }}">{{ __('equipment.planner_title') }}</a>
            <a href="{{ lroute('servers.index') }}">{{ __('ui.srv_title') }}</a>
            <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a>
        </nav>
    </div>
</div>
@include('partials.parts-compare-bar')
@endsection
