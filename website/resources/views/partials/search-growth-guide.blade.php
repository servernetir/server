@php
 $searchGuide = __('search-growth.'.$searchTopic);
 $searchPosts = collect($searchGuide['cards'])->contains(fn ($card) => ($card['route'] ?? null) === 'blog')
    ? collect(app(\App\Services\BlogRepository::class)->index())->keyBy('slug') : collect();
@endphp
@once
@push('head')
<style id="search-growth-guide-css">
.search-growth{margin:44px auto;max-width:1120px;padding:26px;border:1px solid var(--line);border-radius:20px;background:var(--surface);scroll-margin-top:calc(var(--header-h) + 20px);overflow-wrap:anywhere}
.search-growth>h2{font-size:clamp(20px,2.3vw,27px);line-height:1.7;margin:0 0 12px}.search-growth>p{max-width:82ch;color:var(--muted);font-size:14px;line-height:2;margin:0 0 22px}
.search-growth-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.search-growth-card{padding:20px;border:1px solid var(--line);border-radius:14px;background:var(--surface-2)}
.search-growth-card h3{font-size:16px;line-height:1.8;margin:0 0 10px}.search-growth-card p,.search-growth-checks li{font-size:13.5px;line-height:2;color:var(--muted)}.search-growth-card p{margin:0 0 12px}
.search-growth a{color:var(--cyan);text-decoration:underline;text-underline-offset:4px;line-height:1.9}.search-growth a:focus-visible{outline:2px solid currentColor;outline-offset:4px}.search-growth-checks{margin:22px 0 0;padding-inline-start:22px;display:grid;gap:7px}
@media(max-width:800px){.search-growth{padding:20px;margin:30px auto}.search-growth-grid{grid-template-columns:1fr}}
</style>
@endpush
@endonce
<section class="search-growth" id="{{ $searchTopic }}-practical-guide" aria-labelledby="{{ $searchTopic }}-practical-title">
 <h2 id="{{ $searchTopic }}-practical-title">{{ $searchGuide['title'] }}</h2>
 <p>{{ $searchGuide['intro'] }}</p>
 <div class="search-growth-grid">
 @foreach($searchGuide['cards'] as $guideCard)
  @if(($guideCard['route'] ?? null) === 'blog' && (!isset($searchPosts[$guideCard['parameters']['slug']]) || !empty($searchPosts[$guideCard['parameters']['slug']]['untranslated']))) @continue @endif
  <article class="search-growth-card"><h3>{{ $guideCard['title'] }}</h3><p>{{ $guideCard['text'] }}</p><a href="{{ $guideCard['fragment'] ?? lroute($guideCard['route'], $guideCard['parameters'] ?? []) }}">{{ $guideCard['link'] }}</a></article>
 @endforeach
 </div>
 <ul class="search-growth-checks">@foreach($searchGuide['checks'] as $guideCheck)<li>{{ $guideCheck }}</li>@endforeach</ul>
</section>
