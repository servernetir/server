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
  $selectedCard = collect($cards)->firstWhere('slug', old('plan')) ?? ($cards[0] ?? null);
  $selectedApp = old('image', in_array('gpu-jupyter', $selectedCard['apps'] ?? [], true) ? 'gpu-jupyter' : ($selectedCard['apps'][0] ?? ''));
  $appDescriptions = ['gpu-ollama' => 'gpu_app_llm_d', 'gpu-comfyui' => 'gpu_app_img_d', 'gpu-jupyter' => 'gpu_app_nb_d'];

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
      'fa'      => $isFa,
      'unit'    => $isFa ? 'تومان' : null,
      'rate'    => cloud_eur_rate(),
      'perHour' => __('ui.gpu_per_hour'),
      'apps' => array_column($cards, 'apps', 'slug'),
      'minHours' => $minHours,
      'minCredit' => __('ui.gpu_min_credit', ['amount' => '{amount}']),
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
  .gpu-cards{max-height:640px;overflow:auto;overscroll-behavior:contain}
  .gpu-app-options{display:grid;gap:9px;margin:14px 0 20px}
  .gpu-app-option{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:12px;cursor:pointer}
  .gpu-app-option:has(input:checked){border-color:var(--cyan);background:rgba(34,211,238,.08)}
  .gpu-app-option[hidden]{display:none}
  .gpu-app-option input{accent-color:var(--cyan);margin-top:4px}
  .gpu-app-option b{display:block;font-size:13px;line-height:1.6}
  .gpu-app-option small{display:block;color:var(--muted);font-size:12px;line-height:1.8;margin-top:3px}
  .gpu-name{display:grid;gap:8px;font-size:13px;margin-bottom:18px}
  .gpu-name input{width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--surface-2);color:var(--text);font:inherit}
  .gpu-name input:focus{outline:2px solid var(--cyan);outline-offset:2px}
  .gpu-faq{margin-top:48px}
  .gpu-faq h2{text-align:center;margin-bottom:24px}
  .gpu-faq details{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:16px 18px;margin-bottom:10px}
  .gpu-faq summary{cursor:pointer;font-weight:600;line-height:1.8}
  .gpu-faq p{color:var(--muted);font-size:14px;line-height:2;margin:12px 0 0}
  .gpu-errors{padding:16px;border:1px solid var(--line);border-radius:12px;margin-bottom:20px}
  .gpu-side button:disabled{opacity:.5;cursor:not-allowed}
</style>

