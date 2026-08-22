@extends('layouts.site')

@php
    $cats = config('blog.categories');
    $covers = config('blog.covers');
    $cat = $cats[$post['category']] ?? null;
    $url = url()->current();
    $shareTitle = rawurlencode($post['title']);
    $shareUrl = rawurlencode($url);
    /* ⚠️ `img_url()` مقدارِ خرابِ رشتهٔ «null» را هم نال می‌کند؛ وگرنه
       `src="null"` نسبی حل می‌شود و از `/en/blog/<slug>` یک ۴۰۴ روی
       `/en/blog/null` می‌سازد. */
    $img = img_url($post['image'] ?? null);              // تصویر شاخص واقعی (در صورت وجود)
    $author = $post['author'] ?? __('ui.brand');
    $initial = mb_strtoupper(mb_substr(trim($author), 0, 1));
    $reading = $isFa ? fa_num($post['reading']) : $post['reading'];
    /* پلِ بلاگ→محصول (ممیزی ۳): سرویسِ فروختنیِ متناظر با دستهٔ همین پست.
       null یعنی نگاشت/محصول نیست و بلاک اصلاً رندر نمی‌شود — لینکِ مرده ممنوع. */
    $relProduct = blog_related_product($post['category'] ?? null);
@endphp

@section('title', $post['title'].' — '.__('ui.brand'))
@section('description', $post['excerpt'] ?? '')

@section('content')

{{-- نوار پیشرفت مطالعه --}}
<div class="bp-progress" aria-hidden="true"><span id="bp-bar"></span></div>

{{-- ============ HEADER ============ --}}
<section class="bp-head">
  <div class="container">
    <nav class="blog-crumbs reveal">
      <a href="{{ lroute('home') }}">{{ __('ui.bl_home') }}</a><span>/</span>
      <a href="{{ lroute('blog.index') }}">{{ __('ui.bl_title') }}</a>
      @if($cat)<span>/</span><a href="{{ lroute('blog.index') }}?cat={{ $post['category'] }}">{{ lc($cat) }}</a>@endif
    </nav>

    <div class="bp-head-tx">
      @if($cat)
      <a class="blog-post-cat reveal" href="{{ lroute('blog.index') }}?cat={{ $post['category'] }}" style="transition-delay:.05s">
        <svg class="icon"><use href="#i-{{ $cat['icon'] }}"/></svg>{{ lc($cat) }}
      </a>
      @endif
      <h1 class="reveal" style="transition-delay:.1s">{{ $post['title'] }}</h1>
      @if(!empty($post['excerpt']))
      <p class="bp-lead reveal" style="transition-delay:.14s">{{ $post['excerpt'] }}</p>
      @endif

      <div class="bp-byline reveal" style="transition-delay:.18s">
        <span class="bp-av" aria-hidden="true">{{ $initial }}</span>
        <div class="bp-byline-tx">
          <b>{{ $author }}</b>
          <small>
            <span><svg class="icon"><use href="#i-clock"/></svg>{{ blog_date($post['date'] ?? '') }}</span>
            <i>·</i>
            <span><svg class="icon"><use href="#i-book"/></svg>{{ $reading }} {{ __('ui.bl_min') }}</span>
          </small>
        </div>
        {{-- ممیزی ۴ (QA، ۴ دور): هیچ hrefی با الگوی share نماند — نقطه‌های
             اشتراکِ تلگرام و لینکدین بدونِ کوئری ۴۰۴اند و چهار دور «لینکِ
             اشتراک‌گذاری شکسته» شمرده شدند (Audit4RegressionTest سورسِ همین
             فایل را قفل کرده، پس آن الگوها حتی در کامنت هم ممنوع‌اند).
             جایگزین: لینک ایستا + اشتراکِ بومی (موبایل، تلگرام را هم می‌گیرد). --}}
        <div class="bp-share-inline">
          <a class="bsh wa" href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="icon"><use href="#i-message"/></svg></a>
          <a class="bsh em" href="mailto:?subject={{ $shareTitle }}&body={{ $shareTitle }}%0A{{ $shareUrl }}" aria-label="Email"><svg class="icon"><use href="#i-mail"/></svg></a>
          <button class="bsh ns" type="button" data-native-share data-title="{{ $post['title'] }}" data-url="{{ $url }}" hidden aria-label="{{ __('ui.bl_share') }}"><svg class="icon"><use href="#i-send"/></svg></button>
          <button class="bsh cp" type="button" id="blog-copy" data-url="{{ $url }}" data-done="{{ __('ui.bl_copied') }}" aria-label="{{ __('ui.bl_share') }}"><svg class="icon"><use href="#i-link"/></svg></button>
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ============ تصویر شاخص ============ --}}
<section class="container">
  <figure class="bp-cover reveal {{ $img ? 'has-img' : '' }}" @unless($img) style="background:{{ $covers[$post['cover'] ?? 'a'] ?? '' }}" @endunless>
    @if($img)
      <img src="{{ $img }}" alt="{{ $post['title'] }}" fetchpriority="high" decoding="async">
    @else
      <span class="bp-cover-grid" aria-hidden="true"></span>
      <span class="bp-cover-glow" aria-hidden="true"></span>
      <svg class="bp-cover-ic" aria-hidden="true"><use href="#i-{{ $post['icon'] ?? 'book' }}"/></svg>
    @endif
    <span class="bp-cover-scrim" aria-hidden="true"></span>
    @if($cat)<span class="bp-cover-badge"><svg class="icon"><use href="#i-{{ $cat['icon'] }}"/></svg>{{ lc($cat) }}</span>@endif
  </figure>
