@extends('layouts.site')
@section('title', __('storage.'.$kind.'_title').' | ServerNet')
@section('description', __('storage.'.$kind.'_intro'))
@push('head')
<style>
.sn-storage{padding:120px 0 64px}.sn-storage h1{font-size:clamp(28px,4vw,48px);margin:18px 0}.sn-storage p{max-width:800px;line-height:1.9;color:var(--muted)}.sn-storage-nav,.sn-storage-locations{display:flex;gap:10px;flex-wrap:wrap;margin:22px 0}.sn-storage-nav a,.sn-storage-locations a{padding:10px 16px;min-height:44px;border:1px solid var(--line,#303e57);border-radius:12px;color:inherit;text-decoration:none}.sn-storage-locations a[aria-current=page]{border-color:var(--cyan);color:var(--cyan)}.sn-storage-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,255px),1fr));gap:18px}.sn-storage-card{padding:24px;border:1px solid var(--line,#303e57);border-radius:18px;background:var(--card,transparent);min-width:0}.sn-storage-card h2{font-size:21px;overflow-wrap:anywhere}.sn-storage-card dl{margin:24px 0;line-height:1.7}.sn-storage-card dl div{display:flex;gap:12px;justify-content:space-between;margin:10px 0}.sn-storage-card dd{margin:0;direction:ltr}.sn-storage-price{font-size:25px;font-weight:750;display:block;overflow-wrap:anywhere}.sn-storage-card small{display:block;color:var(--muted);line-height:1.7}.sn-storage-buy,.sn-storage-unavailable{display:block;padding:12px;margin-top:20px;text-align:center;border-radius:10px;min-height:44px}.sn-storage-buy{background:var(--cyan,#67e8f9);color:#102033;font-weight:700;text-decoration:none}.sn-storage-unavailable{border:1px solid var(--line,#303e57);color:var(--muted)}.sn-storage-note{padding:20px;border:1px solid var(--line,#303e57);border-radius:14px;margin:24px 0}.sn-storage-note h2{font-size:19px}.sn-storage-note ul{line-height:2;padding-inline-start:22px}
</style>
@endpush
@section('content')
<section class="section sn-storage"><div class="container">
  <div class="sn-product-hero sn-product-polish">
    @include('partials.atlas-product-horizon')
    <div class="hero-sub-inner sn-storage-intro">
  <nav class="sn-storage-nav" aria-label="{{ __('storage.choose_service') }}">
    <a href="{{ lroute('cloud.location', ['location'=>'object-storage']) }}" @if($kind === 'object-storage') aria-current="page" @endif>Object Storage</a>
    <a href="{{ lroute('cloud.location', ['location'=>'block-storage']) }}" @if($kind === 'block-storage') aria-current="page" @endif>Block Storage</a>
    <a href="{{ lroute('cloud.location', ['location'=>'storage-box']) }}" @if($kind === 'storage-box') aria-current="page" @endif>Storage Box</a>
  </nav>
  <h1>{{ __('storage.'.$kind.'_title') }}</h1>
  <p class="lead">{{ __('storage.'.$kind.'_intro') }}</p>
    </div>
    @include('partials.atlas-product-stage', ['atlasStage'=>'storage', 'storageKind'=>$kind])
  </div>
  @if($kind === 'object-storage' && ! $rows->contains(fn($row) => $row['ready']))
    <div class="sn-storage-note" role="status"><strong>{{ __('storage.preparing') }}</strong><p>{{ __('storage.object_pending') }}</p></div>
  @endif
  <nav class="sn-storage-locations" aria-label="{{ __('storage.location') }}">
    @foreach($locations as $location)
      <a href="{{ url()->current().'?location='.rawurlencode($location) }}" @if($selected === $location) aria-current="page" @endif>{{ __('storage.location_'.$location) }}</a>
    @endforeach
  </nav>
  <div class="sn-storage-grid">
  @forelse($rows as $row)
    @php
      $p=$row['product']; $meta=$row['meta']; $quote=$row['quote'];
      $amount=$quote['irt']??0;
      $setup=$quote['setup_irt']??0;
    @endphp
    <article class="sn-storage-card">
      <h2>{{ $p->displayName() }}</h2>
      <dl>
        <div><dt>{{ __('storage.capacity') }}</dt><dd>{{ $kind === 'object-storage' ? ($meta['capacity_tb'].' TB') : ($kind === 'block-storage' ? ($meta['size_gb'].' GB') : (rtrim(rtrim(number_format(($meta['size']??0)/(1024**4),2,'.',''),'0'),'.').' TiB')) }}</dd></div>
        <div><dt>{{ __('storage.location') }}</dt><dd>{{ strtoupper($meta['location']) }}</dd></div>
        @if($kind === 'object-storage')<div><dt>{{ __('storage.egress') }}</dt><dd>{{ $meta['egress_tb'] }} TB</dd></div>@endif
        @if($kind === 'storage-box' && isset($meta['subaccounts_limit']))<div><dt>{{ __('storage.subaccounts') }}</dt><dd>{{ $meta['subaccounts_limit'] }}</dd></div>@endif
      </dl>
      @if($amount > 0)
        <span class="sn-storage-price">{{ cloud_price($amount) }}</span>
        <small>{{ __('storage.per_month') }}</small>
        <small>{{ __('storage.tax_note') }}</small>
        <small>{{ __('storage.setup') }}: {{ cloud_price($setup) }}</small>
      @else
        <strong>{{ __('storage.price_unavailable') }}</strong>
      @endif
      @if($row['ready'])
        <a class="sn-storage-buy" href="{{ lroute('account.order', ['product'=>$p->slug]) }}">{{ __('storage.buy') }}</a>
      @else
        <span class="sn-storage-unavailable">{{ __('storage.'.($kind === 'object-storage' ? 'preparing' : 'unavailable')) }}</span>
      @endif
    </article>
  @empty
    <p>{{ __('storage.catalog_pending') }}</p>
  @endforelse
  </div>
  <section class="sn-storage-note">
    <h2>{{ __('storage.conditions') }}</h2>
    <ul>@foreach(__('storage.'.$kind.'_conditions') as $condition)<li>{{ $condition }}</li>@endforeach</ul>
  </section>
</div></section>
@if(in_array($kind, ['object-storage', 'block-storage'], true))
  @include('partials.product-guides', ['guidesCat' => 'cloud', 'guidesSeed' => 'cloud/'.$kind])
@endif
@endsection
