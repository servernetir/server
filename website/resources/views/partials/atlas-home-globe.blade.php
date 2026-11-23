{{-- Geographic illustration; locale focus is independent of sales availability. --}}
<div class="sn-globe sn-home-globe" data-sn-home-globe data-land-src="{{ asset_ver('assets/js/atlas-land.js') }}" data-countries="{{ json_encode($atlasRouteCountries) }}" data-country="{{ $atlasDefault }}" data-approximate="true" tabindex="0" role="img" aria-label="{{ __('atlas.drag_globe') }}">
  <img class="sn-globe-fallback" src="{{ asset_ver(match(app()->getLocale()) { 'en'=>'assets/img/atlas-earth-us.svg', 'tr'=>'assets/img/atlas-earth-tr.svg', default=>'assets/img/atlas-earth.svg' }) }}" width="680" height="680" alt="{{ __('atlas.globe_alt') }}" decoding="async">
  <canvas class="sn-globe-canvas" aria-hidden="true"></canvas>
</div>
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/atlas-home-globe.css') }}">
<script src="{{ asset_ver('assets/js/cloud-store-geography.js') }}" defer fetchpriority="low"></script>
<script src="{{ asset_ver('assets/js/atlas-home-globe.js') }}" defer fetchpriority="low"></script>
@endpush
