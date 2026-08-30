{{--
  صفحهٔ فرودِ «سرور مجازی ساعتی» — /vps/hourly (fa / en / tr).

  نقشِ سئویی: تنها صفحه‌ای که برای کلیدواژهٔ «سرور مجازی ساعتی» و مشتقاتش
  («سرور ساعتی ایران»، «خرید سرور ساعتی») رتبه می‌گیرد. ساختارش عمداً
  پرسش‌محور است (تیترها همان سؤال‌هایی‌اند که کاربر واقعاً می‌پرسد) و هر بخش با
  یک پاسخِ مستقیم شروع می‌شود تا موتورهای پاسخ (AI Overview، Perplexity،
  ChatGPT) بتوانند قطعهٔ خودبسنده استخراج کنند.

  ⚠️ هیچ عددی در این ویو ساخته نمی‌شود؛ همه از HourlyVpsController می‌آید که
     خودش از CloudPlan می‌خواند. عددِ سخت‌کد این‌جا = قیمتِ دروغ در آیندهٔ نزدیک.
  ⚠️ نخستین بستهٔ در-جریان `.section` است (نه `.hero`) تا جبرانِ هدر را از
     `#main` بگیرد و دو بار جبران نکند (FixedHeaderOffsetTest).
  ⚠️ استایل درجاست با پیشوندِ `hv-` — site.css مرزِ agentِ دیگری است و کلاسِ
     نبود، بی‌خطا بی‌استایل رندر می‌شود.
--}}
@extends('layouts.site')

