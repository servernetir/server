@php
  $queryFor = fn (array $values) => $n8nConsole
      ? lroute('account.cloud.store', ['application' => 'n8n'] + $values)
      : lroute('catalog', ['category' => 'cloud', 'slug' => 'n8n'] + $values);
  $wantedLocation = old('location', request('location', ''));
  $wantedPlan = old('plan', request('plan', ''));
  $chosen = collect($n8nCards)->first(fn ($p) => $p['slug'] === $wantedPlan && $p['location'] === $wantedLocation);
  $countryInput = request('country', is_string($wantedLocation) ? substr($wantedLocation, 0, 2) : '');
  $wizardCountry = $chosen['country'] ?? (is_string($countryInput) ? strtoupper($countryInput) : '');
  $wizardCountry = in_array($wizardCountry, $n8nCountries, true) ? $wizardCountry : '';
  $wizardCycle = old('cycle', request('cycle', 'monthly'));
  $wizardCycle = in_array($wizardCycle, ['monthly','quarterly','semiannual','yearly'], true) ? $wizardCycle : 'monthly';
  $wizardHourly = $chosen && $chosen['hourly'] > 0 && old('billing_mode', request('billing_mode')) === 'hourly';
  $wizardLabel = old('label', request('label', 'my-n8n'));
  $wizardLabel = is_string($wizardLabel) ? mb_substr($wizardLabel, 0, 32) : 'my-n8n';
  $wizardData = array_map(function ($card) {
      $card['prices'] = array_map(fn ($amount) => cloud_price($amount), $card['cycles']);
      $card['hourlyPrice'] = cloud_hourly_price($card['hourly']);
      $card['countryLabel'] = __('n8n.'.$card['country']);
      return $card;
  }, $n8nCards);
  $wizardLabels = ['monthly'=>__('n8n.monthly'),'quarterly'=>__('n8n.quarterly'),'semiannual'=>__('n8n.semiannual'),'yearly'=>__('n8n.yearly'),'hourly'=>__('n8n.per_hour')];
