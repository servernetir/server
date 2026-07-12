@extends('layouts.site')

@section('title', '404 — '.__('ui.brand'))

@section('content')
@php $loc = app()->getLocale(); @endphp
<section class="hero hero-sub err-page" style="min-height:70vh;display:flex;align-items:center">
  <div class="container">
    <div class="hero-sub-inner">
      <div class="err-code reveal">
        <span>4</span>
        <span class="err-orb"><svg class="icon"><use href="#i-server"/></svg></span>
        <span>4</span>
      </div>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.e404_h1a') }} <span class="grad">{{ __('ui.e404_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.e404_lead') }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s" style="justify-content:center">
        <a class="btn btn-primary" href="{{ route(($p = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '').'home') }}"><span>{{ __('ui.e404_home') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="{{ route($p.'contact') }}">{{ __('ui.nav_contact') }}</a>
      </div>
    </div>
  </div>
</section>
@endsection
