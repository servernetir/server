{{-- Geographic illustration, not a network-status or latency measurement. --}}
<div class="sn-globe {{ ($wireframe ?? false) ? 'sn-globe-wire' : '' }}" data-sn-globe data-land-src="{{ asset_ver('assets/js/atlas-land.js') }}" data-wireframe="{{ ($wireframe ?? false) ? 'true' : 'false' }}">
  <img class="sn-globe-fallback" src="{{ asset_ver('assets/img/atlas-earth.svg') }}" width="680" height="680" alt="{{ __('atlas.globe_alt') }}" decoding="async" @if($wireframe ?? false) loading="lazy" @endif>
  <canvas class="sn-globe-canvas" aria-hidden="true"></canvas>
  <div class="sn-globe-coordinate" aria-hidden="true"><span>SERVERNET</span><span>IRAN / EUROPE / GLOBAL</span></div>
</div>
