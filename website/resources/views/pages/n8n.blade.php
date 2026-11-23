@extends('layouts.site')
@section('title', __('n8n.title').' — '.__('ui.brand'))
@section('description', __('n8n.description'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/n8n.css') }}">
@endpush
@section('content')
<main class="n8n-page">
  <section class="n8n-hero">
    <div>
      <span class="n8n-eyebrow">{{ __('n8n.eyebrow') }}</span>
      <h1>{{ __('n8n.title') }}</h1>
      <p>{{ __('n8n.intro') }}</p>
      <a class="n8n-button" href="{{ lroute('account.cloud.store', ['application' => 'n8n']) }}">{{ __('n8n.configure') }} <span aria-hidden="true">↗</span></a>
      <p class="n8n-small">{{ __('n8n.independent') }}</p>
    </div>
    <div class="n8n-visual" aria-hidden="true">
      <span class="n8n-node">API</span><span class="n8n-flow">→</span>
      <img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="108" height="108">
      <span class="n8n-flow">→</span><span class="n8n-node">AI</span>
    </div>
  </section>
  <section class="n8n-section" id="plans">
    <div class="n8n-heading"><h2>{{ __('n8n.configure') }}</h2><span>n8n Community</span></div>
    <p class="n8n-small">{{ __('n8n.price_note') }}</p>
    @if(! $n8nEnabled)<p class="n8n-notice">{{ __('n8n.disabled') }}</p>@endif
    @include('partials.n8n-plans')
    <p class="n8n-small">{{ __('n8n.turkey_monthly') }}</p>
    <p class="n8n-small">{{ __('n8n.tax_notice') }}</p>
  </section>
  <section class="n8n-section">
    <h2>{{ __('n8n.steps') }}</h2>
    <ol class="n8n-steps">
      @foreach(['step_server','step_install','step_https','step_ready'] as $step)
        <li><span>{{ fa_num($loop->iteration) }}</span>{{ __('n8n.'.$step) }}</li>
      @endforeach
    </ol>
    <p class="n8n-small">{{ __('n8n.scope') }}</p>
  </section>
</main>
@endsection
