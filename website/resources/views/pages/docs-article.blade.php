@extends('layouts.site')

@php
    $sec = $doc['section'];
    $url = url()->current();
@endphp

@section('title', $doc['title'].' — '.__('ui.dc_title').' — '.__('ui.brand'))
@section('description', $doc['excerpt'] ?: __('ui.dc_sub'))

@section('content')

<div class="bp-progress" aria-hidden="true"><span id="bp-bar"></span></div>

<section class="docs-wrap">
  <div class="container">
    <div class="docs-layout">

      {{-- ============ سایدبار درختی ============ --}}
      <aside class="docs-side" id="docs-side">
        <div class="docs-side-in">
          <a class="docs-side-home" href="{{ lroute('docs.index') }}"><svg class="icon"><use href="#i-layout"/></svg>{{ __('ui.dc_title') }}</a>
          <div class="docs-side-search">
            <svg class="icon"><use href="#i-search"/></svg>
            <input type="text" id="docs-nav-q" placeholder="{{ __('ui.dc_filter_ph') }}" aria-label="{{ __('ui.dc_filter_ph') }}" autocomplete="off">
          </div>
          <nav class="docs-tree">
            @foreach($tree as $key => $s)
            <div class="docs-tree-sec {{ $key === $doc['category'] ? 'open' : '' }}">
              <button type="button" class="docs-tree-head" aria-expanded="{{ $key === $doc['category'] ? 'true' : 'false' }}">
                <svg class="icon ic"><use href="#i-{{ $s['meta']['icon'] }}"/></svg>
                <span>{{ lc($s['meta'])['t'] }}</span>
                <svg class="icon chev"><use href="#i-chev"/></svg>
              </button>
              <ul class="docs-tree-list">
                @foreach($s['items'] as $it)
                <li><a href="{{ lroute('docs', $it['slug']) }}" class="{{ $it['slug'] === $doc['slug'] ? 'on' : '' }}" data-title="{{ $it['title'] }}">{{ $it['title'] }}</a></li>
                @endforeach
              </ul>
            </div>
            @endforeach
          </nav>
        </div>
      </aside>

      {{-- ============ محتوا ============ --}}
      <div class="docs-main">
        <nav class="blog-crumbs">
          <a href="{{ lroute('home') }}">{{ __('ui.bl_home') }}</a><span>/</span>
          <a href="{{ lroute('docs.index') }}">{{ __('ui.dc_title') }}</a>
          @if($sec)<span>/</span><span>{{ lc($sec)['t'] }}</span>@endif
        </nav>

        <h1 class="docs-h1">{{ $doc['title'] }}</h1>
        @if($doc['excerpt'])<p class="docs-lead">{{ $doc['excerpt'] }}</p>@endif
        <div class="docs-meta">
          <span><svg class="icon"><use href="#i-book"/></svg>{{ $isFa ? fa_num($doc['reading']) : $doc['reading'] }} {{ __('ui.bl_min') }}</span>
          <span><svg class="icon"><use href="#i-clock"/></svg>{{ __('ui.bl_updated') }} {{ blog_date($doc['date']) }}</span>
        </div>

        <article class="blog-prose docs-prose" id="bp-article">{!! $doc['content'] !!}</article>

        @if(!empty($doc['tags']))
        <div class="blog-post-tags">
          @foreach($doc['tags'] as $t)<span>{{ $t }}</span>@endforeach
        </div>
        @endif

        {{-- بازخورد مفید بودن --}}
        <div class="docs-feedback">
          <b>{{ __('ui.dc_helpful') }}</b>
          <div class="docs-fb-btns">
            <button type="button" data-fb="yes"><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.dc_yes') }}</button>
            <button type="button" data-fb="no"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.dc_no') }}</button>
          </div>
          <p class="docs-fb-thanks" hidden>{{ __('ui.dc_thanks') }} <a href="{{ lroute('contact') }}">{{ __('ui.dc_ask') }}</a></p>
        </div>

        {{-- ناوبری قبلی/بعدی --}}
        @if($neighbours['prev'] || $neighbours['next'])
        <div class="docs-nav">
          @if($neighbours['prev'])
          <a class="docs-nav-a prev" href="{{ lroute('docs', $neighbours['prev']['slug']) }}">
            <small>{{ __('ui.dc_prev') }}</small><b>{{ $neighbours['prev']['title'] }}</b>
          </a>@else<span></span>@endif
          @if($neighbours['next'])
          <a class="docs-nav-a next" href="{{ lroute('docs', $neighbours['next']['slug']) }}">
            <small>{{ __('ui.dc_next') }}</small><b>{{ $neighbours['next']['title'] }}</b>
          </a>@endif
        </div>
        @endif
      </div>

      {{-- ============ در این صفحه ============ --}}
      <aside class="docs-toc">
        <div class="docs-toc-in" id="docs-toc-card" hidden>
          <b class="docs-toc-t">{{ __('ui.dc_onpage') }}</b>
          <ul id="bp-toc" class="bp-toc"></ul>
        </div>
      </aside>

    </div>
  </div>
