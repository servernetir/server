<section class="section cvl-sec" id="country-selection">
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
