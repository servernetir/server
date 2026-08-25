@extends('layouts.site')

@section('title', $metaTitle.' — '.__('ui.brand'))
@section('description', $metaDesc)
@unless($flagship)
{{-- ممیزی ۶ (سئو): فقط SKUهای پرچم‌دار ایندکس می‌شوند؛ ۶۴ صفحهٔ هم‌قالب
     ریسکِ «محتوای مقیاس‌شده» است. لینک‌ها دنبال می‌شوند (follow). --}}
@section('noindex', '1')
@endunless

{{-- خلاصهٔ سفارشِ پیش از ورود — بازطراحیِ ممیزی ۶ (UX):
     رادیوگروپ به‌جای جدول (جدولِ چهارستونه روی موبایلِ RTL تقریباً همیشه
     می‌شکند)، پیش‌فرض سالانه با نشانِ «بیشترین صرفه‌جویی»، برچسبِ صرفه‌جویی
     به تومان، «قیمت پایه» به‌جای ستونِ خالی، CTAی پویا با مبلغ، کارتِ جمعِ
     چسبان در موبایل، نوارِ پیشرفتِ چهارگامی که تا console ادامه دارد.
     دسترس‌پذیری: fieldset+legend، کلِ ردیف کلیک‌پذیر (≥44px).
     اعدادِ فارسی dir نمی‌گیرند (شورا/UX): «۱٬۲۰۰٬۰۰۰ تومان» در RTL درست
     می‌نشیند و ltr واحد را به سمتِ غلط پرت می‌کرد؛ فقط €/لاتین ltr می‌شود.
     جمع و CTA سمتِ سرور با دورهٔ پیش‌فرض پر می‌شوند تا بدونِ JS هم صفحه
     کامل باشد (و Lighthouse متنِ خالی نبیند).
     استایلِ درجا چون کلاسِ تازه در site.css مرزِ agentِ دیگری است. --}}

@section('content')
@php /* فقط €/لاتین ltr می‌گیرد؛ برای فارسی اصلاً صفت نمی‌نویسیم */ $ltr = $isFa ? '' : 'dir="ltr"'; @endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.os_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.os_h1', ['name' => $product->name]) }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.os_sub') }}</p>
      {{-- نوارِ پیشرفتِ مشترک دو دامنه: جهشِ دامنه را به «یک گام» تبدیل می‌کند --}}
      <ol class="os-steps reveal" aria-label="{{ __('ui.os_steps_label') }}">
        <li class="on" aria-current="step">{{ __('ui.os_step1') }}</li><li>{{ __('ui.os_step2') }}</li><li>{{ __('ui.os_step3') }}</li><li>{{ __('ui.os_step4') }}</li>
      </ol>
    </div>
  </div>
</section>