</section>

{{-- ============ بدنه + سایدبار ============ --}}
<section class="section bp-main">
  <div class="container">
    <div class="bp-layout">

      <div class="bp-col">
        <article class="blog-prose reveal" id="bp-article">{!! $post['content'] ?? '' !!}</article>

        @if(!empty($post['tags']))
        <div class="blog-post-tags reveal">
          @foreach($post['tags'] as $t)<a href="{{ lroute('blog.index') }}?tag={{ urlencode($t) }}">{{ $t }}</a>@endforeach
        </div>
        @endif

        {{-- کارت نویسنده --}}
        <div class="bp-author reveal">
          <span class="bp-av lg" aria-hidden="true">{{ $initial }}</span>
          <div class="bp-author-tx">
            <b>{{ $author }}</b>
            <p>{{ __('ui.bl_author_bio') }}</p>
          </div>
          {{-- CTA به محصولِ مرتبط، نه /contact: ممیزی ۳ نشان داد ۱۰۷ پست ×
               «تماس با ما» بزرگ‌ترین تغذیه‌کنندهٔ /contact بود (۲۶۰ لینک) در
               حالی که مسیرِ خرید صفر لینک می‌گرفت. تماس در هدر/فوتر هست. --}}
          @if($relProduct)
          <a class="btn btn-glass bp-author-cta" href="{{ $relProduct['href'] }}"><svg class="icon"><use href="#i-server"/></svg>{{ $relProduct['title'] }}</a>
          @else
          <a class="btn btn-glass bp-author-cta" href="{{ lroute('contact') }}"><svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.nav_contact') }}</a>
          @endif
        </div>

        {{-- اشتراک‌گذاری — همان قاعدهٔ ردیفِ بالای صفحه: صفر href با /share|/sharing --}}
        <div class="blog-share reveal">
          <span>{{ __('ui.bl_share') }}</span>
          <a class="bsh wa" href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="icon"><use href="#i-message"/></svg></a>
          <a class="bsh em" href="mailto:?subject={{ $shareTitle }}&body={{ $shareTitle }}%0A{{ $shareUrl }}" aria-label="Email"><svg class="icon"><use href="#i-mail"/></svg></a>
          <button class="bsh ns" type="button" data-native-share data-title="{{ $post['title'] }}" data-url="{{ $url }}" hidden aria-label="{{ __('ui.bl_share') }}"><svg class="icon"><use href="#i-send"/></svg></button>
          <button class="bsh cp" type="button" data-copy data-url="{{ $url }}" data-done="{{ __('ui.bl_copied') }}" aria-label="{{ __('ui.bl_share') }}"><svg class="icon"><use href="#i-link"/></svg></button>
        </div>

        {{-- ============ نظرات ============ --}}
        <div id="comments" class="bp-comments-wrap">
          <h2 class="blog-comments-h reveal">{{ __('ui.bl_comments') }} <b>{{ $isFa ? fa_num($comments->count()) : $comments->count() }}</b></h2>

          @if(session('comment_status') === 'published')
          <div class="blog-note ok reveal">{{ __('ui.bl_published') }}</div>
          @elseif(session('comment_status') === 'pending')
          <div class="blog-note ok reveal">{{ __('ui.bl_pending') }}</div>
          @elseif(session('comment_status') === 'busy')
          <div class="blog-note reveal">{{ __('ui.bl_busy') }}</div>
          @endif

          @if($comments->count())
          <div class="blog-comments reveal">
            @foreach($comments as $c)
            <div class="blog-comment">
              <span class="blog-comment-av">{{ mb_substr($c->name, 0, 1) }}</span>
              <div class="blog-comment-b">
                <div class="blog-comment-h">
                  <b>{{ $c->name }}</b>
                  <small>{{ blog_date($c->created_at->toDateString()) }}</small>
                  @if($c->isTranslated())<span class="bc-tr" title="{{ __('ui.bl_translated_t') }}"><svg class="icon"><use href="#i-globe"/></svg>{{ __('ui.bl_translated') }}</span>@endif
                </div>
                <p>{{ $c->bodyIn() }}</p>

                @if($c->replyIn())
                <div class="bc-reply">
                  <div class="bc-reply-h">
                    <span class="bc-reply-av"><svg class="icon"><use href="#i-headset"/></svg></span>
                    <b>{{ __('ui.bl_reply_by') }}</b>
                    <span class="bc-ai">{{ __('ui.bl_ai_badge') }}</span>
                  </div>
                  <p>{{ $c->replyIn() }}</p>
                </div>
                @endif
              </div>
            </div>
            @endforeach
          </div>
          @else
          <p class="blog-no-comments reveal">{{ __('ui.bl_no_comments') }}</p>
          @endif

          <form class="blog-comment-form reveal" method="post" action="{{ lroute('blog.comment', $post['slug']) }}">
            @csrf
            <h3>{{ __('ui.bl_leave') }}</h3>
            @if($errors->any())<div class="blog-note err">{{ __('ui.bl_err') }}</div>@endif
            <div class="bcf-row">
              <input type="text" name="name" placeholder="{{ __('ui.bl_name') }}" maxlength="80" required value="{{ old('name') }}">
              <input type="email" name="email" placeholder="{{ __('ui.bl_email') }}" maxlength="120" value="{{ old('email') }}">
            </div>
            <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
            <textarea name="body" rows="4" placeholder="{{ __('ui.bl_your') }}" maxlength="2000" required>{{ old('body') }}</textarea>
            <button class="btn btn-primary" type="submit">{{ __('ui.bl_submit') }}<svg class="icon dir"><use href="#i-send"/></svg></button>
          </form>
        </div>
      </div>

      {{-- سایدبار چسبان با فهرست مطالب --}}
      <div class="bp-side-col">
        @include('partials.blog-sidebar', ['showToc' => true])
      </div>

    </div>
  </div>
