@extends('panel.layout')
@section('title', __('ui.cvb_gpu_title'))
@section('panel')
<section class="pnl-sec">
  <div class="pnl-sec-h"><h1>{{ __('gpu.resuming') }}</h1></div>
  <div class="pnl-sec-b">
    <p>{{ __('gpu.resume_note') }}</p>
    <p><b>{{ $intent['label'] }}</b> · {{ \App\Services\Cloud\SaladClient::APPS[$intent['image']]['label'] ?? $intent['image'] }}</p>
    <p>{{ cloud_price($intent['quoted_hourly_irt']) }} {{ __('ui.gpu_per_hour') }}</p>
    <form method="post" action="{{ lroute('account.gpu.complete') }}" id="gpu-resume-form">
      @csrf
      <input type="hidden" name="intent_key" value="{{ $intent['gpu_request_key'] }}">
      <button class="pnl-btn" type="submit">{{ __('ui.gpu_cta') }}</button>
    </form>
  </div>
</section>
<script>document.getElementById('gpu-resume-form').requestSubmit();</script>
@endsection
