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

@section('title', __('ui.gpu_meta_t'))
@section('description', $gpuMetaD)

@section('content')
<script type="application/ld+json">{!! schema_ld($gpuCrumbs[0], 'BreadcrumbList') !!}</script>

<style>
  .gpu-wrap{max-width:1120px;margin:0 auto;padding:0 20px}
  .gpu-head{text-align:center;margin:0 0 34px}
  .gpu-head h1{font-size:clamp(26px,4vw,40px);margin:0 0 12px;line-height:1.35}
  .gpu-head p{color:var(--muted);font-size:15.5px;line-height:2;max-width:680px;margin:0 auto}
  .gpu-from{display:inline-block;margin-top:16px;padding:7px 16px;border-radius:999px;
    background:rgba(34,211,238,.1);border:1px solid rgba(34,211,238,.28);color:var(--cyan);font-size:14px}

  /* 🔴 نوارِ قطع‌شدنی‌بودن — نه ریزنویسِ پاورقی. مشتری‌ای که این را نبیند و
     ماشینش وسطِ کار قطع شود، حق دارد شکایت کند. */
  .gpu-warn{margin:0 auto 34px;max-width:900px;border-radius:14px;padding:16px 18px;
    background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3)}
  .gpu-warn b{display:block;color:#fbbf24;font-size:14.5px;margin:0 0 6px}
  .gpu-warn p{margin:0;color:var(--muted);font-size:13.5px;line-height:2}

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

  .gpu-side{position:sticky;top:130px;background:var(--surface);border:1px solid var(--line);
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
</style>

<section class="section">
  <div class="gpu-wrap">

    <div class="gpu-head">
      <h1>{{ __('ui.gpu_h1') }}</h1>
      <p>{{ __('ui.gpu_lead') }}</p>
      @if($fromHourly)
        <div class="gpu-from">{{ __('ui.gpu_from', ['price' => $fromHourly]) }}</div>
      @endif
    </div>

    {{-- 🔴 پیش از پیکربند، نه بعدش: تصمیمِ خرید بعد از دیدنِ قیمت گرفته
         می‌شود، پس هشدار باید **قبلش** دیده شود نه زیرِ دکمه. --}}
    @if($interruptible)
      <div class="gpu-warn">
        <b>⚠️ {{ __('ui.gpu_warn_t') }}</b>
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
             href="{{ lroute('account.cloud.store') }}?billing_mode=hourly&plan={{ $cards[0]['slug'] }}">
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

    cta.href = CFG.store + '?billing_mode=hourly&plan=' + encodeURIComponent(p.value)
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
