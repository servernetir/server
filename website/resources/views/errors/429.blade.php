{{--
  ۴۲۹ — سقفِ نرخ.
  🔴 تا مرداد ۱۴۰۵ فقط ۴۰۴ ویو داشت، پس هر برخورد با throttle صفحهٔ خامِ
  انگلیسیِ «Too Many Requests» می‌داد: بی‌متنِ فارسی، بی‌راهِ برگشت، و بی‌هیچ
  سرنخی از اینکه چقدر باید صبر کرد.
  ⚠️ این صفحه در مسیرِ ورود و ثبت‌نام دیده می‌شود — یعنی دقیقاً وقتی که کاربر
  از قبل کلافه است و کوچک‌ترین ابهام یعنی رفتن.
--}}
@extends('layouts.site')

@section('title', '۴۲۹ — '.__('ui.brand'))

@section('content')
@php
  $p = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
  // ⚠️ لاراول ثانیهٔ باقی‌مانده را در همین هدر می‌گذارد؛ اگر نبود، عددِ ساختگی
  //    نشان نمی‌دهیم — حدسِ اشتباه بدتر از نگفتن است.
  $retry = (int) (request()->headers->get('Retry-After') ?? 0);
@endphp
<section class="hero hero-sub err-page" style="min-height:70vh;display:flex;align-items:center">
  <div class="container">
    <div class="hero-sub-inner">
      <div class="err-code reveal">
        <span>4</span>
        <span class="err-orb"><svg class="icon"><use href="#i-clock"/></svg></span>
        <span>9</span>
      </div>

      <h1 class="reveal" style="transition-delay:.08s">
        کمی <span class="grad">صبر کنید</span>
      </h1>

      <p class="lead reveal" style="transition-delay:.16s">
        درخواست‌های شما در بازهٔ کوتاهی زیاد بوده و موقتاً محدود شده‌اید.
        @if($retry > 0)
          حدود <b>{{ fa_num((int) ceil($retry / 60) ?: 1) }}</b> دقیقهٔ دیگر دوباره تلاش کنید.
        @else
          چند دقیقهٔ دیگر دوباره تلاش کنید.
        @endif
      </p>

      <p class="lead reveal" style="transition-delay:.2s;font-size:14px;opacity:.8">
        این محدودیت برای جلوگیری از سوءاستفاده است و روی حسابِ شما اثری ندارد.
        اگر کدِ ورود دستتان نرسیده، صندوقِ ایمیل و پیام‌های بله را هم ببینید.
      </p>

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
