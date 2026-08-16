@extends('layouts.site')

@section('title', 'مستندات API نمایندگی دامنه — ServerNet')
@section('description', 'مستندات API سرورنت برای نمایندگان دامنه: ساخت توکن، استعلام قیمت، ثبت، تمدید و مدیریت دامنه، به‌همراه افزونهٔ WHMCS.')

@section('content')

{{--
  مستندات API نمایندگی دامنه.

  ⚠️ این صفحه در هر سه زبان همین متنِ فارسی را نشان می‌دهد. عمدی است: مخاطبش
  نمایندهٔ ایرانی است و ترجمهٔ ناقصِ یک سندِ فنی از نبودش بدتر است (کسی که
  انگلیسی می‌خواند و متنِ نصفه می‌گیرد، اشتباه پیاده‌سازی می‌کند). اگر روزی
  نمایندهٔ خارجی داشتیم، نسخهٔ انگلیسی باید **کامل** نوشته شود نه ماشینی.

  🔴 هر عددِ محدودیت از config خوانده می‌شود، نه تایپ‌شده. مستنداتی که عددش
  دستی نوشته شود، اولین باری که تنظیمات عوض شود دروغ می‌گوید — و کسی که بر
  اساسش کد نوشته، خرابی‌اش را ماه‌ها بعد کشف می‌کند.
--}}

@php
  $rate = (array) config('domain_reseller.limits.rate', []);
  $maxYears = (int) config('domain_reseller.limits.max_years', 10);
  $panelOnly = (array) config('domain_reseller.panel_only_operations', []);
  $abilities = \App\Models\CustomerApiToken::ABILITIES;
  $base = url('/api/v1');
@endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>API نسخهٔ ۱</span></span>
      <h1 class="reveal" style="transition-delay:.08s">مستندات API نمایندگی دامنه</h1>
      <p class="lead reveal" style="transition-delay:.16s">
        دامنه را از سامانهٔ خودتان ثبت، تمدید و مدیریت کنید — با قیمت سطح نمایندگی‌تان.
        برای WHMCS افزونهٔ آماده داریم؛ برای بقیه، همین API.
      </p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container dev-doc">

    {{-- ══════════ شروع ══════════ --}}
    <h2 id="start">۱. شروع</h2>
    <ol class="dev-steps">
      <li>حساب کاربری‌تان باید به‌عنوان <b>نمایندهٔ دامنه</b> فعال شده باشد (از پشتیبانی بخواهید).</li>
      <li>در <a href="{{ url('/account/security') }}#sec-api">پنل کاربری → امنیت → توکن API</a> توکن بسازید.</li>
      <li>دسترسی‌های لازم را تیک بزنید و <b>IP سرور خودتان</b> را در فهرست مجاز بگذارید.</li>
      <li>حساب را شارژ کنید — ثبت و تمدید <b>از اعتبار</b> کسر می‌شود.</li>
    </ol>

    <div class="dev-note dev-warn">
      <b>توکن فقط یک بار نشان داده می‌شود.</b> ما فقط هشِ آن را نگه می‌داریم و
      بازیابی‌اش ممکن نیست. اگر گمش کردید، توکن تازه بسازید و قبلی را باطل کنید.
    </div>

    {{-- ══════════ احراز هویت ══════════ --}}
    <h2 id="auth">۲. احراز هویت</h2>
    <p>هر درخواست باید هدر <code dir="ltr">Authorization</code> داشته باشد:</p>

<pre dir="ltr" class="dev-code">curl -H "Authorization: Bearer sn_xxxxxxxx" \
     {{ $base }}/ping</pre>

    <h3>دسترسی‌ها</h3>
    <table class="dev-table">
      <thead><tr><th>دسترسی</th><th>کاری که اجازه می‌دهد</th></tr></thead>
      <tbody>
        @foreach($abilities as $key => $desc)
          <tr><td><code dir="ltr">{{ $key }}</code></td><td>{!! e($desc) !!}</td></tr>
        @endforeach
      </tbody>
    </table>

    <p class="dev-sub">
      <code dir="ltr">domains:write</code> خودبه‌خود شامل <code dir="ltr">domains:read</code> است.
      عکسش هرگز — توکن خواندنی نمی‌تواند بنویسد.
    </p>

    {{-- ══════════ شکل پاسخ ══════════ --}}
    <h2 id="shape">۳. شکل پاسخ</h2>
    <p>همهٔ پاسخ‌ها یک شکل دارند. روی <code dir="ltr">error</code> شرط بگذارید، نه روی متن پیام:</p>

