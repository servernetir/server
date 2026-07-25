@extends('layouts.site')
@section('title', 'ثبت دامنه — جستجو و خرید آنلاین — سرورنت کلاود')
@section('description', 'دامنهٔ دلخواه خود را جستجو کنید، قیمت لحظه‌ای را ببینید و آنلاین ثبت کنید.')

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/panel.css') }}">
@endpush

@section('content')
<section class="wt-wrap">
  <div class="container">

    <div class="wt-head">
      <span class="wt-head-ic"><svg class="icon"><use href="#i-globe"/></svg></span>
      <div>
        <h1>ثبت دامنه</h1>
        <p>نام دلخواه را بنویسید تا موجودی و قیمت لحظه‌ای را ببینید.</p>
      </div>
    </div>

    {{-- ============ کادر جستجو ============ --}}
    <div class="dm-search">
      <div class="dm-in">
        <svg class="icon"><use href="#i-search"/></svg>
        <input type="text" id="dm-q" dir="ltr" autocomplete="off" spellcheck="false"
               placeholder="example.com یا فقط example">
        <button class="btn btn-primary" id="dm-go">
          <span class="dm-go-t">جستجو</span>
          <span class="dm-spin" hidden></span>
        </button>
      </div>
      <p class="dm-hint">قیمت‌ها بر اساس نرخ لحظه‌ای ارز محاسبه می‌شوند و تا ۱۵ دقیقه معتبرند.</p>
    </div>

    <div id="dm-error" class="tool-error" hidden></div>

    {{-- ============ نتایج ============ --}}
    <div id="dm-results" class="dm-results" hidden></div>

    {{-- حالت اولیه --}}
    <div id="dm-idle" class="pnl-empty" style="margin-top:30px">
      <svg class="icon"><use href="#i-globe"/></svg>
      <b>دامنه‌ای جستجو نکرده‌اید</b>
      <p>نام مورد نظرتان را بالا بنویسید. اگر پسوند ننویسید، چند پسوند پرکاربرد را هم بررسی می‌کنیم.</p>
    </div>

  </div>
</section>

<style>
.dm-search{margin:8px 0 10px}
.dm-in{display:flex;align-items:center;gap:10px;background:var(--surface);
  border:1px solid var(--line-2);border-radius:16px;padding:8px 8px 8px 16px}
html[data-theme="light"] .dm-in{background:#fff}
.dm-in>.icon{width:20px;height:20px;color:var(--dim);flex:none}
.dm-in input{flex:1;min-width:0;background:transparent;border:none;outline:none;
  color:var(--text);font-family:var(--font-body);font-size:16px;padding:12px 4px}
.dm-in input::placeholder{color:var(--dim)}
.dm-hint{font-size:12.5px;color:var(--dim);margin-top:10px;padding-inline-start:4px}
.dm-spin{width:15px;height:15px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;
  border-radius:50%;display:inline-block;animation:dmspin .7s linear infinite}
@keyframes dmspin{to{transform:rotate(360deg)}}

.dm-results{display:flex;flex-direction:column;gap:10px;margin-top:24px}
.dm-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  background:var(--surface);border:1px solid var(--line-2);border-radius:14px;padding:16px 18px}
html[data-theme="light"] .dm-row{background:#fff}
.dm-row.ok{border-color:var(--ok-line)}
.dm-row.premium{border-color:var(--warn-line)}
.dm-row.no{opacity:.72}
.dm-name{font-size:17px;font-weight:700;min-width:0;flex:1}
.dm-name small{display:block;font-size:12px;color:var(--dim);font-weight:400;margin-top:3px}
.dm-price{text-align:end;white-space:nowrap;font-variant-numeric:tabular-nums}
.dm-price b{font-size:19px;font-family:var(--font-disp)}
.dm-price small{display:block;font-size:11.5px;color:var(--dim);margin-top:2px}
@media(max-width:560px){
  .dm-in{flex-wrap:wrap}
  .dm-in input{width:100%;order:1}
  .dm-row{gap:10px}
  .dm-price{text-align:start}
}
</style>

<script>
(function () {
  var q = document.getElementById('dm-q'),
      go = document.getElementById('dm-go'),
      box = document.getElementById('dm-results'),
      idle = document.getElementById('dm-idle'),
      err = document.getElementById('dm-error'),
      spin = go.querySelector('.dm-spin'),
      label = go.querySelector('.dm-go-t');

  var faDigits = function (s) {
    return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  };
  var money = function (n) {
    return faDigits(Number(n).toLocaleString('en-US'));
  };
  var esc = function (s) {
    var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
  };

  function busy(on) {
    go.disabled = on;
    spin.hidden = !on;
    label.hidden = on;
  }

  function row(r) {
    // سه وضعیت مجزا که کارفرما خواست
    if (!r.available) {
      return '<div class="dm-row no">' +
        '<div class="dm-name" dir="ltr">' + esc(r.domain) + '<small>این دامنه قبلاً ثبت شده است</small></div>' +
        '<span class="pnl-pill mute">ثبت‌شده</span></div>';
    }
    if (!r.orderable) {
      var why = r.reason === 'fx_unavailable'
        ? 'قیمت لحظه‌ای در دسترس نیست — چند دقیقه بعد دوباره امتحان کنید'
        : 'قیمت این دامنه از رسیلری دریافت نشد';
      return '<div class="dm-row no">' +
        '<div class="dm-name" dir="ltr">' + esc(r.domain) + '<small>' + why + '</small></div>' +
        '<span class="pnl-pill warn">فعلاً قابل سفارش نیست</span></div>';
    }
    var premium = r.is_premium;
    return '<div class="dm-row ' + (premium ? 'premium' : 'ok') + '">' +
      '<div class="dm-name" dir="ltr">' + esc(r.domain) +
        (premium ? '<small>دامنهٔ ویژه — قیمت متفاوت با نرخ عادی</small>' : '<small>آزاد و قابل ثبت</small>') +
      '</div>' +
      '<span class="pnl-pill ' + (premium ? 'warn' : 'ok') + '">' + (premium ? 'ویژه' : 'آزاد') + '</span>' +
      '<div class="dm-price"><b>' + money(r.price_toman) + '</b><small>تومان / سال</small></div>' +
      '<a class="pnl-btn primary" href="#">ثبت دامنه</a></div>';
  }

  async function run() {
    var term = q.value.trim();
    if (!term) { q.focus(); return; }

    busy(true);
    err.hidden = true;
    idle.hidden = true;

    try {
      var res = await fetch(@json(lroute('domain.search.check')), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ q: term })
      });
      var d = await res.json();

      if (!d.ok || !d.results || !d.results.length) {
        err.textContent = 'نتیجه‌ای برنگشت. لطفاً دوباره تلاش کنید.';
        err.hidden = false;
        box.hidden = true;
        return;
      }

      box.innerHTML = d.results.map(row).join('');
      box.hidden = false;
    } catch (e) {
      err.textContent = 'ارتباط با سرور برقرار نشد.';
      err.hidden = false;
    } finally {
      busy(false);
    }
  }

  go.addEventListener('click', run);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') run(); });
})();
</script>
@endsection
