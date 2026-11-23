@extends('layouts.site')
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/panel.css') }}">
<link rel="stylesheet" href="{{ asset_ver('assets/css/cloud-store-public.css') }}">
@stack('panel-head')
@endpush
@section('content')
<section class="pnl-wrap cvb-public"><div class="container"><div class="pnl-main">
  <p class="cvb-public-note"><svg class="icon" aria-hidden="true"><use href="#i-cloud"/></svg>{{ __('cloud_store_guest.browse') }}<span>{{ __('cloud_store_guest.note') }}</span></p>
  @yield('panel')
</div></div></section>
@include('partials.ui-dialog')
@endsection
