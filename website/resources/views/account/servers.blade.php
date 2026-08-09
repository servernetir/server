@extends('panel.layout')
@section('title', __('ui.sec_page_servers'))

{{-- اتاقِ سرور. کارت است نه جدول، و این عمدی است: IP و خطِ SSH داخلِ
     `.pnl-tw{overflow-x:auto}` روی گوشی افقی اسکرول می‌شدند. --}}

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.sec_servers') }}</h1>
    <p>{{ __('ui.sec_servers_lead') }}</p>
  </div>
</div>

@include('account.partials.lens', ['secCounts' => $secCounts, 'secLens' => 'servers'])
@include('account.partials.sec-servers', ['items' => $services, 'room' => true])
@include('account.partials.svc-js')
@endsection
