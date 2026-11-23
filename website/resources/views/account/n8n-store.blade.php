@extends('panel.layout')
@section('title', __('n8n.order'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/n8n.css') }}">
@endpush
@section('panel')
<div class="n8n-store">
  <div class="pnl-head"><div><span class="n8n-eyebrow">n8n Community</span><h1>{{ __('n8n.configure') }}</h1><p>{{ __('n8n.setup') }}</p></div></div>
  @if($errors->any())<div class="dm-note danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
  @php
    $chosen = collect($n8nCards)->first(fn ($p) => $p['slug'] === request('plan') && $p['location'] === request('location'));
  @endphp
  @if($chosen && $n8nEnabled)
    <a href="{{ lroute('account.cloud.store', ['application'=>'n8n']) }}">{{ __('n8n.back') }}</a>
    <section class="n8n-order">
      <div><h2>{{ $chosen['name'] }} · {{ __('n8n.'.$chosen['country']) }}</h2><p>{{ fa_num($chosen['cpu']) }} vCPU · {{ $chosen['ram'] }} RAM · {{ $chosen['disk'] }}</p><p class="n8n-small">{{ __('n8n.independent') }}</p></div>
      <form method="post" action="{{ lroute('account.cloud.store.place') }}">
        @csrf
        <input type="hidden" name="application" value="n8n">
        <input type="hidden" name="location" value="{{ $chosen['location'] }}">
        <input type="hidden" name="plan" value="{{ $chosen['slug'] }}">
        <input type="hidden" name="image" value="{{ $chosen['image'] }}">
        <label class="n8n-field">{{ __('n8n.cycle') }}
          <select name="cycle">
            @foreach($chosen['cycles'] as $cycle => $amount)
              <option value="{{ $cycle }}" @selected(old('cycle','monthly') === $cycle)>{{ __('n8n.'.$cycle) }} · {{ cloud_price($amount) }}</option>
            @endforeach
          </select>
        </label>
        @if($chosen['hourly'] > 0)
          <label class="n8n-check"><input type="checkbox" name="billing_mode" value="hourly" @checked(old('billing_mode') === 'hourly')> {{ __('n8n.hourly') }} · {{ cloud_price($chosen['hourly']) }} {{ __('n8n.per_hour') }}</label>
          <p class="n8n-small">{{ __('n8n.hourly_notice') }}</p>
        @else
          <input type="hidden" name="billing_mode" value="cycle">
          <p class="n8n-small">{{ __('n8n.turkey_monthly') }}</p>
        @endif
        <label class="n8n-field">{{ __('n8n.name') }}<input name="label" maxlength="32" value="{{ old('label', 'my-n8n') }}" autocomplete="off"></label>
        <p><strong>{{ __('n8n.email') }}</strong><br><span dir="ltr">{{ auth('customer')->user()->email }}</span></p>
        <p class="n8n-small">{{ __('n8n.tax_notice') }}</p>
        <button class="n8n-button" type="submit">{{ __('n8n.pay') }}</button>
      </form>
    </section>
  @else
    @if(! $n8nEnabled)<p class="n8n-notice">{{ __('n8n.disabled') }}</p>@endif
    @include('partials.n8n-plans')
  @endif
  <p class="n8n-small">{{ __('n8n.scope') }}</p>
</div>
@endsection
