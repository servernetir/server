@extends('panel.layout')
@section('title', __('ui.sec_page_other'))

{{-- اتاقِ خدمات — عمداً کم‌جزئیات. روی این ردیف‌ها هیچ دادهٔ فنی‌ای نیست، و
     وانمود کردن به عکسش همان چیزی بود که صفحه را خالی نشان می‌داد. --}}

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.sec_other') }}</h1>
    <p>{{ __('ui.sec_other_lead') }}</p>
  </div>
</div>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'other'])
@include('account.partials.sec-other', ['items' => $services, 'room' => true])
@include('account.partials.svc-js')
@endsection
