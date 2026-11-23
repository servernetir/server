@extends('panel.layout')
@section('title', __('ui.sec_page_other'))

{{-- Application workspaces and the customer's acquired managed services. --}}

@section('panel')

<header class="cw-hero">
  <div>
    <p class="cw-eyebrow">{{ __('workspace.eyebrow') }}</p>
    <h1>{{ __('ui.sec_other') }}</h1>
    <p class="cw-lead">{{ __('workspace.services_lead') }}</p>
  </div>
  <div class="cw-orbit" aria-hidden="true"><svg class="icon"><use href="#i-flow"/></svg></div>
</header>
<section class="cw-launch-grid" aria-label="{{ __('ui.sec_other') }}">
  <a class="cw-launch cw-launch--n8n" href="{{ lroute('account.cloud.store', ['application' => 'n8n']) }}">
    <span class="cw-launch-icon"><img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="29" height="29"></span>
    <h2>{{ __('workspace.services_n8n') }}</h2><p>{{ __('workspace.services_n8n_note') }}</p><small>{{ __('workspace.services_start') }}</small>
  </a>
  <a class="cw-launch" href="{{ lroute('account.ai') }}"><span class="cw-launch-icon"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg></span><h2>{{ __('workspace.services_ai') }}</h2><p>{{ __('workspace.services_ai_note') }}</p><small>{{ __('workspace.services_open') }}</small></a>
  <a class="cw-launch" href="{{ lroute('account.identity') }}"><span class="cw-launch-icon"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg></span><h2>{{ __('workspace.services_identity') }}</h2><p>{{ __('workspace.services_identity_note') }}</p><small>{{ __('workspace.services_open') }}</small></a>
</section>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'other'])
@include('account.partials.sec-other', ['items' => $services, 'room' => true])
@include('account.partials.svc-js')
@endsection