<pre dir="ltr" class="dev-code">// موفق
{"ok": true, "data": { ... }}

// ناموفق
{"ok": false, "error": "insufficient_credit", "message": "اعتبارِ حساب کافی نیست."}</pre>

    <div class="dev-note">
      <b>به کد HTTP تنها تکیه نکنید.</b> همیشه فیلد <code dir="ltr">ok</code> را بسنجید.
      این قاعده‌ای است که ما خودمان با هزینه یاد گرفته‌ایم: چند سرویس بالادستی
      روی خطا هم کد ۲۰۰ برمی‌گردانند.
    </div>

    {{-- ══════════ نقاط پایانی ══════════ --}}
    <h2 id="endpoints">۴. نقاط پایانی</h2>

    <table class="dev-table">
      <thead><tr><th>متد و مسیر</th><th>دسترسی</th><th>کار</th></tr></thead>
      <tbody>
        <tr><td><code dir="ltr">GET /api/v1/ping</code></td><td><code dir="ltr">read</code></td><td>آزمون اتصال، سطح، اعتبار</td></tr>
        <tr><td><code dir="ltr">GET /api/v1/tlds</code></td><td><code dir="ltr">domains:read</code></td><td>قیمت پسوندها (ثبت/تمدید/انتقال)</td></tr>
        <tr><td><code dir="ltr">POST /api/v1/domains/check</code></td><td><code dir="ltr">domains:read</code></td><td>استعلام موجودی و قیمت</td></tr>
        <tr><td><code dir="ltr">GET /api/v1/domains</code></td><td><code dir="ltr">domains:read</code></td><td>فهرست دامنه‌های شما</td></tr>
        <tr><td><code dir="ltr">GET /api/v1/domains/{domain}</code></td><td><code dir="ltr">domains:read</code></td><td>جزئیات، انقضا، وضعیت</td></tr>
        <tr><td><code dir="ltr">POST /api/v1/domains</code></td><td><code dir="ltr">domains:write</code></td><td><b>ثبت</b> — از اعتبار کسر می‌شود</td></tr>
        <tr><td><code dir="ltr">POST /api/v1/domains/{domain}/renew</code></td><td><code dir="ltr">domains:write</code></td><td><b>تمدید</b> — از اعتبار کسر می‌شود</td></tr>
        <tr><td><code dir="ltr">PUT /api/v1/domains/{domain}/nameservers</code></td><td><code dir="ltr">domains:manage</code></td><td>تغییر نام‌سرور</td></tr>
        <tr><td><code dir="ltr">POST /api/v1/domains/{domain}/lock</code></td><td><code dir="ltr">domains:manage</code></td><td>روشن کردن قفل انتقال</td></tr>
        <tr><td><code dir="ltr">POST /api/v1/domains/{domain}/auto-renew</code></td><td><code dir="ltr">domains:manage</code></td><td>تمدید خودکار</td></tr>
      </tbody>
    </table>

    <h3 id="check">استعلام</h3>
<pre dir="ltr" class="dev-code">POST {{ $base }}/domains/check
{"domain": "example.com", "tlds": ["com", "net", "ir"]}

