@php
    $repo = app(\App\Services\BlogRepository::class);
    $cats = config('blog.categories');
    $catCounts = $repo->categoryCounts();
    $recent = $repo->recent(5);
    $tags = $repo->popularTags(14);
@endphp
<aside class="blog-side">

  {{-- فهرست مطالب (فقط در صفحه‌ی تک‌پست؛ با JS از تیترهای محتوا ساخته می‌شود) --}}
  @if(!empty($showToc))
  <div class="bs-card bp-toc-card" id="bp-toc-card" hidden>
    <h3 class="bs-title"><svg class="icon"><use href="#i-list"/></svg>{{ __('ui.bl_toc') }}</h3>
    <nav><ul class="bp-toc" id="bp-toc"></ul></nav>
  </div>
  @endif

  {{-- جستجو --}}
  <div class="bs-card">
    <form class="bs-search" action="{{ lroute('blog.index') }}" method="get">
      <svg class="icon"><use href="#i-search"/></svg>
      <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="{{ __('ui.bl_search_ph') }}" aria-label="{{ __('ui.bl_search_ph') }}">
    </form>
  </div>

  {{-- دسته‌ها --}}
  <div class="bs-card">
    <h3 class="bs-title"><svg class="icon"><use href="#i-layout"/></svg>{{ __('ui.bl_categories') }}</h3>
    <ul class="bs-cats">
      @foreach($cats as $key => $c)
        @if(($catCounts[$key] ?? 0) > 0)
        <li><a href="{{ lroute('blog.index') }}?cat={{ $key }}">
          <span><svg class="icon" style="color:var(--cyan)"><use href="#i-{{ $c['icon'] }}"/></svg>{{ lc($c) }}</span>
          <b>{{ $isFa ? fa_num($catCounts[$key]) : $catCounts[$key] }}</b>
        </a></li>
        @endif
      @endforeach
    </ul>
  </div>

  {{-- آخرین مطالب --}}
  <div class="bs-card">
    <h3 class="bs-title"><svg class="icon"><use href="#i-clock"/></svg>{{ __('ui.bl_recent') }}</h3>
    <ul class="bs-recent">
      @foreach($recent as $r)
      <li><a href="{{ lroute('blog', $r['slug']) }}">
        <span class="bs-recent-cover {{ !empty($r['image']) ? 'has-img' : '' }}" @empty($r['image']) style="background:{{ config('blog.covers.'.($r['cover'] ?? 'a')) }}" @endempty>
          @if(!empty($r['image']))<img src="{{ $r['image'] }}" alt="" loading="lazy" decoding="async">@else<svg class="icon"><use href="#i-{{ $r['icon'] ?? 'book' }}"/></svg>@endif
        </span>
        <span class="bs-recent-tx"><b>{{ $r['title'] }}</b><small>{{ blog_date($r['date'] ?? '') }}</small></span>
      </a></li>
      @endforeach
    </ul>
  </div>

  {{-- تگ‌ها --}}
  @if($tags)
  <div class="bs-card">
    <h3 class="bs-title"><svg class="icon"><use href="#i-key"/></svg>{{ __('ui.bl_tags') }}</h3>
    <div class="bs-tags">
      @foreach($tags as $t)<a href="{{ lroute('blog.index') }}?tag={{ urlencode($t) }}">{{ $t }}</a>@endforeach
    </div>
  </div>
  @endif

  {{-- CTA --}}
  <div class="bs-card bs-cta">
    <div class="bs-cta-glow"></div>
    <span class="bs-cta-ic"><svg class="icon"><use href="#i-rocket"/></svg></span>
    <h3>{{ __('ui.bl_cta_t') }}</h3>
    <p>{{ __('ui.bl_cta_d') }}</p>
    <a class="btn btn-primary" href="{{ lroute('home') }}#products">{{ __('ui.bl_cta_btn') }}</a>
  </div>

</aside>
