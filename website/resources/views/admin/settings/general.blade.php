{{-- تبِ عمومی: هویتِ شرکت روی اسناد + اعتبارنامهٔ گوگل‌کلندر --}}

{{--
  فرمِ «قطعِ اتصالِ تقویمِ گوگل» — عمداً **بیرونِ** فرمِ اصلی و خالی.

  دکمه‌اش پایین‌تر با `form="gcal-disconnect"` به آن وصل می‌شود. این تنها راهِ
  درستِ داشتنِ دو کنشِ مستقل روی یک صفحه است؛ `<form>`ِ تودرتو در HTML مجاز
  نیست و مرورگر بی‌هیچ خطایی فرمِ بیرونی را زودتر می‌بندد — یک بار همین باعث
  شد دکمهٔ «ذخیره» به هیچ فرمی وصل نباشد و **هیچ تنظیمی ذخیره نشود**.

  ⚠️ کنشِ مستقلِ تازه لازم شد؟ **همین‌جا** فرمِ خالی‌اش را بگذار و دکمه را با
  `form=` وصل کن — نه یک `<form>` وسطِ فرمِ اصلی.
--}}
<form id="gcal-disconnect" method="post" action="/admin/calendar/google/disconnect"
      data-confirm="اتصالِ تقویمِ گوگلِ شما قطع شود؟" data-confirm-danger>@csrf</form>

