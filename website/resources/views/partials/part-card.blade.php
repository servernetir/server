{{--
  کارتِ یک قطعه — مشترکِ فهرستِ دسته، صفحهٔ نسل، و «پرفروش‌ها».

  ⚠️ چک‌باکسِ مقایسه **بیرونِ** لینک است. اگر داخلش بود، هر تیک‌زدن کاربر را
  به صفحهٔ محصول می‌بُرد و انتخابِ چندتایی — که کلِ هدفِ مقایسه است — عملاً
  ناممکن می‌شد.

  @param  \App\Models\ServerPart  $part
  @param  bool  $compare  نمایشِ چک‌باکسِ مقایسه
--}}
@php
    $compare = $compare ?? true;
    $price   = $part->displayPrice();
    $gens    = (array) ($part->compat_gens ?? []);
    $condLbl = [
        'new'    => __('ui.parts_cond_new'),
        'refurb' => __('ui.parts_cond_refurb'),
        'used'   => __('ui.parts_cond_used'),
    ];
@endphp

<article class="sp-card" data-slug="{{ $part->slug }}">
    <a class="sp-card-main" href="{{ lroute('parts.show', [$part->category, $part->slug]) }}">
        {{-- ⚠️ `img_url()` و نه `!empty()`: ردیفی که رشتهٔ «null» دارد از
             `!empty()` رد می‌شود و `src="null"` می‌سازد — یعنی یک ۴۰۴ به‌ازای
             هر کارت. همان تلهٔ فروشگاهِ سرورِ فیزیکی. --}}
        @if($thumb = img_url(\App\Services\Shop\HardwarePresentation::metadata($part->slug)['thumbnail'] ?? null) ?? img_url(((array) ($part->gallery ?? []))[0] ?? null))
            <span class="sp-thumb"><img src="{{ $thumb }}" alt="{{ \App\Services\Shop\HardwarePresentation::imageAlt($part->slug, $part->label(), $thumb) }}" width="640" height="480" loading="lazy" decoding="async"></span>
        @endif

        <div class="sp-card-head">
            <span class="sp-cat">{{ $part->categoryLabel() }}</span>
            <span class="sp-cond {{ $part->condition }}">{{ \App\Services\Shop\HardwarePresentation::condition($part->condition) }}</span>
        </div>

        <h3>{{ $part->label() }}</h3>

        @if($tag = ($part->tagline[app()->getLocale()] ?? $part->tagline['fa'] ?? null))
            <p class="sp-tag">{{ $tag }}</p>
        @endif

        @if($gens)
            <ul class="sp-gens">
                @foreach(array_slice($gens, 0, 5) as $g)
                    {{-- ⚠️ `strtoupper` نه: «Gen9» را «GEN9» می‌کرد و همان
                         نسل در چیپِ فیلترِ بالای همان صفحه «Gen9» بود. --}}
                    <li>{{ str_replace('gen', 'Gen', $g) }}</li>
                @endforeach
            </ul>
        @endif

        <div class="sp-card-foot">
            @if($price === null)
                <span class="sp-price contact">{{ __('ui.parts_quote') }}</span>
            @else
                <span class="sp-price">{{ $price }}</span>
            @endif

            {{-- ناموجودی پنهان نمی‌شود: خریدارِ فنی که راه افتاده و بعد می‌فهمد
                 نیست، دیگر برنمی‌گردد. --}}
            <span @class(['sp-stock', 'out' => ! $part->in_stock])>
                {{ \App\Services\Shop\HardwarePresentation::stock($part) }}
            </span>
        </div>
    </a>

    @if($compare)
        <label class="sp-cmp">
            <input type="checkbox" class="sp-cmp-box" value="{{ $part->slug }}">
            <span>{{ __('ui.parts_compare') }}</span>
        </label>
    @endif
</article>
