@extends('layouts.site')
@section('title', __('n8n.title').' — '.__('ui.brand'))
@section('description', __('n8n.description'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/n8n.css') }}">
@endpush
@section('content')
<div class="n8n-page">
  <script type="application/ld+json">{!! schema_ld($n8nSeo['service'], 'Service') !!}</script>
  <script type="application/ld+json">{!! schema_ld($n8nSeo['faq'], 'FAQPage') !!}</script>
  <script type="application/ld+json">{!! schema_ld($n8nSeo['crumbs'], 'BreadcrumbList') !!}</script>
  @include('partials.n8n-hero', ['n8nConsole' => false])
  @include('partials.n8n-configurator', ['n8nConsole' => false])
  <section class="n8n-delivery" aria-labelledby="n8n-delivery-heading">
    <div><span class="n8n-eyebrow">{{ __('n8n.delivery_eyebrow') }}</span><h2 id="n8n-delivery-heading">{{ __('n8n.steps') }}</h2><p>{{ __('n8n.delivery_lead') }}</p></div>
    <ol class="n8n-delivery-steps">
      @foreach(['step_server' => 'server','step_install' => 'flow','step_https' => 'shield','step_ready' => 'check'] as $step => $icon)
      <li><span><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg></span><b>{{ __('n8n.'.$step) }}</b></li>
      @endforeach
    </ol>
  </section>
  @include('partials.n8n-guide', ['copy' => $n8nSeo['copy'], 'rates' => $n8nSeo['rates']])
  <p class="n8n-footnote">{{ __('n8n.scope') }}</p>
</div>
@endsection
