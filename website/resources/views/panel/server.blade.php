@extends('panel.layout')
@section('title', 'مدیریت سرور — سرورنت کلاود')

@section('panel')

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ url()->current() }}">پنل</a><span>/</span>
      <a href="{{ url()->current() }}">سرویس‌ها</a><span>/</span>
      <span>سرور ابری</span>
    </nav>
    <h1>سرور ابری — ۴ هسته / ۸ گیگ</h1>
    <p><span dir="ltr">185.231.115.42</span> · تهران · اوبونتو ۲۴.۰۴</p>
  </div>
  <span class="pnl-pill ok" style="font-size:12.5px;padding:7px 15px">روشن</span>
</div>

{{-- ============ کنترل سرور ============
     الگوی مهم: عمل برگشت‌ناپذیر (بازنصب) هرگز کنار عمل عادی نیست و
     همیشه تأیید تایپی می‌خواهد. ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>کنترل سرور</h2>
    <span style="font-size:12px;color:var(--dim)">آخرین بررسی: چند لحظه پیش</span>
  </div>
  <div class="pnl-sec-b">
    <div class="pnl-acts">
      <button class="pnl-btn" data-act="reboot"><svg class="icon"><use href="#i-restore"/></svg>راه‌اندازی مجدد</button>
      <button class="pnl-btn danger" data-act="shutdown"><svg class="icon"><use href="#i-zap"/></svg>خاموش کردن</button>
      <button class="pnl-btn" data-act="console"><svg class="icon"><use href="#i-monitor"/></svg>کنسول VNC</button>
      <button class="pnl-btn" data-act="password"><svg class="icon"><use href="#i-key"/></svg>بازنشانی رمز root</button>
      <button class="pnl-btn danger" data-act="rebuild"><svg class="icon"><use href="#i-restore"/></svg>نصب مجدد سیستم‌عامل</button>
    </div>
    <p style="margin-top:14px;font-size:12.5px;color:var(--dim);line-height:1.9">
      عملیات ممکن است تا یک دقیقه طول بکشد. وضعیت به‌صورت زنده به‌روز می‌شود.
    </p>
  </div>
</section>

{{-- ============ منابع ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>مصرف منابع</h2><span style="font-size:12px;color:var(--dim)">۲۴ ساعت گذشته</span></div>
  <div class="pnl-sec-b">
    <div class="pnl-res">
      <div class="pnl-res-i">
        <small>پردازنده</small><b>۲۳٪</b>
        <div class="pnl-bar"><span style="width:23%"></span></div>
      </div>
      <div class="pnl-res-i">
        <small>حافظه</small><b>۵٫۱ / ۸ گیگ</b>
        <div class="pnl-bar"><span style="width:64%"></span></div>
      </div>
      <div class="pnl-res-i">
        <small>دیسک</small><b>۷۴ / ۸۰ گیگ</b>
        <div class="pnl-bar hot"><span style="width:92%"></span></div>
      </div>
    </div>
    <p style="margin-top:14px;font-size:12.5px;color:var(--warn);line-height:1.9">
      فضای دیسک رو به اتمام است. می‌توانید پلن را ارتقا دهید یا فضای اضافه بخرید.
    </p>
  </div>
</section>

{{-- ============ اطلاعات ============ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>مشخصات</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <tbody>
          <tr><td style="color:var(--muted)">آی‌پی اصلی</td><td><b dir="ltr">185.231.115.42</b></td></tr>
          <tr><td style="color:var(--muted)">سیستم‌عامل</td><td>Ubuntu 24.04 LTS</td></tr>
          <tr><td style="color:var(--muted)">منابع</td><td>۴ هسته · ۸ گیگ رم · ۸۰ گیگ NVMe</td></tr>
          <tr><td style="color:var(--muted)">پهنای باند</td><td>۴٫۲ / ۱۰ ترابایت این ماه</td></tr>
          <tr><td style="color:var(--muted)">تاریخ تحویل</td><td>۱۲ خرداد ۱۴۰۴</td></tr>
          <tr><td style="color:var(--muted)">تمدید بعدی</td><td>۱۲ شهریور ۱۴۰۴ — <span class="pnl-money">۲٬۴۹۰٬۰۰۰</span> تومان</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

{{-- ============ گفت‌وگوی تأیید ============ --}}
<div id="pnl-confirm" hidden style="position:fixed;inset:0;z-index:400;display:grid;place-items:center;
     background:rgba(0,0,0,.6);backdrop-filter:blur(3px);padding:20px">
  <div class="pnl-card" style="max-width:440px;width:100%;border-color:var(--danger-line)">
    <h3 id="pc-title" style="font-size:17px;font-family:var(--font-disp);margin-bottom:10px"></h3>
    <p id="pc-body" style="font-size:13.5px;color:var(--muted);line-height:2;margin-bottom:16px"></p>
    <div id="pc-typewrap" hidden style="margin-bottom:16px">
      <label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:7px">
        برای تأیید، عبارت <b id="pc-word" dir="ltr" style="color:var(--danger)"></b> را تایپ کنید
      </label>
      <input id="pc-type" dir="ltr" autocomplete="off" style="width:100%;background:var(--surface-2);
        border:1px solid var(--line-2);border-radius:10px;color:var(--text);
        font-family:var(--font-body);font-size:13.5px;padding:10px 13px;outline:none">
    </div>
    <div class="pnl-acts" style="justify-content:flex-end">
      <button class="pnl-btn" id="pc-cancel">انصراف</button>
      <button class="pnl-btn danger" id="pc-ok">تأیید</button>
    </div>
  </div>
</div>

<script>
(function () {
  /* هر عمل، سطح خطر خودش را دارد. فقط عمل برگشت‌ناپذیر تأیید تایپی می‌خواهد —
     اگر همه‌چیز تأیید تایپی بخواهد، کاربر یاد می‌گیرد بی‌فکر تایپ کند. */
  var ACTS = {
    reboot:   { t:'راه‌اندازی مجدد سرور؟', b:'سرور حدود یک دقیقه در دسترس نخواهد بود. سرویس‌های در حال اجرا قطع می‌شوند.', type:null },
    shutdown: { t:'خاموش کردن سرور؟',      b:'سرور تا زمانی که دستی روشنش کنید خاموش می‌ماند. سایت شما در این مدت بالا نمی‌آید.', type:null },
    console:  { t:null },
    password: { t:'بازنشانی رمز root؟',    b:'رمز فعلی از کار می‌افتد و رمز تازه به ایمیل شما ارسال می‌شود.', type:null },
    rebuild:  { t:'نصب مجدد سیستم‌عامل؟',  b:'تمام اطلاعات روی این سرور برای همیشه پاک می‌شود. این کار برگشت‌پذیر نیست و پشتیبانی هم نمی‌تواند بازگرداندش.', type:'REBUILD' }
  };
  var box=document.getElementById('pnl-confirm'), pending=null;
  var elT=document.getElementById('pc-title'), elB=document.getElementById('pc-body');
  var wrap=document.getElementById('pc-typewrap'), word=document.getElementById('pc-word');
  var inp=document.getElementById('pc-type'), ok=document.getElementById('pc-ok');

  function close(){ box.hidden=true; pending=null; inp.value=''; }
  function sync(){ ok.disabled = pending && ACTS[pending].type ? inp.value.trim()!==ACTS[pending].type : false;
                   ok.style.opacity = ok.disabled ? .45 : 1; }

  document.querySelectorAll('[data-act]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var a=ACTS[btn.dataset.act];
      if(!a || !a.t) return;              // کنسول تأیید نمی‌خواهد
      pending=btn.dataset.act;
      elT.textContent=a.t; elB.textContent=a.b;
      if(a.type){ wrap.hidden=false; word.textContent=a.type; } else wrap.hidden=true;
      box.hidden=false; sync();
      if(a.type) inp.focus();
    });
  });
  inp.addEventListener('input', sync);
  document.getElementById('pc-cancel').onclick=close;
  box.addEventListener('click', function(e){ if(e.target===box) close(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !box.hidden) close(); });
  ok.onclick=function(){ if(!ok.disabled) close(); };   // نمونهٔ طراحی — عمل واقعی بعداً
})();
</script>

@endsection