{"ok": true, "data": [{
  "domain": "example.com",
  "tld": "com",
  "state": "free",
  "available": true,
  "currency": "IRT",
  "price": {"register": 1150000, "renew": 1250000, "retail": 1320000},
  "discount_pct": 12.88,
  "price_floored": false
}]}</pre>

    <div class="dev-note dev-warn">
      <b>روی <code dir="ltr">state</code> تصمیم بگیرید، نه روی <code dir="ltr">available</code>.</b>
      مقادیر ممکن:
      <code dir="ltr">free</code> (آزاد) ·
      <code dir="ltr">premium</code> (آزاد ولی پرمیوم) ·
      <code dir="ltr">taken</code> (ثبت‌شده) ·
      <code dir="ltr">unchecked</code> (<b>نتوانستیم استعلام کنیم</b>) ·
      <code dir="ltr">unsupported</code> (این پسوند را نمی‌فروشیم) ·
      <code dir="ltr">no_price</code> (آزاد ولی قیمت قابل اتکا نداریم).
      <br>
      <code dir="ltr">unchecked</code> را «ثبت‌شده» نخوانید — این دقیقاً همان اشتباهی است
      که یک بار به کاربران ما گفت اسم دلخواهشان گرفته شده در حالی که آزاد بود.
    </div>

    <p class="dev-sub">
      <code dir="ltr">price_floored: true</code> یعنی تخفیف سطح شما روی این پسوند
      کامل اعمال نشده، چون قیمت به کف حاشیهٔ ما رسیده. پنهانش نمی‌کنیم —
      <a href="#floor">بخش ۷</a> را ببینید.
    </p>

    <h3 id="register">ثبت</h3>
<pre dir="ltr" class="dev-code">POST {{ $base }}/domains
Idempotency-Key: your-order-12345
{"domain": "example.com", "years": 1,
 "nameservers": ["ns1.you.com", "ns2.you.com"]}

