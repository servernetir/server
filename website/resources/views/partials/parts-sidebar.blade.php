{{--
  سایدبارِ فروشگاهِ قطعات — مشترکِ هر پنج صفحه.

  🔴 چرا `<details>` و نه یک `<div>` با جاوااسکریپت:
  روی موبایل سایدبار باید جمع شود، وگرنه کاربر برای رسیدن به اولین محصول
  باید از کنارِ ۹ دسته و ۵ نسل رد شود. `<details>` باز و بسته شدن را خودش
  می‌دهد؛ اگر اسکریپت هم نرسد، منو کار می‌کند.

  🔴 چرا `open` در HTML و بستنش با اسکریپت — و نه برعکس:
  نسخهٔ اول `<details>` را **بسته** می‌گذاشت و می‌خواست با
  `.sp-side-inner{display:block}` روی دسکتاپ بازش کند. کار نکرد و ستونِ
  سایدبار **کاملاً خالی** رندر شد: کروم محتوای `<details>`ِ بسته را با
  `display` پنهان نمی‌کند، بلکه اصلاً به slot نمی‌فرستد — پس هیچ قاعدهٔ CSSای
  نمی‌تواند برش گرداند. صفحه ۲۰۰ بود و هیچ خطایی نداشت؛ فقط یک ستونِ خالیِ
  ۲۶۴ پیکسلی. حالا HTML باز است (حالتِ درستِ دسکتاپ و حالتِ امن بی‌جاوااسکریپت)
  و اسکریپت فقط روی نمایشگرِ باریک می‌بنددش.

  ⚠️ `$activeCat` و `$activeGen` اختیاری‌اند: صفحهٔ هاب و مقایسه هیچ‌کدام را
  ندارند و نباید خطا بدهند.
--}}
@php
    $activeCat = $activeCat ?? null;
    $activeGen = $activeGen ?? null;
@endphp

<details class="sp-side" id="sp-side" open>
    <summary class="sp-side-toggle">
        <svg class="icon"><use href="#i-list"/></svg>
        <span>{{ __('ui.parts_browse') }}</span>
        <svg class="icon sp-side-chev"><use href="#i-chev"/></svg>
    </summary>

    <nav class="sp-side-inner" aria-label="{{ __('ui.parts_browse') }}">
        <div class="sp-side-group">
            <h3>{{ __('ui.parts_browse') }}</h3>
            <ul>
                @foreach($categories as $key => $c)
                    <li>
                        <a href="{{ lroute('parts.category', $key) }}"
                           @class(['on' => $activeCat === $key])
                           @if($activeCat === $key) aria-current="page" @endif>
                            <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>
                            <span>{{ $c['label'] }}</span>
                            {{-- دستهٔ خالی عدد نمی‌گیرد: «۰» کنارِ نام، فروشگاه را
                                 خالی‌تر از آن‌چه هست نشان می‌دهد. --}}
                            @if($c['count'] > 0)
                                <b>{{ fa_num($c['count']) }}</b>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="sp-side-group">
            <h3>{{ __('ui.parts_gens') }}</h3>
            <ul>
                @foreach($generations as $key => $g)
                    <li>
                        <a href="{{ lroute('servers.generation', $key) }}"
                           @class(['on' => $activeGen === $key])
                           @if($activeGen === $key) aria-current="page" @endif>
                            <svg class="icon"><use href="#i-server"/></svg>
                            <span>{{ lc($g['name']) }}</span>
                            <em class="sp-side-years" dir="ltr">{{ fa_num($g['years']) }}</em>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <a class="sp-side-cta" href="{{ lroute('contact') }}">
            <svg class="icon"><use href="#i-headset"/></svg>
            <span>
                <b>{{ __('ui.parts_ask') }}</b>
                {{ __('ui.parts_ask_sub') }}
            </span>
        </a>
    </nav>
</details>

<script>
/* سایدبار روی نمایشگرِ باریک بسته شروع می‌شود و روی پهن همیشه باز است.
   ⚠️ گوش‌دادن به resize لازم است نه فقط لودِ اول: کاربری که روی موبایل
   بست و بعد دستگاه را چرخاند، وگرنه روی دسکتاپ هم ستونِ خالی می‌دید. */
(function () {
  var el = document.getElementById('sp-side');
  if (!el) return;
  var wide = window.matchMedia('(min-width:1001px)');
  var sync = function () { el.open = wide.matches; };
  sync();
  wide.addEventListener ? wide.addEventListener('change', sync) : wide.addListener(sync);
})();
</script>