</section>

{{-- ============ سرویس مرتبط (پل بلاگ→محصول — ممیزی ۳) ============
     بالای «مطالب مرتبط»، dofollow و با انکرِ توصیفی (نامِ واقعیِ محصول از
     configِ خودش). چون در قالب است، هر ۱۰۷ پستِ موجود و هر پستِ آینده
     خودبه‌خود حداقل یک لینک به صفحهٔ قابلِ خرید می‌دهند. --}}
@if($relProduct)
<section class="section" style="padding-top:0;padding-bottom:0">
  <div class="container">
    <div class="sol-cta reveal" style="padding:38px 30px">
      <div class="sol-cta-glow"></div>
      <span class="badge">{{ __('ui.bl_product_badge') }}</span>
      <h2 style="margin-top:12px">{{ $relProduct['title'] }}</h2>
      @if($relProduct['desc'] !== '')<p>{{ $relProduct['desc'] }}</p>@endif
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ $relProduct['href'] }}">{{ $relProduct['title'] }} — {{ __('ui.bl_product_cta') }}<svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
      </div>
    </div>
  </div>
</section>
@endif

{{-- ============ مطالب مرتبط ============ --}}
@if(count($related))
<section class="section bp-related">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:26px"><h2 style="font-size:24px">{{ __('ui.bl_related') }}</h2></div>
    <div class="blog-grid blog-grid-3">
      @foreach($related as $i => $p)
      @php $rc = $cats[$p['category']] ?? null; @endphp
      <article class="blog-card reveal" style="transition-delay:{{ $i * 50 }}ms">
        @php $pImg = img_url($p['image'] ?? null); @endphp
        <a class="blog-card-cover {{ $pImg ? 'has-img' : '' }}" href="{{ lroute('blog', $p['slug']) }}" @unless($pImg) style="background:{{ $covers[$p['cover'] ?? 'a'] ?? '' }}" @endunless>
          @if($pImg)
            <img src="{{ $pImg }}" alt="{{ $p['title'] }}" loading="lazy" decoding="async">
          @else
            <svg class="icon"><use href="#i-{{ $p['icon'] ?? 'book' }}"/></svg>
          @endif
          @if($rc)<span class="blog-card-cat">{{ lc($rc) }}</span>@endif
        </a>
        <div class="blog-card-body">
          <div class="blog-card-meta"><span>{{ blog_date($p['date'] ?? '') }}</span></div>
          <h2><a href="{{ lroute('blog', $p['slug']) }}">{{ $p['title'] }}</a></h2>
          <p>{{ $p['excerpt'] ?? '' }}</p>
        </div>
      </article>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ Article schema ============ --}}
