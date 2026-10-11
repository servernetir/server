<div class="server-menu" data-server-menu>
  <div class="sm-main">
    <div class="sm-heading"><h3>{{ __('server-menu.choose') }}</h3><span>{{ __('server-menu.all_visible') }}</span></div>
    <div class="sm-types" aria-label="{{ __('server-menu.type') }}">
      @foreach(['cloud', 'dedicated'] as $smType)
      @if(count($serverMenu[$smType]))
      <button type="button" data-server-type="{{ $smType }}" aria-pressed="{{ $smType === 'cloud' ? 'true' : 'false' }}">{{ __('server-menu.'.$smType) }}</button>
      @endif
      @endforeach
    </div>
    <label class="sm-search"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><input type="search" autocomplete="off" placeholder="{{ __('server-menu.search') }}" aria-label="{{ __('server-menu.search') }}" data-server-search></label>
    @foreach(['cloud', 'dedicated'] as $smType)
    @if(count($serverMenu[$smType]))
    <section class="sm-locations" data-server-panel="{{ $smType }}" aria-label="{{ __('server-menu.'.$smType) }}">
      <h4 class="sm-fallback-heading">{{ __('server-menu.'.$smType) }}</h4>
      <div class="sm-countries">
        @foreach($serverMenu[$smType] as $smCountry)
        <a class="sm-country" href="{{ $smCountry['url'] }}" data-country="{{ $smCountry['iso'] }}" data-country-search="{{ $smCountry['search'] }}" aria-label="{{ $smCountry['label'] }}">
          @include('partials.flag', ['flagSrc' => $smCountry['flag'], 'flagSize' => 20, 'flagEager' => empty($landingPageCss), 'flagEmoji' => null])
          <span>{{ $smCountry['name'] }}</span>
        </a>
        @endforeach
      </div>
      <p class="sm-empty" hidden role="status">{{ __('server-menu.empty') }}</p>
    </section>
    @endif
    @endforeach
    @if($serverMenu['hourly'])<a class="sm-hourly" href="{{ $serverMenu['hourly']['url'] }}"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg><b>{{ $serverMenu['hourly']['label'] }}</b><span>{{ __('server-menu.hourly_hint') }}</span><svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>@endif
    @if(count($serverMenu['guidance']))
    <details class="sm-guidance"><summary>{{ __('server-menu.guidance') }}</summary><div>@foreach($serverMenu['guidance'] as $smLink)<a href="{{ $smLink['url'] }}">{{ $smLink['label'] }}</a>@endforeach</div></details>
    @endif
  </div>
  <div class="sm-side">
    @if($serverMenu['gpu'])<a class="sm-gpu" href="{{ $serverMenu['gpu']['url'] }}"><span class="sm-gpu-tag">GPU</span><svg class="icon" aria-hidden="true"><use href="#i-cpu"/></svg><b>{{ $serverMenu['gpu']['label'] }}</b><small>{{ __('server-menu.gpu_hint') }}</small></a>@endif
    @if($serverMenu['hardware'])
    <div class="sm-hardware"><h4>{{ __('server-menu.hardware') }}</h4><div class="sm-brands">@foreach(['hp' => 'HPE', 'dell' => 'DELL', 'ibm' => 'IBM'] as $smBrand => $smName)<a href="{{ lroute('servers.index', ['brand' => $smBrand]) }}" dir="ltr">{{ $smName }}</a>@endforeach</div><a class="sm-all" href="{{ lroute('servers.index') }}">{{ __('server-menu.other_brands') }} <svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a></div>
    @endif
    @if(count($serverMenu['parts']))<div class="sm-parts"><h4>{{ __('server-menu.parts') }}</h4>@foreach($serverMenu['parts'] as $smLink)<a href="{{ $smLink['url'] }}">{{ $smLink['label'] }}</a>@endforeach</div>@endif
  </div>
</div>