<section class="section">
  <div class="gpu-wrap">

    <div class="sn-product-hero sn-product-polish">
    @include('partials.atlas-product-horizon')
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
    @include('partials.atlas-product-stage', ['atlasStage'=>'gpu'])
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
      @if($errors->any())
        <div class="gpu-errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
      @endif
      <form class="gpu-cfg" method="post" action="{{ lroute('gpu.order') }}">
        @csrf

        <div class="gpu-panel">
          <div class="gpu-panel-h">
            <h2>{{ __('ui.gpu_pick_t') }}</h2>
            <span>{{ __('ui.gpu_pick_d') }}</span>
          </div>
          <div class="gpu-cards" id="gpu-cards">
            @foreach($cards as $i => $c)
              <label class="gpu-card{{ $c['slug'] === $selectedCard['slug'] ? ' on' : '' }}">
                <input type="radio" name="plan" value="{{ $c['slug'] }}"
                       data-rate="{{ $c['hourly_raw'] }}" @checked($c['slug'] === $selectedCard['slug']) required>
                <input type="hidden" name="quotes[{{ $c['slug'] }}]" value="{{ $c['hourly_raw'] }}">
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
          <h2>{{ __('ui.cvb_gpu_app_title') }}</h2>
          <p class="gpu-hint">{{ __('ui.gpu_units_d') }}</p>
          <div class="gpu-app-options">
            @foreach($apps as $key => $app)
              <label class="gpu-app-option" data-app="{{ $key }}">
                <input type="radio" name="image" value="{{ $key }}" @checked($selectedApp === $key) required>
                <span><b dir="ltr">{{ $app['label'] }}</b><small>{{ __('ui.'.$appDescriptions[$key]) }}</small></span>
              </label>
            @endforeach
          </div>
          <p id="gpu-app-empty" class="gpu-hint" @if($selectedCard['apps']) hidden @endif>{{ __('gpu.apps_unavailable') }}</p>
          <label class="gpu-name">{{ __('gpu.name_label') }}
            <input name="label" type="text" maxlength="32" pattern="[a-z][a-z0-9\-]*" required autocomplete="off" dir="ltr" value="{{ old('label') }}" placeholder="my-gpu-project" aria-describedby="gpu-name-help">
            <small id="gpu-name-help" class="gpu-hint">{{ __('gpu.name_help') }}</small>
          </label>

          <div class="gpu-sum">
            <div class="gpu-sum-row">
              <span>{{ __('ui.gpu_total') }} · {{ __('ui.gpu_per_hour') }}</span>
              <b id="gpu-total">{{ $selectedCard['hourly'] }}</b>
            </div>
            <div class="gpu-sum-row sub">
              <span>{{ __('ui.gpu_per_day') }}</span>
              <b id="gpu-daily">—</b>
            </div>
          </div>

          <p class="gpu-hint">{{ __('gpu.continue_note') }}</p>
          <p class="gpu-hint" id="gpu-start-credit">{{ __('ui.gpu_min_credit', ['amount' => cloud_price($selectedCard['hourly_raw'] * $minHours)]) }}</p>
          <button class="btn btn-primary" id="gpu-cta" type="submit" @disabled(! $selectedCard['apps'])>
            {{ __('ui.gpu_cta') }}
          </button>
        </div>

      </form>
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
        <div class="gpu-app"><b>Ollama <small>OpenAI API</small></b><p>{{ ($ollamaSalesPaused ?? false) ? __('ui.gpu_app_sales_paused', ['app' => 'Ollama']) : __('gpu.app_llm_details') }}</p></div>
        <div class="gpu-app"><b>ComfyUI <small>Stable Diffusion</small></b><p>{{ ($comfyuiSalesPaused ?? false) ? __('ui.gpu_app_sales_paused', ['app' => 'ComfyUI']) : __('gpu.app_img_details') }}</p></div>
        <div class="gpu-app"><b>Jupyter <small>PyTorch</small></b><p>{{ ($jupyterSalesPaused ?? false) ? __('ui.gpu_jupyter_sales_paused') : __('gpu.app_nb_details') }}</p></div>
      </div>
    </div>

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

    <section class="gpu-faq" aria-labelledby="gpu-faq-title">
      <h2 id="gpu-faq-title">{{ __('gpu.faq_title') }}</h2>
      @foreach(range(1, 12) as $n)
        <details><summary>{{ __('gpu.faq'.$n.'_q') }}</summary><p>{{ __('gpu.faq'.$n.'_a') }}</p></details>
      @endforeach
    </section>

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
  var total = document.getElementById('gpu-total');
  var daily = document.getElementById('gpu-daily');
  var cta   = document.getElementById('gpu-cta');

  if (!cards || !total || !cta) { return; }

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
    return cards.querySelector('input[name="plan"]:checked');
  }

  function paint() {
    var p = picked();

    if (!p) { return; }

    var rate = parseInt(p.getAttribute('data-rate'), 10) || 0;

    total.textContent = money(rate);
    daily.textContent = money(rate * 24);
    document.getElementById('gpu-start-credit').textContent = CFG.minCredit.replace('{amount}', money(rate * CFG.minHours));
    var allowed = CFG.apps[p.value] || [];
    var selected = document.querySelector('input[name="image"]:checked');
    Array.prototype.forEach.call(document.querySelectorAll('.gpu-app-option'), function (el) {
      var input = el.querySelector('input');
      var compatible = allowed.indexOf(input.value) !== -1;
      el.hidden = !compatible;
      input.disabled = !compatible;
      if (!compatible) { input.checked = false; }
    });
    if (!selected || allowed.indexOf(selected.value) === -1) {
      var first = document.querySelector('.gpu-app-option input:not(:disabled)');
      if (first) { first.checked = true; }
    }
    cta.disabled = allowed.length === 0;
    document.getElementById('gpu-app-empty').hidden = allowed.length > 0;

    Array.prototype.forEach.call(cards.querySelectorAll('.gpu-card'), function (el) {
      el.classList.toggle('on', el.contains(p));
    });
  }

  cards.addEventListener('change', paint);

  paint();
})();
</script>
@endif
@endsection
