@extends('panel.layout')
@section('title', 'پنل مدیریت — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>داشبورد مدیریت</h1>
    <p>نمای کلی کسب‌وکار</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn" href="#"><svg class="icon"><use href="#i-restore"/></svg>گزارش‌ها</a>
    <a class="pnl-btn primary" href="#"><svg class="icon"><use href="#i-plus"/></svg>محصول جدید</a>
  </div>
</div>

{{-- ============ ویجت دلار زنده ============
     کارفرما این را می‌خواست: قیمت لحظه‌ای دلار، مبنای قیمت‌گذاری.
     در پیش‌نمایش داده نمونه است؛ منطق واقعی = سرویس ExchangeRate + کران ساعتی. --}}
<section class="pnl-sec" style="border-color:var(--info-line)">
  <div class="pnl-sec-h" style="background:var(--info-bg)">
    <h2 style="color:var(--info)">نرخ دلار — مبنای قیمت‌گذاری</h2>
    <span style="font-size:12px;color:var(--dim)">منبع: alanchand.com · به‌روزرسانی خودکار هر ۱ ساعت</span>
  </div>
  <div class="pnl-sec-b">
    <div class="adm-fx">
      <div class="adm-fx-main">
        <small>دلار آمریکا</small>
        {{-- کلید rate_toman است؛ وقتی ExchangeRate چندارزی شد نامش عوض شد --}}
        @if(!empty($usd['rate_toman']))
          <b class="pnl-num">{{ fa_num(number_format($usd['rate_toman'])) }}</b>
          <span class="adm-fx-unit">تومان</span>
        @else
          <b class="pnl-num" style="font-size:22px;color:var(--dim)">—</b>
          <span class="adm-fx-unit">در دسترس نیست</span>
        @endif
      </div>
      <div class="adm-fx-meta">
        @if(!empty($usd['at']))
          <span class="pnl-pill ok" style="font-size:11px"><svg class="icon" style="width:11px;height:11px"><use href="#i-check"/></svg> زنده از {{ $usd['source'] ?? 'alanchand' }}</span>
          <small>آخرین به‌روزرسانی: {{ \Carbon\Carbon::parse($usd['at'])->diffForHumans() }}</small>
        @else
          <span class="pnl-pill warn" style="font-size:11px">منبع پاسخ نداد</span>
          <small>در نسخهٔ واقعی از سرور ایران دریافت می‌شود</small>
        @endif
      </div>
      <div class="adm-fx-act">
        <button class="pnl-btn"><svg class="icon"><use href="#i-restore"/></svg>به‌روزرسانی دستی</button>
      </div>
    </div>
    <p style="margin-top:14px;font-size:12.5px;color:var(--muted);line-height:1.95">
      قیمت پایهٔ محصولات به دلار تعریف می‌شود؛ قیمت تومان از این نرخ + درصد سود
      محاسبه و برای تأیید به شما پیشنهاد می‌شود. تا زمانی که قیمت را قفل نکنید،
      تغییری در فروشگاه اعمال نمی‌شود.
    </p>
  </div>
</section>

{{-- ============ وضعیت رسیلری دامنه ============ --}}
<section class="pnl-sec" id="op-card">
  <div class="pnl-sec-h">
    <h2>رسیلری دامنه — OpenProvider</h2>
    <span id="op-badge" class="pnl-pill mute">در حال بررسی…</span>
  </div>
  <div class="pnl-sec-b">
    <p id="op-msg" style="font-size:13.5px;color:var(--muted);line-height:2">
      در حال بررسی اتصال به رسیلری…
    </p>
    <div class="pnl-acts" style="margin-top:12px">
      <button class="pnl-btn" id="op-retry"><svg class="icon"><use href="#i-restore"/></svg>بررسی دوباره</button>
      <a class="pnl-btn" href="{{ lroute('domain.search') }}"><svg class="icon"><use href="#i-search"/></svg>صفحهٔ جستجوی دامنه</a>
    </div>
  </div>
</section>

<script>
(function () {
  var badge = document.getElementById('op-badge'),
      msg = document.getElementById('op-msg'),
      card = document.getElementById('op-card');

  async function check() {
    badge.className = 'pnl-pill mute';
    badge.textContent = 'در حال بررسی…';
    msg.textContent = 'در حال بررسی اتصال به رسیلری…';
    try {
      var d = await (await fetch(@json(lroute('domain.status')))).json();
      if (d.connected) {
        badge.className = 'pnl-pill ok';
        badge.textContent = 'متصل';
        card.style.borderColor = 'var(--ok-line)';
        msg.textContent = 'اتصال برقرار است. جستجو و استعلام قیمت دامنه فعال است.';
      } else if (!d.configured) {
        badge.className = 'pnl-pill warn';
        badge.textContent = 'تنظیم نشده';
        card.style.borderColor = 'var(--warn-line)';
        msg.textContent = d.reason;
      } else {
        badge.className = 'pnl-pill danger';
        badge.textContent = 'قطع';
        card.style.borderColor = 'var(--danger-line)';
        msg.textContent = d.reason + (d.code ? ' (کد ' + d.code + ')' : '');
      }
    } catch (e) {
      badge.className = 'pnl-pill danger';
      badge.textContent = 'خطا';
      msg.textContent = 'بررسی وضعیت انجام نشد.';
    }
  }

  document.getElementById('op-retry').addEventListener('click', check);
  check();
})();
</script>

{{-- ============ KPI ============ --}}
<div class="pnl-stats">
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-coins"/></svg>درآمد امروز</div>
    <b class="pnl-num">۱۴٫۲م</b>
    <small>تومان · ۸ فاکتور</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-server"/></svg>سرویس فعال</div>
    <b class="pnl-num">۳۴۷</b>
    <small>+۵ این هفته</small>
  </div>
  <div class="pnl-stat is-warn">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-coins"/></svg>سفارش در انتظار</div>
    <b class="pnl-num">۴</b>
    <small>منتظر پرداخت یا بررسی</small>
  </div>
  <div class="pnl-stat is-danger">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-zap"/></svg>تحویل ناموفق</div>
    <b class="pnl-num">۱</b>
    <small>نیاز به رسیدگی فوری</small>
  </div>
</div>

{{-- ============ نیاز به رسیدگی ============ --}}
<section class="pnl-sec pnl-alert">
  <div class="pnl-sec-h"><h2>نیاز به رسیدگی مدیر</h2></div>
  <ul class="pnl-todo">
    <li>
      <span class="pnl-todo-ic d"><svg class="icon"><use href="#i-zap"/></svg></span>
      <span class="pnl-todo-t">
        <b>تحویل سرور #P-4821 ناموفق ماند</b>
        <small>Proxmox تهران · خطای cloud-init · ۳ بار تلاش شد</small>
      </span>
      <a class="pnl-btn danger" href="#">بررسی</a>
    </li>
    <li>
      <span class="pnl-todo-ic w"><svg class="icon"><use href="#i-user"/></svg></span>
      <span class="pnl-todo-t">
        <b>۲ مدرک احراز هویت در انتظار تأیید</b>
        <small>یک حقیقی · یک حقوقی</small>
      </span>
      <a class="pnl-btn" href="#">بررسی</a>
    </li>
  </ul>
</section>

{{-- ============ سفارش‌های اخیر ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>سفارش‌های اخیر</h2><a class="pnl-more" href="#">همه</a></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr><th>شماره</th><th>مشتری</th><th>محصول</th><th>وضعیت</th><th class="num">مبلغ</th></tr>
        </thead>
        <tbody>
          <tr>
            <td dir="ltr">#۱۴۸۲۳</td>
            <td>احسان ابراهیمی <small style="color:var(--dim)" dir="ltr">SN-104829</small></td>
            <td>سرور ابری ۴/۸</td>
            <td><span class="pnl-pill ok">پرداخت شد</span></td>
            <td class="num pnl-num">۲٬۴۹۰٬۰۰۰</td>
          </tr>
          <tr>
            <td dir="ltr">#۱۴۸۲۲</td>
            <td>شرکت آریا داده <small style="color:var(--dim)" dir="ltr">SN-104811</small></td>
            <td>دامنه aria.com</td>
            <td><span class="pnl-pill info">در حال تحویل</span></td>
            <td class="num pnl-num">۱٬۱۹۰٬۰۰۰</td>
          </tr>
          <tr>
            <td dir="ltr">#۱۴۸۲۱</td>
            <td>مریم کاظمی <small style="color:var(--dim)" dir="ltr">SN-104803</small></td>
            <td>هاست حرفه‌ای</td>
            <td><span class="pnl-pill warn">در انتظار پرداخت</span></td>
            <td class="num pnl-num">۱٬۲۹۰٬۰۰۰</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

{{-- ============ دو ستون ============ --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--pnl-gap)" class="pnl-two">
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>وضعیت نودها</h2><a class="pnl-more" href="#">مدیریت</a></div>
    <div class="pnl-sec-b flush">
      <div class="pnl-tw"><table class="pnl-table"><tbody>
        <tr><td>Proxmox تهران</td><td><span class="pnl-pill ok">سالم</span></td><td class="num" style="color:var(--muted)">۶۲٪ ظرفیت</td></tr>
        <tr><td>Hetzner آلمان</td><td><span class="pnl-pill ok">سالم</span></td><td class="num" style="color:var(--muted)">API متصل</td></tr>
        <tr><td>cPanel هاست ایران</td><td><span class="pnl-pill ok">سالم</span></td><td class="num" style="color:var(--muted)">۴۱٪ ظرفیت</td></tr>
      </tbody></table></div>
    </div>
  </section>

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>سرویس‌های رو به انقضا</h2><a class="pnl-more" href="#">همه</a></div>
    <div class="pnl-sec-b flush">
      <div class="pnl-tw"><table class="pnl-table"><tbody>
        <tr><td dir="ltr">mysite.ir</td><td><span class="pnl-pill warn">۳ روز</span></td><td class="num" style="color:var(--muted)">دامنه</td></tr>
        <tr><td>هاست — کاظمی</td><td><span class="pnl-pill warn">۵ روز</span></td><td class="num" style="color:var(--muted)">هاست</td></tr>
        <tr><td>سرور — آریا داده</td><td><span class="pnl-pill mute">۱۲ روز</span></td><td class="num" style="color:var(--muted)">VPS</td></tr>
      </tbody></table></div>
    </div>
  </section>
</div>

<style>
.adm-fx{display:flex;align-items:center;gap:26px;flex-wrap:wrap}
.adm-fx-main{display:flex;align-items:baseline;gap:8px}
.adm-fx-main small{font-size:13px;color:var(--muted)}
.adm-fx-main b{font-size:38px;font-family:var(--font-disp);line-height:1;letter-spacing:-1px}
.adm-fx-unit{font-size:14px;color:var(--muted)}
.adm-fx-meta{display:flex;flex-direction:column;gap:6px}
.adm-fx-meta small{font-size:11.5px;color:var(--dim)}
.adm-fx-act{margin-inline-start:auto}
@media(max-width:560px){.adm-fx-act{margin-inline-start:0}}
</style>

@endsection