<script type="application/ld+json">{!! json_encode(array_filter([
    '@'.'context' => 'https://schema.org', '@type' => 'BlogPosting',
    'headline' => $post['title'], 'description' => $post['excerpt'] ?? '',
    'image' => $img ? url($img) : null,
    'datePublished' => $post['date'] ?? null, 'inLanguage' => app()->getLocale(),
    'wordCount' => word_count_fa($post['content'] ?? '') ?: null,
    'author' => ['@type' => 'Organization', 'name' => 'ServerNet'],
    'publisher' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
    'mainEntityOfPage' => $url,
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

<script>
(function () {
  const article = document.getElementById('bp-article');

  /* ---- اشتراکِ بومی (موبایل) — فقط وقتی مرورگر پشتیبانی کند دیده می‌شود.
     روی موبایل شیتِ سیستم باز می‌شود و تلگرام/هرچیزِ نصب‌شده را پوشش می‌دهد —
     بدونِ هیچ hrefِ /share که ممیزی ۴ دور شکسته شمرد. ---- */
  document.querySelectorAll('[data-native-share]').forEach(btn => {
    if (!navigator.share) return;
    btn.hidden = false;
    btn.addEventListener('click', () => {
      navigator.share({ title: btn.dataset.title, url: btn.dataset.url }).catch(() => {});
    });
  });

  /* ---- کپی لینک ---- */
  document.querySelectorAll('#blog-copy,[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(btn.dataset.url);
        btn.classList.add('done');
        setTimeout(() => btn.classList.remove('done'), 1500);
      } catch (e) {}
    });
  });

  if (!article) return;

  /* ---- ساخت فهرست مطالب از تیترها ---- */
  const heads = article.querySelectorAll('h2, h3');
  const list = document.getElementById('bp-toc');
  const card = document.getElementById('bp-toc-card');
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
      a.href = '#' + h.id;
      a.textContent = h.textContent;
      if (h.tagName === 'H3') li.className = 'sub';
      a.addEventListener('click', ev => {
        ev.preventDefault();
        h.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', '#' + h.id);
      });
      li.appendChild(a);
      list.appendChild(li);
      links.push({ el: h, a });
    });
    card.hidden = false;

    /* ---- هایلایت تیتر فعال هنگام اسکرول ---- */
    const spy = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (!en.isIntersecting) return;
        links.forEach(l => l.a.classList.toggle('on', l.el === en.target));
      });
    }, { rootMargin: '-90px 0px -70% 0px', threshold: 0 });
    heads.forEach(h => spy.observe(h));
  }

  /* ---- نوار پیشرفت مطالعه ---- */
  const bar = document.getElementById('bp-bar');
  if (bar) {
    let ticking = false;
    const update = () => {
      // موقعیت مطلق مقاله در سند (offsetTop نسبت به offsetParent است و اشتباه می‌شود)
      const top = article.getBoundingClientRect().top + window.scrollY;
      const end = top + article.offsetHeight;
      const mark = window.scrollY + window.innerHeight * 0.65;   // نقطه‌ی مطالعه
      const pct = Math.max(0, Math.min(1, (mark - top) / Math.max(1, end - top)));
      bar.style.transform = 'scaleX(' + pct + ')';
      ticking = false;
    };
    addEventListener('scroll', () => {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    addEventListener('resize', update, { passive: true });
    update();
  }
})();
</script>
@endsection
