@php
  /*
  |----------------------------------------------------------------------------
  | صفحهٔ عمومیِ انتقالِ دامنه
  |----------------------------------------------------------------------------
  |
  | ⚠️ هیچ عددِ قیمتی این‌جا نیست. قیمتِ انتقال per-TLD است و در لحظهٔ سفارش از
  | `TldPriceBook` خوانده می‌شود؛ عددِ تایپ‌شده در صفحهٔ بازاریابی اولین باری که
  | نرخِ ارز بپرد دروغ می‌گوید.
  |
  | ⚠️ فرم به روتِ **پنل** پست می‌شود. مهمانِ واردنشده به ورود هدایت می‌شود و
  | نامِ دامنه‌اش در کوئری می‌مانَد تا بعد از ورود دوباره تایپ نکند.
  |
  | ⚠️ صفحهٔ تازه هیچ `padding-top` نمی‌گذارد — `#main` فضای هدرِ ثابت را
  | یک‌جا رزرو می‌کند (قانونِ ثبت‌شده در CLAUDE.md §۳).
  */
  $c    = require resource_path('content/domain-transfer.php');
  $L    = fn (string $k) => lc($c[$k] ?? []);
  $isFa = app()->getLocale() === 'fa';
  $n    = fn ($v) => $isFa ? fa_num((string) $v) : (string) $v;

  $signedIn = auth('customer')->check();
@endphp

@extends('layouts.site')

@section('title', $L('title').' — '.__('ui.brand'))
@section('description', $L('meta_desc'))

@section('content')

@php
  // FAQPage از **همان** آرایه‌ای ساخته می‌شود که روی صفحه رندر می‌شود، نه یک
  // نسخهٔ موازی — وگرنه روزی متن عوض می‌شود و نشانه‌گذاری حرفِ قدیمی را می‌زند.
  $faqLd = [];
  foreach ((array) $L('faqs') as $f) {
      $faqLd[] = ['@type' => 'Question', 'name' => $f[0],
                  'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($f[1])]];
  }
  $howLd = [];
  foreach ((array) $L('how_steps') as $i => $s) {
      $howLd[] = ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $s[0], 'text' => $s[1]];
  }
@endphp
<script type="application/ld+json">{!! schema_ld(['mainEntity' => $faqLd], 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld(['name' => $L('title'), 'step' => $howLd], 'HowTo') !!}</script>
<script type="application/ld+json">{!! schema_ld(['itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
  ['@type' => 'ListItem', 'position' => 2, 'name' => $L('title'), 'item' => url()->current()],
]], 'BreadcrumbList') !!}</script>

<section class="hero hero-sub" id="dt-top">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $L('badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $L('title') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $L('lead') }}</p>

      <form class="dt-form reveal" style="transition-delay:.24s"
            method="POST" action="{{ $signedIn ? lroute('account.domains.transfer') : lroute('login') }}">
        @csrf
        <label class="dt-field">
          <span class="dt-lbl">{{ $L('form_label') }}</span>
          <input type="text" name="domain" required dir="ltr" autocomplete="off"
                 placeholder="example.com" pattern="[A-Za-z0-9\-\.]{3,253}">
        </label>
        <button class="btn btn-primary" type="submit">
          <span>{{ $L('cta') }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
        </button>
      </form>

      @unless($signedIn)
        <p class="dt-note reveal" style="transition-delay:.3s">{{ $L('cta_note') }}</p>
      @endunless
    </div>
  </div>
</section>

<section class="section">
  <div class="container">

    {{-- ══ چرا ══ --}}
    <h2 class="sec-title">{{ $L('why') }}</h2>
    <div class="dt-grid">
      @foreach((array) $L('why_items') as $w)
        <div class="dt-card">
          <b>{{ $w[0] }}</b>
          <p>{{ $w[1] }}</p>
        </div>
      @endforeach
    </div>

    {{-- ══ پیش‌نیاز ══ --}}
    <h2 class="sec-title" style="margin-top:64px">{{ $L('need') }}</h2>
    <p class="dt-sub">{{ $L('need_p') }}</p>
    <div class="dt-grid">
      @foreach((array) $L('need_items') as $i => $w)
        <div class="dt-card dt-need">
          <span class="dt-n">{{ $n($i + 1) }}</span>
          <div><b>{{ $w[0] }}</b><p>{{ $w[1] }}</p></div>
        </div>
      @endforeach
    </div>

    {{-- ══ مراحل ══ --}}
    <h2 class="sec-title" style="margin-top:64px">{{ $L('how') }}</h2>
    <ol class="dt-steps">
      @foreach((array) $L('how_steps') as $s)
        <li><b>{{ $s[0] }}</b><span>{{ $s[1] }}</span></li>
      @endforeach
    </ol>

    {{-- ══ صداقت ══
         🔴 این بخش باید بمانَد. صفحه‌ای که فقط مزیت بگوید، ناخواسته ادعا
         می‌کند بقیهٔ چیزها مشکلی ندارند — و اولین مشتری‌ای که با پنجرهٔ ۵ روزهٔ
         رجیستری روبه‌رو شود، حس می‌کند فریب خورده. --}}
    <h2 class="sec-title" style="margin-top:64px">{{ $L('honest') }}</h2>
    <ul class="dt-honest">
      @foreach((array) $L('honest_items') as $h)
        {{-- ⚠️ اول escape، بعد پررنگ: ترتیبِ عکس یعنی HTMLِ محتوا اجرا شود --}}
        <li>{!! preg_replace('~\*\*(.+?)\*\*~u', '<b>$1</b>', e($h)) !!}</li>
      @endforeach
    </ul>

    {{-- ══ پرسش‌ها ══ --}}
    <h2 class="sec-title" style="margin-top:64px">{{ $L('faq') }}</h2>
    <div class="dt-faq">
      @foreach((array) $L('faqs') as $f)
        <details>
          <summary>{{ $f[0] }}</summary>
          <p>{{ $f[1] }}</p>
        </details>
      @endforeach
    </div>

    <p style="margin-top:44px;text-align:center">
      <a class="btn btn-primary" href="#dt-top">{{ $L('cta') }}</a>
      <a class="btn btn-glass" href="{{ lroute('domain.search') }}">{{ $L('search_cta') }}</a>
    </p>
  </div>
</section>
@endsection
