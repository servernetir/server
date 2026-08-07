{{--
  500 — تا مرداد ۱۴۰۵ این حالت صفحهٔ خامِ لاراول را نشان می‌داد: انگلیسی،
  بی‌طراحیِ سایت، و بی‌راهِ برگشت. کاربری که وسطِ تسویه به آن می‌خورد، نمی‌دانست
  پولش چه شد.
--}}
@extends('layouts.site')

@section('title', '500 — '.__('ui.brand'))

@section('content')
@php $p = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? ''; @endphp
<section class="hero hero-sub err-page" style="min-height:70vh;display:flex;align-items:center">
  <div class="container">
    <div class="hero-sub-inner">
      <div class="err-code reveal">
        <span>5</span>
        <span class="err-orb"><svg class="icon"><use href="#i-server"/></svg></span>
        <span>0</span>
      </div>

      <h1 class="reveal" style="transition-delay:.08s">خطایی <span class="grad">رخ داد</span></h1>

      <p class="lead reveal" style="transition-delay:.16s">مشکلی در سمتِ ما پیش آمد و تیم فنی خودکار خبردار شد. اگر در حالِ پرداخت بودید، مبلغ کسر نشده یا خودکار برمی‌گردد.</p>

      <div class="hero-ctas reveal" style="transition-delay:.24s;justify-content:center">
        <a class="btn btn-primary" href="{{ route($p.'home') }}">
          <span>{{ __('ui.e404_home') }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
        </a>
        <a class="btn btn-glass" href="{{ route($p.'contact') }}">{{ __('ui.nav_contact') }}</a>
      </div>
    </div>
  </div>
</section>
@endsection
