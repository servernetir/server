@extends('layouts.site')

@section('title', lc(config('webdesign.meta.title')).' — '.__('ui.brand'))
@section('description', lc(config('webdesign.meta.desc')))

@section('content')

{{--
  صفحهٔ فرودِ شخصیِ «طراحی سایت و زیرساخت» — /webdesign

  ⚠️ عمداً **در منوی اصلی نیست** (config/servernet.php دست‌نخورده). مقصدِ لینکِ
  پروفایلِ لینکدین و اینستاگرام است. ولی در نقشهٔ سایت **هست** — این دو یکی
  نیستند، و کلِ هدفِ صفحه ورودیِ ارگانیک از «طراحی سایت در ارومیه» است.

  ⚠️ هیچ کلاسِ CSSِ تازه‌ای ندارد: همهٔ کلاس‌ها از `sol-*` و `lk-faq` موجود در
  site.css می‌آیند. کلاسِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود؛ پس صفحهٔ
  تازه با استایلِ تازه، دو ریسکِ هم‌زمان بود.

  ⚠️ padding-top نمی‌گذارد — `#main` جبرانِ هدرِ ثابت را سراسری انجام می‌دهد.
--}}

@php
  $w   = config('webdesign');
  $loc = app()->getLocale();
  $lx  = fn ($v) => is_array($v) ? ($v[$loc] ?? $v['en'] ?? '') : $v;

  // ⚠️ **متن** را ساده نگه می‌داریم و فقط `**تأکید**` را به <b> تبدیل می‌کنیم.
  //    e() اول اجرا می‌شود، پس ورودی هرگز به‌صورت HTML خام رد نمی‌شود.
  $md = fn ($s) => preg_replace('~\*\*(.+?)\*\*~us', '<b>$1</b>', e($s));

  $city  = $lx($w['city']);
  $mail  = 'mailto:'.$w['email'];
  $faqLd = array_map(fn ($f) => ['q' => $lx($f['q']), 'a' => $lx($f['a'])], $w['faq']['items']);

  /*
  | قیمت — فارسی تومان، en/tr یورو.
  |
  | ⚠️ عمداً `site_price()` نیست: آن `price_factor()`ِ سراسریِ هاست را ضرب
  |    می‌کند و ضریبی که مدیر برای پکیج‌های میزبانی می‌گذارد نباید قیمتِ
  |    خدماتِ طراحی را جابه‌جا کند. این‌جا هیچ نرخِ ارزی هم دخیل نیست، پس
  |    قطعیِ سرویسِ نرخ نمی‌تواند صفحه را به «—» تبدیل کند.
  */
  $pct = (int) ($w['pricing']['discount_pct'] ?? 0);

  $money = function (array $p, bool $discounted = false) use ($isFa, $pct) {
      $f = $discounted ? (100 - $pct) / 100 : 1;

      if ($isFa) {
          // گردکردن به نزدیک‌ترین ۱۰۰٬۰۰۰ تومان تا عدد عمدی به نظر برسد
          $v = (int) round(((int) $p['irt']) * $f, -5);

          return fa_num(number_format($v)).' تومان';
      }

      return '€'.number_format((int) round(((int) $p['eur']) * $f));
  };

  // «از» فقط روی پلنی که سقفش بعد از جلسهٔ شناخت مشخص می‌شود
  $from = fn (array $p, string $s) => empty($p['from']) ? $s
      : ($isFa ? 'از '.$s : ($loc === 'tr' ? $s.' üzeri' : 'from '.$s));
@endphp

