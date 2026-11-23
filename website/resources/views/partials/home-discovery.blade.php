@include('partials.home-discovery-style')
@php
    $homeDirectory = \App\Support\HomeServiceDirectory::entries();
    $homeDirectorySchema = \App\Support\HomeServiceDirectory::schema($homeDirectory, lroute('home'));
@endphp
@push('head')
<script type="application/ld+json">{!! schema_ld($homeDirectorySchema, 'ItemList') !!}</script>
@endpush
<section class="sn-discovery" id="service-directory" aria-labelledby="service-directory-title">
  <div class="container">
    <div class="sn-discovery-head">
      <span class="sn-discovery-kicker">{{ __('home_discovery.eyebrow') }}</span>
      <h2 id="service-directory-title">{{ __('home_discovery.heading') }}</h2>
      <p>{{ __('home_discovery.intro') }}</p>
    </div>
    <div class="sn-discovery-grid">
      @foreach($homeDirectory as $key => $service)
      <article class="sn-discovery-card" data-service-guide="{{ $key }}">
        <span class="sn-discovery-icon" aria-hidden="true"><svg class="icon"><use href="#i-{{ $service['icon'] }}"/></svg></span>
        <h3>{{ $service['name'] }}</h3>
        <p>{{ $service['description'] }}</p>
        <p class="sn-discovery-detail">{{ $service['detail'] }}</p>
        <a class="sn-discovery-link" href="{{ $service['url'] }}">{{ $service['cta'] }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
      </article>
      @endforeach
    </div>
    <aside class="sn-discovery-trust" aria-labelledby="service-guide-verify">
      <div><h3 id="service-guide-verify">{{ __('home_discovery.verify') }}</h3><p>{{ __('home_discovery.trust_text') }}</p></div>
      <div class="sn-discovery-trust-links">
        @foreach(['status'=>'status', 'sla'=>'sla', 'docs'=>'docs.index', 'api'=>'ai.home', 'contact'=>'contact'] as $label => $route)
          <a class="sn-discovery-link" href="{{ lroute($route) }}">{{ __('home_discovery.'.$label) }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
        @endforeach
      </div>
    </aside>
  </div>
</section>
