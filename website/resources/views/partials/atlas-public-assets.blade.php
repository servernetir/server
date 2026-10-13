@php
  $atlasPublicCssPath = public_asset_path('assets/css/atlas-public.min.css');
  $atlasPublicCss = $atlasPublicCssPath ? @file_get_contents($atlasPublicCssPath) : false;
@endphp
@if(is_string($atlasPublicCss) && $atlasPublicCss !== '' && !str_contains(strtolower($atlasPublicCss), '</style'))
<style id="sn-public-css">{!! $atlasPublicCss !!}</style>
@else
<link rel="stylesheet" href="{{ asset_ver('assets/css/atlas-public.css') }}">
@endif