</section>

<script type="application/ld+json">{!! json_encode(array_filter([
    '@'.'context' => 'https://schema.org', '@type' => 'TechArticle',
    'headline' => $doc['title'], 'description' => $doc['excerpt'] ?: null,
    'dateModified' => $doc['date'], 'inLanguage' => app()->getLocale(),
    'articleSection' => $sec ? lc($sec)['t'] : null,
    'author' => ['@type' => 'Organization', 'name' => 'ServerNet'],
    'publisher' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
    'mainEntityOfPage' => $url,
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

<script>
(function () {
  /* ---- آکاردئون سایدبار ---- */
  document.querySelectorAll('.docs-tree-head').forEach(btn => {
    btn.addEventListener('click', () => {
      const sec = btn.closest('.docs-tree-sec');
      const open = sec.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* ---- فیلتر سایدبار ---- */
  const nq = document.getElementById('docs-nav-q');
  if (nq) nq.addEventListener('input', () => {
    const t = nq.value.trim().toLowerCase();
    document.querySelectorAll('.docs-tree-sec').forEach(sec => {
      let hit = 0;
      sec.querySelectorAll('li').forEach(li => {
        const ok = !t || (li.querySelector('a').dataset.title || '').toLowerCase().includes(t);
        li.hidden = !ok; if (ok) hit++;
      });
      sec.hidden = t && hit === 0;
      if (t && hit) sec.classList.add('open');
    });
  });

  /* ---- بازخورد ---- */
  const fb = document.querySelector('.docs-feedback');
  if (fb) fb.querySelectorAll('[data-fb]').forEach(b => b.addEventListener('click', () => {
    fb.querySelector('.docs-fb-btns').hidden = true;
    fb.querySelector('.docs-fb-thanks').hidden = false;
  }));

  /* ---- «در این صفحه» + نوار پیشرفت ---- */
  const article = document.getElementById('bp-article');
  if (!article) return;
  const heads = article.querySelectorAll('h2, h3');
  const list = document.getElementById('bp-toc');
  const card = document.getElementById('docs-toc-card');
  const links = [];

  if (list && heads.length >= 2) {
    heads.forEach((h, i) => {
      if (!h.id) {
        const base = (h.textContent || '').trim().toLowerCase()
          .replace(/[^\p{L}\p{N}\s-]/gu, '').replace(/\s+/g, '-').slice(0, 60);
        h.id = base ? base + '-' + i : 'h-' + i;
      }
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.href = '#' + h.id; a.textContent = h.textContent;
      if (h.tagName === 'H3') li.className = 'sub';
      a.addEventListener('click', ev => {
        ev.preventDefault();
        h.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', '#' + h.id);
      });
      li.appendChild(a); list.appendChild(li); links.push({ el: h, a });
    });
    card.hidden = false;
    const spy = new IntersectionObserver(es => {
      es.forEach(en => { if (en.isIntersecting) links.forEach(l => l.a.classList.toggle('on', l.el === en.target)); });
    }, { rootMargin: '-90px 0px -70% 0px', threshold: 0 });
    heads.forEach(h => spy.observe(h));
  }

  const bar = document.getElementById('bp-bar');
  if (bar) {
    let ticking = false;
    const update = () => {
      const top = article.getBoundingClientRect().top + window.scrollY;
      const end = top + article.offsetHeight;
      const mark = window.scrollY + window.innerHeight * 0.65;
      bar.style.transform = 'scaleX(' + Math.max(0, Math.min(1, (mark - top) / Math.max(1, end - top))) + ')';
      ticking = false;
    };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    addEventListener('resize', update, { passive: true });
    update();
  }
})();
</script>
@endsection
