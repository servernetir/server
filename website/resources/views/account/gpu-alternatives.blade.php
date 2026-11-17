@extends('panel.layout')
@section('title', __('gpu-alternatives.title'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/gpu-alternatives.css') }}">
<script src="{{ asset_ver('assets/js/gpu-live-capacity.js') }}" defer></script>
@endpush
@section('panel')
<div class="pnl-head"><div><h1>{{ __('gpu-alternatives.title') }}</h1><p>{{ __('gpu-alternatives.intro') }}</p></div>
  <a class="pnl-btn" href="{{ lroute('account.cloud.show', $service) }}">{{ __('gpu-alternatives.back') }}</a>
</div>
<p class="ga-notice">{{ __('gpu-alternatives.notice') }}</p>
@if($errors->any())<div class="dm-note err" role="alert">{{ $errors->first() }}</div>@endif
<label class="ga-search">{{ __('gpu-alternatives.search') }}<input type="search" data-gpu-search autocomplete="off"></label>
<div class="ga-grid">
@forelse($cards as $card)
  <article class="pnl-sec ga-card" data-gpu-card data-gpu-name="{{ $card['gpu'] }}">
    <h2>{{ $card['gpu'] }}</h2>
    <p>{{ fa_num($card['cpu']) }} vCPU · {{ $card['ram'] }} · {{ $card['disk'] }}</p>
    @include('account.partials.gpu-live-capacity', ['capacitySlug' => $card['slug'], 'capacityUrl' => lroute('account.gpu.capacity')])
    <div class="ga-rate"><bdi>{{ cloud_price($card['rate']) }}</bdi><span>{{ __('ui.cvb_hourly_per') }}</span></div>
    <a class="pnl-btn primary" href="{{ lroute('account.cloud.gpu-alternative-preview', [$service, $card['id']]) }}">{{ __('gpu-alternatives.review') }}</a>
  </article>
@empty
  <p>{{ __('gpu-alternatives.empty') }}</p>
@endforelse
</div>
@endsection