@php
  $hvN = count($countries);
  $hvNLabel = $isFa ? fa_num($hvN) : (string) $hvN;
  $hvHours = $isFa ? fa_num($minHours) : (string) $minHours;

  $hvMetaD = $fromHourly
      ? __('ui.hv_meta_d', ['price' => $fromHourly, 'n' => $hvNLabel])
      : __('ui.hv_meta_d_nop');

  // جای‌نگهدارهای مشترک — لاراول `:key` را جایگزین می‌کند
  $hvRep = ['hours' => $hvHours, 'min' => $minStart ?? ''];
  $hvStep1 = $minStart ? __('ui.hv_step1_d', $hvRep) : __('ui.hv_step1_d_nop', $hvRep);
  $hvFaq2 = $minStart ? __('ui.hv_faq2_a', $hvRep) : __('ui.hv_faq2_a_nop', $hvRep);
  $hvFaq5 = $irCities ? __('ui.hv_faq5_a', ['cities' => implode($isFa ? '، ' : ', ', $irCities)]) : __('ui.hv_faq5_a_nop');

  $hvFaq = [
      ['q' => __('ui.hv_faq1_q'), 'a' => __('ui.hv_faq1_a')],
      ['q' => __('ui.hv_faq2_q'), 'a' => $hvFaq2],
      ['q' => __('ui.hv_faq3_q'), 'a' => __('ui.hv_faq3_a', $hvRep)],
      ['q' => __('ui.hv_faq4_q'), 'a' => __('ui.hv_faq4_a')],
      ['q' => __('ui.hv_faq5_q'), 'a' => $hvFaq5],
      ['q' => __('ui.hv_faq6_q'), 'a' => __('ui.hv_faq6_a')],
      ['q' => __('ui.hv_faq7_q'), 'a' => __('ui.hv_faq7_a')],
      ['q' => __('ui.hv_faq8_q'), 'a' => __('ui.hv_faq8_a')],
  ];

  $hvSteps = [
      ['t' => __('ui.hv_step1_t'), 'd' => $hvStep1],
      ['t' => __('ui.hv_step2_t'), 'd' => __('ui.hv_step2_d')],
      ['t' => __('ui.hv_step3_t'), 'd' => __('ui.hv_step3_d')],
      ['t' => __('ui.hv_step4_t'), 'd' => __('ui.hv_step4_d')],
  ];

  $hvUses = [
      ['i' => 'check',  't' => __('ui.hv_use1_t'), 'd' => __('ui.hv_use1_d')],
      ['i' => 'cpu',    't' => __('ui.hv_use2_t'), 'd' => __('ui.hv_use2_d')],
      ['i' => 'cloud',  't' => __('ui.hv_use3_t'), 'd' => __('ui.hv_use3_d')],
      ['i' => 'globe',  't' => __('ui.hv_use4_t'), 'd' => __('ui.hv_use4_d')],
      ['i' => 'arrow',  't' => __('ui.hv_use5_t'), 'd' => __('ui.hv_use5_d')],
      ['i' => 'db',     't' => __('ui.hv_use6_t'), 'd' => __('ui.hv_use6_d')],
  ];

  $hvVsRows = [
      [__('ui.hv_vs_r1'), __('ui.hv_vs_r1_h'), __('ui.hv_vs_r1_m')],
      [__('ui.hv_vs_r2'), __('ui.hv_vs_r2_h', $hvRep), __('ui.hv_vs_r2_m')],
      [__('ui.hv_vs_r3'), __('ui.hv_vs_r3_h'), __('ui.hv_vs_r3_m')],
      [__('ui.hv_vs_r4'), __('ui.hv_vs_r4_h'), __('ui.hv_vs_r4_m')],
      [__('ui.hv_vs_r5'), __('ui.hv_vs_r5_h'), __('ui.hv_vs_r5_m')],
  ];

  /*
  | دادهٔ ساختاریافته.
  |
  | Product + Offer با UnitPriceSpecification (unitCode HUR = ساعت) تا موتور
  | جست‌وجو و مدل‌های زبانی بفهمند این قیمت **ساعتی** است، نه ماهانه. IRR یعنی
  | ریال، پس عددِ تومانی ×۱۰ می‌شود (همان قاعدهٔ hosting.blade). بدونِ قیمتِ ارزی
  | روی en/tr، اصلاً Offer ساخته نمی‌شود — نشانه‌گذاریِ نبود از غلط بهتر است.
  */
  $hvCur = $isFa ? 'IRR' : 'EUR';
  $hvOffers = [];
  foreach ($featured as $hvF) {
      $hvRaw = $hvF['ld_price'] ?? null;
      if ($hvRaw === null || $hvRaw <= 0) {
          continue;
      }
      // + schema_offer_extras: هشدارهای Merchant listings (ممیزی ۲۴ اوت ۲۰۲۶)
      $hvOffers[] = schema_offer_extras($hvCur) + [
          '@type' => 'Offer',
          'name' => $hvF['name'].' — '.$hvF['city'],
          'priceCurrency' => $hvCur,
          'price' => $hvRaw,
          'priceSpecification' => [
              '@type' => 'UnitPriceSpecification',
              'price' => $hvRaw,
              'priceCurrency' => $hvCur,
              'unitCode' => 'HUR',
              'unitText' => $isFa ? 'ساعت' : 'hour',
          ],
          'priceValidUntil' => now()->addDays(30)->toDateString(),
          'availability' => 'https://schema.org/InStock',
          'url' => url()->current(),
      ];
  }
  $hvProduct = [
      'name' => __('ui.hv_badge'),
      'description' => $hvMetaD,
      'url' => url()->current(),
      // image: بدونش Merchant listings آیتم را invalid می‌کند.
      'image' => [asset('assets/img/og.png')],
      'brand' => ['@type' => 'Brand', 'name' => __('ui.brand')],
      'offers' => $hvOffers,
  ];
  $hvFaqLd = [];
  foreach ($hvFaq as $hvQ) {
      $hvFaqLd[] = ['@type' => 'Question', 'name' => $hvQ['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $hvQ['a']]];
  }
  $hvCrumbs = ['itemListElement' => [
      ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $homeUrl],
      ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.f_p2'), 'item' => $cloudUrl],
      ['@type' => 'ListItem', 'position' => 3, 'name' => __('ui.hv_badge'), 'item' => url()->current()],
  ]];
  $hvHowLd = ['name' => __('ui.hv_how_t'), 'step' => []];
  foreach ($hvSteps as $hvI => $hvS) {
      $hvHowLd['step'][] = ['@type' => 'HowToStep', 'position' => $hvI + 1, 'name' => $hvS['t'], 'text' => $hvS['d']];
  }
@endphp

@section('title', __('ui.hv_meta_t'))
@section('description', $hvMetaD)

@section('content')

@if($hvOffers)
<script type="application/ld+json">{!! schema_ld($hvProduct, 'Product') !!}</script>
@endif
<script type="application/ld+json">{!! schema_ld(['mainEntity' => $hvFaqLd], 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($hvCrumbs, 'BreadcrumbList') !!}</script>
<script type="application/ld+json">{!! schema_ld($hvHowLd, 'HowTo') !!}</script>

{{-- ═══════════ سرتیتر ═══════════ --}}
<section class="section hv-top">
  <div class="container">
    <nav class="hv-crumbs" aria-label="breadcrumb">
      <a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a>
      <span aria-hidden="true">/</span>
      <a href="{{ $cloudUrl }}">{{ __('ui.f_p2') }}</a>
      <span aria-hidden="true">/</span>
      <span>{{ __('ui.hv_badge') }}</span>
    </nav>

    <div class="hv-head">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.hv_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.hv_h1') }} <span class="grad">{{ __('ui.hv_h1_g') }}</span></h1>
      {{-- پاسخِ مستقیم در ۴۰–۶۰ کلمهٔ اول: همین پاراگراف است که نقل می‌شود --}}
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.hv_lead') }}</p>

      <div class="hv-pills reveal" style="transition-delay:.22s">
        @if($fromHourly)
          <span class="hv-pill hv-pill-p">{{ __('ui.from') }} <b>{{ $fromHourly }}</b> {{ __('ui.hv_per_hour') }}</span>
        @endif
        <span class="hv-pill">{{ __('ui.hv_pill_deliver') }}</span>
        <span class="hv-pill">{{ __('ui.hv_pill_nocommit') }}</span>
        @if($hvN > 0)
          <span class="hv-pill">{{ $irCities ? __('ui.hv_pill_iran').' · ' : '' }}{{ __('ui.hv_pill_countries', ['n' => $hvNLabel]) }}</span>
        @endif
      </div>

      <div class="hero-ctas reveal" style="transition-delay:.28s">
        <a class="btn btn-primary" href="{{ $storeUrl }}" rel="nofollow"><span>{{ __('ui.hv_cta_order') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="#rates">{{ __('ui.hv_cta_rates') }}</a>
      </div>
    </div>
  </div>
</section>

{{-- ═══════════ چطور کار می‌کند ═══════════ --}}
<section class="section hv-sec" id="how">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.hv_how_t') }}</h2>
      <p>{{ __('ui.hv_how_d') }}</p>
    </div>
    <ol class="hv-steps">
      @foreach($hvSteps as $i => $s)
        <li class="hv-step reveal" style="transition-delay:{{ $i * 60 }}ms">
          <span class="hv-step-n">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span>
          <h3>{{ $s['t'] }}</h3>
          <p>{{ $s['d'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>

{{-- ═══════════ نرخ‌ها به تفکیک کشور ═══════════ --}}
<section class="section hv-sec" id="rates">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.hv_rates_t') }}</h2>
      <p>{{ __('ui.hv_rates_d') }}</p>
    </div>

    @if($countries)
      <div class="hv-table-wrap">
        <table class="hv-table">
          <thead>
            <tr>
              <th>{{ __('ui.hv_th_country') }}</th>
              <th>{{ __('ui.hv_th_hourly') }}</th>
              <th>{{ __('ui.hv_th_monthly') }}</th>
              <th>{{ __('ui.hv_th_plans') }}</th>
              <th>{{ __('ui.hv_th_link') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($countries as $c)
              <tr>
                <td class="hv-td-c">@include('partials.flag', ['flagSrc' => $c['flag_svg'], 'flagEmoji' => $c['flag'], 'flagSize' => 18]) <span>{{ $c['label'] }}</span></td>
                <td class="hv-td-p"><b>{{ $c['hourly'] }}</b> <small>{{ __('ui.hv_per_hour') }}</small></td>
                <td>{{ $c['monthly'] }} <small>{{ __('ui.mo') }}</small></td>
                <td>{{ $isFa ? fa_num($c['plans']) : $c['plans'] }}</td>
                <td><a class="hv-link" href="{{ $c['url'] }}">{{ $isFa ? 'سرور مجازی '.$c['label'] : $c['label'].' VPS' }}</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="hv-empty">
        <p>{{ __('ui.hv_rates_empty') }}</p>
        <a class="btn btn-primary" href="{{ $storeUrl }}" rel="nofollow">{{ __('ui.hv_cta_order') }}</a>
      </div>
    @endif
  </div>
</section>

{{-- ═══════════ نمونه پلن‌ها ═══════════ --}}
@if($featured)
<section class="section hv-sec" id="plans">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.hv_featured_t') }}</h2>
      <p>{{ __('ui.hv_featured_d') }}</p>
    </div>
    <div class="hv-cards">
      @foreach($featured as $i => $f)
        <article class="hv-card reveal" style="transition-delay:{{ ($i % 4) * 50 }}ms">
          <header>
            <span class="hv-card-loc">@include('partials.flag', ['flagSrc' => $f['flag_svg'], 'flagEmoji' => $f['flag'], 'flagSize' => 16]) {{ $f['city'] }}</span>
            <b>{{ $f['name'] }}</b>
            <small>{{ $f['cpu_kind'] }}</small>
          </header>
          <dl>
            <div><dt>vCPU</dt><dd dir="ltr">{{ $isFa ? fa_num($f['vcpu']) : $f['vcpu'] }}</dd></div>
            <div><dt>RAM</dt><dd dir="ltr">{{ $f['ram'] }}</dd></div>
            <div><dt>NVMe</dt><dd dir="ltr">{{ $f['disk'] }}</dd></div>
            <div><dt>{{ __('ui.traffic') }}</dt><dd dir="ltr">{{ $f['traffic'] }}</dd></div>
          </dl>
          <div class="hv-card-price">
            <b>{{ $f['hourly'] }}</b><span>{{ __('ui.hv_per_hour') }}</span>
          </div>
          <ul class="hv-card-meta">
            <li><span>{{ __('ui.hv_card_monthly') }}</span><b>{{ $f['monthly'] }}</b></li>
            <li><span>{{ __('ui.hv_card_min') }}</span><b>{{ $f['min_start'] }}</b></li>
          </ul>
          <a class="hv-buy" href="{{ $f['buy_url'] }}" rel="nofollow">{{ __('ui.hv_card_buy') }}</a>
        </article>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ═══════════ ساعتی یا ماهانه ═══════════ --}}
<section class="section hv-sec" id="hourly-vs-monthly">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.hv_vs_t') }}</h2>
      <p>{{ __('ui.hv_vs_lead') }}</p>
    </div>
    <div class="hv-table-wrap">
      <table class="hv-table hv-table-vs">
        <thead>
          <tr>
            <th>{{ __('ui.hv_vs_h_item') }}</th>
            <th>{{ __('ui.hv_vs_h_hourly') }}</th>
            <th>{{ __('ui.hv_vs_h_monthly') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($hvVsRows as $r)
            <tr><th scope="row">{{ $r[0] }}</th><td>{{ $r[1] }}</td><td>{{ $r[2] }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>

{{-- ═══════════ کاربردها ═══════════ --}}
<section class="section hv-sec" id="use-cases">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.hv_uses_t') }}</h2>
    </div>
    <div class="hv-uses">
      @foreach($hvUses as $i => $u)
        <div class="hv-use reveal" style="transition-delay:{{ $i * 40 }}ms">
          <svg class="icon"><use href="#i-{{ $u['i'] }}"/></svg>
          <h3>{{ $u['t'] }}</h3>
          <p>{{ $u['d'] }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══════════ پرسش‌های متداول ═══════════ --}}
<section class="section hv-sec" id="faq">
  <div class="container">
    <div class="hv-sec-h">
      <h2>{{ __('ui.faq_title') }}</h2>
    </div>
    <div class="hv-faq">
      @foreach($hvFaq as $i => $row)
        <details @if($i === 0) open @endif>
          <summary>{{ $row['q'] }}</summary>
          <div>{{ $row['a'] }}</div>
        </details>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══════════ لینک‌سازی داخلی ═══════════ --}}
<section class="section hv-sec hv-cross-sec">
  <div class="container">
    <h2 class="hv-cross-t">{{ __('ui.hv_cross_t') }}</h2>
    <div class="hv-cross">
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'iran']) }}">{{ __('ui.hv_cross_iran') }}</a>
      <a href="{{ $cloudUrl }}">{{ __('ui.hv_cross_all') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'trading']) }}">{{ __('ui.hv_cross_trading') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']) }}">{{ __('ui.hv_cross_iaas') }}</a>
      <a href="{{ lroute('domain.search') }}">{{ __('ui.hv_cross_domains') }}</a>
      <a href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
    </div>
  </div>
</section>

{{-- راهنماهای بلاگ (پل محصول→بلاگ) --}}
@include('partials.product-guides', ['guidesCat' => config('blog.product_guides.cloud')])

<style>
/* صفحهٔ سرور ساعتی — استایلِ درجا با پیشوندِ hv- */
.hv-top{ padding-bottom:30px }
.hv-sec{ padding:44px 0 }
.hv-crumbs{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--dim); margin-bottom:18px; flex-wrap:wrap }
.hv-crumbs a{ color:var(--muted) }
.hv-crumbs a:hover{ color:var(--cyan) }
.hv-head{ max-width:860px }
.hv-head h1{ font-family:var(--font-disp); font-size:clamp(27px,4.4vw,44px); font-weight:700;
  letter-spacing:-.6px; line-height:1.25; margin:14px 0 16px; text-wrap:balance }