<form method="post" action="/admin/settings" enctype="multipart/form-data">
  @csrf
  <input type="hidden" name="tab" value="general">

  <div class="ad-panel">
    <div class="ad-panel-h"><h2>مهر شرکت</h2></div>
    <p class="set-lead">روی فاکتورهای پرداخت‌شده چاپ می‌شود. فایل بیرونِ ریشهٔ سایت ذخیره می‌شود و لینکِ عمومی ندارد.</p>

    <div style="padding:0 18px 18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div class="set-stamp">
        @if($stampData)<img src="{{ $stampData }}" alt="مهر شرکت">
        @else<span>بدون مهر</span>@endif
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <input type="file" name="stamp" accept="image/png,image/jpeg" style="font:inherit;font-size:12.5px;color:var(--muted)">
        <small style="color:var(--dim);font-size:12px">PNG با پس‌زمینهٔ شفاف بهتر است — تا ۲ مگابایت.</small>
        @if($stampData)
          <label class="set-danger"><input type="checkbox" name="remove_stamp" value="1"> حذف مهر فعلی</label>
        @endif
      </div>
    </div>
  </div>

  {{-- ── نماد اعتماد الکترونیکی ──────────────────────────────────────
       کنارِ مهرِ شرکت، چون هر دو «هویتِ رسمیِ شرکت»اند.

       🔴 هر دو مقدار لازم است. با یکی، آدرسِ تأیید به صفحهٔ نامعتبرِ نماد
       می‌رود — و خریدارِ ایرانی این مهر را **کلیک می‌کند**. مهرِ بی‌اعتبار
       کلِ سایت را مشکوک می‌کند، یعنی برعکسِ کاری که برایش گذاشته شده. برای
       همین `trust_seals()` مهرِ نیمه‌ساخته را اصلاً نمی‌سازد. --}}
  <div class="ad-panel" style="margin-top:18px">
    <div class="ad-panel-h"><h2>نماد اعتماد الکترونیکی</h2></div>
    <p class="set-lead">
      بعد از پر کردن، مهر در فوترِ همهٔ صفحات نشان داده می‌شود و به صفحهٔ
      استعلامِ خودِ نماد لینک می‌شود. هر دو مقدار را از پنلِ enamad.ir بردارید —
      در نشانیِ استعلام به‌شکل <code dir="ltr">?id=…&amp;code=…</code> هستند.
    </p>

    <div class="set-grid" style="padding:0 18px 18px">
      <label>
        <span>شناسه (id)</span>
        <input type="text" name="enamad_id" dir="ltr" autocomplete="off"
               value="{{ $enamad['id'] ?? '' }}" placeholder="709775">
      </label>
      <label>
        <span>کد (code)</span>
        <input type="text" name="enamad_code" dir="ltr" autocomplete="off"
               value="{{ $enamad['code'] ?? '' }}" placeholder="xNeZbKo7…">
      </label>
    </div>

    @php($seals = trust_seals())
    @if($seals)
      <div style="padding:0 18px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        @foreach($seals as $sl)
          <a href="{{ $sl['href'] }}" target="_blank" rel="noopener noreferrer" referrerpolicy="origin"
             style="background:#fff;border-radius:10px;padding:6px;line-height:0">
            <img src="{{ $sl['src'] }}" alt="{{ $sl['alt'] }}" width="90" height="90" referrerpolicy="origin">
          </a>
        @endforeach
        <small style="color:var(--dim);font-size:12px;max-width:420px;line-height:2">
          ⚠️ اگر این تصویر این‌جا بارگذاری نشد نگران نباشید: enamad.ir به
          آی‌پیِ غیرِایرانی سرویس نمی‌دهد و سرورِ ما در آلمان است. برای
          بازدیدکنندهٔ ایرانی درست نمایش داده می‌شود. لینکِ بالا را از داخلِ
          ایران باز کنید تا مطمئن شوید.
        </small>
      </div>
    @endif
  </div>

  {{-- ── گوگل‌کلندر ──────────────────────────────────────────────────
       اعتبارنامهٔ **اپ** این‌جاست، نه حسابِ شخصیِ کسی: یک OAuth client برای کلِ
       نصب. اتصالِ حسابِ گوگلِ هر کاربرِ پنل جداگانه است و توکنش per-user ذخیره
       می‌شود.

       ⚠️ Client ID عمومی است (در URLِ ورود دیده می‌شود) پس رمزنگاری ندارد؛
       Secret با `putSecret()` رمزنگاری می‌شود و هرگز به فرم برنمی‌گردد. --}}
  <div class="ad-panel">
    <div class="ad-panel-h">
      <h2>گوگل‌کلندر — همگام‌سازی تقویم</h2>
      @if($google['ready'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">اعتبارنامه ذخیره‌شده</span>@endif
    </div>
    <p class="set-lead">
      در <span dir="ltr">Google Cloud Console → APIs &amp; Services → Credentials</span>
      یک <b>OAuth client ID</b> از نوع <span dir="ltr">Web application</span> بسازید و
      این آدرسِ بازگشت را دقیقاً اضافه کنید:
      <br><code dir="ltr" style="user-select:all;color:var(--text)">{{ url('/admin/calendar/google/callback') }}</code>
      <br>⚠️ اگر وضعیتِ انتشارِ اپ روی <b>Testing</b> بماند، توکنِ گوگل حدود
      <b>۷ روزه</b> منقضی می‌شود و هر هفته باید دوباره وصل کنید. برای پایدارماندن،
      آن را روی <span dir="ltr">In production</span> بگذارید.
    </p>

    <div class="set-grid" style="padding:0 18px 16px">
      <label class="set-f">Client ID
        <input type="text" name="google_client_id" dir="ltr" maxlength="200"
               value="{{ $google['client_id'] }}" placeholder="…apps.googleusercontent.com"></label>
      <label class="set-f">Client Secret
        <input type="password" name="google_client_secret" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $google['ready'] ? '••••••••  خالی = بدونِ تغییر' : 'GOCSPX-…' }}"></label>
    </div>

    @if($google['ready'])
      <div style="padding:0 18px 16px">
        <label class="set-danger">
          <input type="checkbox" name="google_forget" value="1">
          اعتبارنامهٔ گوگل را فراموش کن (اتصالِ همهٔ کاربران قطع می‌شود)
        </label>
      </div>

      {{-- ══ اتصالِ حسابِ **خودِ این کاربر** ══
           بالا اعتبارنامهٔ اپ است (یکی برای کلِ نصب)؛ این پایین حسابِ شخصیِ
           همین کاربر. جدا نگه‌داشتنشان عمدی است: مدیرِ دیگری که وارد شود، همان
           اپ را دارد ولی حسابِ خودش را وصل می‌کند. --}}
      <div style="padding:0 18px 18px;border-top:1px solid var(--line);margin:0 18px;padding-top:14px">
        @if($google['connected'])
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:12.5px">
            <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">حسابِ شما وصل است</span>
            @if($google['email'])<span dir="ltr" style="color:var(--muted);unicode-bidi:isolate">{{ $google['email'] }}</span>@endif
            @if($google['synced_at'])<span style="color:var(--dim)">· آخرین همگام‌سازی {{ $google['synced_at'] }}</span>@endif
            <span style="margin-inline-start:auto;display:flex;gap:7px">
              <a class="btn btn-glass" href="/admin/calendar/google/connect">اتصال دوباره</a>
              {{-- 🔴 با `form=` به فرمِ بیرونیِ بالای صفحه وصل است، نه یک
                   `<form>`ِ تودرتو. دلیلش در همان کامنت بالا نوشته شده. --}}
              <button type="submit" form="gcal-disconnect" class="btn" style="background:#ff6b6b;color:var(--bg)">قطع اتصال</button>
            </span>
          </div>
          @if($google['last_error'])
            <p style="margin:9px 0 0;color:#ff6b6b;font-size:12.5px;line-height:1.9">⚠️ {{ $google['last_error'] }}</p>
          @endif
        @else
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:12.5px">
            <span style="color:var(--muted)">
              حسابِ گوگلِ شما وصل نیست. بعد از اتصال، رویدادهای شخصی‌تان در تقویمِ
              کسب‌وکار کنارِ سررسیدهای کاری دیده می‌شوند — <b style="color:var(--text)">فقط خودتان</b>.
            </span>
            <a class="btn btn-primary" style="margin-inline-start:auto" href="/admin/calendar/google/connect">اتصال به گوگل</a>
          </div>
        @endif
      </div>
    @endif
  </div>

  @include('admin.settings._save')
</form>
