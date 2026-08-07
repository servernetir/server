@extends('layouts.site')
@section('title', __('ui.dsr_title'))
@section('description', __('ui.dsr_meta_desc'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/panel.css') }}">
@endpush

@section('content')

{{-- ============ رشته‌های موردنیازِ جاوااسکریپت (سه‌زبانه) ============ --}}
@php
$T = [
  'taken_note'         => __('ui.dsr_taken_note'),
  'taken_pill'         => __('ui.dsr_taken_pill'),
  'fx_unavailable'     => __('ui.dsr_fx_unavailable'),
  'no_price'           => __('ui.dsr_no_price'),
  'not_orderable_pill' => __('ui.dsr_not_orderable_pill'),
  'premium_note'       => __('ui.dsr_premium_note'),
  'free_note'          => __('ui.dsr_free_note'),
  'premium_pill'       => __('ui.dsr_premium_pill'),
  'free_pill'          => __('ui.dsr_free_pill'),
  'price_unit'         => __('ui.dsr_price_unit'),
  'register_btn'       => __('ui.dsr_register_btn'),
  'err_empty'          => __('ui.dsr_err_empty'),
  'err_conn'           => __('ui.dsr_err_conn'),
  // ⚠️ جای‌نگهدار `__N__` است نه `:n` — جایگزینی سمتِ جاوااسکریپت انجام
  // می‌شود و آن‌جا رقمِ فارسی هم باید بنشیند.
  'count_tpl'          => __('ui.dsr_count', ['n' => '__N__']),
  'is_fa'              => app()->getLocale() === 'fa',
  'eur_rate'           => cloud_eur_rate(),
  // فروش و تحویلِ دامنه در کنسولِ خودمان است، نه WHMCSِ بیرونی
  'panel'              => lroute('account.domains'),

  /*
  | دسته‌بندیِ پسوندها برای بارگذاریِ تدریجی.
  |
  | ⚠️ اندازهٔ دسته **۱۰** است چون اعتبارسنجیِ روت سقفِ ۱۲ دارد؛ بزرگ‌ترش
  | یعنی هر درخواست با خطای اعتبارسنجی برمی‌گردد و کاربر هیچ نتیجه‌ای
  | نمی‌بیند — خرابی‌ای که فقط در مرورگر دیده می‌شود، نه در تست‌های سرور.
  */
  'tld_first'          => \App\Services\Domain\DomainSearch::firstBatch(),
  'tld_rest'           => \App\Services\Domain\DomainSearch::restBatches(),
];
@endphp
<script>window.T = @json($T);</script>

{{--
  ═══════════════ صفحهٔ ثبتِ دامنه ═══════════════

  چیدمان عمداً **وسط‌چین** است و نه ستونیِ معمولِ سایت: این صفحه یک کار دارد و
  فقط یک کار — نوشتنِ یک نام. هر چیزی که چشم را از کادرِ جستجو دور کند، همان
  کار را سخت‌تر می‌کند.

  ⚠️ RTL/LTR: کلِ چیدمان با ویژگی‌های منطقی (`inline-start`) نوشته شده، ولی
  خودِ نامِ دامنه و قیمت همیشه `dir="ltr"`اند — «example.com» در متنِ راست‌به‌چپ
  بدونِ این، وارونه دیده می‌شود.
--}}
<section class="dsx">
  <div class="dsx-glow" aria-hidden="true"></div>

  <div class="container dsx-wrap">

    <header class="dsx-head">
      <span class="dsx-badge">
        <svg class="icon"><use href="#i-globe"/></svg>{{ __('ui.dsr_badge') }}
      </span>
      <h1>{{ __('ui.dsr_h1') }}</h1>
      <p>{{ __('ui.dsr_lead') }}</p>
    </header>

    {{-- ============ کادرِ جستجو ============ --}}
    <div class="dsx-box">
      <div class="dsx-in">
        <svg class="icon dsx-in-ic" aria-hidden="true"><use href="#i-search"/></svg>
        <input type="text" id="dm-q" dir="ltr" autocomplete="off" spellcheck="false"
               aria-label="{{ __('ui.dsr_input_ph') }}"
               placeholder="{{ __('ui.dsr_input_ph') }}">
        <button class="dsx-go" id="dm-go">
          <span class="dm-go-t">{{ __('ui.dsr_search_btn') }}</span>
          <span class="dm-spin" hidden></span>
        </button>
      </div>
      <p class="dsx-hint">{{ __('ui.dsr_hint') }}</p>
    </div>

    <div id="dm-error" class="dsx-err" hidden role="alert"></div>

    {{-- ============ فیلترها ============
         روی نتیجه‌اند نه روی درخواست: همه‌چیز از قبل در صفحه است و فیلتر فقط
         نمایش را عوض می‌کند، پس بی‌درنگ است و هیچ تماسِ تازه‌ای نمی‌سازد.
         شمارندهٔ کنارِ هر گزینه هم می‌گوید فیلتر چه چیزی را پنهان می‌کند. --}}
    <div class="dsx-filters" id="dm-filters" hidden>
      <label class="dsx-chk">
        <input type="checkbox" id="f-taken" checked>
        <span>{{ __('ui.dsr_f_hide_taken') }}</span>
        <i class="dsx-n" id="n-taken">۰</i>
      </label>
      <label class="dsx-chk">
        <input type="checkbox" id="f-premium">
        <span>{{ __('ui.dsr_f_hide_premium') }}</span>
        <i class="dsx-n" id="n-premium">۰</i>
      </label>
      <label class="dsx-chk">
        <input type="checkbox" id="f-unavail" checked>
        <span>{{ __('ui.dsr_f_hide_unorderable') }}</span>
        <i class="dsx-n" id="n-unavail">۰</i>
      </label>

      <span class="dsx-sep" aria-hidden="true"></span>

      <label class="dsx-sort">
        <span>{{ __('ui.pt_f_sort') }}</span>
        <select id="f-sort">
          <option value="best">{{ __('ui.dsr_sort_best') }}</option>
          <option value="price">{{ __('ui.pt_sort_cheap') }}</option>
          <option value="-price">{{ __('ui.pt_sort_dear') }}</option>
          <option value="tld">{{ __('ui.dsr_sort_tld') }}</option>
        </select>
      </label>

      <span class="dsx-count" id="dm-count" aria-live="polite"></span>
    </div>

    {{-- ============ نتایج ============ --}}
    <div class="dsx-table" id="dm-table" hidden>
      <div class="dsx-tr dsx-th" aria-hidden="true">
        <span>{{ __('ui.pt_plan') }}</span>
        <span>{{ __('ui.dsr_th_state') }}</span>
        <span>{{ __('ui.pt_price') }}</span>
        <span></span>
      </div>
      <div id="dm-results" role="list"></div>
    </div>

    {{-- «هنوز دارد می‌آید» — بدونِ آن، کاربر فهرستِ نیمه‌کامل را کامل فرض
         می‌کند و فکر می‌کند پسوندِ موردِ نظرش را نداریم. --}}
    <p id="dm-more" class="dsx-more" hidden aria-live="polite">
      <span class="dsx-dots"><i></i><i></i><i></i></span>{{ __('ui.dsr_more') }}
    </p>

    <p id="dm-empty" class="dsx-empty" hidden>{{ __('ui.dsr_f_all_hidden') }}</p>

    {{-- حالت اولیه --}}
    <div id="dm-idle" class="pnl-empty" style="margin-top:30px">
      <svg class="icon"><use href="#i-globe"/></svg>
      <b>{{ __('ui.dsr_idle_title') }}</b>
      <p>{{ __('ui.dsr_idle_text') }}</p>
    </div>

  </div>
</section>

<style>
/* ═══════════ صفحهٔ ثبتِ دامنه — شیشه‌ای، وسط‌چین ═══════════
 *
 * ⚠️ همهٔ فاصله‌ها با ویژگی‌های **منطقی** (inline-start/end) نوشته شده‌اند تا
 * فارسی و انگلیسی و ترکی از یک کد بیایند. `dir` جداگانه ست نمی‌شود مگر برای
 * خودِ نامِ دامنه و عدد، که همیشه چپ‌به‌راست‌اند.
 */
/* ⚠️ این عدد **فقط فاصلهٔ تزئینی** است، نه جبرانِ هدر.
   جبرانِ هدرِ ثابت یک‌جا و برای همهٔ صفحات در `#main{padding-top:var(--header-h)}`
   انجام می‌شود (انتهای site.css). قبلاً این‌جا ۵۶px بود که هم جبران بود هم
   فاصله — و چون هدر ۱۱۱px است، تیتر و بالای باکسِ جستجو زیرِ هدر می‌رفت. */
.dsx{position:relative;padding:40px 0 96px;overflow:hidden}
.dsx-wrap{position:relative;z-index:2;max-width:940px}

/* هالهٔ پس‌زمینه — عمقِ شیشه از همین می‌آید، نه از سایه */
.dsx-glow{position:absolute;inset-inline-start:50%;top:-160px;width:820px;height:520px;
  transform:translateX(-50%);pointer-events:none;z-index:1;
  background:radial-gradient(closest-side,rgba(34,211,238,.16),transparent 70%);
  filter:blur(40px)}

.dsx-head{text-align:center;margin-bottom:26px}
.dsx-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
  font-size:12.5px;font-weight:600;color:var(--cyan);
  background:rgba(34,211,238,.09);border:1px solid rgba(34,211,238,.25)}
.dsx-badge .icon{width:14px;height:14px}
.dsx-head h1{margin:14px 0 10px;font-size:clamp(26px,4.4vw,40px);line-height:1.35;letter-spacing:-.4px}
.dsx-head p{margin:0 auto;max-width:56ch;color:var(--muted);font-size:15px;line-height:2}

/* ── کادرِ جستجو ── */
.dsx-box{max-width:720px;margin:0 auto 18px}
.dsx-in{display:flex;align-items:center;gap:10px;padding:9px;border-radius:20px;
  background:rgba(255,255,255,.045);border:1px solid var(--line-2);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  box-shadow:0 24px 60px rgba(0,0,0,.34),inset 0 1px 0 rgba(255,255,255,.06);
  transition:border-color .22s,box-shadow .22s}
.dsx-in:focus-within{border-color:rgba(34,211,238,.5);
  box-shadow:0 24px 60px rgba(0,0,0,.34),0 0 0 4px rgba(34,211,238,.1)}
.dsx-in-ic{width:19px;height:19px;color:var(--dim);flex:none;margin-inline-start:10px}
.dsx-in input{flex:1;min-width:0;border:0;background:none;outline:none;color:var(--text);
  font-size:17px;padding:12px 0;font-family:ui-monospace,Menlo,Consolas,monospace}
.dsx-in input::placeholder{color:var(--dim);font-family:inherit}
.dsx-go{display:inline-flex;align-items:center;gap:8px;padding:13px 26px;border:0;border-radius:14px;
  font:inherit;font-size:15px;font-weight:700;cursor:pointer;color:#04141c;
  background:linear-gradient(135deg,#67e8f9,#22d3ee);
  box-shadow:0 8px 24px rgba(34,211,238,.28);transition:transform .18s,box-shadow .18s}
.dsx-go:hover{transform:translateY(-1px);box-shadow:0 12px 30px rgba(34,211,238,.4)}
.dsx-go:disabled{opacity:.6;cursor:default;transform:none}
.dsx-hint{margin:12px 0 0;text-align:center;font-size:12.8px;color:var(--dim);line-height:1.9}

.dsx-err{max-width:720px;margin:0 auto 18px;padding:13px 16px;border-radius:14px;text-align:center;
  font-size:13.5px;color:#fca5a5;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.28)}

/* ── فیلترها ── */
.dsx-filters{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:10px;
  margin:0 auto 16px;padding:12px 16px;max-width:860px;border-radius:16px;
  background:rgba(255,255,255,.035);border:1px solid var(--line);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.dsx-chk{display:inline-flex;align-items:center;gap:8px;padding:7px 13px;border-radius:999px;
  font-size:12.8px;cursor:pointer;user-select:none;
  background:rgba(255,255,255,.04);border:1px solid transparent;transition:border-color .18s,background .18s}
.dsx-chk:hover{background:rgba(255,255,255,.07)}
.dsx-chk.on{border-color:rgba(34,211,238,.4);background:rgba(34,211,238,.08);color:var(--cyan)}
.dsx-chk input{width:15px;height:15px;accent-color:#22d3ee;cursor:pointer;flex:none}
.dsx-n{font-style:normal;font-size:11px;padding:1px 7px;border-radius:999px;
  background:rgba(255,255,255,.08);color:var(--dim)}
.dsx-chk.on .dsx-n{background:rgba(34,211,238,.16);color:var(--cyan)}
.dsx-sep{width:1px;height:22px;background:var(--line)}
.dsx-sort{display:inline-flex;align-items:center;gap:8px;font-size:12.8px;color:var(--muted)}
.dsx-sort select{padding:7px 11px;border-radius:10px;font:inherit;font-size:12.8px;cursor:pointer;
  background:var(--bg2);color:var(--text);border:1px solid var(--line)}
.dsx-count{margin-inline-start:auto;font-size:12.5px;color:var(--dim)}

/* ── جدولِ نتایج ── */
.dsx-table{max-width:860px;margin:0 auto;border-radius:20px;overflow:hidden;
  background:rgba(255,255,255,.035);border:1px solid var(--line);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  box-shadow:0 24px 64px rgba(0,0,0,.3)}
.dsx-tr{display:grid;grid-template-columns:1fr 130px 168px 132px;align-items:center;gap:14px;
  padding:15px 20px;border-bottom:1px solid var(--line)}
.dsx-tr:last-child{border-bottom:0}
.dsx-th{font-size:11.8px;font-weight:700;color:var(--dim);letter-spacing:.3px;
  background:rgba(255,255,255,.025)}
.dsx-row{transition:background .18s}
.dsx-row:hover{background:rgba(255,255,255,.045)}
.dsx-row.is-hidden{display:none}

/* ورودِ نرمِ هر ردیف — ردیف‌ها دسته‌دسته می‌رسند و پرشِ ناگهانی حسِ خرابی
   می‌دهد. با prefers-reduced-motion خاموش می‌شود. */
@keyframes dsx-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.dsx-row{animation:dsx-in .26s ease both}
@media(prefers-reduced-motion:reduce){.dsx-row{animation:none}}

.dsx-name{font-size:15.5px;font-weight:600;word-break:break-all;min-width:0}
.dsx-name small{display:block;margin-top:3px;font-size:11.5px;color:var(--dim);font-weight:400}
.dsx-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;
  font-size:11.5px;font-weight:700;white-space:nowrap}
.dsx-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.dsx-pill.free{color:#34d399;background:rgba(52,211,153,.1)}
.dsx-pill.prem{color:#fbbf24;background:rgba(251,191,36,.1)}
.dsx-pill.taken{color:var(--dim);background:rgba(255,255,255,.05)}
.dsx-pill.no{color:#fca5a5;background:rgba(239,68,68,.08)}
.dsx-price{font-size:15px;font-weight:700;white-space:nowrap}
.dsx-price small{display:block;font-size:11px;color:var(--dim);font-weight:400;margin-top:2px}
.dsx-buy{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;border-radius:11px;
  font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;color:#04141c;
  background:linear-gradient(135deg,#67e8f9,#22d3ee);transition:transform .16s,box-shadow .16s}
.dsx-buy:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(34,211,238,.32)}

.dsx-more{display:flex;align-items:center;justify-content:center;gap:9px;margin:18px 0 0;
  font-size:13px;color:var(--muted)}
.dsx-dots{display:inline-flex;gap:4px}
.dsx-dots i{width:6px;height:6px;border-radius:50%;background:var(--cyan);
  animation:dsx-pulse 1.1s ease-in-out infinite}
.dsx-dots i:nth-child(2){animation-delay:.16s}
.dsx-dots i:nth-child(3){animation-delay:.32s}
@keyframes dsx-pulse{0%,100%{opacity:.22;transform:scale(.8)}50%{opacity:1;transform:scale(1)}}
@media(prefers-reduced-motion:reduce){.dsx-dots i{animation:none;opacity:.6}}
.dsx-empty{margin:20px 0 0;text-align:center;font-size:13.5px;color:var(--muted)}

/* حالتِ روشن: شیشهٔ تیره روی زمینهٔ روشن دیده نمی‌شود */
html[data-theme="light"] .dsx-in,
html[data-theme="light"] .dsx-table,
html[data-theme="light"] .dsx-filters{background:rgba(255,255,255,.72)}
html[data-theme="light"] .dsx-row:hover{background:rgba(0,0,0,.03)}
html[data-theme="light"] .dsx-th{background:rgba(0,0,0,.02)}

@media(max-width:720px){
  .dsx{padding:22px 0 64px}      /* فاصلهٔ تزئینی؛ جبرانِ هدر روی #main است */
  .dsx-in{flex-wrap:wrap;padding:12px}
  .dsx-in input{width:100%;order:1;font-size:16px}
  .dsx-go{width:100%;order:2;justify-content:center}
  .dsx-in-ic{display:none}
  .dsx-th{display:none}
  .dsx-tr{grid-template-columns:1fr auto;gap:10px;padding:14px 16px}
  .dsx-name{grid-column:1/-1}
  .dsx-price{text-align:start}
  .dsx-buy{width:100%;grid-column:1/-1}
  .dsx-count{margin-inline-start:0;width:100%;text-align:center}
}
</style>

<script>
(function () {
  var T = window.T || {};

  var q       = document.getElementById('dm-q'),
      go      = document.getElementById('dm-go'),
      box     = document.getElementById('dm-results'),
      table   = document.getElementById('dm-table'),
      filters = document.getElementById('dm-filters'),
      countEl = document.getElementById('dm-count'),
      emptyEl = document.getElementById('dm-empty'),
      err     = document.getElementById('dm-error'),
      more    = document.getElementById('dm-more'),
      spin    = go.querySelector('.dm-spin'),
      label   = go.querySelector('.dm-go-t');

  var fTaken   = document.getElementById('f-taken'),
      fPremium = document.getElementById('f-premium'),
      fUnavail = document.getElementById('f-unavail'),
      fSort    = document.getElementById('f-sort');

  var faDigits = function (s) {
    return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  };
  /*
   * 🔴 وقتی نرخِ یورو نیست، **عدد چاپ نمی‌شود**.
   *
   * نسخهٔ قبلی در آن حالت عددِ خامِ تومانی برمی‌گرداند و صداکننده کنارش
   * `T.price_unit` (یعنی «€») می‌گذاشت. نتیجه: مشتریِ خارجی «۱٬۲۵۰٬۰۰۰ €»
   * می‌دید به‌جای «۲۵٫۰۰ €» — عددی ~۵۰٬۰۰۰ برابر.
   *
   * یا سایت را کلاهبردار فرض می‌کرد و می‌رفت، یا اگر ثبت می‌زد با مبلغی
   * روبه‌رو می‌شد که هیچ ربطی به آنچه دیده بود نداشت.
   *
   * ⚠️ `null` یعنی «قیمت نداریم» — نه صفر و نه عددِ حدسی. همان قاعده‌ای که
   *    سمتِ سرور هم رعایت می‌شود: قیمتِ غلط از نبودِ قیمت بدتر است.
   */
  var money = function (n) {
    n = Number(n);

    if (T.is_fa) { return faDigits(n.toLocaleString('en-US')); }

    if (T.eur_rate > 0) {
      return (n / T.eur_rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    return null;
  };
  var esc = function (s) {
    var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
  };
  var num = function (n) { return T.is_fa ? faDigits(n) : String(n); };

  /* ═══ وضعیت ═══
   * `rows` همهٔ نتیجه‌هاست؛ فیلترها فقط `is-hidden` می‌زنند و هیچ درخواستِ
   * تازه‌ای نمی‌سازند — پس تیک‌زدن بی‌درنگ است.
   */
  var rows = [];
  var token = 0;

  function stateOf(r) {
    if (!r.available) { return 'taken'; }
    if (!r.orderable) { return 'unavail'; }
    return r.is_premium ? 'premium' : 'free';
  }

  function render(r) {
    var st = stateOf(r);
    var el = document.createElement('div');
    el.className = 'dsx-tr dsx-row';
    el.setAttribute('role', 'listitem');
    el.dataset.state = st;
    el.dataset.price = r.price_toman || 0;
    el.dataset.tld = r.tld || '';

    var pill, note, right;

    if (st === 'taken') {
      pill = '<span class="dsx-pill taken">' + T.taken_pill + '</span>';
      note = T.taken_note;
      right = '';
    } else if (st === 'unavail') {
      pill = '<span class="dsx-pill no">' + T.not_orderable_pill + '</span>';
      note = r.reason === 'fx_unavailable' ? T.fx_unavailable : T.no_price;
      right = '';
    } else {
      var prem = st === 'premium';
      pill = '<span class="dsx-pill ' + (prem ? 'prem' : 'free') + '">'
           + (prem ? T.premium_pill : T.free_pill) + '</span>';
      note = prem ? T.premium_note : T.free_note;
      right = '<a class="dsx-buy" href="' + T.panel + '?register=' + encodeURIComponent(r.domain) + '">'
            + T.register_btn + '</a>';
    }

    // ⚠️ نرخِ ارز که نبود، نه عدد نشان می‌دهیم نه دکمهٔ ثبت — وگرنه مشتری
    //    چیزی سفارش می‌دهد که قیمتش را ندیده.
    var amount = (st === 'free' || st === 'premium') ? money(r.price_toman) : null;

    if (amount === null && (st === 'free' || st === 'premium')) {
      pill = '<span class="dsx-pill">' + T.not_orderable_pill + '</span>';
      note = T.fx_unavailable;
      right = '';
    }

    var price = amount !== null
      ? '<div class="dsx-price" dir="ltr">' + amount + '<small>' + T.price_unit + '</small></div>'
      : '<div class="dsx-price" aria-hidden="true">—</div>';

    el.innerHTML =
      '<div class="dsx-name" dir="ltr">' + esc(r.domain) + '<small>' + note + '</small></div>' +
      '<div>' + pill + '</div>' + price +
      '<div>' + right + '</div>';

    return el;
  }

  /* فیلتر + مرتب‌سازی — روی DOMِ موجود، بدونِ ساختنِ دوباره */
  function apply() {
    var hide = { taken: fTaken.checked, premium: fPremium.checked, unavail: fUnavail.checked };
    var n = { taken: 0, premium: 0, unavail: 0 }, shown = 0;

    var els = [].slice.call(box.children);

    els.forEach(function (el) {
      var st = el.dataset.state;
      if (st in n) { n[st]++; }
      var hidden = (st === 'taken' && hide.taken)
                || (st === 'premium' && hide.premium)
                || (st === 'unavail' && hide.unavail);
      el.classList.toggle('is-hidden', hidden);
      if (!hidden) { shown++; }
    });

    var mode = fSort.value;
    var visible = els.filter(function (el) { return !el.classList.contains('is-hidden'); });

    visible.sort(function (a, b) {
      var pa = +a.dataset.price || 0, pb = +b.dataset.price || 0;
      if (mode === 'price')  { return (pa || 1e15) - (pb || 1e15); }
      if (mode === '-price') { return pb - pa; }
      if (mode === 'tld')    { return a.dataset.tld.localeCompare(b.dataset.tld); }
      // «بهترین»: آزادها اول، بعد پرمیوم، و درونِ هرکدام ارزان‌تر بالاتر
      var rank = { free: 0, premium: 1, unavail: 2, taken: 3 };
      var d = rank[a.dataset.state] - rank[b.dataset.state];
      return d !== 0 ? d : (pa || 1e15) - (pb || 1e15);
    });

    visible.forEach(function (el) { box.appendChild(el); });

    document.getElementById('n-taken').textContent = num(n.taken);
    document.getElementById('n-premium').textContent = num(n.premium);
    document.getElementById('n-unavail').textContent = num(n.unavail);

    // برچسبِ روشن‌بودن: `:has()` در سافاریِ قدیمی نیست، پس کلاس دستی می‌زنیم
    [[fTaken], [fPremium], [fUnavail]].forEach(function (p) {
      p[0].closest('.dsx-chk').classList.toggle('on', p[0].checked);
    });

    countEl.textContent = T.count_tpl.replace('__N__', num(shown));
    emptyEl.hidden = !(els.length > 0 && shown === 0);
  }

  [fTaken, fPremium, fUnavail, fSort].forEach(function (el) {
    el.addEventListener('change', apply);
  });

  function busy(on) {
    go.disabled = on;
    spin.hidden = !on;
    label.hidden = on;
  }

  var fetchBatch = async function (term, tlds) {
    var res = await fetch(@json(lroute('domain.search.check')), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(tlds ? { q: term, tlds: tlds } : { q: term })
    });
    return res.json();
  };

  async function run() {
    var term = q.value.trim();
    if (!term) { q.focus(); return; }

    var mine = ++token;
    busy(true);
    err.hidden = true;
    box.innerHTML = '';
    rows = [];
    table.hidden = true;
    filters.hidden = true;
    emptyEl.hidden = true;

    var seen = {};
    var append = function (list) {
      var added = 0;
      (list || []).forEach(function (r) {
        var k = String(r.domain || '').toLowerCase();
        if (!k || seen[k]) { return; }   // پسوندِ خودِ کاربر در دستهٔ اول هم هست
        seen[k] = true;
        rows.push(r);
        box.appendChild(render(r));
        added++;
      });
      if (added) {
        table.hidden = false;
        filters.hidden = false;
        apply();
      }
    };

    try {
      var first = await fetchBatch(term, T.tld_first);
      if (mine !== token) { return; }

      if (!first.ok) {
        err.textContent = T.err_empty;
        err.hidden = false;
        return;
      }

      append(first.results);
      busy(false);                       // از همین‌جا می‌شود خرید

      for (var i = 0; i < T.tld_rest.length; i++) {
        if (mine !== token) { return; }
        more.hidden = false;
        try {
          var d = await fetchBatch(term, T.tld_rest[i]);
          if (mine !== token) { return; }
          append(d.results);
        } catch (e) {
          // یک دستهٔ ناموفق نباید بقیه را متوقف کند
        }
      }

      if (!rows.length) {
        err.textContent = T.err_empty;
        err.hidden = false;
      }
    } catch (e) {
      err.textContent = T.err_conn;
      err.hidden = false;
    } finally {
      if (mine === token) { more.hidden = true; }
      busy(false);
    }
  }

  go.addEventListener('click', run);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { run(); } });

  // ?q= در آدرس: لینکِ مستقیم به نتیجهٔ یک دامنه
  var pre = new URLSearchParams(location.search).get('q');
  if (pre) { q.value = pre; run(); }
})();
</script>
@endsection