@endphp
<section class="n8n-config" id="configure" data-n8n-config data-country="{{ $wizardCountry }}" data-enabled="{{ $n8nEnabled ? '1' : '0' }}">
  <div class="n8n-config-title"><div><p class="n8n-eyebrow">{{ __('n8n.configure_eyebrow') }}</p><h2>{{ __('n8n.configure_title') }}</h2></div><span class="n8n-included"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('n8n.setup') }}</span></div>
  <nav class="n8n-progress" aria-label="{{ __('n8n.configure') }}">
    <a href="#n8n-country"><span>{{ fa_num(1) }}</span>{{ __('n8n.country_step') }}</a><a href="#n8n-packages"><span>{{ fa_num(2) }}</span>{{ __('n8n.package_step') }}</a><a href="#n8n-details"><span>{{ fa_num(3) }}</span>{{ __('n8n.details_step') }}</a>
  </nav>
  @if(! $n8nEnabled)<p class="n8n-notice">{{ __('n8n.disabled') }}</p>@endif
  <div class="n8n-config-grid">
    <div class="n8n-config-main">
      <section class="n8n-config-step" id="n8n-country" aria-labelledby="n8n-country-heading">
        <div class="n8n-step-title"><h3 id="n8n-country-heading">{{ __('n8n.country_step') }}</h3><p>{{ __('n8n.country_hint') }}</p></div>
        <div class="n8n-countries">
          @foreach($n8nCountries as $country)
          <a class="n8n-country-choice {{ $wizardCountry === $country ? 'is-selected' : '' }}" data-n8n-country="{{ $country }}" href="{{ $queryFor(['country' => $country]) }}#configure" @if($wizardCountry === $country) aria-current="true" @endif>
            <img src="{{ asset_ver('assets/flags/'.strtolower($country).'.svg') }}" alt="" width="40" height="29"><strong>{{ __('n8n.'.$country) }}</strong><small>{{ __('n8n.country_'.strtolower($country).'_note') }}</small><span class="n8n-choice-check" aria-hidden="true">✓</span>
          </a>
          @endforeach
        </div>
      </section>
      <section class="n8n-config-step" id="n8n-packages" aria-labelledby="n8n-packages-heading">
        <div class="n8n-step-title"><h3 id="n8n-packages-heading">{{ __('n8n.package_step') }}</h3><p>{{ __('n8n.package_hint') }}</p></div>
        <p class="n8n-placeholder" data-n8n-country-prompt @if($wizardCountry !== '') hidden @endif>{{ __('n8n.select_country_first') }}</p>
        @foreach($n8nCountries as $country)
        @php($available = collect($n8nCards)->filter(fn ($p) => $p['country'] === $country))
        <div data-n8n-country-packages="{{ $country }}" @if($wizardCountry !== $country) hidden @endif>
          @if($available->isEmpty())
            <div class="n8n-notice">@if($country === 'IR' && $n8nIranRestricted)<p>{{ __('n8n.iran_verification') }}</p><a class="n8n-button n8n-button--small" href="{{ lroute('account.profile') }}">{{ __('n8n.verify_profile') }}</a>@else<p>{{ __('n8n.unavailable') }}</p>@endif</div>
          @else
          <div class="n8n-packages">
            @foreach($n8nCards as $index => $card)
            @continue($card['country'] !== $country)
            <a class="n8n-package {{ $chosen && $chosen['slug'] === $card['slug'] && $chosen['location'] === $card['location'] ? 'is-selected' : '' }}" data-n8n-plan="{{ $index }}" href="{{ $queryFor(['country' => $country, 'location' => $card['location'], 'plan' => $card['slug']]) }}#n8n-details">
              <div class="n8n-package-top"><strong>{{ $card['name'] }}</strong><span>{{ ucfirst(str_replace('-', ' ', substr($card['location'], 3))) }}</span></div>
              <dl><div><dt>{{ __('n8n.cpu') }}</dt><dd><bdi>{{ fa_num($card['cpu']) }}</bdi> vCPU</dd></div><div><dt>{{ __('n8n.ram') }}</dt><dd><bdi>{{ $card['ram'] }}</bdi></dd></div><div><dt>{{ __('n8n.disk') }}</dt><dd><bdi>{{ $card['disk'] }}</bdi></dd></div></dl>
              @if($card['starter'] ?? false)<p class="n8n-small">{{ __('n8n.starter_note') }}</p>@endif
              <div class="n8n-package-price"><span><b>{{ cloud_price($card['cycles']['monthly']) }}</b><small>{{ __('n8n.per_month') }}</small></span><span class="n8n-choice-check" aria-hidden="true">✓</span></div>
            </a>
            @endforeach
          </div>
          @endif
        </div>
        @endforeach
      </section>
      <section class="n8n-config-step" id="n8n-details" aria-labelledby="n8n-details-heading" @if(! $chosen) hidden @endif data-n8n-details>
        <div class="n8n-step-title"><h3 id="n8n-details-heading">{{ __('n8n.details_step') }}</h3><p>{{ __('n8n.details_hint') }}</p></div>
        <form method="{{ $n8nConsole ? 'post' : 'get' }}" action="{{ $n8nConsole ? lroute('account.cloud.store.place') : lroute('account.cloud.store', ['application' => 'n8n']) }}" data-n8n-order>
          @if($n8nConsole) @csrf @endif
          <input type="hidden" name="application" value="n8n"><input type="hidden" name="location" value="{{ $chosen['location'] ?? '' }}"><input type="hidden" name="plan" value="{{ $chosen['slug'] ?? '' }}"><input type="hidden" name="image" value="{{ $chosen['image'] ?? '' }}">
          <fieldset class="n8n-billing"><legend>{{ __('n8n.cycle') }}</legend><div class="n8n-terms">
            @foreach(['monthly','quarterly','semiannual','yearly'] as $cycle)
            <label class="n8n-term"><input type="radio" name="cycle" value="{{ $cycle }}" @checked($wizardCycle === $cycle)><span>{{ __('n8n.'.$cycle) }}</span></label>
            @endforeach
            <label class="n8n-term n8n-term--hourly" data-n8n-hourly-choice @if(! $chosen || $chosen['hourly'] <= 0) hidden @endif><input type="checkbox" name="billing_mode" value="hourly" @checked($wizardHourly) @disabled(! $chosen || $chosen['hourly'] <= 0)><span>{{ __('n8n.hourly') }}</span></label>
          </div></fieldset>
          <p class="n8n-small" data-n8n-hourly-note @if(! $wizardHourly) hidden @endif>{{ __('n8n.hourly_notice') }}</p>
          <p class="n8n-small" data-n8n-turkey-note @if(($chosen['country'] ?? '') !== 'TR') hidden @endif>{{ __('n8n.turkey_monthly') }}</p>
          <label class="n8n-field" for="n8n-label"><span>{{ __('n8n.name') }}</span><input id="n8n-label" name="label" maxlength="32" value="{{ $wizardLabel }}" placeholder="my-n8n" autocomplete="off"><small>{{ __('n8n.name_hint') }}</small></label>
          @if($n8nConsole)<p class="n8n-login-email"><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg><span>{{ __('n8n.email') }}<bdi>{{ auth('customer')->user()->email }}</bdi></span></p>@endif
          <p class="n8n-small">{{ __('n8n.tax_notice') }}</p>
          <button class="n8n-button" type="submit" @disabled(! $n8nEnabled || ! $chosen)>{{ $n8nConsole ? __('n8n.pay') : __('n8n.continue_order') }}</button>
        </form>
      </section>
    </div>
    <aside class="n8n-summary" aria-labelledby="n8n-summary-heading">
      <span class="n8n-summary-logo"><img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="36" height="36">n8n</span>
      <h3 id="n8n-summary-heading">{{ __('n8n.summary') }}</h3>
      <p class="n8n-small" data-n8n-summary-empty @if($chosen) hidden @endif>{{ __('n8n.summary_empty') }}</p>
      <div data-n8n-summary-selection @if(! $chosen) hidden @endif><p class="n8n-summary-plan" data-n8n-summary-plan>{{ $chosen['name'] ?? '' }}</p><p class="n8n-small" data-n8n-summary-location>{{ $chosen ? __('n8n.'.$chosen['country']) : '' }}</p><dl class="n8n-summary-specs"><div><dt>{{ __('n8n.cpu') }}</dt><dd data-n8n-summary-cpu>{{ $chosen ? fa_num($chosen['cpu']).' vCPU' : '' }}</dd></div><div><dt>{{ __('n8n.ram') }}</dt><dd data-n8n-summary-ram>{{ $chosen['ram'] ?? '' }}</dd></div><div><dt>{{ __('n8n.disk') }}</dt><dd data-n8n-summary-disk>{{ $chosen['disk'] ?? '' }}</dd></div></dl><p class="n8n-summary-price"><strong data-n8n-summary-price>{{ $chosen ? ($wizardHourly ? cloud_hourly_price($chosen['hourly']) : cloud_price($chosen['cycles'][$wizardCycle])) : '' }}</strong><small data-n8n-summary-term>{{ $wizardHourly ? __('n8n.per_hour') : __('n8n.'.$wizardCycle) }}</small></p></div>
      <ul class="n8n-inclusions"><li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('n8n.dedicated') }}</li><li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('n8n.included_install') }}</li><li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('n8n.included_domain') }}</li></ul>
      <p class="n8n-small">{{ __('n8n.price_note') }}</p>
    </aside>
  </div>
  <script type="application/json" data-n8n-catalog>@json($wizardData)</script>
  <script type="application/json" data-n8n-labels>@json($wizardLabels)</script>
</section>
<script src="{{ asset_ver('assets/js/n8n-configurator.js') }}" defer></script>
