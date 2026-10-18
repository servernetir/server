{{-- One catalogue location code per link. Country disclosure never changes order state. --}}
<section class="pnl-sec cvb-step cvx-location" id="cvb-step-1" data-step="1">
  <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-1">
    <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('cloud-store.location') }}</b></span>
    <span class="cvb-step-v"><span>{{ $locLabel }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
  </button></h2>
  <div class="cvb-step-b" id="cvb-b-1"><div class="cvb-step-i"><div class="pnl-sec-b">
    @error('location')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
    <div class="cvx-explorer">
      <div class="cvx-picker">
    <div class="cvx-section-head">
      <div><p class="cvb-eyebrow">{{ __('ui.cvb_step_idx', ['n' => fa_num(1), 't' => fa_num($stepTotal)]) }}</p>
        <h3 class="cvb-q" tabindex="-1">{{ __('cloud-store.location_title') }}</h3>
        <p class="cvb-lede">{{ __('cloud-store.location_hint') }}</p></div>
    </div>

        <label class="cvx-search" for="cvx-search"><svg class="icon"><use href="#i-search"/></svg>
          <input type="search" id="cvx-search" placeholder="{{ __('cloud-store.search') }}" aria-label="{{ __('cloud-store.search') }}" autocomplete="off" aria-controls="cvx-results">
          <kbd aria-hidden="true">Ctrl K</kbd>
        </label>
        <div class="cvx-regions" role="group" aria-label="{{ __('cloud-store.regions') }}">
          @foreach(['all' => __('cloud-store.everywhere')] + $regionOrder as $key => $label)
            <button type="button" data-cvx-region="{{ $key }}" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}">{{ $label }}</button>
          @endforeach
        </div>
        <div class="cvx-result-heading"><b>{{ __('cloud-store.available_locations') }}</b><span id="cvx-count" role="status" aria-live="polite">{{ fa_num(count($groups)) }} {{ __('cloud-store.countries') }}</span></div>
        <div class="cvx-results" id="cvx-results" tabindex="0" aria-label="{{ __('cloud-store.location') }}">
          @foreach($groups as $g)
            @php $gShut = (int) ($g['openCities'] ?? 0) === 0; $gOn = strtoupper($g['country']) === $curCountry; @endphp
            <details class="cvx-country" data-region="{{ $g['region'] ?? 'other' }}" data-country="{{ strtoupper($g['country']) }}" @if($gOn) open @endif>
              <summary class="cvb-nat @if($gShut) is-shut @endif @if($gOn) on @endif" data-nat="{{ $g['country'] }}" data-label="{{ $g['label'] }}">
                <span class="cvb-flag">@include('partials.flag', ['flagSrc' => $g['flag_svg'] ?? null, 'flagEmoji' => $g['flag'], 'flagSize' => 24])</span>
                <span class="cvb-nat-t"><b>{{ $g['label'] }}</b><small>{{ $g['country'] }} @if((int) ($g['openCities'] ?? 0) > 1) · {{ trans_choice('ui.cvb_c_cities', (int) $g['openCities'], ['n' => fa_num((int) $g['openCities'])]) }} @endif</small></span>
                <span class="cvb-nat-p">@if($gShut)<em class="cvb-outb">{{ __('ui.cvb_c_soldout') }}</em>@elseif((int) ($g['fromIrt'] ?? 0) > 0)
                  <b>{{ __('ui.cvb_from', ['amount' => cloud_price((int) $g['fromIrt'])]) }} {{ __('ui.mo') }}</b>
                  @if((int) ($g['hourlyFromIrt'] ?? 0) > 0)<small>{{ __('ui.cvb_from', ['amount' => cloud_price((int) $g['hourlyFromIrt'])]) }}{{ __('ui.cvb_hourly_per') }}</small>@endif
                @endif</span><span class="cvx-chevron" aria-hidden="true">⌄</span>
              </summary>
              <div class="cvb-cities">
                @foreach($g['cities'] as $c)
                  @foreach($c['members'] as $mi => $m)
                    @php
                      $mCode = (string) $m->code;
                      $mOpen = in_array($mCode, (array) $openCodes, true);
                      $mLow = (int) ($anchors[$mCode]['irt'] ?? 0);
                      $mLabel = $c['label'].($c['n'] > 1 ? ' · '.__('ui.cvb_dc_n', ['n' => fa_num($mi + 1)]) : '');
                    @endphp
                    <a class="cvb-city @if(! $mOpen) is-shut @endif @if($mCode === (string) $locCode) on @endif" data-city="{{ $mCode }}" data-label="{{ $g['label'].' — '.$mLabel }}" data-city-label="{{ $c['generic'] ? '' : $m->city }}" data-approximate="{{ $c['generic'] ? 'true' : 'false' }}" @if($mOpen) href="{{ $cityHref($mCode) }}" @else role="link" aria-disabled="true" tabindex="-1" @endif @if($mCode === (string) $locCode) aria-current="true" @endif>
                      <span class="cvx-city-dot" aria-hidden="true"></span><span class="cvb-city-t"><b>{{ $c['generic'] ? $g['label'].' · '.$mLabel : $mLabel }}</b>
                        @if(! $mOpen)<em class="cvb-outb">{{ __('ui.cvb_c_soldout') }}</em>@elseif($mLow > 0)<small>{{ __('ui.cvb_from', ['amount' => cloud_price($mLow)]) }} {{ __('ui.mo') }}</small>@endif
                      </span><svg class="icon cvx-city-check" aria-hidden="true"><use href="#i-check"/></svg>
                    </a>
                  @endforeach
                @endforeach
              </div>
            </details>
          @endforeach
          <div class="cvx-empty" id="cvx-empty" hidden><svg class="icon"><use href="#i-search"/></svg><b>{{ __('cloud-store.no_results') }}</b><p>{{ __('cloud-store.try_search') }}</p></div>
        </div>
        <p class="cvx-lock-note">{{ __('ui.cvb_loc_note') }}</p>
        <p class="cvx-price-note">{{ __('cloud-store.price_note') }}</p>
      </div>
      <div class="cvx-globe-panel">
        <div class="cvx-orbit-label"><span class="cvx-status-dot"></span> SERVERNET <span>/</span> GLOBAL CLOUD</div>
        <div id="cvx-globe" class="cvx-globe" tabindex="0" role="img" aria-label="{{ __('cloud-store.globe_controls') }}" data-land-src="{{ asset_ver('assets/js/atlas-land.js') }}" data-country="{{ $curCountry }}" data-approximate-label="{{ __('cloud-store.country_pin') }}" data-exact-label="{{ __('cloud-store.city_pin') }}">
          <img class="cvx-globe-fallback" src="{{ asset_ver('assets/img/atlas-earth.svg') }}" alt="{{ __('cloud-store.globe_alt') }}" width="680" height="680">
          <canvas aria-hidden="true"></canvas>
        </div>
        <div class="cvx-globe-caption"><span class="cvx-caption-k">{{ __('cloud-store.exploring') }}</span><strong id="cvx-globe-name">{{ $locLabel }}</strong><span id="cvx-globe-detail">{{ __('cloud-store.country_pin') }}</span><span id="cvx-globe-coordinates" dir="ltr"></span></div>
        <div class="cvx-globe-footer"><span><svg class="icon"><use href="#i-pin"/></svg>{{ __('cloud-store.globe_drag_hint') }}</span><span dir="ltr">01 / GLOBAL</span></div>
      </div>
    </div>
  </div></div></div>
</section>
