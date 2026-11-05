@push('head')
<style id="hosting-stack-styles">
#hosting-stack-guide h2{font-size:clamp(22px,3vw,30px);line-height:1.5;margin:0 0 16px}
#hosting-stack-guide p,#hosting-stack-guide li{line-height:1.9;color:var(--muted)}
#hosting-stack-guide ol{display:grid;gap:14px;padding-inline-start:1.5em;margin:24px 0}
#hosting-stack-guide nav{display:flex;flex-wrap:wrap;gap:12px 24px}
#hosting-stack-guide a{color:var(--cyan);text-underline-offset:4px;overflow-wrap:anywhere}
</style>
@endpush
<section class="section" id="hosting-stack-guide" aria-labelledby="hosting-stack-title">
  <div class="container">
    <h2 id="hosting-stack-title">{{ $copy['guide_title'] }}</h2>
    <p>{{ $copy['guide_intro'] }}</p>
    <ol>@foreach($copy['checks'] as $check)<li>{{ $check }}</li>@endforeach</ol>
    <nav aria-label="{{ $copy['related_label'] }}">
      <a href="{{ lroute('hosting', $copy['other_slug']) }}">{{ $copy['other'] }}</a>
      <a href="{{ lroute('vps.hourly') }}">{{ $copy['vps'] }}</a>
      <a href="{{ lroute('contact') }}">{{ $copy['contact'] }}</a>
    </nav>
  </div>
</section>
