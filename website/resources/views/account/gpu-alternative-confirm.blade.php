@extends('panel.layout')
@section('title', __('gpu-alternatives.review'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/gpu-alternatives.css') }}">
@endpush
@section('panel')
<div class="pnl-head"><div><h1>{{ __('gpu-alternatives.review') }}</h1><p>{{ $plan->gpu_model }}</p></div></div>
<section class="pnl-sec ga-confirm">
  <p>{{ fa_num($plan->vcpu) }} vCPU · {{ $plan->ramLabel() }} · {{ $plan->diskLabel() }}</p>
  @if($errors->any())<div class="dm-note err" role="alert">{{ $errors->first() }}</div>@endif
  @if($capacity['ok'] && $capacity['available'] === true && $rate !== null)
    <p class="ga-status" data-state="available">{{ __('gpu-alternatives.available', ['count' => fa_num($capacity['count'])]) }}</p>
    <p>{{ __('gpu-alternatives.checked') }} <time datetime="{{ $capacity['checked_at'] }}">{{ stime(\Illuminate\Support\Carbon::parse($capacity['checked_at'])) }}</time></p>
    <dl class="ga-facts">
      <div><dt>{{ __('gpu-alternatives.rate') }}</dt><dd><bdi>{{ cloud_price($rate) }}</bdi></dd></div>
      <div><dt>{{ __('gpu-alternatives.minimum') }}</dt><dd><bdi>{{ cloud_price($minimum) }}</bdi></dd></div>
      <div><dt>{{ __('gpu-alternatives.balance') }}</dt><dd><bdi>{{ cloud_price($available) }}</bdi></dd></div>
      <div><dt>{{ __('gpu-alternatives.shortfall') }}</dt><dd><bdi>{{ cloud_price(max(0, $minimum - $available)) }}</bdi></dd></div>
    </dl>
    <p class="ga-notice">{{ __('gpu-alternatives.consent_note') }}</p>
    <form method="post" action="{{ lroute('account.cloud.gpu-alternative-replace', $service) }}">
      @csrf
      <input type="hidden" name="plan_id" value="{{ $plan->id }}">
      <input type="hidden" name="quoted_rate_irt" value="{{ $rate }}">
      <label class="ga-consent"><input type="checkbox" name="confirm" value="1" required> {{ __('gpu-alternatives.consent', ['gpu' => $plan->gpu_model, 'rate' => cloud_price($rate)]) }}</label>
      <button class="pnl-btn primary" type="submit">{{ __('gpu-alternatives.confirm') }}</button>
    </form>
  @else
    <p role="status">{{ __('gpu-alternatives.changed') }}</p>
  @endif
  <a class="pnl-btn" href="{{ lroute('account.cloud.gpu-alternatives', $service) }}">{{ __('gpu-alternatives.back_list') }}</a>
</section>
@endsection
