{{--
  سایدبار ابزارها — همه‌ی ۲۲ ابزار، گروه‌بندی‌شده، با ابزار جاری برجسته.
  در DOM بعد از محتوای اصلی می‌آید و با order در دسکتاپ به ابتدا منتقل می‌شود؛
  این‌طور کاربر صفحه‌کلید و خزنده اول به خود ابزار می‌رسند نه به ۲۲ لینک.

  ورودی: $slug (ابزار جاری)، $catKey (دسته‌ی جاری)
--}}
@php
    $wtCats = config('webtools.categories', []);
    $wtTotal = collect($wtCats)->sum(fn ($c) => count($c['tools'] ?? []));
@endphp

<aside class="wt-side" aria-label="{{ __('ui.wt_side_title') }}">
  <div class="wt-side-in">

    <div class="wt-side-top">
      <b>{{ __('ui.wt_side_title') }}</b>
      <span>{{ __('ui.wt_side_count', ['n' => $wtTotal]) }}</span>
    </div>

    <div class="wt-side-q">
      <svg class="icon"><use href="#i-search"/></svg>
      <input type="search" id="wt-side-q" autocomplete="off"
             placeholder="{{ __('ui.wt_side_search') }}"
             aria-label="{{ __('ui.wt_side_search') }}">
    </div>

    <nav class="wt-side-nav" id="wt-side-nav">
      @foreach($wtCats as $cKey => $c)
        @php $cl = lc($c); @endphp
        <div class="wt-side-sec {{ $cKey === $catKey ? 'cur' : '' }}" data-sec>
          <div class="wt-side-sec-h">
            <svg class="icon"><use href="#i-{{ $c['icon'] }}"/></svg>
            <span>{{ $cl['t'] }}</span>
          </div>
          <ul class="wt-side-list">
            @foreach($c['tools'] ?? [] as $tSlug => $tool)
              @php $tl = lc($tool); $isCur = $tSlug === $slug; @endphp
              <li data-name="{{ $tl['t'] }}" data-desc="{{ $tl['d'] }}">
                <a href="{{ lroute('webtools', $tSlug) }}"
                   class="{{ $isCur ? 'on' : '' }}"
                   @if($isCur) aria-current="page" @endif>
                  <svg class="icon"><use href="#i-{{ $tool['icon'] }}"/></svg>
                  <span>{{ $tl['t'] }}</span>
                  @if($isCur)<em>{{ __('ui.wt_side_current') }}</em>@endif
                </a>
              </li>
            @endforeach
          </ul>
        </div>
      @endforeach
      <p class="wt-side-none" id="wt-side-none" hidden>{{ __('ui.wt_side_none') }}</p>
    </nav>

    <a class="wt-side-all" href="{{ lroute('webtools.index') }}">
      {{ __('ui.wt_all') }}<svg class="icon dir"><use href="#i-arrow"/></svg>
    </a>

  </div>
</aside>

<script>
(function () {
  var q    = document.getElementById('wt-side-q');
  var nav  = document.getElementById('wt-side-nav');
  var none = document.getElementById('wt-side-none');
  if (!q || !nav) return;

  var ZWNJ = /[‌‎‏]/g;

  /* یکسان‌سازی: ی و ک عربی، ارقام عربی و فارسی، فاصله‌ی اضافه. */
  function fold(s) {
    return (s || '')
      .replace(/[يى]/g, 'ی')   // ي / ى  →  ی
      .replace(/ك/g, 'ک')           // ك عربی →  ک فارسی
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0630); })
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06c0); })
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  /* نیم‌فاصله دو جور تایپ می‌شود: «سازنده‌ی» را کاربر یا «سازنده ی» می‌نویسد
     یا «سازندهی». اگر فقط یکی را ایندکس کنیم، آن یکی هیچ نتیجه‌ای نمی‌دهد —
     پس هر دو شکل در انبار جستجو می‌رود. */
  function hayFor(s) {
    return fold(s.replace(ZWNJ, ' ')) + ' ‖ ' + fold(s.replace(ZWNJ, ''));
  }

  var items = [].slice.call(nav.querySelectorAll('li'));
  var secs  = [].slice.call(nav.querySelectorAll('[data-sec]'));

  items.forEach(function (li) {
    li.dataset.hay = hayFor(li.dataset.name + ' ' + li.dataset.desc + ' ' + li.querySelector('a').getAttribute('href'));
  });

  q.addEventListener('input', function () {
    /* تطبیق واژه‌به‌واژه، نه زیررشته‌ای: «سازنده‌ی رمز عبور» با نیم‌فاصله
       به «سازنده ی رمز عبور» باز می‌شود، و کاربری که «سازنده رمز» می‌نویسد
       آن «ی» تنها را رد می‌کند — پس زیررشته شکست می‌خورد ولی واژه‌ها می‌خورند.
       این‌طور ترتیب کلمات هم مهم نیست. */
    var words = fold(q.value.replace(ZWNJ, ' ')).split(' ').filter(Boolean);
    var hits = 0;

    items.forEach(function (li) {
      var hay = li.dataset.hay;
      var show = words.every(function (w) { return hay.indexOf(w) !== -1; });
      li.hidden = !show;
      if (show) hits++;
    });

    // دسته‌ای که همه‌ی ابزارهایش پنهان شده‌اند، خودش هم پنهان شود
    secs.forEach(function (sec) {
      sec.hidden = !sec.querySelector('li:not([hidden])');
    });

    none.hidden = hits > 0;
  });

  /* ابزار جاری را در کادر اسکرول‌دار به دید بیاور — بدون این، در فهرست بلند
     ممکن است کاربر اصلاً نبیند کجای فهرست ایستاده. */
  var cur = nav.querySelector('a.on');
  if (cur) {
    var box = nav.parentElement;
    var off = cur.offsetTop - box.clientHeight / 2 + cur.offsetHeight;
    if (off > 0) box.scrollTop = off;
  }
})();
</script>