{"ok": true, "data": {
  "domain": "example.com",
  "status": "pending",
  "order_state": "registered",
  "registrant": "reseller",
  "charged": 1265000,
  "currency": "IRT",
  "expires_at": "2027-09-26T00:00:00+00:00"
}}</pre>

    <table class="dev-table">
      <thead><tr><th><code dir="ltr">order_state</code></th><th>یعنی</th><th>چه کنید</th></tr></thead>
      <tbody>
        <tr><td><code dir="ltr">registered</code></td><td>ثبت شد</td><td>هیچ</td></tr>
        <tr><td><code dir="ltr">pending</code></td><td>در صف — چند دقیقه طول می‌کشد</td><td>هر چند دقیقه <code dir="ltr">GET /domains/{d}</code></td></tr>
        <tr><td><code dir="ltr">manual</code></td><td>نیازمند بررسی انسانی نزد ما</td><td>منتظر بمانید؛ ما پیگیری می‌کنیم</td></tr>
        <tr><td><code dir="ltr">failed</code></td><td>ثبت نشد</td><td>مبلغ به اعتبار برمی‌گردد</td></tr>
      </tbody>
    </table>

    <div class="dev-note dev-warn">
      <b><code dir="ltr">pending</code> شکست نیست.</b> اگر آن را «ناموفق» بخوانید و
      دوباره سفارش بدهید، ممکن است دامنه‌ای که همان لحظه دارد ثبت می‌شود را
      دوباره بخرید. برای همین <code dir="ltr">Idempotency-Key</code> هست.
    </div>

    {{-- ══════════ idempotency ══════════ --}}
    <h2 id="idempotency">۵. کلید یکتاسازی — مهم‌ترین بخش</h2>

    <p>
      روی درخواست‌های <b>ثبت</b> و <b>تمدید</b> هدر
      <code dir="ltr">Idempotency-Key</code> بفرستید. اگر همان کلید دوباره برسد،
      همان پاسخ قبلی برمی‌گردد و <b>خرید دومی انجام نمی‌شود</b>.
      پاسخ پخش‌شده فیلد <code dir="ltr">"replayed": true</code> دارد.
    </p>

    <div class="dev-note dev-warn">
      <b>کلید تمدید باید تاریخ انقضای فعلی را در خودش داشته باشد.</b><br>
      اگر کلیدتان فقط بر اساس نام دامنه باشد، تمدید سال بعدِ همان دامنه
      دقیقاً همان کلید را می‌سازد — ما آن را «تکراری» می‌بینیم و پاسخ پارسال را
      برمی‌گردانیم. نتیجه: سامانهٔ شما «تمدید شد» می‌گیرد، مشتری پول می‌دهد، و
      <b>هیچ تمدیدی انجام نمی‌شود</b> تا روزی که دامنه منقضی شود.<br>
      نمونهٔ درست: <code dir="ltr">sha256("renew|example.com|2027-01-01|1")</code>
    </div>

    <p class="dev-sub">
      اگر کلید نفرستید، هیچ محافظی در کار نیست و مسئولیت درخواست تکراری با شماست.
      حداکثر طول کلید ۸۰ نویسه است.
    </p>

    {{-- ══════════ خطاها ══════════ --}}
    <h2 id="errors">۶. کدهای خطا</h2>
    <table class="dev-table">
      <thead><tr><th><code dir="ltr">error</code></th><th>HTTP</th><th>یعنی</th></tr></thead>
      <tbody>
        <tr><td><code dir="ltr">missing_token</code> / <code dir="ltr">invalid_token</code></td><td>۴۰۱</td><td>توکن نیامده یا شناخته نشد</td></tr>
        <tr><td><code dir="ltr">token_expired</code></td><td>۴۰۱</td><td>توکن منقضی شده — تازه بسازید</td></tr>
        <tr><td><code dir="ltr">token_revoked</code></td><td>۴۰۱</td><td>توکن باطل شده</td></tr>
        <tr><td><code dir="ltr">ip_not_allowed</code></td><td>۴۰۳</td><td>IP شما در فهرست مجاز توکن نیست</td></tr>
        <tr><td><code dir="ltr">insufficient_scope</code></td><td>۴۰۳</td><td>توکن دسترسی لازم را ندارد</td></tr>
        <tr><td><code dir="ltr">panel_only</code></td><td>۴۰۳</td><td>این عمل فقط از پنل انجام می‌شود</td></tr>
        <tr><td><code dir="ltr">insufficient_credit</code></td><td>۴۰۲</td><td>اعتبار کافی نیست (<code dir="ltr">data.required</code> و <code dir="ltr">data.balance</code>)</td></tr>
        <tr><td><code dir="ltr">daily_cap_reached</code></td><td>۴۲۹</td><td>سقف خرج روزانه پر شده</td></tr>
        <tr><td><code dir="ltr">already_registered</code> / <code dir="ltr">already_yours</code></td><td>۴۰۹</td><td>دامنه در سامانهٔ ما فعال است</td></tr>
        <tr><td><code dir="ltr">renewal_in_progress</code></td><td>۴۰۹</td><td>تمدیدی برای این دامنه در جریان است</td></tr>
        <tr><td><code dir="ltr">request_in_progress</code></td><td>۴۰۹</td><td>همین کلید در حال پردازش است</td></tr>
        <tr><td><code dir="ltr">tld_blocked</code></td><td>۴۲۲</td><td>ثبت این پسوند موقتاً مقدور نیست؛ مبلغی کسر نشد</td></tr>
        <tr><td><code dir="ltr">tld_not_sold</code></td><td>۴۲۲</td><td>این پسوند را نمی‌فروشیم</td></tr>
        <tr><td><code dir="ltr">registrant_incomplete</code></td><td>۴۲۲</td><td>پروفایل مالک در حساب شما ناقص است</td></tr>
        <tr><td><code dir="ltr">no_price</code></td><td>۴۲۲</td><td>قیمت قابل اتکا نداریم</td></tr>
        <tr><td><code dir="ltr">lookup_failed</code></td><td>۵۰۳</td><td>استعلام از رجیسترار ممکن نشد — دوباره تلاش کنید</td></tr>
        <tr><td><code dir="ltr">registrar_rejected</code></td><td>۵۰۲</td><td>رجیسترار تغییر را نپذیرفت</td></tr>
      </tbody>
    </table>

    {{-- ══════════ قیمت‌گذاری ══════════ --}}
    <h2 id="floor">۷. قیمت‌گذاری و سطح‌بندی</h2>

    <p>
      قیمتی که API به شما می‌دهد قیمت <b>خرید شما</b>ست: قیمت خرده‌فروشی منهای
      تخفیف سطح شما. سطح بر اساس <b>مجموع خرید ۱۲ ماه گذشته</b> و
      <b>تعداد دامنهٔ فعال</b> تعیین می‌شود و روزانه بازبینی می‌گردد.
    </p>

    <ul class="dev-list">
      <li><b>ارتقا فوری است</b> — همان لحظه که خریدتان از آستانه رد شود.</li>
      <li><b>تنزل کند است</b> — اگر حجمتان افت کند، ۳۰ روز مهلت دارید و بعد حداکثر یک پله.</li>
      <li>سطح فعلی و پیشرفتتان در <a href="{{ url('/account/reseller') }}">پنل نمایندگی</a> است.</li>
    </ul>

    <h3>کف قیمت — چیزی که پنهانش نمی‌کنیم</h3>
    <p>
      سود ما روی هر پسوند متفاوت است. روی پسوندهای کم‌حاشیه — که معمولاً
      پرفروش‌ترین‌ها هم هستند — تخفیف سطح شما فقط تا جایی اعمال می‌شود که قیمت
      زیر بهای تمام‌شدهٔ ما نرود. هر جا این اتفاق بیفتد، پاسخ API
      <code dir="ltr">price_floored: true</code> دارد.
    </p>
    <p class="dev-sub">
      می‌توانستیم ساکت بمانیم و شما فقط عدد کمتر را ببینید. ننوشتنش یعنی شما
      انتظار تخفیف کامل داشته باشید و توضیحی نبینید — و آن از خود محدودیت بدتر است.
    </p>

    {{-- ══════════ محدودیت‌ها ══════════ --}}
    <h2 id="limits">۸. محدودیت‌ها</h2>
    <table class="dev-table">
      <thead><tr><th>مورد</th><th>مقدار</th></tr></thead>
      <tbody>
        <tr><td>درخواست خواندنی</td><td dir="ltr">{{ str_replace(',', ' / ', $rate['read'] ?? '120,1') }} دقیقه</td></tr>
        <tr><td>استعلام قیمت</td><td dir="ltr">{{ str_replace(',', ' / ', $rate['check'] ?? '60,1') }} دقیقه</td></tr>
        <tr><td>درخواست نوشتنی</td><td dir="ltr">{{ str_replace(',', ' / ', $rate['write'] ?? '20,1') }} دقیقه</td></tr>
        <tr><td>حداکثر سال در هر سفارش</td><td>{{ fa_num((string) $maxYears) }}</td></tr>
        <tr><td>حداکثر توکن فعال</td><td>{{ fa_num('20') }}</td></tr>
      </tbody>
    </table>

    <p class="dev-sub">
      سقف خرج روزانه هم روی هر حساب هست و در پنل نمایندگی دیده می‌شود. این سقف
      محافظ شماست: اگر روزی توکنتان لو برود، خسارت به همان سقف محدود می‌مانَد.
    </p>

    {{-- ══════════ عمداً نیست ══════════ --}}
    <h2 id="absent">۹. چه چیزی عمداً در API نیست</h2>
    <p>این‌ها «هنوز نساخته‌ایم» نیستند؛ تصمیم آگاهانه‌اند:</p>
    <table class="dev-table">
      <thead><tr><th>عملیات</th><th>چرا</th></tr></thead>
      <tbody>
        @foreach($panelOnly as $key => $why)
          <tr><td><code dir="ltr">{{ $key }}</code></td><td>{{ $why }}</td></tr>
        @endforeach
        <tr><td><code dir="ltr">transfer</code></td><td>انتقال دامنه هنوز از API پشتیبانی نمی‌شود.</td></tr>
        <tr><td><code dir="ltr">dns records</code></td><td>مدیریت رکوردهای DNS از این API انجام نمی‌شود؛ نام‌سرور خودتان را ست کنید.</td></tr>
      </tbody>
    </table>

    <div class="dev-note dev-warn">
      <b>مالک ثبت‌شدهٔ دامنه در این نسخه، حساب نمایندگی شماست</b> — نه مشتری
      نهایی شما. اگر اطلاعات تماس مشتری را در بدنهٔ درخواست بفرستید، ما آن را
      <b>نادیده می‌گیریم و ذخیره نمی‌کنیم</b>. انتقال اطلاعات هویتی مشتری نهایی
      به ما مسیر داده‌ای جداگانه‌ای می‌خواهد (رضایت، نگهداری، حذف) که هنوز ساخته
      نشده؛ پذیرفتن خاموشِ آن داده ریسکی است که نباید بی‌تصمیم برداشته شود.
    </div>

    <div class="dev-note">
      <b>پسوندهای ایرانی (<code dir="ltr">.ir</code> و خانواده‌اش) از این مسیر فروخته نمی‌شوند.</b>
      قیمت آنها از رجیسترار اروپایی چند ده برابر قیمت واقعی ایرنیک است.
      برای <code dir="ltr">.ir</code> فعلاً از مسیر مستقیم ایرنیک استفاده کنید.
    </div>

    {{-- ══════════ افزونه‌ها ══════════ --}}
    <h2 id="plugins">۱۰. افزونه‌های آماده</h2>
    <p>
      اگر نمی‌خواهید چیزی بنویسید، دو افزونهٔ آماده داریم. هر دو را از
      <a href="{{ url('/account/reseller') }}">پنل نمایندگی</a> دانلود می‌کنید.
    </p>

    <h3>WHMCS</h3>
    <p>
      در <code dir="ltr">modules/registrars/servernet/</code> بگذارید و توکنتان را وارد کنید.
      استعلام، ثبت، تمدید، تغییر نام‌سرور، قفل انتقال، همگام‌سازی وضعیت و وارد
      کردن قیمت‌ها را پوشش می‌دهد و خودش کلید یکتاسازی درست می‌سازد.
    </p>

    <h3>وردپرس و ووکامرس</h3>
    <p>
      در <code dir="ltr">wp-content/plugins/servernet-domains/</code> بگذارید و فعال کنید.
      کد کوتاه <code dir="ltr">[servernet_domain_search]</code> جعبهٔ جستجو را می‌سازد؛
      با ووکامرس، دامنه به سبد اضافه می‌شود، مشتری از <b>درگاه خودتان</b> پرداخت
      می‌کند، و ثبت پس از پرداخت خودکار انجام می‌شود.
    </p>

    <div class="dev-note dev-warn">
      <b>سه محافظی که در افزونهٔ وردپرس تعبیه شده — و اگر خودتان کد می‌نویسید هم لازمشان دارید:</b>
      <ol style="margin:8px 0 0;padding-inline-start:20px;line-height:2.1">
        <li><b>قیمت از مرورگر گرفته نمی‌شود.</b> هنگام افزودن به سبد، قیمت دوباره
          از ما پرسیده می‌شود. بی‌این، هر کسی با یک درخواست دستی دامنهٔ گران را به
          قیمت دلخواه سفارش می‌دهد و تفاوت را <b>شما</b> می‌دهید.</li>
        <li><b>اگر قیمت خرید شما بین سفارش و پرداخت بالا رفته باشد، ثبت خودکار
          انجام نمی‌شود</b> و سفارش به حالت «در انتظار» می‌رود. یک جهش ارز بین
          این دو لحظه، بی‌این محافظ، مستقیم از سود شما برمی‌دارد.</li>
        <li><b>پرداخت تکراری دامنهٔ دوم نمی‌خرد.</b> ووکامرس یک سفارش را چند بار
          «پرداخت‌شده» می‌کند (وب‌هوک درگاه، بازگشت کاربر، تغییر دستی مدیر)؛ کلید
          یکتاسازی از شناسهٔ <b>آیتم</b> سفارش ساخته می‌شود، نه شناسهٔ سفارش.</li>
      </ol>
    </div>

  </div>
</section>

@endsection
