{{-- Geographic illustration, not a network-status or latency measurement. --}}
<div class="sn-globe {{ ($wireframe ?? false) ? 'sn-globe-wire' : '' }}" @unless($wireframe ?? false) data-sn-globe data-land-src="{{ asset_ver('assets/js/atlas-land.js') }}" @endunless data-wireframe="{{ ($wireframe ?? false) ? 'true' : 'false' }}">
  <img class="sn-globe-fallback" src="{{ asset_ver(($wireframe ?? false) ? 'assets/img/atlas-earth-wire.svg' : 'assets/img/atlas-earth.svg') }}" width="680" height="680" alt="{{ __('atlas.globe_alt') }}" decoding="async" @if($wireframe ?? false) loading="lazy" @endif>
  @unless($wireframe ?? false)<canvas class="sn-globe-canvas" aria-hidden="true"></canvas>@endunless
  <div class="sn-globe-coordinate" aria-hidden="true"><span>SERVERNET</span><span>IRAN / EUROPE / GLOBAL</span></div>
</div>