{{-- ═══ قهرمان ═══ --}}
<section class="hero hero-sub sol-hero sol-cyan">
  <div class="sol-hero-glow"></div>
  <div class="container">
    <div class="sol-hero-inner">
      <div class="sol-hero-txt">
        <span class="badge reveal"><span class="pulse"></span><span>{{ $lx($w['hero']['badge']) }}</span></span>

        <h1 class="reveal" style="transition-delay:.08s">
          {{ $lx($w['hero']['h1a']) }} <span class="grad">{{ $lx($w['hero']['h1b']) }}</span> {{ $lx($w['hero']['h1c']) }}
        </h1>

        <p class="lead reveal" style="transition-delay:.16s">{!! $md($lx($w['hero']['lead'])) !!}</p>

        <div class="sol-hero-cta reveal" style="transition-delay:.22s">
          <a class="btn btn-primary" href="{{ $mail }}">
            <span>{{ $lx($w['hero']['cta1']) }}</span>
            <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
          </a>
          <a class="btn btn-glass" href="#packages">{{ $lx($w['hero']['cta2']) }}</a>
        </div>
      </div>

      <div class="sol-hero-stats reveal" style="transition-delay:.3s">
        @foreach($w['stats'] as $s)
        <div class="sol-stat">
          <b>{{ $isFa && is_numeric($s['n']) ? fa_num($s['n']) : $s['n'] }}</b>
          <span>{{ $lx($s) }}</span>
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ═══ مسئله ═══ --}}
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['problem']['kicker']) }}</span>
      <h2>{{ $lx($w['problem']['h2']) }}</h2>
      <p>{!! $md($lx($w['problem']['lead'])) !!}</p>
    </div>

    {{-- ⚠️ `cols-4` لازم است: پیش‌فرضِ `.sol-feat-grid` سه‌ستونه است، پس چهار
         کارت «۳+۱» می‌شد و کارتِ چهارم عملاً از دیدِ کاربر می‌افتاد. --}}
    <div class="sol-feat-grid cols-4">
      @foreach($w['problem']['items'] as $p)
      <div class="sol-feat reveal">
        <span class="sol-feat-ic"><svg class="icon"><use href="#i-info"/></svg></span>
        <h3>{{ $lx($p['q']) }}</h3>
        <p>{{ $lx($p['a']) }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ خدمات ═══ --}}
<section class="section" id="services">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['services']['kicker']) }}</span>
      <h2>{{ $lx($w['services']['h2']) }}</h2>
      <p>{{ $lx($w['services']['lead']) }}</p>
    </div>

    <div class="sol-feat-grid cols-4">
      @foreach($w['services']['items'] as $s)
      <div class="sol-feat reveal">
        <span class="sol-feat-ic"><svg class="icon"><use href="#{{ $s['icon'] }}"/></svg></span>
        <h3>{{ $lx($s['t']) }}</h3>
        <p>{!! $md($lx($s['d'])) !!}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ قیمت ═══ --}}
<section class="section" id="packages">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['pricing']['kicker']) }}</span>
      <h2>{{ $lx($w['pricing']['h2']) }}</h2>
      <p>{!! $md($lx($w['pricing']['lead'])) !!}</p>
    </div>

    <div class="sol-plans">
      @foreach($w['pricing']['plans'] as $p)
      <div class="sol-plan reveal @if($p['popular']) featured @endif">
        @if($p['popular'])<span class="sol-plan-tag">{{ __('ui.popular') }}</span>@endif
        <h3>{{ $lx($p['name']) }}</h3>
        <p class="sol-plan-tag2">{{ $lx($p['for']) }}</p>

        {{-- قیمتِ پیشین خط‌خورده تا اندازهٔ تخفیف واقعاً دیده شود --}}
        <div class="wd-price">
          @if($pct > 0)
            <span class="wd-was">{{ $from($p['price'], $money($p['price'])) }}</span>
            <span class="wd-off">{{ $lx($w['pricing']['discount_badge']) }}</span>
          @endif
          <b>{{ $from($p['price'], $money($p['price'], true)) }}</b>
        </div>
        <ul class="sol-plan-feats">
          @foreach($p['features'] as $f)
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $lx($f) }}</li>
          @endforeach
        </ul>
        <p class="sol-plan-tag2">{{ $lx($p['time']) }}</p>
        <a class="btn btn-glass" href="{{ $mail }}">{{ $lx($p['cta']) }}</a>
      </div>
      @endforeach
    </div>

    @php
      $care = strtr($lx($w['pricing']['care']['text']), [
          '{a}' => $money($w['pricing']['care']['hosting'], true),
          '{b}' => $money($w['pricing']['care']['social'], true),
      ]);
    @endphp
    <p class="sol-plans-note reveal">{!! $md($care) !!}</p>
    @if($pct > 0)
      <p class="sol-plans-note reveal" style="margin-top:8px">{!! $md($lx($w['pricing']['discount_note'])) !!}</p>
    @endif
  </div>
</section>

