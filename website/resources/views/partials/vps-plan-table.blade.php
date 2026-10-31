@once
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/vps-catalog.css') }}">
<script src="{{ asset_ver('assets/js/vps-catalog.js') }}" defer></script>
@endpush
@endonce
<div class="vpsc-catalog" data-vps-catalog>
  <div class="section-head"><span class="badge">{{ __('vps-catalog.live') }}</span><h2>{{ __('vps-catalog.plans') }}</h2><p>{{ __('vps-catalog.intro') }}</p></div>
  @if($vpsRows)
  <form class="vpsc-filters" data-vps-filters role="search">
    <label>{{ __('vps-catalog.search') }}<input type="search" data-vps-search placeholder="{{ __('vps-catalog.search_hint') }}"></label>
    @if($hourlyPage)<label>{{ __('vps-catalog.country') }}<select data-vps-country><option value="">{{ __('vps-catalog.all') }}</option>@foreach(collect($vpsRows)->unique('country')->sortBy('country_label') as $r)<option value="{{ $r['country'] }}">{{ $r['country_label'] }}</option>@endforeach</select></label>@endif
    <label>{{ __('vps-catalog.city') }}<select data-vps-city><option value="">{{ __('vps-catalog.all') }}</option>@foreach(collect($vpsRows)->unique('location')->sortBy('city') as $r)<option value="{{ $r['location'] }}" data-country="{{ $r['country'] }}">{{ $r['city'] }}</option>@endforeach</select></label>
    <label>{{ __('vps-catalog.cpu') }}<select data-vps-cpu><option value="">{{ __('vps-catalog.all') }}</option>@foreach(collect($vpsRows)->pluck('cpu')->unique()->sort() as $cpu)<option value="{{ $cpu }}">{{ fa_num($cpu) }} vCPU</option>@endforeach</select></label>
    <label>{{ __('vps-catalog.ram') }}<select data-vps-ram><option value="">{{ __('vps-catalog.all') }}</option>@foreach(collect($vpsRows)->unique('ram_mb')->sortBy('ram_mb') as $r)<option value="{{ $r['ram_mb'] }}">{{ $r['ram'] }}</option>@endforeach</select></label>
    <label>{{ __('vps-catalog.billing') }}<select data-vps-billing><option value="">{{ __('vps-catalog.all') }}</option><option value="hourly">{{ __('vps-catalog.hourly') }}</option><option value="monthly">{{ __('vps-catalog.monthly_only') }}</option></select></label>
    <label>{{ __('vps-catalog.sort') }}<select data-vps-sort><option value="monthly">{{ __('vps-catalog.monthly_price') }}</option><option value="hourly">{{ __('vps-catalog.hourly_price') }}</option><option value="cpu">{{ __('vps-catalog.cpu') }}</option><option value="ram">{{ __('vps-catalog.ram') }}</option></select></label>
  </form>
  <div class="vpsc-toolbar"><span data-vps-count data-vps-count-template="{{ __('vps-catalog.count_filtered') }}" role="status" aria-live="polite">{{ __('vps-catalog.count', ['n' => fa_num(count($vpsRows))]) }}</span><small>{{ __('vps-catalog.scroll') }}</small></div>
  <div class="vpsc-table-wrap" role="region" tabindex="0" aria-label="{{ __('vps-catalog.plans') }}">
  <table class="vpsc-table plan-table"><thead><tr>
    <th>{{ __('vps-catalog.plan') }}</th><th>{{ __('vps-catalog.location') }}</th><th>{{ __('vps-catalog.cpu') }}</th><th>{{ __('vps-catalog.ram') }}</th><th>{{ __('vps-catalog.disk') }}</th><th>{{ __('vps-catalog.traffic') }}</th><th>{{ __('vps-catalog.monthly_price') }}</th><th>{{ __('vps-catalog.hourly_price') }}</th><th>{{ __('vps-catalog.order') }}</th>
  </tr></thead><tbody>
  @foreach($vpsRows as $r)
  <tr data-vps-row data-country="{{ $r['country'] }}" data-city="{{ $r['location'] }}" data-monthly="{{ $r['monthly'] }}" data-hourly="{{ $r['hourly'] }}" data-cpu="{{ $r['cpu'] }}" data-ram="{{ $r['ram_mb'] }}">
    <th scope="row"><b dir="ltr">{{ $r['name'] }}</b><small>{{ $r['cpu_kind'] }} · <span dir="ltr">{{ $r['arch'] }}</span></small></th>
    <td><span>@include('partials.flag', ['flagSrc' => $r['flag_svg'], 'flagEmoji' => $r['flag'], 'flagSize' => 18]) {{ $r['country_label'] }}</span><small>{{ $r['city'] }}</small></td>
    <td><b>{{ fa_num($r['cpu']) }}</b> <small>vCPU</small></td><td dir="ltr">{{ $r['ram'] }}</td><td dir="ltr">{{ $r['disk'] }}</td><td class="vpsc-traffic">{{ $r['traffic'] }}</td>
    <td class="vpsc-price"><b>{{ $r['monthly_label'] }}</b><small>{{ __('vps-catalog.per_month') }}</small>@if($r['setup_label'])<small>{{ __('vps-catalog.setup', ['price' => $r['setup_label']]) }}</small>@endif</td>
    <td class="vpsc-price">@if($r['hourly'] > 0)<b>{{ $r['hourly_label'] }}</b><small>{{ __('vps-catalog.per_hour') }}</small>@else<span class="vpsc-monthly-only">{{ $r['unavailable_label'] }}</span>@endif</td>
    <td>@if($hourlyPage && $r['hourly_url'])<a class="btn btn-primary" href="{{ $r['hourly_url'] }}">{{ __('vps-catalog.order_hourly') }}</a>@elseif($r['monthly_url'])<a class="btn {{ $hourlyPage ? 'btn-glass' : 'btn-primary' }}" href="{{ $r['monthly_url'] }}">{{ __('vps-catalog.order_monthly') }}</a>@else<small>{{ __('ui.iran_gate_blocked') }}</small>@endif</td>
  </tr>
  @endforeach
  </tbody></table></div>
  <p class="vpsc-empty" data-vps-empty hidden>{{ __('vps-catalog.no_results') }}</p>
  <button type="button" class="btn btn-glass vpsc-more" data-vps-more hidden>{{ __('vps-catalog.more') }}</button>
  <p class="vpsc-footnote">{{ __('vps-catalog.tax_note') }}</p>
  @else<p class="vpsc-empty">{{ __('vps-catalog.unavailable') }}</p>@endif
</div>
