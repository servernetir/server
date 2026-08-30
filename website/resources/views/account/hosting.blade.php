@extends('panel.layout')
@section('title', __('ui.sec_page_hosting'))

{{-- اتاقِ هاست. هیچ padding-top این‌جا نیست: فضای هدرِ ثابت را `#main` یک‌جا
     رزرو می‌کند (CLAUDE.md §۳ «قانونِ هدرِ ثابت»). --}}

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.sec_hosting') }}</h1>
    <p>{{ __('ui.sec_hosting_lead') }}</p>
  </div>
</div>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'hosting'])
@include('account.partials.sec-hosting', ['items' => $services, 'room' => true])
@include('account.partials.svc-js')
@endsection
