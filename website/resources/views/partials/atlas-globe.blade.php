{{-- Geographic illustration, not a network-status or latency measurement. --}}
<div class="sn-globe {{ ($wireframe ?? false) ? 'sn-globe-wire' : '' }}" @unless($wireframe ?? false) data-sn-globe tabindex="0" role="img" aria-label="{{ __('atlas.drag_globe') }}" data-land-src="{{ asset_ver('assets/js/atlas-land.js') }}" @endunless data-wireframe="{{ ($wireframe ?? false) ? 'true' : 'false' }}">
  @if($wireframe ?? false)
    <img class="sn-globe-fallback" src="{{ asset_ver('assets/img/atlas-earth-wire.svg') }}" width="680" height="680" alt="{{ __('atlas.globe_alt') }}" decoding="async" loading="lazy">
  @else
    <div class="sn-globe-poster">
      <img class="sn-globe-fallback" src="{{ asset_ver(match(app()->getLocale()) { 'en'=>'assets/img/atlas-earth-us.svg', 'tr'=>'assets/img/atlas-earth-tr.svg', default=>'assets/img/atlas-earth.svg' }) }}" width="680" height="680" alt="{{ __('atlas.globe_alt') }}" decoding="async">
      @isset($atlasNodes) @include('partials.atlas-network-poster') @endisset
    </div>
    <canvas class="sn-globe-canvas" aria-hidden="true"></canvas>
    <span class="sn-orbit-label sn-orbit-label-a" aria-hidden="true">CLOUD</span>
    <span class="sn-orbit-label sn-orbit-label-b" aria-hidden="true">GPU / AI</span>
  @endif
  <div class="sn-globe-coordinate" aria-hidden="true"><span>SERVERNET</span><span>IRAN / EUROPE / GLOBAL</span></div>
</div>
