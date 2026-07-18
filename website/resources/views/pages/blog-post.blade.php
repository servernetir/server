@extends('layouts.site')

@php
    $cats = config('blog.categories');
    $covers = config('blog.covers');
    $cat = $cats[$post['category']] ?? null;
    $url = url()->current();
    $shareTitle = rawurlencode($post['title']);
    $shareUrl = rawurlencode($url);
@endphp

@section('title', $post['title'].' — '.__('ui.brand'))
@section('description', $post['excerpt'] ?? '')

@section('content')

{{-- ============ POST HEADER ============ --}}
<section class="hero hero-sub blog-post-hero" style="padding-bottom:24px">
  <div class="container" style="max-width:860px">
    <nav class="blog-crumbs reveal">
      <a href="{{ lroute('home') }}">{{ __('ui.bl_home') }}</a><span>/</span>
      <a href="{{ lroute('blog.index') }}">{{ __('ui.bl_title') }}</a>
      @if($cat)<span>/</span><a href="{{ lroute('blog.index') }}?cat={{ $post['category'] }}">{{ lc($cat) }}</a>@endif
    </nav>
    @if($cat)<span class="blog-post-cat reveal" style="transition-delay:.05s">{{ lc($cat) }}</span>@endif
    <h1 class="reveal" style="transition-delay:.1s">{{ $post['title'] }}</h1>
    <div class="blog-post-meta reveal" style="transition-delay:.16s">
      <span><svg class="icon"><use href="#i-user"/></svg>{{ $post['author'] ?? __('ui.brand') }}</span>
      <span><svg class="icon"><use href="#i-clock"/></svg>{{ blog_date($post['date'] ?? '') }}</span>
      <span><svg class="icon"><use href="#i-book"/></svg>{{ $isFa ? fa_num($post['reading']) : $post['reading'] }} {{ __('ui.bl_min') }}</span>
    </div>
  </div>
</section>

{{-- ============ COVER ============ --}}
<section class="container" style="max-width:900px;margin-bottom:8px">
  <div class="blog-post-cover reveal" style="background:{{ $covers[$post['cover'] ?? 'a'] ?? '' }}">
    <svg class="icon"><use href="#i-{{ $post['icon'] ?? 'book' }}"/></svg>
  </div>
</section>

{{-- ============ BODY + SHARE ============ --}}
<section class="section" style="padding-top:20px;padding-bottom:50px">
  <div class="container" style="max-width:760px">
    <article class="blog-prose reveal">{!! $post['content'] ?? '' !!}</article>

    @if(!empty($post['tags']))
    <div class="blog-post-tags reveal">
      @foreach($post['tags'] as $t)<a href="{{ lroute('blog.index') }}?tag={{ urlencode($t) }}">{{ $t }}</a>@endforeach
    </div>
    @endif

    {{-- share --}}
    <div class="blog-share reveal">
      <span>{{ __('ui.bl_share') }}</span>
      <a class="bsh tg" href="https://t.me/share/url?url={{ $shareUrl }}&text={{ $shareTitle }}" target="_blank" rel="noopener" aria-label="Telegram"><svg class="icon"><use href="#i-send"/></svg></a>
      <a class="bsh wa" href="https://wa.me/?text={{ $shareTitle }}%20{{ $shareUrl }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="icon"><use href="#i-message"/></svg></a>
      <a class="bsh in" href="https://www.linkedin.com/sharing/share-offsite/?url={{ $shareUrl }}" target="_blank" rel="noopener" aria-label="LinkedIn"><svg class="icon"><use href="#i-linkedin"/></svg></a>
      <button class="bsh cp" type="button" id="blog-copy" data-url="{{ $url }}" aria-label="Copy link"><svg class="icon"><use href="#i-link"/></svg></button>
    </div>
  </div>
</section>

{{-- ============ COMMENTS ============ --}}
<section class="section" id="comments" style="padding-top:0;padding-bottom:40px">
  <div class="container" style="max-width:760px">
    <h2 class="blog-comments-h reveal">{{ __('ui.bl_comments') }} <b>{{ $isFa ? fa_num($comments->count()) : $comments->count() }}</b></h2>

    @if(session('comment_status') === 'pending')
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
          <div class="blog-comment-h"><b>{{ $c->name }}</b><small>{{ blog_date($c->created_at->toDateString()) }}</small></div>
          <p>{{ $c->body }}</p>
        </div>
      </div>
      @endforeach
    </div>
    @else
    <p class="blog-no-comments reveal">{{ __('ui.bl_no_comments') }}</p>
    @endif

    {{-- comment form --}}
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
</section>

{{-- ============ RELATED ============ --}}
@if(count($related))
<section class="section" style="padding-top:10px;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:26px"><h2 style="font-size:24px">{{ __('ui.bl_related') }}</h2></div>
    <div class="blog-grid blog-grid-3">
      @foreach($related as $i => $p)
      @php $rc = $cats[$p['category']] ?? null; @endphp
      <article class="blog-card reveal" style="transition-delay:{{ $i * 50 }}ms">
        <a class="blog-card-cover" href="{{ lroute('blog', $p['slug']) }}" style="background:{{ $covers[$p['cover'] ?? 'a'] ?? '' }}">
          <svg class="icon"><use href="#i-{{ $p['icon'] ?? 'book' }}"/></svg>
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
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org', '@type' => 'BlogPosting',
    'headline' => $post['title'], 'description' => $post['excerpt'] ?? '',
    'datePublished' => $post['date'] ?? null, 'inLanguage' => app()->getLocale(),
    'author' => ['@type' => 'Organization', 'name' => 'ServerNet'],
    'publisher' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
    'mainEntityOfPage' => $url,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

<script>
document.getElementById('blog-copy')?.addEventListener('click', async (e) => {
  try { await navigator.clipboard.writeText(e.currentTarget.dataset.url); e.currentTarget.classList.add('done'); setTimeout(()=>e.currentTarget.classList.remove('done'),1500); } catch {}
});
</script>
@endsection
