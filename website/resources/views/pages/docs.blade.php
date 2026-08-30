@extends('layouts.site')

@section('title', __('ui.dc_title').' — '.__('ui.brand'))
@section('description', __('ui.dc_sub'))

@section('content')

<section class="hero hero-sub docs-hero" style="padding-bottom:34px">
  <div class="container">
    <span class="badge reveal"><svg class="icon"><use href="#i-layout"/></svg>{{ __('ui.dc_badge') }}</span>
    <h1 class="reveal" style="transition-delay:.06s">{{ __('ui.dc_h1a') }} <span class="grad">{{ __('ui.dc_h1b') }}</span></h1>
    <p class="hero-lead reveal" style="transition-delay:.12s;max-width:640px">{{ __('ui.dc_sub') }}</p>

    <div class="docs-search reveal" style="transition-delay:.18s">
      <svg class="icon"><use href="#i-search"/></svg>
      <input type="text" id="docs-q" placeholder="{{ __('ui.dc_search_ph') }}" aria-label="{{ __('ui.dc_search_ph') }}" autocomplete="off">
    </div>
  </div>
</section>

<section class="section" style="padding-top:10px;padding-bottom:74px">
  <div class="container">
    @if(count($tree))
    <div class="docs-cards" id="docs-cards">
      @foreach($tree as $key => $sec)
      <div class="docs-card reveal" data-sec="{{ $key }}">
        <div class="docs-card-h">
          <span class="docs-card-ic"><svg class="icon"><use href="#i-{{ $sec['meta']['icon'] }}"/></svg></span>
          <div>
            <h2>{{ lc($sec['meta'])['t'] }}</h2>
            <p>{{ lc($sec['meta'])['d'] }}</p>
          </div>
        </div>
        {{--
          🔴 **همه** رندر می‌شوند؛ موردهای بعد از ششم فقط با CSS پنهان‌اند.
          قبلاً `array_slice(…, 0, 6)` بود، پس جستجو فقط در همان شش تا می‌گشت:
          کاربر عنوانِ دقیقِ یک مقالهٔ **موجود** را تایپ می‌کرد و صفحه می‌گفت
          «چیزی پیدا نشد» — پس نتیجه می‌گرفت سرورنت آن مستند را ندارد و
          می‌رفت سراغِ رقیب. با ۱۰۱ موضوع، بیشترِ کتابخانه نامرئی بود.
        --}}
        <ul class="docs-card-list">
          @foreach($sec['items'] as $k => $it)
          <li data-title="{{ $it['title'] }}" @if($k >= 6) class="is-extra" @endif><a href="{{ lroute('docs', $it['slug']) }}"><svg class="icon"><use href="#i-arrow"/></svg>{{ $it['title'] }}</a></li>
          @endforeach
        </ul>
        <style>.docs-card-list li.is-extra{display:none}.docs-searching .docs-card-list li.is-extra{display:list-item}</style>
        @if(count($sec['items']) > 6)
        <span class="docs-card-more">{{ __('ui.dc_more', ['n' => $isFa ? fa_num(count($sec['items']) - 6) : count($sec['items']) - 6]) }}</span>
        @endif
      </div>
      @endforeach
    </div>
    <p class="docs-empty" id="docs-noresult" hidden>{{ __('ui.dc_noresult') }}</p>
    @else
    <div class="blog-empty reveal"><svg class="icon"><use href="#i-layout"/></svg><p>{{ __('ui.dc_none') }}</p></div>
    @endif
  </div>
</section>

<script>
(function(){
  const q = document.getElementById('docs-q');
  if (!q) return;
  const cards = [...document.querySelectorAll('.docs-card')];
  const none = document.getElementById('docs-noresult');
  q.addEventListener('input', () => {
    const t = q.value.trim().toLowerCase();
    let shown = 0;
    // ⚠️ هنگام جستجو، موردهای «اضافه» هم باید دیده شوند وگرنه فیلترِ زیر
    //    رویشان اثر دارد ولی CSS همچنان پنهانشان می‌کند.
    document.body.classList.toggle('docs-searching', t !== '');
    cards.forEach(card => {
      const items = [...card.querySelectorAll('.docs-card-list li')];
      let hit = 0;
      items.forEach(li => {
        const ok = !t || (li.dataset.title || '').toLowerCase().includes(t);
        li.hidden = !ok; if (ok) hit++;
      });
      const secHit = !t || hit > 0 || card.querySelector('h2').textContent.toLowerCase().includes(t);
      card.hidden = !secHit; if (secHit) shown++;
    });
    if (none) none.hidden = shown > 0;
  });
})();
</script>
@endsection
