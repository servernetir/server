@php
  // Approximate geographic centres; links and available countries come from the public menu.
  $atlasMenuConfig = app(\App\Services\MenuManager::class)->mega(app(\App\Services\SiteMenu::class)->mega());
  $atlasMenu = app(\App\Services\ServerMenu::class)->build($atlasMenuConfig['vps'] ?? []);
  $atlasCoordinates = [
    'IR'=>[32.4,53.6], 'TR'=>[39,35], 'US'=>[39,-98], 'DE'=>[51.2,10.4], 'FR'=>[46.2,2.2], 'NL'=>[52.1,5.3],
    'GB'=>[54,-2], 'FI'=>[64,26], 'SE'=>[62,15], 'AT'=>[47.5,14.5], 'RU'=>[56,60], 'CA'=>[56,-106],
    'AE'=>[24,54], 'SA'=>[24,45], 'BH'=>[26,50.6], 'QA'=>[25.3,51.2], 'KW'=>[29.3,47.5], 'IQ'=>[33,44],
    'SG'=>[1.35,103.8], 'JP'=>[36,138], 'KR'=>[36,128], 'CN'=>[35,105], 'HK'=>[22.3,114.2], 'TW'=>[24,121],
    'VN'=>[16,108], 'PH'=>[13,122], 'TH'=>[15,101], 'MY'=>[4,102], 'ID'=>[-2,118], 'KH'=>[13,105],
    'BD'=>[24,90], 'IN'=>[22,79], 'NP'=>[28,84], 'MM'=>[21,96], 'PK'=>[30,69], 'AU'=>[-25,134],
    'BR'=>[-14,-52], 'AR'=>[-34,-64], 'CL'=>[-33,-71], 'CO'=>[4,-74], 'EC'=>[-1,-78], 'MX'=>[24,-102],
    'BG'=>[43,25], 'RO'=>[46,25], 'GR'=>[39,22], 'IT'=>[43,12], 'ES'=>[40,-4], 'PT'=>[39,-8],
    'PL'=>[52,19], 'CZ'=>[50,15], 'CH'=>[47,8], 'IE'=>[53,-8], 'BE'=>[51,4], 'DK'=>[56,10],
    'NO'=>[62,10], 'HU'=>[47,19], 'ZA'=>[-30,25], 'EG'=>[27,30], 'UA'=>[49,32], 'NZ'=>[-41,174],
  ];
  $atlasNodes = [];
  foreach ($atlasMenu['cloud'] as $iso => $country) {
    if (isset($atlasCoordinates[$iso])) {
      $atlasNodes[$iso] = ['position'=>$atlasCoordinates[$iso], 'label'=>$country['name'], 'url'=>$country['url']];
    }
  }
  $atlasDefault = match(app()->getLocale()) { 'tr'=>'TR', 'en'=>'US', default=>\App\Services\Customer\IranSalesVisibility::allowsCountry('IR') ? 'IR' : 'DE' };
  $atlasLabels = ['IR'=>'iran','TR'=>'turkey','US'=>'usa','DE'=>'germany','FR'=>'france','NL'=>'netherlands'];
  $atlasPicker = array_values(array_unique(array_merge([$atlasDefault], ['US','TR','DE','FR','NL','IR'])));
  $atlasPicker = array_values(array_filter($atlasPicker, fn ($iso) => \App\Services\Customer\IranSalesVisibility::allowsCountry($iso)));
  foreach ($atlasPicker as $iso) {
    $atlasNodes[$iso] ??= ['position'=>$atlasCoordinates[$iso], 'label'=>__('atlas.'.$atlasLabels[$iso]), 'url'=>lroute('cloud.index')];
  }
@endphp
<div class="sn-atlas-display" data-sn-region-group data-sn-selected="{{ $atlasDefault }}" data-sn-nodes="{{ json_encode($atlasNodes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}">
  @include('partials.atlas-globe')
  <div class="sn-region-detail" aria-live="polite">
    <div><span class="sn-region-code" aria-hidden="true">REGION</span><strong data-sn-region-label>{{ $atlasNodes[$atlasDefault]['label'] }}</strong></div>
    <p data-sn-region-description>{{ __('atlas.region_'.$atlasLabels[$atlasDefault]) }}</p>
    <a data-sn-region-link href="{{ $atlasNodes[$atlasDefault]['url'] }}">{{ __('atlas.view_region') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
  </div>
  <div class="sn-region-picker" role="group" aria-label="{{ __('atlas.regions') }}">
    @foreach($atlasPicker as $iso)
    <button type="button" data-sn-region="{{ $iso }}" data-label="{{ $atlasNodes[$iso]['label'] }}" data-description="{{ __('atlas.region_'.$atlasLabels[$iso]) }}" data-url="{{ $atlasNodes[$iso]['url'] }}" aria-pressed="{{ $iso === $atlasDefault ? 'true' : 'false' }}">{{ $atlasNodes[$iso]['label'] }}</button>
    @endforeach
  </div>
  <p class="sn-map-note">{{ __('atlas.globe_note') }} <span>{{ __('atlas.drag_globe') }}</span></p>
</div>
