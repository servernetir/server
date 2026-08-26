{{--
  بلاکِ «راهنماها و مقالات مرتبط» — پلِ محصول→بلاگ (ممیزی ۳).

  سه پستِ تازهٔ دستهٔ بلاگِ مرتبط با این صفحهٔ محصول، کاملاً خودکار از
  `blog_guides()`. چون در قالب است، هر صفحهٔ محصولِ موجود و آینده بدونِ کارِ
  دستی حداقل ۳ لینکِ dofollow به بلاگ می‌دهد — معیارِ پذیرشِ دورِ چهارم.

  ورودی: $guidesCat (اسلاگِ دستهٔ بلاگ — نبودنش یعنی پرشدن با تازه‌ترین‌ها)

  ⚠️ مارک‌آپ همان کارتِ بلاگ است (blog-grid / blog-card در site.css) تا هیچ
  کلاسِ CSS تازه‌ای لازم نشود — کلاسِ نبود، بی‌خطا بی‌استایل رندر می‌شود.
--}}
@php
  // بذرِ پخش: مسیرِ همین صفحه — هر صفحهٔ محصول سه پستِ متفاوتِ همان دسته را لینک می‌دهد (ممیزی ۶)
  $gPosts = blog_guides($guidesCat ?? null, 3, $guidesSeed ?? request()->path());
  $gCats = config('blog.categories');
  $gCovers = config('blog.covers');
@endphp
@if(count($gPosts))
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:26px">
      <h2 style="font-size:24px">{{ __('ui.hp_guides_title') }}</h2>
    </div>
    <div class="blog-grid blog-grid-3">
      @foreach($gPosts as $i => $gp)
      @php $gc = $gCats[$gp['category']] ?? null; $gImg = img_url($gp['image'] ?? null); @endphp
      <article class="blog-card reveal" style="transition-delay:{{ $i * 50 }}ms">
        <a class="blog-card-cover {{ $gImg ? 'has-img' : '' }}" href="{{ lroute('blog', $gp['slug']) }}" @unless($gImg) style="background:{{ $gCovers[$gp['cover'] ?? 'a'] ?? '' }}" @endunless>
          @if($gImg)
            <img src="{{ $gImg }}" alt="{{ $gp['title'] }}" loading="lazy" decoding="async">
          @else
            <svg class="icon"><use href="#i-{{ $gp['icon'] ?? 'book' }}"/></svg>
          @endif
          @if($gc)<span class="blog-card-cat">{{ lc($gc) }}</span>@endif
        </a>
        <div class="blog-card-body">
          <div class="blog-card-meta"><span>{{ blog_date($gp['date'] ?? '') }}</span></div>
          {{-- h2 عمدی است: site.css فقط `.blog-card h2` را استایل می‌دهد (الگوی خودِ بلاگ) --}}
          <h2><a href="{{ lroute('blog', $gp['slug']) }}">{{ $gp['title'] }}</a></h2>
          <p>{{ $gp['excerpt'] ?? '' }}</p>
        </div>
      </article>
      @endforeach
    </div>
    <div class="reveal" style="text-align:center;margin-top:22px">
      <a class="btn btn-glass" href="{{ lroute('blog.index') }}{{ !empty($guidesCat) ? '?cat='.$guidesCat : '' }}">{{ __('ui.hp_guides_all') }}<svg class="icon dir" style="width:15px;height:15px"><use href="#i-arrow"/></svg></a>
    </div>
  </div>
</section>
@endif