<section class="section" style="padding-top:10px">
  <div class="container" style="max-width:820px">

    @if(is_array($product->specs) && count($product->specs))
    <div class="sla-doc reveal" style="margin-bottom:22px">
      <h2 style="font-size:20px">{{ __('ui.os_specs') }}</h2>
      <ul>
        @foreach(array_slice($product->specs, 0, 6) as $spec)
        @if(is_string($spec) && $spec !== '')<li>{{ $spec }}</li>@endif
        @endforeach
      </ul>
    </div>
    @endif

    <form class="sla-doc reveal" id="os-form" onsubmit="return false">
      <fieldset class="os-cycles">
        <legend><h2 style="font-size:20px;margin:0">{{ __('ui.os_choose_legend') }}</h2></legend>

        @foreach($rows as $r)
        @php $isDef = $r['cycle'] === $default; @endphp
        <label class="os-opt {{ $isDef ? 'on' : '' }}" for="cy-{{ $r['cycle'] }}">
          <input type="radio" name="cycle" id="cy-{{ $r['cycle'] }}" value="{{ $r['cycle'] }}"
                 data-href="{{ $r['href'] }}"
                 data-total="{{ cloud_price($r['grand']) }}"
                 data-first="{{ cloud_price($r['first']) }}"
                 data-label="{{ $r['label'] }}"
                 data-saving="{{ $r['saving'] }}"
                 @checked($isDef)>
          <span class="os-opt-main">
            <b>{{ $r['label'] }}
              @if($r['cycle'] === 'yearly' && $r['saving'] > 0)<i class="os-pop">{{ __('ui.os_popular') }}</i>@endif
            </b>
            <small>
              @if($r['months'] > 0)<span {!! $ltr !!}>{{ cloud_price($r['monthly']) }}</span> /{{ __('ui.mo') }}@endif
              · @if($r['saving'] > 0){{ __('ui.os_saving_pct', ['p' => $isFa ? fa_num($r['saving']) : $r['saving']]) }}@else{{ __('ui.os_base') }}@endif
            </small>
            @if($r['saved'] > 0)
            <em class="os-saved">{{ __('ui.os_saved', ['amount' => cloud_price($r['saved']), 'months' => $isFa ? fa_num($r['months']) : $r['months']]) }}</em>
            @endif
          </span>
          <span class="os-opt-total" {!! $ltr !!}>{{ cloud_price($r['grand']) }}</span>
        </label>
        @endforeach
      </fieldset>

      <ul class="os-notes">
        @if($setup > 0)
        <li>{{ __('ui.os_setup') }}: <b {!! $ltr !!}>{{ cloud_price($setup) }}</b> — {{ __('ui.os_first_note') }}</li>
        @endif
        @if($product->tax_percent > 0)
          @if($vatVerified)
          <li>{{ __('ui.os_tax_note', ['p' => $isFa ? fa_num($product->tax_percent) : $product->tax_percent]) }}</li>
          @else
          {{-- حقوقی: تا تأییدِ ثبت‌نامِ ارزش افزوده، ادعای «۱۰٪ مالیات» روی صفحه نیاید --}}
          <li>{{ __('ui.os_tax_neutral') }}</li>
          @endif
        @endif
        @if($product->isRefundable())
        <li>{{ __('ui.hp_inc5') }} — <a href="{{ lroute('terms') }}">{{ __('ui.os_refund_policy') }}</a></li>
        @else
        <li>{{ __('ui.os_no_refund') }} — <a href="{{ lroute('terms') }}">{{ __('ui.os_refund_policy') }}</a></li>
        @endif
      </ul>

      {{-- کارتِ جمع — روی موبایل به پایینِ صفحه می‌چسبد؛ مقدارِ اولیه سمتِ سرور --}}
      <div class="os-total">
        <div>
          <small>{{ $setup > 0 ? __('ui.os_first_label') : __('ui.os_total_label') }} · <span id="os-cycle-name">{{ $defaultRow['label'] }}</span></small>
          <b id="os-total" {!! $ltr !!}>{{ cloud_price($setup > 0 ? $defaultRow['first'] : $defaultRow['grand']) }}</b>
        </div>
        <a class="btn btn-primary" id="os-cta" href="{{ $defaultRow['href'] }}" rel="nofollow">
          <span id="os-cta-text">{{ __('ui.os_cta_dynamic', ['cycle' => $defaultRow['label'], 'total' => cloud_price($setup > 0 ? $defaultRow['first'] : $defaultRow['grand'])]) }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg>
        </a>
      </div>
      <p class="os-login">{{ __('ui.os_saved_note') }}</p>
    </form>

  </div>
</section>

<style>
.os-steps{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;list-style:none;padding:0;margin:18px 0 0;font-size:12.5px;color:var(--muted)}
.os-steps li{padding:4px 10px;border:1px solid var(--line-2);border-radius:99px}
.os-steps li.on{color:var(--text);border-color:var(--cyan)}
.os-cycles{border:0;padding:0;margin:0 0 16px}
.os-cycles legend{margin-bottom:12px;padding:0}
.os-opt{display:flex;align-items:center;gap:14px;padding:14px 16px;min-height:56px;border:1px solid var(--line-2);border-radius:14px;margin-bottom:10px;cursor:pointer;transition:.2s}
.os-opt.on{border-color:var(--cyan);box-shadow:0 0 0 2px rgba(34,211,238,.18)}
.os-opt input{width:20px;height:20px;flex:none;accent-color:#22D3EE}
.os-opt-main{flex:1;display:flex;flex-direction:column;gap:2px}
.os-opt-main b{font-size:15.5px}
.os-opt-main small{color:var(--muted);font-size:12.5px}
.os-pop{display:inline-block;font-style:normal;font-size:11px;font-weight:800;color:#04121A;background:linear-gradient(100deg,#22D3EE,#8B5CF6);padding:2px 9px;border-radius:99px;margin-inline-start:8px;vertical-align:middle}
.os-saved{display:block;font-style:normal;font-size:12.5px;color:var(--cyan);margin-top:2px}
.os-opt-total{font-weight:800;font-size:16px;white-space:nowrap}
.os-notes{margin:0 0 18px;padding-inline-start:18px;font-size:13.5px;color:var(--muted)}
.os-notes li{margin-bottom:6px}
/* پس‌زمینهٔ تیرهٔ **تخت** (نه var(--surface)ِ نیمه‌شفاف): وقتی روی موبایل
   می‌چسبد، متنِ زیرش نباید از پشت بخواند (شورا/UX). backdrop-filter برای مرورگری
   که از آن پشتیبانی می‌کند لبهٔ نرم‌تری می‌دهد؛ بدونِ آن هم رنگِ تخت کافی است. */
.os-total{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px 18px;border:1px solid var(--line-2);border-radius:14px;background:#0B1220}
html[data-theme="light"] .os-total{background:#fff}
.os-total small{display:block;font-size:12.5px;color:var(--muted)}
.os-total b{font-size:20px}
.os-login{margin-top:12px;font-size:13px;color:var(--muted)}
@media(max-width:640px){.os-total{position:sticky;bottom:10px;z-index:5;box-shadow:0 10px 30px rgba(0,0,0,.35);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}.os-opt-total{font-size:15px}}
</style>

<script type="application/ld+json">{!! schema_ld($schema, 'Product') !!}</script>

@php
  /* هر مقدارِ واردِ JS از json می‌رود نه از echoی معمولی — قاعدهٔ ثبت‌شدهٔ CLAUDE.md */
  $osCfg = [
    'cta'     => __('ui.os_cta_dynamic'),
    'beacon'  => lroute('api.funnel'),
    'sku'     => $product->slug,
    'first'   => $setup > 0,
    'ourHost' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
  ];
@endphp
<script>
(function () {
  var cfg = @json($osCfg);
  var radios = document.querySelectorAll('#os-form input[name="cycle"]');
  var cta = document.getElementById('os-cta'), ctaText = document.getElementById('os-cta-text');
  var total = document.getElementById('os-total'), name = document.getElementById('os-cycle-name');
  var idx = 0;

  /* sid و ref در مرورگر ساخته می‌شوند: صفحه کش می‌شود و sidِ سرور بینِ همهٔ
     بازدیدکننده‌های یک دقیقه مشترک می‌شد. sid فقط شناسهٔ اتصالِ رویدادهاست؛
     هیچ تصمیمی به آن وابسته نیست، پس امضا نمی‌خواهد (console با regex می‌پذیرد). */
  function makeSid() {
    var a = new Uint8Array(8), s = '';
    try { (window.crypto || window.msCrypto).getRandomValues(a); } catch (e) { for (var i = 0; i < 8; i++) { a[i] = Math.floor(Math.random() * 256); } }
    for (var j = 0; j < a.length; j++) { s += ('0' + a[j].toString(16)).slice(-2); }
    return s;
  }
  function refBucket() {
    var r = document.referrer || '';
    if (!r) { return 'direct'; }
    try {
      var u = new URL(r);
      if (u.host !== cfg.ourHost) { return 'external'; }
      if (u.pathname.indexOf('/blog') !== -1) { return 'blog'; }
      if (u.pathname.indexOf('/order') !== -1) { return 'order'; }
      return 'site';
    } catch (e) { return 'external'; }
  }
  var sid = makeSid(), ref = refBucket();

  function beacon(event, extra) {
    try {
      var meta = document.querySelector('meta[name="csrf-token"]');
      var body = Object.assign({ event: event, sku: cfg.sku, sid: sid, ref: ref, _token: meta ? meta.content : '' }, extra || {});
      var blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
      if (navigator.sendBeacon) { navigator.sendBeacon(cfg.beacon, blob); }
    } catch (e) {}
  }

  function apply(r, fromUser) {
    radios.forEach(function (x) { x.closest('.os-opt').classList.toggle('on', x === r); });
    var amount = cfg.first ? r.dataset.first : r.dataset.total;
    cta.href = r.dataset.href + '&sid=' + sid + '&ref=' + ref;
    total.textContent = amount;
    name.textContent = r.dataset.label;
    ctaText.textContent = cfg.cta.replace(':cycle', r.dataset.label).replace(':total', amount);
    if (fromUser) {
      idx += 1;
      beacon('cycle_selected', { cycle: r.value, discount_pct: r.dataset.saving, selection_index: idx });
    }
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { apply(r, true); });
    if (r.checked) { apply(r, false); }
  });

  beacon('order_summary_view', {});
  var t0 = Date.now();
  cta.addEventListener('click', function () {
    var r = document.querySelector('#os-form input[name="cycle"]:checked');
    beacon('checkout_click', { cycle_at_click: r ? r.value : '', time_on_page: Math.round((Date.now() - t0) / 1000) });
  });
})();
</script>
@endsection
