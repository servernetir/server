@extends('panel.layout')
@section('title', 'تیکت‌های من — سرورنت')

@section('panel')

<div class="pnl-head">
  <div><h1>تیکت‌های پشتیبانی</h1><p>درخواست‌ها و گفت‌وگوهای شما با تیم پشتیبانی</p></div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="#"><svg class="icon"><use href="#i-plus"/></svg>تیکت جدید</a>
  </div>
</div>

{{-- ============ لیست تیکت‌های من ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>تیکت‌های من</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw"><table class="pnl-table">
      <thead><tr><th>موضوع</th><th>دپارتمان</th><th>وضعیت</th><th>آخرین پاسخ</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td><b>راهنمای اتصال به هاست</b> <span dir="ltr" style="color:var(--dim);font-size:11px">#T-2838</span></td>
          <td>فنی</td>
          <td><span class="pnl-pill info">پاسخ داده شد</span></td>
          <td>۳ ساعت پیش</td>
          <td><a class="pnl-btn" href="#tk">مشاهده</a></td>
        </tr>
        <tr>
          <td><b>سؤال دربارهٔ تمدید دامنه</b> <span dir="ltr" style="color:var(--dim);font-size:11px">#T-2799</span></td>
          <td>مالی</td>
          <td><span class="pnl-pill mute">بسته شد</span></td>
          <td>۲ روز پیش</td>
          <td><a class="pnl-btn" href="#tk">مشاهده</a></td>
        </tr>
      </tbody>
    </table></div>
  </div>
</section>

{{-- ============ نمای گفت‌وگو ============ --}}
<section class="pnl-sec" id="tk">
  <div class="pnl-sec-h">
    <h2>راهنمای اتصال به هاست — <span dir="ltr" style="color:var(--dim);font-size:13px">#T-2838</span></h2>
    <span class="pnl-pill info">پاسخ داده شد</span>
  </div>
  <div class="pnl-sec-b">
    <div class="tk-thread">

      <div class="tk-msg">
        <div class="tk-msg-h"><span class="pnl-avatar" style="width:30px;height:30px;font-size:13px">ا</span>
          <b>شما</b><small>دیروز ۱۴:۲۰</small></div>
        <div class="tk-msg-b">سلام، هاست حرفه‌ای را خریدم ولی نمی‌دانم چطور فایل‌هایم را آپلود کنم. لطفاً راهنمایی کنید.</div>
      </div>

      <div class="tk-msg staff">
        <div class="tk-msg-h"><span class="pnl-avatar" style="width:30px;height:30px;font-size:13px;background:var(--info)">پ</span>
          <b>پشتیبانی سرورنت</b><small>دیروز ۱۴:۳۸</small></div>
        <div class="tk-msg-b">سلام و وقت بخیر. اطلاعات ورود به cPanel به ایمیل شما ارسال شد. برای آپلود می‌توانید از بخش File Manager در cPanel یا از طریق FTP استفاده کنید. اگر نیاز به راهنمای تصویری دارید، بفرمایید تا برایتان بفرستیم.</div>
      </div>

    </div>

    {{-- کادر پاسخ --}}
    <div class="tk-reply">
      <textarea class="wt-ta" rows="3" placeholder="پاسخ خود را بنویسید…" style="width:100%"></textarea>
      <div class="pnl-acts" style="margin-top:10px;justify-content:space-between">
        <button class="pnl-btn"><svg class="icon"><use href="#i-plus"/></svg>پیوست فایل</button>
        <div class="pnl-acts">
          <button class="pnl-btn">بستن تیکت</button>
          <button class="pnl-btn primary"><svg class="icon"><use href="#i-send"/></svg>ارسال پاسخ</button>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.tk-thread{display:flex;flex-direction:column;gap:16px;margin-bottom:20px}
.tk-msg{max-width:82%}
.tk-msg.staff{margin-inline-start:auto}
.tk-msg-h{display:flex;align-items:center;gap:9px;margin-bottom:7px}
.tk-msg-h b{font-size:13px}
.tk-msg-h small{font-size:11px;color:var(--dim);margin-inline-start:auto}
.tk-msg-b{background:var(--surface-2);border:1px solid var(--line);border-radius:14px;
  padding:12px 15px;font-size:13.5px;line-height:2}
.tk-msg.staff .tk-msg-b{background:var(--info-bg);border-color:var(--info-line)}
.tk-reply{border-top:1px solid var(--line);padding-top:16px}
</style>

@endsection
