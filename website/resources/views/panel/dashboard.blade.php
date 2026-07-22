@extends('panel.layout')
@section('title', 'پنل کاربری — سرورنت کلاود')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>سلام {{ $pnlUser['first'] }}</h1>
    <p>حساب شما فعال است و {{ fa_num(3) }} سرویس در حال اجرا دارید.</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="#"><svg class="icon"><use href="#i-plus"/></svg>سفارش سرویس جدید</a>
    <a class="pnl-btn" href="#"><svg class="icon"><use href="#i-coins"/></svg>افزایش اعتبار</a>
  </div>
</div>

{{-- ============ آمار ============ --}}
<div class="pnl-stats">
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-server"/></svg>سرویس فعال</div>
    <b>{{ fa_num(3) }}</b>
    <small>۲ سرور · ۱ هاست</small>
  </div>
  <div class="pnl-stat is-warn">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-coins"/></svg>فاکتور پرداخت‌نشده</div>
    <b>{{ fa_num(1) }}</b>
    <small>سررسید ۳ روز دیگر</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت باز</div>
    <b>{{ fa_num(0) }}</b>
    <small>میانگین پاسخ: ۱۸ دقیقه</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-db"/></svg>اعتبار حساب</div>
    <b class="pnl-money">{{ fa_num('۴۲۰٬۰۰۰') }}</b>
    <small>تومان</small>
  </div>
</div>

{{-- ============ توجه لازم ============
     مهم‌ترین بلوک پنل. اگر کاربر این را نبیند، سرویسش قطع می‌شود.
     برای همین بالای همه‌چیز و با رنگ هشدار است. ============ --}}
<section class="pnl-sec pnl-alert">
  <div class="pnl-sec-h">
    <h2>نیاز به رسیدگی</h2>
  </div>
  <ul class="pnl-todo">
    <li>
      <span class="pnl-todo-ic w"><svg class="icon"><use href="#i-coins"/></svg></span>
      <span class="pnl-todo-t">
        <b>فاکتور #{{ fa_num('۱۴۰۴۰۸۸۲') }} — تمدید هاست حرفه‌ای</b>
        <small>مبلغ ۱٬۲۹۰٬۰۰۰ تومان · سررسید ۳ مرداد ۱۴۰۴</small>
      </span>
      <a class="pnl-btn primary" href="#">پرداخت</a>
    </li>
    <li>
      <span class="pnl-todo-ic w"><svg class="icon"><use href="#i-globe"/></svg></span>
      <span class="pnl-todo-t">
        <b>دامنه mysite.ir تا ۱۹ روز دیگر منقضی می‌شود</b>
        <small>تمدید خودکار خاموش است</small>
      </span>
      <a class="pnl-btn" href="#">تمدید</a>
    </li>
  </ul>
</section>

{{-- ============ سرویس‌ها ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>سرویس‌های من</h2>
    <a class="pnl-more" href="#">مشاهدهٔ همه</a>
  </div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr>
            <th>سرویس</th><th>وضعیت</th><th>لوکیشن</th>
            <th>تمدید بعدی</th><th class="num">مبلغ</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <div class="pnl-svc">
                <span class="pnl-svc-ic"><svg class="icon"><use href="#i-server"/></svg></span>
                <span class="pnl-svc-t"><b>سرور ابری — ۴ هسته / ۸ گیگ</b><small>۱۸۵.۲۳۱.۱۱۵.۴۲</small></span>
              </div>
            </td>
            <td><span class="pnl-pill ok">فعال</span></td>
            <td>تهران</td>
            <td>۱۲ شهریور ۱۴۰۴</td>
            <td class="num pnl-money">۲٬۴۹۰٬۰۰۰</td>
            <td><a class="pnl-btn" href="#">مدیریت</a></td>
          </tr>
          <tr>
            <td>
              <div class="pnl-svc">
                <span class="pnl-svc-ic"><svg class="icon"><use href="#i-server"/></svg></span>
                <span class="pnl-svc-t"><b>سرور ابری — ۲ هسته / ۴ گیگ</b><small>۹۵.۲۱۶.۴۴.۱۹۸</small></span>
              </div>
            </td>
            <td><span class="pnl-pill ok">فعال</span></td>
            <td>آلمان — فالکنشتاین</td>
            <td>۲۸ مرداد ۱۴۰۴</td>
            <td class="num pnl-money">۱٬۱۵۰٬۰۰۰</td>
            <td><a class="pnl-btn" href="#">مدیریت</a></td>
          </tr>
          <tr>
            <td>
              <div class="pnl-svc">
                <span class="pnl-svc-ic"><svg class="icon"><use href="#i-hdd"/></svg></span>
                <span class="pnl-svc-t"><b>هاست حرفه‌ای — ۱۰ گیگ</b><small>mysite.ir</small></span>
              </div>
            </td>
            <td><span class="pnl-pill warn">در انتظار پرداخت</span></td>
            <td>ایران</td>
            <td>۳ مرداد ۱۴۰۴</td>
            <td class="num pnl-money">۱٬۲۹۰٬۰۰۰</td>
            <td><a class="pnl-btn" href="#">مدیریت</a></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

{{-- ============ دو ستون ============ --}}
<div class="pnl-two" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--pnl-gap)">

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>دامنه‌ها</h2><a class="pnl-more" href="#">مدیریت</a></div>
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <tbody>
            <tr>
              <td><b dir="ltr">mysite.ir</b></td>
              <td><span class="pnl-pill warn">۱۹ روز</span></td>
              <td class="num"><a class="pnl-btn" href="#">تمدید</a></td>
            </tr>
            <tr>
              <td><b dir="ltr">myshop.com</b></td>
              <td><span class="pnl-pill ok">۲۴۱ روز</span></td>
              <td class="num"><a class="pnl-btn" href="#">تنظیم DNS</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>تیکت‌های اخیر</h2><a class="pnl-more" href="#">تیکت جدید</a></div>
    <div class="pnl-empty">
      <svg class="icon"><use href="#i-lifebuoy"/></svg>
      <b>تیکت بازی ندارید</b>
      <p>اگر سؤالی دارید یا مشکلی پیش آمده، تیم پشتیبانی شبانه‌روزی در دسترس است.</p>
      <a class="pnl-btn primary" href="#">ثبت تیکت</a>
    </div>
  </section>

</div>

@endsection
