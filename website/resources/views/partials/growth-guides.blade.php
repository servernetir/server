@php
    $growthIndex = collect(app(\App\Services\BlogRepository::class)->index())->keyBy('slug');
    $growthLinks = [];
    foreach (config('growth.'.($growthPage ?? ''), []) as $growthLink) {
        if (isset($growthLink['post'])) {
            $growthPost = $growthIndex->get($growthLink['post']);
            if (! $growthPost || ! empty($growthPost['untranslated'])) { continue; }
            $growthLinks[] = ['href' => lroute('blog', $growthLink['post']), 'title' => $growthPost['title']];
        } else {
            $growthLinks[] = ['href' => lroute($growthLink['route'], $growthLink['parameters'] ?? []), 'title' => lc($growthLink['title'])];
        }
    }
@endphp
@if($growthLinks)
<aside class="growth-guides" aria-label="{{ lc(['fa' => 'راهنما و انتخاب مرتبط', 'en' => 'Related guides and options', 'tr' => 'İlgili rehberler ve seçenekler']) }}">
  <h2>{{ lc(['fa' => 'برای قدم بعدی', 'en' => 'Your next step', 'tr' => 'Sonraki adımınız']) }}</h2>
  <ul>@foreach($growthLinks as $growthLink)<li><a href="{{ $growthLink['href'] }}">{{ $growthLink['title'] }}</a></li>@endforeach</ul>
</aside>
<style>
.growth-guides{margin:28px auto;padding:22px;max-width:1080px;border:1px solid var(--line);border-radius:16px;background:var(--surface);overflow-wrap:anywhere}
.growth-guides h2{font-size:20px;margin:0 0 12px}.growth-guides ul{padding-inline-start:22px;display:grid;gap:12px}.growth-guides a{color:var(--cyan);text-decoration:underline;text-underline-offset:4px;line-height:1.9}.growth-guides a:focus-visible{outline:2px solid currentColor;outline-offset:4px}
</style>
@endif
