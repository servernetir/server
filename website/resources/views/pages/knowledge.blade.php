@extends('layouts.site')

@section('title', __('ui.kb_title').' — '.__('ui.brand'))
@section('description', __('ui.kb_sub'))

@section('content')
@php $loc = app()->getLocale(); @endphp

{{-- ============ HERO + SEARCH ============ --}}
<section class="hero hero-sub" style="padding-bottom:55px">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_knowledge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.kb_h1a') }} <span class="grad">{{ __('ui.kb_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.kb_sub') }}</p>
      <div class="kb-search reveal" style="transition-delay:.24s">
        <svg class="icon"><use href="#i-search"/></svg>
        <input type="search" id="kb-search" placeholder="{{ __('ui.kb_search_ph') }}" autocomplete="off">
      </div>
    </div>
  </div>
</section>

{{-- ============ BLOG ============ --}}
<section class="section" id="blog" style="padding-top:20px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ lc(config('servernet.knowledge_menu')[0])['t'] }}</span>
      <h2>{{ __('ui.kb_blog_title') }}</h2>
      <p>{{ __('ui.kb_blog_sub') }}</p>
    </div>
    {{-- نوشته‌های **واقعیِ** بلاگ از دیتابیس (همان‌ها که در پنل مدیریت منتشر
         می‌شوند). اگر هنوز نوشته‌ای نیست، کارت‌های نمونهٔ config نشان داده
         می‌شود تا صفحه خالی نماند. --}}
    <div class="kb-grid" id="kb-articles">
      @forelse($posts as $i => $p)
      @php $tag = $p['tags'][0] ?? ($p['category'] ?? ''); @endphp
      <article class="kb-card reveal" style="transition-delay:{{ $i * 60 }}ms" data-search="{{ $p['title'] }} {{ $tag }} {{ $p['excerpt'] }}">
        <div class="kb-card-top">
          @if($tag)<span class="kb-tag">{{ $tag }}</span>@endif
          <span class="kb-min"><svg class="icon"><use href="#i-clock"/></svg>{{ $isFa ? fa_num($p['reading']) : $p['reading'] }} {{ __('ui.kb_min') }}</span>
        </div>
        <span class="kb-ico"><svg class="icon"><use href="#i-{{ $p['icon'] ?: 'book' }}"/></svg></span>
        <h3><a href="{{ lroute('blog', $p['slug']) }}">{{ $p['title'] }}</a></h3>
        <p>{{ \Illuminate\Support\Str::limit($p['excerpt'], 120) }}</p>
        <div class="kb-card-foot">
          <time>{{ blog_date($p['date']) }}</time>
          {{-- لینکِ واقعی به نوشته؛ قبلاً href="#" بود و هم کاربر را به جایی
               نمی‌برد هم برای گوگل لینکِ مرده بود --}}
          <a class="buy" href="{{ lroute('blog', $p['slug']) }}">{{ __('ui.kb_read') }} <svg class="icon dir"><use href="#i-arrow"/></svg></a>
        </div>
      </article>
      @empty
      @foreach($kb['articles'] as $i => $a)
      <article class="kb-card reveal" style="transition-delay:{{ $i * 60 }}ms" data-search="{{ lc($a)['t'] }} {{ $a['tag'] }}">
        <div class="kb-card-top">
          <span class="kb-tag">{{ $a['tag'] }}</span>
          <span class="kb-min"><svg class="icon"><use href="#i-clock"/></svg>{{ $isFa ? fa_num($a['min']) : $a['min'] }} {{ __('ui.kb_min') }}</span>
        </div>
        <span class="kb-ico"><svg class="icon"><use href="#i-{{ $a['icon'] }}"/></svg></span>
        <h3>{{ lc($a)['t'] }}</h3>
        <p>{{ lc($a)['d'] }}</p>
        <div class="kb-card-foot">
          <time>{{ lc($a)['date'] }}</time>
          <a class="buy" href="{{ lroute('blog.index') }}">{{ __('ui.kb_read') }} <svg class="icon dir"><use href="#i-arrow"/></svg></a>
        </div>
      </article>
      @endforeach
      @endforelse
    </div>

    {{-- لینک به آرشیوِ کاملِ بلاگ — هم برای کاربر و هم لینک‌سازیِ داخلی --}}
    @if(!empty($posts))
    <div style="text-align:center;margin-top:26px">
      <a class="btn btn-glass" href="{{ lroute('blog.index') }}">
        {{ __('ui.bl_all') }} <svg class="icon dir"><use href="#i-arrow"/></svg>
      </a>
    </div>
    @endif
  </div>
</section>

