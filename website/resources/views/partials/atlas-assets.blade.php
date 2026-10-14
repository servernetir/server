@push('head')
@php
  // Full page-specific styles stay in the head; the shared stylesheet remains synchronous.
  $atlasCssPath = public_asset_path('assets/css/atlas.min.css');
  $atlasInlineCss = $atlasCssPath ? @file_get_contents($atlasCssPath) : false;
@endphp
@if(is_string($atlasInlineCss) && $atlasInlineCss !== '' && !str_contains(strtolower($atlasInlineCss), '</style'))
<style id="sn-atlas-css">{!! $atlasInlineCss !!}</style>
@else
<link rel="stylesheet" href="{{ asset_ver('assets/css/atlas.css') }}">
@endif
<script src="{{ asset_ver('assets/js/atlas.min.js') }}" defer fetchpriority="low"></script>
@endpush
