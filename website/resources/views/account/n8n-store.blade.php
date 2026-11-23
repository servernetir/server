@extends('panel.layout')
@section('title', __('n8n.order'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/n8n.css') }}">
@endpush
@section('panel')
<div class="n8n-store">
  <a class="n8n-back" href="{{ lroute('account.other') }}">{{ __('n8n.my_services') }}</a>
  @include('partials.n8n-hero', ['n8nConsole' => true])
  @if($errors->any())<div class="n8n-notice n8n-notice--danger" role="alert">@foreach($errors->all() as $error)<p>{{ \App\Support\PanelText::text($error) }}</p>@endforeach</div>@endif
  @include('partials.n8n-configurator', ['n8nConsole' => true])
  <p class="n8n-footnote">{{ __('n8n.scope') }}</p>
</div>
@endsection