{{-- ============ WEBINARS ============ --}}
<section class="section" id="webinars" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ lc(config('servernet.knowledge_menu')[1])['t'] }}</span>
      <h2>{{ __('ui.kb_web_title') }}</h2>
      <p>{{ __('ui.kb_web_sub') }}</p>
    </div>
    <div class="kb-webinars">
      @foreach($kb['webinars'] as $i => $w)
      <div class="kb-webinar reveal" style="transition-delay:{{ $i * 70 }}ms">
        <span class="dc-icon know"><svg class="icon"><use href="#i-{{ $w['icon'] }}"/></svg></span>
        <div class="kb-web-txt">
          <b>{{ lc($w)['t'] }}</b>
          <small>{{ lc($w)['d'] }}</small>
          <span class="kb-web-meta"><svg class="icon"><use href="#i-video"/></svg>{{ lc($w)['date'] }} · <i dir="ltr">{{ $w['len'] }}</i></span>
        </div>
        <a class="btn btn-glass" href="#">{{ __('ui.kb_register') }}</a>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ DOCS ============ --}}
<section class="section" id="docs" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ lc(config('servernet.knowledge_menu')[2])['t'] }}</span>
      <h2>{{ __('ui.kb_docs_title') }}</h2>
      <p>{{ __('ui.kb_docs_sub') }}</p>
    </div>
    <div class="kb-docs">
      @foreach($kb['docs'] as $i => $d)
      <a class="kb-doc reveal" style="transition-delay:{{ $i * 50 }}ms" href="{{ lroute('docs.index') }}">
        <span class="dc-icon"><svg class="icon"><use href="#i-{{ $d['icon'] }}"/></svg></span>
        <b>{{ lc($d) }}</b>
        <small>{{ $isFa ? fa_num($d['count']) : $d['count'] }} {{ __('ui.kb_articles') }}</small>
      </a>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ LEARNING ============ --}}
<section class="section" id="learning" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ lc(config('servernet.knowledge_menu')[3])['t'] }}</span>
      <h2>{{ __('ui.kb_learn_title') }}</h2>
    </div>
    <div class="why-grid">
      @foreach($kb['learning'] as $i => $l)
      <div class="witem reveal" style="transition-delay:{{ $i * 60 }}ms">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $l['icon'] }}"/></svg></div>
        <div>
          <h4>{{ lc($l)['t'] }} <span class="free-badge">{{ lc($l['tag']) }}</span></h4>
          <p>{{ lc($l)['d'] }}</p>
        </div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ DEVELOPERS ============ --}}
<section class="section" id="developers" style="padding-top:40px;padding-bottom:60px">
  <div class="container">
    <div class="sig-panel sig-term reveal">
      <div class="section-head">
        <span class="badge">{{ __('ui.kb_dev_badge') }}</span>
        <h2>{{ __('ui.kb_dev_title') }}</h2>
        <p>{{ __('ui.kb_dev_sub') }}</p>
      </div>
      <div class="terminal" aria-hidden="true">
        <div class="bar"><i class="r"></i><i class="y"></i><i class="g"></i><span>api.servernet.cloud</span></div>
        <div class="body">
          <div class="ln p">$ curl -H "Authorization: Bearer $SNET_TOKEN" \</div>
          <div class="ln w">    https://api.servernet.cloud/v1/servers</div>
          <div class="ln ok">{ "servers": [ { "id": "srv-8f2a", "status": "running", "ip": "185.xx.xx.42" } ] }</div>
          <div class="ln c"># Full REST API · OpenAPI docs · Python & Node SDKs</div>
        </div>
      </div>
      <div style="text-align:center;margin-top:30px">
        <a class="btn btn-glass" href="#">{{ __('ui.kb_dev_cta') }}<svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
      </div>
    </div>
  </div>
</section>

{{-- ============ NEWSLETTER CTA ============ --}}
<section class="cta-wrap reveal">
  <div class="cta">
    <h2>{{ __('ui.kb_nl_title') }}</h2>
    <p>{{ __('ui.kb_nl_sub') }}</p>
    <form class="kb-nl" onsubmit="event.preventDefault(); this.querySelector('.kb-nl-ok').hidden=false; this.reset();">
      <input type="email" required placeholder="{{ __('ui.kb_nl_ph') }}" dir="ltr" aria-label="Email">
      <button class="btn btn-primary" type="submit">{{ __('ui.kb_nl_btn') }}</button>
      <span class="kb-nl-ok" hidden>✓ {{ __('ui.kb_nl_ok') }}</span>
    </form>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // جستجوی زنده روی کارت‌های مقاله
  const input = document.getElementById('kb-search');
  if (!input) return;
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    document.querySelectorAll('#kb-articles .kb-card').forEach((c) => {
      c.style.display = !q || (c.dataset.search + c.textContent).toLowerCase().includes(q) ? '' : 'none';
    });
    if (q) document.getElementById('blog').scrollIntoView({ behavior: 'smooth' });
  });
});
</script>
@endsection
