@php
  $hvCalcPlans = [];
  foreach ($featured as $key => $plan) {
      if (($plan['hourly_minor'] ?? 0) <= 0) continue;
      $hvCalcPlans[] = ['id' => $key, 'name' => $plan['name'], 'city' => $plan['city'], 'country' => $plan['iso'], 'ram' => $plan['ram_mb'], 'cpu' => $plan['vcpu'], 'disk' => $plan['disk'], 'diskType' => $plan['disk_type'], 'hourly' => $plan['hourly_minor'], 'monthly' => $plan['monthly_minor'], 'url' => $plan['buy_url']];
  }
  $hvCalcConfig = ['locale' => app()->getLocale(), 'minor' => $isFa ? 1 : 100, 'minHours' => $minHours, 'plans' => $hvCalcPlans, 'text' => array_intersect_key($hvUx, array_flip(['hours', 'results', 'no_match', 'no_country', 'suggested', 'compare_limit', 'compare_empty', 'remove', 'price', 'resources', 'estimate', 'monthly', 'valid_hours', 'choose']))];
  $hvFirst = $hvCalcPlans[0] ?? null;
  $hvMoney = fn ($amount) => $isFa ? fa_num(number_format($amount)).' تومان' : '€'.number_format($amount / 100, 2, '.', '');
@endphp
<section class="section hv-sec hv-explorer" id="hourly-calculator" aria-labelledby="hourly-calculator-title">
  <div class="container">
    <div class="hv-sec-h"><span class="hv-eyebrow">{{ $hvUx['eyebrow'] }}</span><h2 id="hourly-calculator-title">{{ $hvUx['title'] }}</h2><p>{{ $hvUx['intro'] }}</p></div>
    @if($hvFirst)
    <div class="hv-estimator" data-hourly-enhance hidden>
      <div class="hv-estimator-controls">
        <label for="hourly-plan">{{ $hvUx['plan'] }}</label>
        <select id="hourly-plan">
          @foreach($hvCalcPlans as $p)<option value="{{ $p['id'] }}">{{ $p['city'] }} · {{ $p['name'] }} · {{ $isFa ? fa_num($p['cpu']) : $p['cpu'] }} vCPU / {{ $isFa ? fa_num($p['ram'] / 1024) : $p['ram'] / 1024 }} GB RAM</option>@endforeach
        </select>
        <div class="hv-hours-heading"><label for="hourly-hours">{{ $hvUx['duration'] }}</label><div><input id="hourly-hours" type="number" inputmode="numeric" min="1" max="720" step="1" value="24" aria-describedby="hourly-hours-error"> <span>{{ $hvUx['hours'] }}</span></div></div>
        <input id="hourly-range" type="range" min="1" max="720" step="1" value="24" aria-label="{{ $hvUx['duration'] }}">
        <p id="hourly-hours-error" class="hv-field-error" role="status" hidden>{{ $hvUx['valid_hours'] }}</p>
        <div class="hv-presets" role="group" aria-label="{{ $hvUx['presets'] }}">@foreach([6, 24, 72, 168] as $hours)<button type="button" data-hourly-preset="{{ $hours }}" aria-pressed="{{ $hours === 24 ? 'true' : 'false' }}">{{ $isFa ? fa_num($hours) : $hours }} {{ $hvUx['hours'] }}</button>@endforeach</div>
        <p class="hv-small">{{ $hvUx['sample_note'] }} <a href="{{ $storeUrl }}" rel="nofollow">{{ $hvUx['all_plans'] }}</a></p>
      </div>
      <div class="hv-estimator-result">
        <p class="hv-estimate-label">{{ $hvUx['estimate'] }}</p>
        <output id="hourly-total" for="hourly-plan hourly-hours hourly-range" aria-live="polite">{{ $hvMoney($hvFirst['hourly'] * 24) }}</output>
        <dl class="hv-quote-details"><div><dt>{{ $hvUx['credit'] }}</dt><dd id="hourly-credit">{{ $hvMoney($hvFirst['hourly'] * $minHours) }}</dd></div><div id="hourly-monthly-row"><dt>{{ $hvUx['monthly'] }}</dt><dd id="hourly-monthly">{{ $hvMoney($hvFirst['monthly']) }}</dd></div><div id="hourly-break-row"><dt>{{ $hvUx['break_even'] }}</dt><dd id="hourly-break"></dd></div></dl>
        <p class="hv-small" id="hourly-break-note">{{ $hvUx['break_note'] }}</p>
        <a class="hv-buy" id="hourly-order" href="{{ $hvFirst['url'] }}" rel="nofollow">{{ $hvUx['choose'] }}</a>
        <p class="hv-small">{{ $hvUx['cost_note'] }}</p>
      </div>
    </div>
    <noscript><p class="hv-note">{{ $hvUx['sample_note'] }} <a href="#plans">{{ $hvUx['jump_plans'] }}</a></p></noscript>

    @else
    <p class="hv-note">{{ $hvUx['no_match'] }} <a href="{{ $storeUrl }}" rel="nofollow">{{ $hvUx['all_plans'] }}</a></p>
    @endif
    <script type="application/json" id="hourly-explorer-config">{!! json_encode($hvCalcConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <div class="hv-billing-notes"><p>{{ $hvUx['credit_note'] }}</p><p>{{ $hvUx['stop_note'] }}</p></div>
    <div class="hv-scenario-wrap" data-hourly-enhance hidden>
      <h3>{{ $hvUx['usage'] }}</h3><p class="hv-small">{{ $hvUx['usage_note'] }}</p>
      <div class="hv-scenarios">@foreach($hvUx['scenarios'] as $s)<button type="button" data-hourly-scenario="{{ $s['id'] }}" data-ram="{{ $s['ram'] }}" data-cpu="{{ $s['cpu'] }}" aria-pressed="false"><strong>{{ $s['title'] }}</strong><span>{{ $s['text'] }}</span><small dir="ltr">{{ $s['cpu'] }} vCPU · {{ $s['ram'] / 1024 }} GB RAM+</small></button>@endforeach</div>
      <p id="hourly-suggestion" role="status" aria-live="polite"></p>
    </div>
  </div>
</section>
