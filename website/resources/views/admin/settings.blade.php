@extends('admin.layout')
@section('title', 'تنظیمات')
@section('nav_settings', 'on')
@section('content')


<div class="ad-panel">
  <div class="ad-panel-h"><h2>حساب بانکی شرکت — برای «واریز به حساب»</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    این مشخصات به مشتری نشان داده می‌شود تا واریز کند. تا وقتی شبا یا شمارهٔ حساب را وارد نکنید،
    گزینهٔ «واریز به حساب» در صفحهٔ پرداخت نمایش داده نمی‌شود.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول تنظیمات روی این سرور هنوز ساخته نشده. پس از مهاجرت فعال می‌شود.</p>
  @else
  <form method="post" action="/admin/settings" enctype="multipart/form-data" style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:720px">
    @csrf
    <label class="set-f" style="grid-column:1/3">نام صاحب حساب
      <input type="text" name="bank_holder" value="{{ $bank['bank_holder'] }}" maxlength="120" placeholder="اطمینان داده‌پردازان دانش"></label>
    <label class="set-f">نام بانک
      <input type="text" name="bank_name" value="{{ $bank['bank_name'] }}" maxlength="80" placeholder="ملت / سامان / …"></label>
    <label class="set-f">شمارهٔ کارت
      <input type="text" name="bank_card" value="{{ $bank['bank_card'] }}" maxlength="20" dir="ltr" placeholder="6104-****-****-****"></label>
    <label class="set-f">شبا (بدون IR)
      <input type="text" name="bank_sheba" value="{{ $bank['bank_sheba'] }}" maxlength="34" dir="ltr" placeholder="000000000000000000000000"></label>
    <label class="set-f">شمارهٔ حساب
      <input type="text" name="bank_account" value="{{ $bank['bank_account'] }}" maxlength="40" dir="ltr"></label>
    <label class="set-f" style="grid-column:1/3">توضیح (اختیاری)
      <input type="text" name="bank_note" value="{{ $bank['bank_note'] }}" maxlength="300" placeholder="مثلاً: پس از واریز، شناسهٔ پرداخت را ثبت کنید"></label>

    {{-- مهر شرکت --}}
    <div style="grid-column:1/3;border-top:1px solid var(--line);padding-top:14px;margin-top:4px">
      <label style="font-size:13px;color:var(--text);font-weight:600;display:block;margin-bottom:8px">مهر شرکت (روی فاکتورهای پرداخت‌شده چاپ می‌شود)</label>
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="width:96px;height:96px;border:1px dashed var(--line2);border-radius:12px;display:grid;place-items:center;background:var(--surface2);overflow:hidden">
          @if($stampData)<img src="{{ $stampData }}" alt="مهر" style="max-width:100%;max-height:100%">
          @else<span style="font-size:11px;color:var(--dim)">بدون مهر</span>@endif
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <input type="file" name="stamp" accept="image/png,image/jpeg" style="font:inherit;font-size:12.5px;color:var(--muted)">
          <small style="color:var(--dim);font-size:12px">PNG با پس‌زمینهٔ شفاف بهتر است — تا ۲ مگابایت.</small>
          @if($stampData)
            <label style="display:flex;align-items:center;gap:7px;color:#ff6b6b;font-size:12.5px"><input type="checkbox" name="remove_stamp" value="1"> حذف مهر فعلی</label>
          @endif
        </div>
      </div>
    </div>

    {{-- قیمت‌گذاریِ منعطف — اتصال به نرخِ یورو --}}
    <div style="grid-column:1/3;border-top:1px solid var(--line);padding-top:14px;margin-top:4px">
      <label style="font-size:13px;color:var(--text);font-weight:600;display:block;margin-bottom:4px">قیمت‌گذاری خودکار (اتصال به نرخِ یورو)</label>
      <p style="margin:0 0 12px;color:var(--muted);font-size:12.5px;line-height:1.9">
        قیمت‌های پایه (تومان) لنگرند. اگر «نرخِ مبنا» را برابرِ نرخِ فعلیِ یورو بگذارید، از این پس همهٔ قیمت‌های سایت و فروشگاه خودکار با نرخِ روزِ یورو بالا/پایین می‌روند. برای تغییرِ کلی هم فقط این یک عدد را عوض کنید.
        @if($liveRate)<br>نرخِ زندهٔ یورو الان: <b dir="ltr">{{ number_format($liveRate) }}</b> تومان · ضریبِ فعلیِ قیمت‌ها: <b dir="ltr">{{ number_format($priceFactor, 3) }}×</b>@endif
      </p>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <label class="set-f">نرخِ مبنای یورو (تومان)
          <input type="number" name="pricing_baseline_rate" dir="ltr" min="0" value="{{ $pricing['pricing_baseline_rate'] }}" placeholder="خالی = خاموش"></label>
        <label class="set-f">نرخِ دستی (به‌جای نرخِ زنده)
          <input type="number" name="pricing_rate_override" dir="ltr" min="0" value="{{ $pricing['pricing_rate_override'] }}" placeholder="خالی = نرخِ زنده"></label>
        <label class="set-f">حاشیهٔ سود (٪)
          <input type="number" name="price_margin_pct" dir="ltr" step="0.1" value="{{ $pricing['price_margin_pct'] }}" placeholder="۰"></label>
      </div>
      <p style="margin:8px 0 0;color:var(--dim);font-size:12px">تا وقتی «نرخِ مبنا» خالی باشد، هیچ قیمتی تغییر نمی‌کند (حالتِ امن).</p>
    </div>

    {{-- Cloudflare — رکوردِ DNS زیردامنهٔ رایگان --}}
    @php $cfOn = \App\Models\Setting::getSecret('cloudflare_token') !== null; @endphp
    <div style="grid-column:1/3;border-top:1px solid var(--line);padding-top:14px;margin-top:4px">
      <label style="font-size:13px;color:var(--text);font-weight:600;display:block;margin-bottom:4px">
        DNS زیردامنهٔ رایگان (Cloudflare)
        @if($cfOn)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399;margin-inline-start:6px">فعال</span>
        @else<span class="ad-badge" style="background:rgba(251,191,36,.12);color:#fbbf24;margin-inline-start:6px">تنظیم نشده</span>@endif
      </label>
      <p style="margin:0 0 12px;color:var(--muted);font-size:12.5px;line-height:1.9">
        وقتی مشتری «زیردامنهٔ رایگان» می‌گیرد، پس از تحویل رکوردِ <b>A</b> آن خودکار به IPِ سرور ست می‌شود
        (بدونِ این، سایتش بالا نمی‌آید چون nameserverها روی Cloudflare است).
        در Cloudflare یک <b>API Token</b> بسازید با دسترسیِ <b dir="ltr">Zone → DNS → Edit</b> و فقط برای zoneِ
        <b dir="ltr">{{ config('servernet.subdomain_zone') }}</b>.
        توکن <b>رمزنگاری‌شده</b> ذخیره می‌شود و دیگر نمایش داده نمی‌شود.
      </p>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <label class="set-f">API Token @if($cfOn)<span style="color:var(--dim)">(خالی = بدونِ تغییر)</span>@endif
          <input type="password" name="cloudflare_token" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cfOn ? '••••••••••  ذخیره‌شده' : 'توکن را این‌جا بچسبانید' }}"></label>
        <label class="set-f">Zone ID <span style="color:var(--dim)">(اختیاری)</span>
          <input type="text" name="cloudflare_zone_id" dir="ltr" maxlength="64"
                 value="{{ \App\Models\Setting::get('cloudflare_zone_id') }}" placeholder="خالی = خودکار پیدا می‌شود"></label>
      </div>
      @if($cfOn)
        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;color:#ff6b6b;font-size:12.5px">
          <input type="checkbox" name="cloudflare_forget" value="1"> حذفِ توکنِ ذخیره‌شده
        </label>
      @endif
    </div>

    {{-- زیرساختِ سرورِ ابری --}}
    <div style="grid-column:1/3;border-top:1px solid var(--line);padding-top:14px;margin-top:4px">
      <label style="font-size:13px;color:var(--text);font-weight:600;display:block;margin-bottom:4px">
        زیرساختِ سرورِ ابری (VPS)
        @if($cloud['plans'] > 0)
          <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399;margin-inline-start:6px">{{ fa_num($cloud['plans']) }} پلن فعال</span>
        @endif
      </label>
      <p style="margin:0 0 12px;color:var(--muted);font-size:12.5px;line-height:1.9">
        توکن‌ها را <b>خودتان</b> این‌جا وارد می‌کنید و <b>رمزنگاری‌شده</b> ذخیره می‌شوند؛
        نه در <span dir="ltr">.env</span> می‌روند و نه دیگر نمایش داده می‌شوند.
        <br>قیمتِ فروش از بهایِ یوروییِ زیرساخت + حاشیهٔ سودِ زیر ساخته می‌شود و
        با نرخِ روزِ یورو به تومان می‌آید — روزی یک‌بار خودکار.
        <br><b style="color:#fbbf24">نامِ زیرساخت هیچ‌جای سایت و پنلِ مشتری دیده نمی‌شود.</b>
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label class="set-f">
          زیرساختِ ۱ — API Token
          @if($cloud['hetzner'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
          <input type="password" name="hetzner_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
                 placeholder="{{ $cloud['hetzner'] ? '••••••••••  خالی = بدونِ تغییر' : 'توکن را این‌جا بچسبانید' }}">
        </label>
        <label class="set-f">
          زیرساختِ ۲ — API Key
          @if($cloud['aeza'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
          <input type="password" name="aeza_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
                 placeholder="{{ $cloud['aeza'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را این‌جا بچسبانید' }}">
        </label>
        <label class="set-f">
          زیرساختِ ۳ — API Key <span style="color:var(--dim)">(ایرانی)</span>
          @if($cloud['arvan'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
          <input type="password" name="arvan_api_token" dir="ltr" autocomplete="new-password" maxlength="400"
                 placeholder="{{ $cloud['arvan'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را با پیشوندِ Apikey بچسبانید' }}">
        </label>
        {{-- زیرساختِ ۴ سه کلید دارد، نه یکی: OVH هر درخواست را جداگانه امضا
             می‌کند و بدونِ هر سه، امضا ساخته نمی‌شود و همه‌چیز ۴۰۳ می‌گیرد.
             برای همین یک کادرِ جدا با توضیحِ صریح دارد. --}}
        <div style="grid-column:1/-1;border:1px solid var(--line2);border-radius:11px;padding:14px">
          <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px">
            <b style="font-size:13.5px">زیرساختِ ۴ — OVHcloud</b>
            @if($cloud['ovh'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">هر سه کلید ذخیره‌شده</span>@endif
          </div>
          <p style="color:var(--muted);font-size:12px;line-height:1.9;margin-bottom:10px">
            این زیرساخت سه کلید می‌خواهد. هر سه را از
            <span dir="ltr">eu.api.ovh.com/createToken</span> بسازید و دسترسی‌های
            <span dir="ltr">GET/POST /vps*</span> و <span dir="ltr">GET /me</span> را بدهید.
            <br>⚠️ <b>خرید خودکار هنوز فعال نیست</b> — سفارش در OVH از سبد خرید چندمرحله‌ای می‌گذرد
            و تا وقتی روی حساب واقعی آزمایش نشده، سفارش‌ها به صف تحویل دستی می‌روند.
            مدیریت سرورهای موجود (روشن/خاموش/نصب دوباره) کامل کار می‌کند.
          </p>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
            <label class="set-f">Application Key
              <input type="password" name="ovh_app_key" dir="ltr" autocomplete="new-password" maxlength="200"
                     placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AK' }}"></label>
            <label class="set-f">Application Secret
              <input type="password" name="ovh_app_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                     placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AS' }}"></label>
            <label class="set-f">Consumer Key
              <input type="password" name="ovh_consumer_key" dir="ltr" autocomplete="new-password" maxlength="200"
                     placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'CK' }}"></label>
          </div>
          @if($cloud['ovh'])
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12.5px;cursor:pointer">
              <input type="checkbox" name="ovh_forget" value="1">
              <span>هر سه کلید را فراموش کن</span>
            </label>
          @endif
        </div>
        <label class="set-f">حاشیهٔ سودِ سرورِ ابری (٪)
          <input type="number" name="cloud_margin_pct" dir="ltr" step="1" min="0" max="500"
                 value="{{ $cloud['margin'] }}" placeholder="{{ fa_num(\App\Services\Cloud\CloudPricing::DEFAULT_MARGIN_PCT) }} (پیش‌فرض)"></label>

        {{-- ── گوگل‌کلندر ──────────────────────────────────────────────────
             اعتبارنامهٔ **اپ** این‌جاست، نه حسابِ شخصیِ کسی: یک OAuth client
             برای کلِ نصب. اتصالِ حسابِ گوگلِ هر کاربرِ پنل جداگانه و از خودِ
             صفحهٔ تقویم انجام می‌شود، و توکنش per-user ذخیره می‌شود.

             ⚠️ Client ID عمومی است (در URLِ ورود دیده می‌شود) پس رمزنگاری
             ندارد؛ Secret با `putSecret()` رمزنگاری می‌شود و هرگز به فرم
             برنمی‌گردد — همان الگوی Cloudflare و OVH. --}}
        <div style="grid-column:1/-1;border:1px solid var(--line2);border-radius:11px;padding:14px">
          <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px">
            <b style="font-size:13.5px">گوگل‌کلندر — همگام‌سازی تقویم</b>
            @if($google['ready'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">اعتبارنامه ذخیره‌شده</span>@endif
          </div>
          <p style="color:var(--muted);font-size:12px;line-height:1.9;margin-bottom:10px">
            در <span dir="ltr">Google Cloud Console → APIs &amp; Services → Credentials</span>
            یک <b>OAuth client ID</b> از نوع <span dir="ltr">Web application</span> بسازید و
            این آدرسِ بازگشت را دقیقاً اضافه کنید:
            <br><code dir="ltr" style="user-select:all;color:var(--text)">{{ url('/admin/calendar/google/callback') }}</code>
            <br>⚠️ اگر وضعیتِ انتشارِ اپ روی <b>Testing</b> بماند، توکنِ گوگل حدود
            <b>۷ روزه</b> منقضی می‌شود و هر هفته باید دوباره وصل کنید. برای پایدارماندن،
            آن را روی <span dir="ltr">In production</span> بگذارید.
          </p>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <label class="set-f">Client ID
              <input type="text" name="google_client_id" dir="ltr" maxlength="200"
                     value="{{ $google['client_id'] }}"
                     placeholder="…apps.googleusercontent.com"></label>
            <label class="set-f">Client Secret
              <input type="password" name="google_client_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                     placeholder="{{ $google['ready'] ? '••••••••  خالی = بدونِ تغییر' : 'GOCSPX-…' }}"></label>
          </div>
          @if($google['ready'])
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12.5px;cursor:pointer">
              <input type="checkbox" name="google_forget" value="1">
              <span>اعتبارنامهٔ گوگل را فراموش کن (اتصالِ همهٔ کاربران قطع می‌شود)</span>
            </label>
          @endif
        </div>

        {{-- ── دامنه ──
             ⚠️ درصدِ سودِ دامنه جدا از سرورِ ابری است و باید هم باشد: بهای
             دامنه سالانه و کوچک است، پس همان درصدی که روی سرور منطقی است
             روی دامنه قیمت را غیررقابتی می‌کند. --}}
        <label class="set-chk">
          <input type="checkbox" name="cloud_traffic_unlimited" value="1" @checked($cloud['unlimited'])>
          <span>ترافیک را «نامحدود» نشان بده
            <small style="display:block;color:var(--dim);font-size:11.5px;line-height:1.9">
              ⚠️ وعدهٔ تجاری است نه توصیف فنی: سقف واقعی زیرساخت سر جایش می‌مانَد و
              مصرف بیش از آن هزینه‌اش با ماست. عددِ واقعی در دیتابیس دست‌نخورده می‌مانَد.
            </small></span>
        </label>

        <label class="set-f">حاشیهٔ سودِ دامنه (٪)
          <input type="number" name="domain_margin_pct" dir="ltr" step="1" min="0" max="500"
                 value="{{ $cloud['dmargin'] }}" placeholder="۰ (پیش‌فرض)">
          <small style="display:block;color:var(--dim);font-size:11.5px;line-height:1.9;margin-top:4px">
            روی قیمتِ ثبت، تمدید و انتقال اعمال می‌شود. صفر یعنی دقیقاً به بهای تمام‌شده
            می‌فروشیم — برای جذبِ مشتری.
          </small></label>

        <label class="set-f">نام‌سرورهای پیش‌فرضِ دامنه
          <input type="text" name="domain_nameservers" dir="ltr"
                 value="{{ $cloud['dns'] }}"
                 placeholder="{{ implode(',', (array) config('services.openprovider.nameservers')) }}">
          <small style="display:block;color:var(--dim);font-size:11.5px;line-height:1.9;margin-top:4px">
            با کاما جدا کنید. دستِ‌کم دو تا لازم است، وگرنه ثبتِ دامنه به صفِ دستی می‌رود
            (دامنهٔ بی‌نام‌سرور به هیچ‌جا اشاره نمی‌کند).
          </small></label>
        <label class="set-f">هزینهٔ IPv4 (سنتِ یورو، ماهانه)
          <input type="number" name="cloud_ipv4_eur_cents" dir="ltr" step="1" min="-1" max="10000"
                 value="{{ $cloud['ipv4'] }}" placeholder="خالی = خودکار از زیرساخت"></label>
        
      </div>

      <p style="margin:10px 0 0;color:var(--warn);font-size:12.5px;line-height:1.9">
        <b>⚠️ دیگر هیچ تبدیلِ ارزی در کار نیست.</b> پشتیبانیِ زیرساختِ ۲ کتباً گفت
        موجودیِ حساب <b>فقط یورو</b> می‌تواند باشد، پس قیمت‌های APIشان هم یورویی‌اند.
        فیلدِ «۱ یورو چند روبل» و تماس با مسیرِ نرخِ ارز (که کدِ ۵۰۰ می‌داد و
        همگام‌سازی را می‌خواباند) هر دو حذف شدند.
        <br>تنها چیزی که مانده «عدد سنت است یا یورو» است و از داده قابلِ حدس نیست:
        عددِ ۵۰۰ اگر سنت باشد ۵ یورو است و اگر یورو باشد ۵۰۰ یورو، و هر دو برای یک
        سرورِ مجازی ممکن‌اند.
        <br>اگر قیمت‌ها <b>خیلی ارزان</b> افتادند این را روی «یورو» بگذارید؛ اگر
        <b>خیلی گران</b>، روی «سنتِ یورو». صفحهٔ
        <a href="/admin/cloud/probe" style="color:#22d3ee">ساختارِ خامِ پاسخ</a>
        عددِ خام را کنارِ قیمتِ تفسیرشده نشان می‌دهد تا با فاکتورِ خودتان مقایسه کنید.
      </p>

      <label style="display:flex;align-items:flex-start;gap:8px;margin-top:12px;font-size:12.5px;color:var(--muted);line-height:1.9">
        <input type="checkbox" name="aeza_include_promo" value="1" @checked($cloud['promo']) style="margin-top:4px">
        <span>
          <b>پلن‌های تشویقی (PROMO) هم فروخته شوند</b>
          <br>پیش‌فرض <b>خاموش</b> است. قیمتِ این پلن‌ها واقعاً پایین است ولی
          <b>موقت</b>: نرخِ تمدیدشان بالاتر می‌رود. چون ما قیمتِ مشتری را سرِ سفارش
          <b>قفل می‌کنیم</b>، از دورهٔ دوم هر تمدید ضررِ خالص است — و چون خودکار
          تمدید می‌شود، ماه‌به‌ماه بی‌صدا تکرار می‌شود.
          <br>فقط اگر با فاکتورِ خودتان مطمئن شدید نرخ پایدار است، روشنش کنید.
        </span>
      </label>

      <p style="margin:10px 0 0;color:var(--muted);font-size:12.5px;line-height:1.9">
        <b>چرا روبل؟</b> API زیرساختِ ۲ قیمت‌ها را — هر ارزی که حسابِ شما باشد — به <b>روبل</b>
        برمی‌گرداند. ما برای رسیدن به تومان اول باید به یورو تبدیلش کنیم.
        اگر این کادر خالی باشد، ضریب از خودِ آنها خوانده می‌شود؛ و اگر آن هم نشد،
        <b>هیچ قیمتی ساخته نمی‌شود</b> (قیمتِ حدسی از نبودِ قیمت بدتر است).
        <br>عددی که این‌جا می‌گذارید <b>اولویت دارد</b> — چون شما می‌دانید واقعاً چند پرداخته‌اید.
      </p>

      @if($cloud['hetzner'] || $cloud['aeza'])
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px">
          @if($cloud['hetzner'])
            <label style="display:flex;align-items:center;gap:8px;color:#ff6b6b;font-size:12.5px">
              <input type="checkbox" name="hetzner_forget" value="1"> حذفِ توکنِ زیرساختِ ۱
            </label>
          @endif
          @if($cloud['aeza'])
            <label style="display:flex;align-items:center;gap:8px;color:#ff6b6b;font-size:12.5px">
              <input type="checkbox" name="aeza_forget" value="1"> حذفِ کلیدِ زیرساختِ ۲
            </label>
          @endif
        </div>
      @endif
    </div>

    <div style="grid-column:1/3;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>ذخیره</button>
    </div>
  </form>
  @endif
</div>

{{-- آزمونِ اتصال و همگام‌سازی — بیرونِ فرمِ تنظیمات، چون عملیاتِ جداگانه‌اند و
     نباید ذخیرهٔ تنظیمات را به تماسِ شبکه گره بزنند. --}}
@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>کاتالوگِ سرورِ ابری</h3></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13px;line-height:1.9">
    اول توکن‌ها را ذخیره کنید، بعد <b>آزمونِ اتصال</b> بزنید و در پایان
    <b>همگام‌سازی</b> تا پلن‌ها و مکان‌ها و سیستم‌عامل‌ها خوانده شوند.
    همگام‌سازی خودکار هم <b>دو روز یک‌بار</b> انجام می‌شود.
  </p>
  <div style="padding:2px 18px 16px;display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" action="/admin/cloud/test" style="display:inline">
      @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>آزمونِ اتصال</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline"
          data-confirm="کاتالوگ از زیرساخت‌ها خوانده و قیمت‌ها بازمحاسبه شود؟ چند ده ثانیه طول می‌کشد.">
      @csrf<button class="btn btn-primary" style="font-size:12.5px"><svg class="icon"><use href="#i-restore"/></svg>همگام‌سازیِ کاتالوگ</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline">
      @csrf<input type="hidden" name="prices_only" value="1">
      <button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-coins"/></svg>فقط بازمحاسبهٔ قیمت</button>
    </form>
  </div>
</div>
@endunless

<style>
.set-f{ display:flex; flex-direction:column; gap:6px; font-size:13px; color:var(--muted) }
.set-f input{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:9px 12px; font:inherit }
@media(max-width:640px){ form{ grid-template-columns:1fr !important } .set-f[style*="1/3"]{ grid-column:1 !important } }
</style>

@endsection
