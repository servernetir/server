{{--
  سرور گرافیکی ساعتی — /gpu (fa / en / tr).

  ⚠️ هیچ عددی در این ویو ساخته نمی‌شود؛ همه از GpuController می‌آید که خودش از
     CloudPlan می‌خواند. عددِ سخت‌کد این‌جا = قیمتِ دروغ در آیندهٔ نزدیک.
  ⚠️ نخستین بستهٔ در-جریان `.section` است (نه `.hero`) تا جبرانِ هدر را از
     `#main` بگیرد و دو بار جبران نکند (FixedHeaderOffsetTest).
  ⚠️ استایل درجاست با پیشوندِ `gpu-` — site.css مرزِ agentِ دیگری است و کلاسِ
     نبود، بی‌خطا بی‌استایل رندر می‌شود.
  🔴 هر مقداری که واردِ جاوااسکریپت می‌شود از `@json()` می‌رود، نه `{{ }}` —
     قاعدهٔ ثبت‌شده: کوتیشنِ escape‌شده کلِ بلوکِ inline را می‌کُشد.
--}}
@extends('layouts.site')

@php
  $gpuHours = $isFa ? fa_num((string) $minHours) : (string) $minHours;

  $gpuMetaD = $fromHourly
      ? __('ui.gpu_meta_d', ['price' => $fromHourly])
      : __('ui.gpu_meta_d_nop');

  $gpuUses = [
      ['i' => 'cpu',   't' => __('ui.gpu_use1_t'), 'd' => __('ui.gpu_use1_d')],
      ['i' => 'zap',   't' => __('ui.gpu_use2_t'), 'd' => __('ui.gpu_use2_d')],
      ['i' => 'flow',  't' => __('ui.gpu_use3_t'), 'd' => __('ui.gpu_use3_d')],
  ];

  /*
  | ⚠️ آرایه **این‌جا** ساخته می‌شود نه درونِ `@json([...])`.
  |
  | تلهٔ ثبت‌شدهٔ Blade: `@json()` با آرایهٔ درون‌خطی پارسر را می‌شکند
  | («Unclosed '[' … does not match ')'») و صفحه ۵۰۰ می‌دهد. همین یک بار هم
  | این‌جا رخ داد و تست گرفتش.
  */
  $gpuCfg = [
      'store'   => lroute('account.cloud.store'),
      'fa'      => $isFa,
      'max'     => $maxUnits,
      'unit'    => $isFa ? 'تومان' : null,
      'rate'    => cloud_eur_rate(),
      'perHour' => __('ui.gpu_per_hour'),
  ];

  $gpuCrumbs = [[
      '@'.'type' => 'BreadcrumbList',
      'itemListElement' => [
          ['@'.'type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
          ['@'.'type' => 'ListItem', 'position' => 2, 'name' => __('ui.gpu_h1'), 'item' => lroute('gpu')],
      ],
  ]];
@endphp

@php
  /*
  | Product + Offer (ساعتی) و FAQPage.
  |
  | Search Console، ۱۶ سپتامبر ۲۰۲۶: /gpu هفتمین صفحهٔ سایت از نظرِ کلیک است و
  | پرس‌وجوهای «اجاره gpu»، «سرور gpu ساعتی»، «اجاره کارت گرافیک» همه در رتبهٔ
  | ۷ تا ۹ نشسته‌اند — ولی برخلافِ /vps/hourly هیچ دادهٔ ساختاریافتهٔ تجاری
  | نداشت. «Product snippets» سایت ۶۹ مورد معتبر دارد؛ این صفحه در آن نبود.
  |
  | ⚠️ قیمت فقط از کارت‌های واقعیِ کنترلر (همان `offers()` که سبد می‌فروشد) و
  |    UnitPriceSpecification با HUR تا «ساعتی» خوانده شود نه ماهانه.
  | ⚠️ FAQ **هیچ متنِ تازه‌ای نمی‌سازد**: سه پرسش همان سه تیترِ سؤالیِ
  |    قابل‌مشاهدهٔ صفحه‌اند. FAQِ نامرئی در schema خلافِ رهنمودِ گوگل است.
  |    پرسشِ «چرا این قیمت؟» فقط وقتی می‌آید که بخشش واقعاً رندر شود.
  */
  $gpuCur = $isFa ? 'IRR' : 'EUR';
  $gpuOffers = [];
  foreach (array_slice($cards, 0, 20) as $gpuC) {
      $gpuRaw = $gpuC['ld_price'] ?? null;
      if ($gpuRaw === null || $gpuRaw <= 0) {
          continue;
      }
      $gpuOffers[] = schema_offer_extras($gpuCur) + [
          '@'.'type' => 'Offer',
          'name' => $gpuC['gpu'].($gpuC['gpu_count'] > 1 ? ' ×'.$gpuC['gpu_count'] : ''),
          'priceCurrency' => $gpuCur,
          'price' => $gpuRaw,
          'priceSpecification' => [
              '@'.'type' => 'UnitPriceSpecification',
              'price' => $gpuRaw,
              'priceCurrency' => $gpuCur,
              'unitCode' => 'HUR',
              'unitText' => $isFa ? 'ساعت' : 'hour',
          ],
          'priceValidUntil' => now()->addDays(30)->toDateString(),
          'availability' => 'https://schema.org/InStock',
          'url' => lroute('gpu'),
      ];
  }
  $gpuProduct = [
      'name' => __('ui.gpu_h1'),
      'description' => $gpuMetaD,
      'url' => lroute('gpu'),
      'image' => [asset('assets/img/og.png')],
      'brand' => ['@'.'type' => 'Brand', 'name' => __('ui.brand')],
      'offers' => $gpuOffers,
  ];

  $gpuFaq = [
      [__('ui.gpu_hourly_t'), __('ui.gpu_hourly_d', ['hours' => $gpuHours])],
      [__('ui.gpu_ssh_t'), __('ui.gpu_ssh_d')],
  ];
  if ($interruptible) {
      array_unshift($gpuFaq, [__('ui.gpu_warn_t'), __('ui.gpu_warn_d')]);
  }

  /*
  | پرسش‌های متداولِ خطِ GPU — ۱۲ پرسش، همه **رندرشده** در بخشِ #faq پایینِ
  | همین صفحه. قاعدهٔ بالا دست‌نخورده می‌مانَد: چیزی که در schema است روی صفحه
  | هم دیده می‌شود، وگرنه خلافِ رهنمودِ گوگل است.
  |
  | 🔴 چرا اضافه شد (شهریور ۱۴۰۵): بیشترین تماسِ پشتیبانیِ ساعتی «وقتی اعتبار
  | تمام شود چه می‌شود؟» بود و هیچ رقیبِ ایرانی هم پاسخش را نمی‌نویسد.
  */
  $gpuMax = $isFa ? fa_num((string) $maxUnits) : (string) $maxUnits;
  /*
  | 🔴 ۱۳ تا ۱۸ (مهر ۱۴۰۵): «کدام ابزار آماده است، چند برنامه روی یک کارت،
  | Workflow سفارشی» — دو تیکتِ یک روز (TK-260926-7607، TK-260926-5019) و
  | مشتری‌ای که برای ComfyUI با نودِ سفارشی خرید و وجهش برگشت
  | (TK-260924-8595). پاسخ‌ها باید با تحویلِ واقعی بخوانند:
  | `SaladOperations::APPS` و `SaladClient::capabilities()`. کنارِ پرسشِ SSH
  | (۵) می‌نشینند، چون همان لحظهٔ تصمیم است.
  */
  $gpuFaqPage = [];
  foreach ([1, 2, 3, 4, 5, 13, 14, 15, 16, 17, 18, 6, 7, 8, 9, 10, 11, 12] as $gpuQn) {
      $gpuFaqPage[] = [
          __('ui.gpu_faq'.$gpuQn.'_q'),
          __('ui.gpu_faq'.$gpuQn.'_a', ['hours' => $gpuHours, 'max' => $gpuMax]),
      ];
  }
  $gpuFaq = array_merge($gpuFaq, $gpuFaqPage);
  $gpuFaqLd = [];
  foreach ($gpuFaq as [$gpuQ, $gpuA]) {
      $gpuFaqLd[] = ['@'.'type' => 'Question', 'name' => $gpuQ, 'acceptedAnswer' => ['@'.'type' => 'Answer', 'text' => $gpuA]];
  }
@endphp

@section('title', __('ui.gpu_meta_t'))
@section('description', $gpuMetaD)

@section('content')
<script type="application/ld+json">{!! schema_ld($gpuCrumbs[0], 'BreadcrumbList') !!}</script>
@if($gpuOffers)
<script type="application/ld+json">{!! schema_ld($gpuProduct, 'Product') !!}</script>
@endif
<script type="application/ld+json">{!! schema_ld(['mainEntity' => $gpuFaqLd], 'FAQPage') !!}</script>

<style>
  .gpu-wrap{max-width:1120px;margin:0 auto;padding:0 20px}
  .gpu-head{text-align:center;margin:0 0 34px}
  .gpu-head h1{font-size:clamp(26px,4vw,40px);margin:0 0 12px;line-height:1.35}
  .gpu-head p{color:var(--muted);font-size:15.5px;line-height:2;max-width:680px;margin:0 auto}
  .gpu-from{display:inline-block;margin-top:16px;padding:7px 16px;border-radius:999px;
    background:rgba(34,211,238,.1);border:1px solid rgba(34,211,238,.28);color:var(--cyan);font-size:14px}

  /* چیپ‌های اعتماد زیرِ تیتر */
  .gpu-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:18px}
  .gpu-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;
    background:var(--surface);border:1px solid var(--line-2);color:var(--muted);font-size:13px}
  .gpu-chip .icon{width:15px;height:15px;color:var(--cyan)}

  /* 🔴 صداقتِ «قطع‌شدنی» حذف نشده — بازروایت شده. متن هنوز صریح می‌گوید
     ماشین ممکن است جابه‌جا شود و برای کارِ بی‌وقفه مناسب نیست؛ فقط به‌جای
     نوارِ زردِ ترسناک، به‌عنوانِ «چرا این قیمت ممکن است» گفته می‌شود.
     همان تستِ GpuPageTest همچنان وجود و جایگاهش (پیش از پیکربند) را قفل می‌کند. */
  .gpu-warn{margin:0 auto 34px;max-width:900px;border-radius:16px;padding:20px 22px;
    background:linear-gradient(135deg,rgba(34,211,238,.07),rgba(34,211,238,.02));
    border:1px solid rgba(34,211,238,.25)}
  .gpu-warn b{display:block;color:var(--cyan);font-size:15px;margin:0 0 8px}
  .gpu-warn p{margin:0;color:var(--muted);font-size:13.5px;line-height:2.1}

  /* «چطور کار می‌کند» — سه گامِ شماره‌دار */
  .gpu-how{margin:44px 0 0}
  .gpu-how h2{text-align:center;font-size:20px;margin:0 0 22px}
  .gpu-how-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px}
  .gpu-step-card{position:relative;background:var(--surface);border:1px solid var(--line);
    border-radius:14px;padding:20px 18px 18px}
  .gpu-step-n{position:absolute;top:-13px;inset-inline-start:16px;width:26px;height:26px;
    border-radius:999px;background:var(--cyan);color:#04252b;font-size:13.5px;font-weight:700;
    display:flex;align-items:center;justify-content:center}
  .gpu-step-card b{display:block;font-size:14.5px;margin:4px 0 7px}
  .gpu-step-card p{margin:0;color:var(--muted);font-size:13px;line-height:2}

  /* برنامه‌های آماده */
  .gpu-apps{margin:44px 0 0;text-align:center}
  .gpu-apps h2{font-size:20px;margin:0 0 6px}
  .gpu-apps > p{color:var(--muted);font-size:13.5px;margin:0 0 20px}
  .gpu-apps-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;text-align:start}
  .gpu-app{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px}
  .gpu-app b{display:block;font-size:14.5px;margin:0 0 6px}
  .gpu-app b small{color:var(--dim);font-weight:400;margin-inline-start:6px;font-size:11.5px}
  .gpu-app p{margin:0;color:var(--muted);font-size:12.5px;line-height:2}

  .gpu-cfg{display:grid;grid-template-columns:1fr 320px;gap:22px;align-items:start}
  @media(max-width:900px){.gpu-cfg{grid-template-columns:1fr}}

  .gpu-panel{background:var(--surface);border:1px solid var(--line);border-radius:16px;overflow:hidden}
  .gpu-panel-h{padding:16px 18px;border-bottom:1px solid var(--line)}
  .gpu-panel-h h2{margin:0 0 4px;font-size:16px}
  .gpu-panel-h span{color:var(--muted);font-size:13px}

  .gpu-cards{display:grid;gap:0}
  .gpu-card{display:grid;grid-template-columns:24px 1fr auto;gap:14px;align-items:center;
    padding:15px 18px;border-top:1px solid var(--line);cursor:pointer;transition:background .15s}
  .gpu-card:first-child{border-top:0}
  .gpu-card:hover{background:var(--surface-2)}
  .gpu-card.on{background:rgba(34,211,238,.07)}
  .gpu-card input{margin:0;width:17px;height:17px;accent-color:var(--cyan)}
  .gpu-card b{display:block;font-size:15px;margin:0 0 3px}
  .gpu-card .gpu-spec{color:var(--muted);font-size:12.5px}
  .gpu-card .gpu-rate{text-align:end;white-space:nowrap;font-size:14px;color:var(--cyan)}
  .gpu-card .gpu-rate small{display:block;color:var(--dim);font-size:11.5px}
  .gpu-badge{display:inline-block;margin-inline-start:7px;padding:2px 8px;border-radius:999px;
    background:var(--surface-2);border:1px solid var(--line-2);color:var(--muted);font-size:11px}

  /* ⚠️ جبرانِ هدر از `--header-h` می‌آید، نه عددِ دستی: قاعدهٔ ثبت‌شدهٔ پروژه.
     عددِ پراکنده همان چیزی است که چند بار «صفحه رفته زیرِ هدر» ساخت، و زیرِ
     ۴۰۰px هدر بلندتر می‌شود (۱۳۲px) که هر عددِ ثابتی را می‌شکند. */
  .gpu-side{position:sticky;top:calc(var(--header-h) + 14px);background:var(--surface);border:1px solid var(--line);
    border-radius:16px;padding:18px}
  @media(max-width:900px){.gpu-side{position:static}}
  .gpu-side h2{margin:0 0 4px;font-size:15px}
  .gpu-side .gpu-hint{color:var(--muted);font-size:12.5px;line-height:1.9;margin:0 0 14px}
  .gpu-steps{display:flex;align-items:center;gap:10px;margin:0 0 16px}
  .gpu-steps button{width:38px;height:38px;border-radius:10px;border:1px solid var(--line-2);
    background:var(--surface-2);color:var(--text);font-size:19px;cursor:pointer;line-height:1}
  .gpu-steps button:hover{border-color:var(--cyan)}
  .gpu-steps output{flex:1;text-align:center;font-size:20px;font-weight:700}
  .gpu-sum{border-top:1px solid var(--line);padding-top:14px;margin-top:4px}
  .gpu-sum-row{display:flex;justify-content:space-between;align-items:baseline;margin:0 0 8px}
  .gpu-sum-row span{color:var(--muted);font-size:13px}
  .gpu-sum-row b{font-size:18px;color:var(--cyan)}
  .gpu-sum-row.sub b{font-size:13.5px;color:var(--muted);font-weight:400}
  .gpu-side .btn{width:100%;margin-top:14px;justify-content:center}

  .gpu-empty{text-align:center;padding:44px 20px}
  .gpu-empty b{display:block;font-size:17px;margin:0 0 8px}
  .gpu-empty p{color:var(--muted);font-size:14px;line-height:2;max-width:520px;margin:0 auto}

  .gpu-notes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-top:44px}
  .gpu-note{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px}
  .gpu-note b{display:block;font-size:14.5px;margin:0 0 7px}
  .gpu-note p{margin:0;color:var(--muted);font-size:13.5px;line-height:2}
  .gpu-note .icon{width:19px;height:19px;color:var(--cyan);margin:0 0 9px}

  /* راهنمای کارت و هزینهٔ واقعی — جدولِ افقی‌اسکرول تا صفحه هرگز افقی نرود */
  .gpu-sec{margin:48px 0 0}
  .gpu-sec h2{font-size:20px;margin:0 0 8px}
  .gpu-sec > p{color:var(--muted);font-size:13.5px;line-height:2;margin:0 0 16px;max-width:820px}
  .gpu-tw{overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--surface)}
  .gpu-t{width:100%;border-collapse:collapse;font-size:13.2px;min-width:560px}
  .gpu-t th,.gpu-t td{padding:12px 14px;text-align:start;border-bottom:1px solid var(--line);vertical-align:top;line-height:1.9}
  .gpu-t thead th{font-size:12px;color:var(--dim);font-weight:600;background:var(--surface-2)}
  .gpu-t tbody tr:last-child td{border-bottom:0}
  .gpu-t td b{color:var(--cyan);font-weight:700;white-space:nowrap}
  .gpu-t small{display:block;color:var(--dim);font-size:11.5px}
  .gpu-amd{color:var(--dim);font-size:12.5px;line-height:1.9;margin:10px 0 0}

  .gpu-faq{display:flex;flex-direction:column;gap:10px;max-width:880px}
  .gpu-faq details{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:14px 18px}
  .gpu-faq summary{font-size:14px;font-weight:600;list-style:none;cursor:pointer}
  .gpu-faq summary::-webkit-details-marker{display:none}
  .gpu-faq details[open] summary{color:var(--cyan)}
  .gpu-faq details div{margin-top:10px;color:var(--muted);font-size:13.2px;line-height:2}
  .gpu-cross{display:flex;flex-wrap:wrap;gap:9px}
  .gpu-cross a{font-size:12.8px;color:var(--muted);border:1px solid var(--line);border-radius:30px;padding:7px 15px}
  .gpu-cross a:hover{border-color:var(--cyan);color:var(--cyan)}
