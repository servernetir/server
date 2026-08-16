@extends('layouts.site')

@php
    $seoMeta = \App\Support\AuditViewData::meta();
@endphp

@section('title', __('ui.rp_title', ['host' => $report->host]).' — '.__('ui.brand'))
@section('description', __('ui.rp_desc', ['host' => $report->host]))

{{-- 🔴 گزارشِ سایتِ **کسِ دیگر** است. ایندکس‌شدنش یعنی نمرهٔ سایتِ یک شرکت در
     نتایجِ گوگل بنشیند بی‌آنکه خودش خواسته باشد — و یعنی هزاران صفحهٔ نازکِ
     تکراری روی دامنهٔ ما. --}}
@section('noindex', '1')

@section('content')

<section class="hero hero-sub" style="padding-bottom:30px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:820px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.rp_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.rp_h1') }} <span class="grad" dir="ltr">{{ $report->host }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.rp_lead') }}</p>

      <p class="rp-when reveal" style="transition-delay:.22s">
        {{ __('ui.rp_made_on', ['date' => sdate($report->created_at)]) }}
      </p>

      {{-- 🔴 گزارشِ کهنه باید **بگوید** که کهنه است.
           بررسی عکسِ لحظه‌ای است؛ سایت از آن روز تا امروز عوض شده. گزارشی که
           تاریخش را پنهان کند، به صاحبِ سایت دربارهٔ وضعیتِ امروزش دروغ می‌گوید
           — و اگر او بر اساسش تصمیم بگیرد، اعتبارِ ما رفته است. --}}
      @if($report->isStale())
        <div class="rp-stale reveal" style="transition-delay:.28s">
          <svg class="icon"><use href="#i-shield"/></svg>
          <span>{{ __('ui.rp_stale') }}</span>
          <a href="{{ lroute('tools', 'seo') }}">{{ __('ui.rp_stale_cta') }}</a>
        </div>
      @endif
    </div>
  </div>
</section>

@include('partials.tools._audit-results', [
    'reportMode' => true,
    'printDate'  => sdate($report->created_at),
    'printUrl'   => $report->url(),
])

{{-- ⚠️ ترتیب مهم است: این دو inline‌اند و همان لحظهٔ پارس اجرا می‌شوند، ولی
     `tools.js` با `defer` بعد از پارسِ کلِ سند می‌دود — پس وقتی به
     `window.SEO_META` می‌رسد، حتماً ست شده است. --}}
<script>
window.SEO_META = @json($seoMeta);
window.AUDIT_DATA = @json($report->result);
</script>
<script src="{{ asset_ver('assets/js/tools.js') }}" defer></script>
@endsection
