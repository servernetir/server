<div class="mk-note info" style="margin-top:16px">
  <svg class="icon"><use href="#i-info"/></svg>
  <div>فقط <b>نام و نشانیِ سایت</b> لازم است. نشانیِ ایمیل را خودِ سیستم از روی سایتشان برمی‌دارد — نشانیِ حدسی بانس می‌خورد و بانس شهرتِ فرستنده را می‌سوزاند.</div>
</div>

<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>افزودنِ یک سرنخ</h2></div>
  <form method="post" action="/admin/marketing" class="mk-form" style="padding:16px 18px">
    @csrf
    <div><label>نامِ کسب‌وکار</label><input name="company" required placeholder="Jumeirah Dental Studio"></div>
    <div><label>نشانیِ سایت</label><input name="website" dir="ltr" required placeholder="https://example.ae"></div>
    <div><label>ایمیل (اختیاری)</label><input name="email" dir="ltr" placeholder="خالی بگذار تا خودش پیدا کند"></div>
    <div><label>شهر</label><input name="city" placeholder="Dubai"></div>
    <div><label>کشور</label><input name="country" dir="ltr" maxlength="2" placeholder="AE"></div>
    <div><label>حوزه</label><input name="vertical" placeholder="dental"></div>
    <div style="align-self:end"><button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>افزودن</button></div>
  </form>
</div>

<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>دسته‌ای از فایل</h2></div>
  <div style="padding:16px 18px">
    <p style="color:var(--muted);font-size:13.5px;line-height:1.95">
      یک CSV با ستون‌های <code dir="ltr">company,website,email,city,country,vertical</code> بساز
      (فقط دو ستونِ اول اجباری‌اند) و این را اجرا کن:
    </p>
    <pre class="mk-ltr" style="margin-top:12px;padding:14px 16px;border-radius:11px">php artisan crm:import leads.csv --dry
php artisan crm:import leads.csv</pre>
    <div class="mk-note" style="margin-top:12px">
      <svg class="icon"><use href="#i-info"/></svg>
      <div>اجرای اول فقط گزارش می‌دهد و چیزی ثبت نمی‌کند. هیچ ردیفی هم بی‌صدا رد نمی‌شود — تکراری، ناقص و فهرستِ سیاه همه با دلیل چاپ می‌شوند.</div>
    </div>
  </div>
</div>