</style>

<section class="section">
  <div class="gpu-wrap">

    <div class="gpu-head">
      <h1>{{ __('ui.gpu_h1') }}</h1>
      <p>{{ __('ui.gpu_lead') }}</p>
      @if($fromHourly)
        <div class="gpu-from">{{ __('ui.gpu_from', ['price' => $fromHourly]) }}</div>
      @endif
      <div class="gpu-chips">
        <span class="gpu-chip"><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.gpu_chip1') }}</span>
        <span class="gpu-chip"><svg class="icon"><use href="#i-zap"/></svg>{{ __('ui.gpu_chip2') }}</span>
        <span class="gpu-chip"><svg class="icon"><use href="#i-key"/></svg>{{ __('ui.gpu_chip3') }}</span>
      </div>
    </div>

    {{-- 🔴 پیش از پیکربند، نه بعدش: تصمیمِ خرید بعد از دیدنِ قیمت گرفته
         می‌شود، پس هشدار باید **قبلش** دیده شود نه زیرِ دکمه. --}}
    @if($interruptible)
      <div class="gpu-warn">
        <b>💡 {{ __('ui.gpu_warn_t') }}</b>
        <p>{{ __('ui.gpu_warn_d') }}</p>
      </div>
    @endif

    @if($cards)
      <div class="gpu-cfg">

        <div class="gpu-panel">
          <div class="gpu-panel-h">
            <h2>{{ __('ui.gpu_pick_t') }}</h2>
            <span>{{ __('ui.gpu_pick_d') }}</span>
          </div>
          <div class="gpu-cards" id="gpu-cards">
            @foreach($cards as $i => $c)
              <label class="gpu-card{{ $i === 0 ? ' on' : '' }}">
                <input type="radio" name="gpu_card" value="{{ $c['slug'] }}"
                       data-rate="{{ $c['hourly_raw'] }}" @checked($i === 0)>
                <span>
                  <b>{{ $c['gpu'] }}@if($c['gpu_count'] > 1)<span class="gpu-badge">{{ $isFa ? fa_num((string) $c['gpu_count']) : $c['gpu_count'] }} {{ __('ui.gpu_cards_n') }}</span>@endif</b>
                  <span class="gpu-spec">
                    {{ $isFa ? fa_num((string) $c['vcpu']) : $c['vcpu'] }} {{ __('ui.gpu_spec_cpu') }}
                    · {{ $isFa ? fa_num((string) $c['ram_gb']) : $c['ram_gb'] }}GB {{ __('ui.gpu_spec_ram') }}
                    · {{ $isFa ? fa_num((string) $c['disk_gb']) : $c['disk_gb'] }}GB {{ __('ui.gpu_spec_disk') }}
                  </span>
                </span>
                <span class="gpu-rate">{{ $c['hourly'] }}<small>{{ __('ui.gpu_per_hour') }}</small></span>
              </label>
            @endforeach
          </div>
        </div>

        <div class="gpu-side">
          <h2>{{ __('ui.gpu_units_t') }}</h2>
          {{-- 🔴 این جمله اختیاری نیست. در اسپکِ زیرساخت «تعداد» یعنی
               replicas — نمونه‌های **مستقل**. مشتری‌ای که فکر کند یک باکسِ
               چهارکارته می‌خرد، SSH که زد یکی می‌بیند و ما هیچ خطایی
               نمی‌بینیم. --}}
          <p class="gpu-hint">{{ __('ui.gpu_units_d') }}</p>

          <div class="gpu-steps">
            <button type="button" id="gpu-minus" aria-label="-">−</button>
            <output id="gpu-units">{{ $isFa ? fa_num('1') : '1' }}</output>
            <button type="button" id="gpu-plus" aria-label="+">+</button>
          </div>

          <div class="gpu-sum">
            <div class="gpu-sum-row">
              <span>{{ __('ui.gpu_total') }} · {{ __('ui.gpu_per_hour') }}</span>
              <b id="gpu-total">{{ $cards[0]['hourly'] }}</b>
            </div>
            <div class="gpu-sum-row sub">
              <span>{{ __('ui.gpu_per_day') }}</span>
              <b id="gpu-daily">—</b>
            </div>
          </div>

          <a class="btn btn-primary" id="gpu-cta" rel="nofollow"
             href="{{ lroute('account.cloud.store') }}?billing_mode=hourly&location=global-gpu&plan={{ $cards[0]['slug'] }}">
            {{ __('ui.gpu_cta') }}
          </a>
        </div>

      </div>
    @else
      <div class="gpu-panel gpu-empty">
        <b>{{ __('ui.gpu_empty_t') }}</b>
        <p>{{ __('ui.gpu_empty_d') }}</p>
      </div>
    @endif

    <div class="gpu-how">
      <h2>{{ __('ui.gpu_how_t') }}</h2>
      <div class="gpu-how-grid">
        @foreach([1, 2, 3] as $n)
          <div class="gpu-step-card">
            <span class="gpu-step-n">{{ $isFa ? fa_num((string) $n) : $n }}</span>
            <b>{{ __('ui.gpu_how'.$n.'_t') }}</b>
            <p>{{ __('ui.gpu_how'.$n.'_d') }}</p>
          </div>
        @endforeach
      </div>
    </div>

    <div class="gpu-apps">
      <h2>{{ __('ui.gpu_apps_t') }}</h2>
      <p>{{ __('ui.gpu_apps_d') }}</p>
      <div class="gpu-apps-grid">
        <div class="gpu-app"><b>Ollama <small>LLM</small></b><p>{{ __('ui.gpu_app_llm_d') }}</p></div>
        <div class="gpu-app"><b>ComfyUI <small>Stable Diffusion</small></b><p>{{ __('ui.gpu_app_img_d') }}</p></div>
        <div class="gpu-app"><b>Jupyter <small>PyTorch</small></b><p>{{ __('ui.gpu_app_nb_d') }}</p></div>
      </div>
    </div>

    @if($guide)
      <div class="gpu-sec" id="which-gpu">
        <h2>{{ __('ui.gpu_guide_t') }}</h2>
        <p>{{ __('ui.gpu_guide_d') }}</p>
        <div class="gpu-tw">
          <table class="gpu-t">
            <thead><tr>
              <th>{{ __('ui.gpu_guide_h_vram') }}</th>
              <th>{{ __('ui.gpu_guide_h_fit') }}</th>
              <th>{{ __('ui.gpu_guide_h_cards') }}</th>
            </tr></thead>
            <tbody>
              @foreach($guide as $g)
                <tr>
                  <td dir="ltr" style="white-space:nowrap">{{ $g['vram'] }}</td>
                  <td>{{ $g['fit'] }}</td>
                  <td><span dir="ltr">{{ implode(' · ', $g['cards']) }}</span><small>{{ __('ui.from') }} {{ $g['from'] }} {{ __('ui.gpu_per_hour') }}</small></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="gpu-amd">{{ __('ui.gpu_guide_amd') }}</p>
      </div>
    @endif

    @if($costs)
      <div class="gpu-sec" id="gpu-cost">
        <h2>{{ __('ui.gpu_cost_t') }}</h2>
        <p>{{ __('ui.gpu_cost_d') }}</p>
        <div class="gpu-tw">
          <table class="gpu-t">
            <thead><tr>
              <th>{{ __('ui.gpu_cost_h_card') }}</th>
              <th>{{ __('ui.gpu_cost_h_1') }}</th>
              <th>{{ __('ui.gpu_cost_h_8') }}</th>
              <th>{{ __('ui.gpu_cost_h_24') }}</th>
            </tr></thead>
            <tbody>
              @foreach($costs as $cx)
                <tr>
                  <td dir="ltr">{{ $cx['gpu'] }}</td>
                  <td><b>{{ $cx['h1'] }}</b></td>
                  <td>{{ $cx['h8'] }}</td>
                  <td>{{ $cx['h24'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif

    <div class="gpu-notes">
      <div class="gpu-note">
        <svg class="icon"><use href="#i-coins"/></svg>
        <b>{{ __('ui.gpu_hourly_t') }}</b>
        <p>{{ __('ui.gpu_hourly_d', ['hours' => $gpuHours]) }}</p>
      </div>
      <div class="gpu-note">
        <svg class="icon"><use href="#i-key"/></svg>
        <b>{{ __('ui.gpu_ssh_t') }}</b>
        <p>{{ __('ui.gpu_ssh_d') }}</p>
      </div>
      @foreach($gpuUses as $u)
        <div class="gpu-note">
          <svg class="icon"><use href="#i-{{ $u['i'] }}"/></svg>
          <b>{{ $u['t'] }}</b>
          <p>{{ $u['d'] }}</p>
        </div>
      @endforeach
    </div>

  </div>
</section>

{{-- 🔴 پایانِ اعتبار — پرتکرارترین تماسِ پشتیبانی؛ هیچ رقیبی صریح نمی‌گوید --}}
@include('partials.credit-lifecycle', ['clMode' => 'gpu'])

<section class="section" id="faq">
  <div class="gpu-wrap">
    <div class="gpu-sec" style="margin-top:0">
      <h2>{{ __('ui.gpu_faq_t') }}</h2>
      <div class="gpu-faq">
        @foreach($gpuFaqPage as $i => $row)
          <details @if($i === 0) open @endif>
            <summary>{{ $row[0] }}</summary>
            <div>{{ $row[1] }}</div>
          </details>
        @endforeach
      </div>
    </div>

    <div class="gpu-sec">
      <h2 style="font-size:15px">{{ __('ui.gpu_cross_t') }}</h2>
      <div class="gpu-cross">
        <a href="{{ lroute('vps.hourly') }}">{{ __('ui.gpu_cross_hourly') }}</a>
        <a href="{{ lroute('catalog', ['category' => 'cloud', 'slug' => 'ai-infrastructure']) }}">{{ __('ui.gpu_cross_ai_infra') }}</a>
        <a href="{{ lroute('catalog', ['category' => 'solutions', 'slug' => 'ai-agents']) }}">{{ __('ui.gpu_cross_agents') }}</a>
        <a href="{{ lroute('cloud.index') }}">{{ __('ui.gpu_cross_cloud') }}</a>
      </div>
    </div>
  </div>
</section>

@if($cards)
{{-- 🔴 هر مقدار از `@json()` می‌رود، نه `{{ }}`: کوتیشنِ HTML-escape‌شده کلِ
     این بلوک را با SyntaxError می‌کُشد و صفحه ۲۰۰ و ظاهراً سالم می‌مانَد. --}}
<script>
(function () {
  'use strict';

  var CFG = @json($gpuCfg);

  var cards = document.getElementById('gpu-cards');
  var out   = document.getElementById('gpu-units');
  var total = document.getElementById('gpu-total');
  var daily = document.getElementById('gpu-daily');
  var cta   = document.getElementById('gpu-cta');

  if (!cards || !out || !total || !cta) { return; }

  var units = 1;

  function digits(s) {
    if (!CFG.fa) { return s; }
    return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }

  /* ⚠️ همان قاعدهٔ `cloud_price()` سمتِ سرور، چون این عدد **زنده** عوض می‌شود
     و نمی‌شود از PHP گرفت. فارسی تومان، بقیه یورو با نرخِ همان صفحه. */
  function money(toman) {
    if (CFG.fa) {
      return digits(Math.round(toman).toLocaleString('en-US')) + ' ' + CFG.unit;
    }

    if (CFG.rate > 0) {
      return '€' + (toman / CFG.rate).toFixed(2);
    }

    return Math.round(toman).toLocaleString('en-US');
  }

  function picked() {
    return cards.querySelector('input[name="gpu_card"]:checked');
  }

  function paint() {
    var p = picked();

    if (!p) { return; }

    var rate = parseInt(p.getAttribute('data-rate'), 10) || 0;

    out.textContent   = digits(units);
    total.textContent = money(rate * units);
    daily.textContent = money(rate * units * 24);

    cta.href = CFG.store + '?billing_mode=hourly&location=global-gpu&plan=' + encodeURIComponent(p.value)
             + '&units=' + units;

    Array.prototype.forEach.call(cards.querySelectorAll('.gpu-card'), function (el) {
      el.classList.toggle('on', el.contains(p));
    });
  }

  cards.addEventListener('change', paint);

  document.getElementById('gpu-minus').addEventListener('click', function () {
    if (units > 1) { units--; paint(); }
  });

  document.getElementById('gpu-plus').addEventListener('click', function () {
    if (units < CFG.max) { units++; paint(); }
  });

  paint();
})();
</script>
@endif
@endsection
