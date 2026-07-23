@extends('panel.layout')
@section('title', 'تیکت‌ها — پنل مدیریت')

@section('panel')

<div class="pnl-head">
  <div><h1>تیکت‌ها</h1><p>گزارش و مدیریت درخواست‌های پشتیبانی</p></div>
  <div class="pnl-acts">
    <a class="pnl-btn" href="#"><svg class="icon"><use href="#i-restore"/></svg>گزارش کامل</a>
  </div>
</div>

{{-- ============ خلاصهٔ تیکت‌ها ============ --}}
<div class="pnl-stats">
  <div class="pnl-stat is-danger">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-lifebuoy"/></svg>باز</div>
    <b class="pnl-num">۳</b><small>۱ خارج از SLA</small>
  </div>
  <div class="pnl-stat is-warn">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-clock"/></svg>در انتظار پاسخ ما</div>
    <b class="pnl-num">۲</b><small>میانگین انتظار: ۴۱ دقیقه</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-user"/></svg>در انتظار مشتری</div>
    <b class="pnl-num">۱</b><small>پاسخ داده شده</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-check"/></svg>بستهٔ امروز</div>
    <b class="pnl-num">۷</b><small>میانگین حل: ۲٫۳ ساعت</small>
  </div>
</div>

{{-- ============ توزیع دپارتمان ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>خلاصه بر اساس دپارتمان</h2><span style="font-size:12px;color:var(--dim)">۷ روز اخیر</span></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw"><table class="pnl-table">
      <thead><tr><th>دپارتمان</th><th>باز</th><th>در انتظار SLA</th><th>میانگین پاسخ اول</th><th class="num">رضایت</th></tr></thead>
      <tbody>
        <tr><td>فنی</td><td>۲</td><td><span class="pnl-pill warn">۱</span></td><td>۱۸ دقیقه</td><td class="num">۹۴٪</td></tr>
        <tr><td>مالی</td><td>۱</td><td><span class="pnl-pill ok">۰</span></td><td>۳۲ دقیقه</td><td class="num">۹۱٪</td></tr>
        <tr><td>فروش</td><td>۰</td><td><span class="pnl-pill ok">۰</span></td><td>۱۲ دقیقه</td><td class="num">۹۷٪</td></tr>
      </tbody>
    </table></div>
  </div>
</section>

{{-- ============ لیست تیکت‌ها ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>تیکت‌های فعال</h2><a class="pnl-more" href="#">همه</a></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw"><table class="pnl-table">
      <thead><tr><th>موضوع</th><th>مشتری</th><th>دپارتمان</th><th>وضعیت</th><th>آخرین فعالیت</th></tr></thead>
      <tbody>
        <tr>
          <td><b>سرور به کنسول پاسخ نمی‌دهد</b> <span dir="ltr" style="color:var(--dim);font-size:11px">#T-2841</span></td>
          <td>آریا داده</td><td>فنی</td>
          <td><span class="pnl-pill danger">خارج از SLA</span></td>
          <td>۱ ساعت پیش</td>
        </tr>
        <tr>
          <td><b>فاکتور را دو بار پرداخت کردم</b> <span dir="ltr" style="color:var(--dim);font-size:11px">#T-2840</span></td>
          <td>مریم کاظمی</td><td>مالی</td>
          <td><span class="pnl-pill warn">در انتظار ما</span></td>
          <td>۲۵ دقیقه پیش</td>
        </tr>
        <tr>
          <td><b>راهنمای اتصال به هاست</b> <span dir="ltr" style="color:var(--dim);font-size:11px">#T-2838</span></td>
          <td>احسان ابراهیمی</td><td>فنی</td>
          <td><span class="pnl-pill info">در انتظار مشتری</span></td>
          <td>۳ ساعت پیش</td>
        </tr>
      </tbody>
    </table></div>
  </div>
</section>

@endsection
