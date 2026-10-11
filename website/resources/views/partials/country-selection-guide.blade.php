@once
@push('head')
<style id="country-selection-styles">
#country-selection h2{font-size:clamp(22px,3vw,30px);line-height:1.5;margin:0 0 20px}
#country-selection h3{font-size:clamp(19px,2.5vw,24px);line-height:1.5;margin:24px 0 12px}
#country-selection p{line-height:1.9;margin:0 0 20px}
#country-selection ul{list-style:disc;padding-inline-start:1.5em;margin:0 0 24px}
#country-selection li{line-height:1.9;margin:8px 0}
</style>
@endpush
@endonce
<section class="section" id="country-selection">
  <div class="container">
    <h2>{{ $countrySeo['check_title'] }}</h2>
    @if($showProfile ?? true)
      <p>{{ $countrySeo['profile'] }}</p>
    @endif
    <ul>
      @foreach($countrySeo['checks'] as $check)
        <li>{{ $check }}</li>
      @endforeach
    </ul>
    <h3>{{ $countrySeo['network_title'] }}</h3>
    <p>{{ $countrySeo['network'] }}</p>
  </div>
</section>
