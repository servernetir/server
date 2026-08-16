@extends('layouts.site')

@section('title', __('ui.rp_unsub_t').' — '.__('ui.brand'))
@section('noindex', '1')

@section('content')
<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:640px;text-align:center">
      <span class="badge reveal"><span>{{ __('ui.rp_unsub_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.rp_unsub_t') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">
        {{ __('ui.rp_unsub_d', ['email' => $email]) }}
      </p>
      <p class="reveal" style="transition-delay:.22s">
        <a class="btn btn-glass" href="{{ lroute('home') }}">{{ __('ui.rp_unsub_home') }}</a>
      </p>
    </div>
  </div>
</section>
@endsection