{{-- ═══ سابقه ═══ --}}
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['background']['kicker']) }}</span>
      <h2>{{ $lx($w['background']['h2']) }}</h2>
      <p>{{ $lx($w['background']['lead']) }}</p>
    </div>

    <div class="sol-feat-grid">
      @foreach($w['background']['items'] as $b)
      <div class="sol-feat reveal">
        <span class="sol-feat-ic"><svg class="icon"><use href="#i-factory"/></svg></span>
        <h3>{{ $b['org'] }}</h3>
        <p>{{ $lx($b['d']) }}</p>
      </div>
      @endforeach
    </div>

    <div class="sla-doc reveal" style="margin-top:28px;max-width:820px;margin-inline:auto">
      <blockquote style="margin:0">
        <p>{{ $lx($w['background']['quote']) }}</p>
        <p style="opacity:.75;font-size:14px">— {{ $lx($w['background']['quote_by']) }}</p>
      </blockquote>
    </div>
  </div>
</section>

{{-- ═══ فرآیند ═══ --}}
<section class="section sol-steps-sec">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['process']['kicker']) }}</span>
      <h2>{{ $lx($w['process']['h2']) }}</h2>
    </div>

    <div class="sol-steps">
      @foreach($w['process']['items'] as $i => $st)
      <div class="sol-step reveal">
        <span class="sol-step-n">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span>
        <h3>{{ $lx($st['t']) }}</h3>
        <p>{{ $lx($st['d']) }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ پرسش‌ها ═══ --}}
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">{{ $lx($w['faq']['kicker']) }}</span>
      <h2>{{ $lx($w['faq']['h2']) }}</h2>
    </div>

    <div class="lk-faq reveal">
      @foreach($faqLd as $f)
      <details class="lk-faq-item"><summary>{{ $f['q'] }}</summary><p>{!! $md($f['a']) !!}</p></details>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ فراخوان پایانی ═══ --}}
<section class="section">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>{{ $lx($w['cta']['h2']) }}</h2>
      <p>{{ $lx($w['cta']['lead']) }}</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ $mail }}">
          <span>{{ $lx($w['cta']['btn']) }}</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
        </a>
        <a class="btn btn-glass" href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
      </div>
      <p style="margin-top:14px;font-size:14px;opacity:.8" dir="ltr">{{ $w['email'] }}</p>
    </div>
  </div>
</section>

{{-- ═══ دادهٔ ساختاریافته ═══
  ⚠️ `areaServed` = ارومیه. کلیدواژهٔ محلی فقط در تگِ عنوان رتبه نمی‌گیرد؛
     هم در متنِ صفحه آمده هم این‌جا.
  ⚠️ `@context` را Blade می‌بلعد — پس فقط از schema_ld() رد می‌شود. --}}
@php
  $T      = '@'.'type';   // ⚠️ هرگز سخت‌کد ننویس — Blade هر @word را directive می‌گیرد
  $person = $isFa ? $w['person_fa'] : $w['person'];

  $ldService = [
      'name' => $person.' — '.$w['brand'],
      'description' => $lx($w['meta']['desc']),
      'url' => url()->current(),
      'email' => $w['email'],
      // ⚠️ در نسخهٔ فارسی نمادِ یورو نمی‌گذاریم: کسب‌وکارِ محلیِ ارومیه که در
      //    نتایج گوگل «€€» بخورد، به مشتریِ ایرانی پیامِ اشتباه می‌دهد.
      'priceRange' => $isFa ? '﷼﷼' : '€€',
      'areaServed' => [
          [$T => 'City', 'name' => $city],
          [$T => 'Country', 'name' => $isFa ? 'ایران' : 'Iran'],
          [$T => 'Country', 'name' => $isFa ? 'ترکیه' : 'Türkiye'],
      ],
      'address' => [$T => 'PostalAddress', 'addressLocality' => $city, 'addressCountry' => 'IR'],
      'founder' => [$T => 'Person', 'name' => $person, 'jobTitle' => 'Founder & CEO'],
      'knowsLanguage' => ['fa', 'en', 'tr'],
      'makesOffer' => array_map(fn ($p) => [
          $T => 'Offer',
          'name' => $lx($p['name']),
          'description' => $lx($p['for']),
          'priceCurrency' => 'EUR',
          // ⚠️ بی‌این، مدل‌های زبانی قیمتِ امروز را ماه‌ها بعد نقل می‌کنند
          'priceValidUntil' => now()->addMonths(6)->toDateString(),
      ], $w['pricing']['plans']),
  ];

  $ldFaq = [
      'mainEntity' => array_map(fn ($f) => [
          $T => 'Question',
          'name' => $f['q'],
          'acceptedAnswer' => [$T => 'Answer', 'text' => strip_tags(str_replace('**', '', $f['a']))],
      ], $faqLd),
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldFaq, 'FAQPage') !!}</script>

@endsection