.hv-head .lead{ color:var(--muted); font-size:15px; line-height:2.1; max-width:760px }
.hv-pills{ display:flex; flex-wrap:wrap; gap:9px; margin-top:22px }
.hv-pill{ font-size:12.5px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:6px 14px; background:var(--surface) }
.hv-pill-p{ border-color:rgba(34,211,238,.3); color:var(--text) }
.hv-pill-p b{ color:var(--cyan); font-weight:700 }
.hv-head .hero-ctas{ margin-top:22px }

.hv-sec-h{ margin-bottom:22px; max-width:860px }
.hv-sec-h h2{ font-family:var(--font-disp); font-size:clamp(20px,3vw,27px); font-weight:700; letter-spacing:-.5px; margin-bottom:10px }
.hv-sec-h p{ color:var(--muted); font-size:14.2px; line-height:2 }

.hv-steps{ list-style:none; display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; counter-reset:none }
.hv-step{ border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:20px }
.hv-step-n{ display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%;
  background:var(--grad); color:#fff; font-weight:700; font-size:14px; margin-bottom:12px }
.hv-step h3{ font-size:15px; font-weight:700; margin-bottom:8px }
.hv-step p{ color:var(--muted); font-size:13.2px; line-height:1.95 }

.hv-table-wrap{ overflow-x:auto; border:1px solid var(--line); border-radius:18px; background:var(--surface) }
.hv-table{ width:100%; border-collapse:collapse; font-size:13.4px; min-width:560px }
.hv-table th, .hv-table td{ padding:13px 16px; text-align:start; border-bottom:1px solid var(--line); vertical-align:middle }
.hv-table thead th{ font-size:12.2px; color:var(--dim); font-weight:600; background:var(--surface-2) }
.hv-table tbody tr:last-child td, .hv-table tbody tr:last-child th{ border-bottom:0 }
.hv-table small{ font-size:11.5px; color:var(--dim) }
.hv-td-c{ white-space:nowrap }
.hv-td-c span{ margin-inline-start:6px }
.hv-td-p b{ color:var(--cyan); font-weight:700; font-size:14.5px }
.hv-link{ color:var(--muted); text-decoration:underline; text-underline-offset:3px }
.hv-link:hover{ color:var(--cyan) }
.hv-table-vs tbody th{ font-weight:600; color:var(--text); background:var(--surface-2); white-space:nowrap }

.hv-cards{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px }
.hv-card{ display:flex; flex-direction:column; border:1px solid var(--line); border-radius:18px;
  background:var(--surface); padding:18px; transition:border-color .18s var(--ease), transform .18s var(--ease) }
.hv-card:hover{ border-color:var(--line-2); transform:translateY(-3px) }
.hv-card header{ display:flex; flex-direction:column; gap:4px; padding-bottom:12px; border-bottom:1px solid var(--line) }
.hv-card-loc{ font-size:11.8px; color:var(--dim); display:flex; align-items:center; gap:6px }
.hv-card header b{ font-size:15.5px; font-weight:700 }
.hv-card header small{ font-size:11.5px; color:var(--dim) }
.hv-card dl{ display:flex; flex-direction:column; gap:7px; margin:12px 0 }
.hv-card dl div{ display:flex; justify-content:space-between; gap:10px }
.hv-card dt{ font-size:12px; color:var(--dim) }
.hv-card dd{ font-size:12.8px; font-weight:600 }
.hv-card-price{ display:flex; align-items:baseline; gap:6px; margin-top:auto; padding-top:10px; border-top:1px solid var(--line) }
.hv-card-price b{ font-size:20px; font-weight:700; color:var(--cyan); letter-spacing:-.3px }
.hv-card-price span{ font-size:12px; color:var(--dim) }
.hv-card-meta{ list-style:none; margin:10px 0 14px; display:flex; flex-direction:column; gap:5px }
.hv-card-meta li{ display:flex; justify-content:space-between; gap:10px; font-size:11.8px; color:var(--dim) }
.hv-card-meta li b{ color:var(--muted); font-weight:600 }
.hv-buy{ display:block; text-align:center; font-size:13px; font-weight:700; color:#fff; background:var(--grad);
  border-radius:10px; padding:10px 16px }
.hv-buy:hover{ box-shadow:0 6px 20px rgba(34,211,238,.32) }

.hv-uses{ display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px }
.hv-use{ border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:20px }
.hv-use .icon{ width:22px; height:22px; color:var(--cyan); margin-bottom:10px }
.hv-use h3{ font-size:14.5px; font-weight:700; margin-bottom:6px }
.hv-use p{ color:var(--muted); font-size:13px; line-height:1.9 }

.hv-empty{ border:1px dashed var(--line-2); border-radius:20px; background:var(--surface);
  padding:34px 24px; text-align:center; max-width:600px }
.hv-empty p{ color:var(--muted); font-size:13.6px; line-height:2; margin-bottom:16px }

.hv-faq{ display:flex; flex-direction:column; gap:10px; max-width:860px }
.hv-faq details{ border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:14px 18px }
.hv-faq summary{ font-size:14px; font-weight:600; list-style:none; cursor:pointer }
.hv-faq summary::-webkit-details-marker{ display:none }
.hv-faq details[open] summary{ color:var(--cyan) }
.hv-faq details div{ margin-top:10px; color:var(--muted); font-size:13.2px; line-height:2 }

.hv-cross-sec{ padding-top:14px }
.hv-cross-t{ font-size:15px; font-weight:700; margin-bottom:14px }
.hv-cross{ display:flex; flex-wrap:wrap; gap:9px }
.hv-cross a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:7px 15px; transition:.16s }
.hv-cross a:hover{ border-color:var(--cyan); color:var(--cyan) }

@media(max-width:640px){
  .hv-sec{ padding:32px 0 }
}
</style>
@endsection
