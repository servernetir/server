<style>
.vpstc-intro{padding:0 18px 18px;color:var(--muted);line-height:2}.vpstc-form{padding:0 18px 20px;display:grid;gap:16px}.vpstc-form label{display:grid;gap:8px}.vpstc-form input,.vpstc-form select{box-sizing:border-box;width:100%;padding:11px 13px;border:1px solid var(--line,#334155);border-radius:10px;background:var(--bg,#0f172a);color:inherit}.vpstc-form small{color:var(--muted);line-height:1.9}.vpstc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:16px}.vpstc-alert{padding:14px 18px;margin-bottom:16px;border:1px solid #64748b;border-radius:12px;line-height:2}.vpstc-report{padding:0 18px 18px;line-height:2}.vpstc-report dt{color:var(--muted)}.vpstc-report dd{margin:0 0 8px;overflow-wrap:anywhere}.vpstc-table-wrap{overflow-x:auto;margin:16px 0}.vpstc-table{border-collapse:collapse;width:100%;min-width:640px;text-align:start}.vpstc-table th,.vpstc-table td{padding:10px 12px;border-bottom:1px solid var(--line,#334155);vertical-align:top}.vpstc-table th{color:var(--muted)}.vpstc-table td{overflow-wrap:anywhere}.vpstc-warning{color:#fbbf24}
</style>
<div class="vpstc-alert">VPS.TC — {{ !empty($vpstcSalesEnabled) ? 'فروش ماهانه فعال است.' : 'فروش تازه بسته است؛ اتصال و قرارداد کاتالوگ را تکمیل کنید.' }} فروش ساعتی پشتیبانی نمی‌شود.</div>
@if(session('success'))<div class="vpstc-alert" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="vpstc-alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="ad-panel">
  <div class="ad-panel-h"><h2>اطلاعات API نمایندگی</h2><a href="/admin/cloud" class="btn btn-glass">بازگشت به زیرساخت‌ها</a></div>
  <p class="vpstc-intro">مبدأ API را از پنل یا پشتیبانی رسمی VPS.TC دریافت کنید. نام کاربری و رمز به‌صورت رمزنگاری‌شده ذخیره می‌شوند و دوباره نمایش داده نمی‌شوند. برای تغییر مبدأ، هر دو را دوباره وارد کنید.</p>
  <form class="vpstc-form" method="post" action="{{ route('admin.cloud.vpstc.save') }}" autocomplete="off">
    @csrf @if(!empty($vpstcInline))<input type="hidden" name="return_to" value="infra">@endif
    <label for="vpstc-origin">مبدأ HTTPS API
      <input id="vpstc-origin" type="url" name="api_origin" value="{{ $apiOrigin }}" required maxlength="255" dir="ltr" autocomplete="off">
      <small>فقط مبدأ؛ بدون مسیر /api، پارامتر، توکن یا اطلاعات ورود.</small>
    </label>
    <div class="vpstc-grid">
      <label for="vpstc-username">نام کاربری API
        <input id="vpstc-username" type="text" name="username" maxlength="512" dir="ltr" autocomplete="off" autocapitalize="none" spellcheck="false">
        <small>{{ $hasUsername ? 'مقدار رمزنگاری‌شده ذخیره شده؛ ورودی خالی آن را حفظ می‌کند.' : 'هنوز ذخیره نشده است.' }}</small>
      </label>
      <label for="vpstc-password">رمز API
        <input id="vpstc-password" type="password" name="password" maxlength="4096" dir="ltr" autocomplete="new-password">
        <small>{{ $hasPassword ? 'مقدار رمزنگاری‌شده ذخیره شده؛ ورودی خالی آن را حفظ می‌کند.' : 'هنوز ذخیره نشده است.' }}</small>
      </label>
    </div>
    <div><button type="submit" class="btn btn-primary">ذخیرهٔ امن اتصال</button></div>
  </form>
</div>
<div class="ad-panel">
  <div class="ad-panel-h"><h2>کاتالوگ و وضعیت فروش</h2></div>
  <div class="vpstc-intro">
    <p>{{ !empty($vpstcMapped) ? 'منابع، مکان، دوره و شرایط ترافیک برای این حساب تطبیق داده شده‌اند.' : 'تطبیق قرارداد کاتالوگ این حساب هنوز انجام نشده است. پلن سالانه یا کانتینر به‌عنوان VPS ماهانه وارد نمی‌شود.' }}</p>
    <p>پس از ورود اطلاعات و درصدهای قیمت‌گذاری، کاتالوگ را تازه کنید. خاموش‌کردن فروش، مدیریت سرویس‌های موجود را متوقف نمی‌کند.</p>
  </div>
  <div class="vpstc-form">
    <form method="post" action="{{ route('admin.cloud.vpstc.catalog') }}">@csrf @if(!empty($vpstcInline))<input type="hidden" name="return_to" value="infra">@endif<button class="btn btn-glass" type="submit">تازه‌سازی کاتالوگ و قیمت</button></form>
    <form method="post" action="{{ route('admin.cloud.vpstc.sales') }}">@csrf @if(!empty($vpstcInline))<input type="hidden" name="return_to" value="infra">@endif
      <input type="hidden" name="enabled" value="{{ !empty($vpstcSalesEnabled) ? '0' : '1' }}">
      <button class="btn btn-primary" type="submit">{{ !empty($vpstcSalesEnabled) ? 'بستن فروش تازه' : 'فعال‌سازی فروش ماهانه' }}</button>
    </form>
  </div>
</div>
<div class="ad-panel">
  <div class="ad-panel-h"><h2>قیمت‌گذاری مستقل این زیرساخت</h2></div>
  <p class="vpstc-intro">واحد حساب را دقیقاً مطابق پنل نمایندگی انتخاب کنید. بهای خرید با مالیات تأمین‌کننده و کارمزد تأمین ارز محاسبه می‌شود؛ درصد سود روی همین بها اضافه می‌شود. مالیات فروش مشتری طبق تنظیمات صورتحساب جداگانه اعمال می‌شود. صفر یک مقدار معتبر است؛ ورودی‌های تعیین‌نشده فروش را باز نمی‌کنند.</p>
  <form class="vpstc-form" method="post" action="{{ route('admin.cloud.vpstc.pricing') }}">
    @csrf @if(!empty($vpstcInline))<input type="hidden" name="return_to" value="infra">@endif
    <div class="vpstc-grid">
      <label for="vpstc-currency">واحد پول حساب تأمین‌کننده
        <select id="vpstc-currency" name="vpstc_currency" required dir="ltr">
          <option value="">انتخاب کنید</option>
          @foreach(['TRY' => 'TRY — لیر ترکیه', 'USD' => 'USD — دلار', 'EUR' => 'EUR — یورو', 'USDT' => 'USDT — تتر'] as $code => $caption)
            <option value="{{ $code }}" @selected(($vpstcPricing['vpstc_currency'] ?? '') === $code)>{{ $caption }}</option>
          @endforeach
        </select>
      </label>
      <label for="vpstc-margin">درصد سود
        <input id="vpstc-margin" name="cloud_margin_pct_vpstc" type="number" min="0" max="500" step="0.01" value="{{ $vpstcPricing['cloud_margin_pct_vpstc'] ?? '' }}" required dir="ltr">
      </label>
      <label for="vpstc-tax">مالیات خرید تأمین‌کننده (درصد)
        <input id="vpstc-tax" name="vpstc_supplier_tax_pct" type="number" min="0" max="100" step="0.01" value="{{ $vpstcPricing['vpstc_supplier_tax_pct'] ?? '' }}" required dir="ltr">
        <small>اگر قیمت API مالیات را شامل می‌شود، صفر وارد کنید تا دوباره اضافه نشود.</small>
      </label>
      <label for="vpstc-fee">کارمزد تأمین و تبدیل ارز (درصد)
        <input id="vpstc-fee" name="pricing_fx_fee_pct_vpstc" type="number" min="0" max="100" step="0.01" value="{{ $vpstcPricing['pricing_fx_fee_pct_vpstc'] ?? '' }}" required dir="ltr">
      </label>
      <label for="vpstc-rate">جایگزین نرخ هر واحد ارز حساب به تومان (اختیاری)
        <input id="vpstc-rate" name="vpstc_fx_rate_toman" type="number" min="0.000001" max="1000000000" step="any" value="{{ $vpstcPricing['vpstc_fx_rate_toman'] ?? '' }}" dir="ltr">
        <small>خالی یعنی نرخ ذخیره‌شدهٔ معتبر نوسان؛ برای لیر، نرخ حوالهٔ لیر استفاده می‌شود. مقدار دستی فقط جایگزین اختیاری همین زیرساخت است و باید به تومان وارد شود.</small>
        <small>@if(!empty($vpstcCentralTryRate['rate_toman']))نرخ مرجع لیر: {{ fa_num(number_format($vpstcCentralTryRate['rate_toman'])) }} تومان — نوسان.@elseنرخ معتبر حوالهٔ لیر هنوز در دسترس نیست؛ وضعیت نرخ‌ها در تب نرخ‌گذاری نمایش داده می‌شود.@endif</small>
      </label>
    </div>
    <p>{{ !empty($pricingConfigured) ? 'ورودی‌های قیمت‌گذاری کامل است؛ موجودی و آمادگی تحویل جداگانه بررسی می‌شوند.' : 'قیمت‌گذاری هنوز کامل نیست؛ فروش این زیرساخت بسته می‌ماند.' }} فروش ساعتی این زیرساخت فعال نمی‌شود.</p>
    <div><button type="submit" class="btn btn-primary">ذخیرهٔ قیمت‌گذاری</button></div>
  </form>
</div>
<div class="ad-panel">
  <div class="ad-panel-h"><h2>بررسی اتصال و کاتالوگ</h2></div>
  <p class="vpstc-intro">این بررسی سفارش یا پرداختی ایجاد نمی‌کند. برای بررسی همهٔ فرم‌های VPS، شناسهٔ دستهٔ VPS را وارد کنید؛ در بررسی کلی فقط چند فرم نمونه خوانده می‌شود. محاسبهٔ آزمایشی قیمت نیز سروری ایجاد نمی‌کند. تأیید اتصال به معنی آماده‌بودن فروش نیست.</p>
  <form class="vpstc-form" method="post" action="{{ route('admin.cloud.vpstc.preflight') }}">
    @csrf @if(!empty($vpstcInline))<input type="hidden" name="return_to" value="infra">@endif
    <div class="vpstc-grid">
      <label for="vpstc-category">شناسهٔ دسته (اختیاری)<input id="vpstc-category" name="category_id" inputmode="numeric" pattern="[1-9][0-9]{0,17}" maxlength="18" dir="ltr"></label>
      <label for="vpstc-product">شناسهٔ محصول (اختیاری)<input id="vpstc-product" name="product_id" inputmode="numeric" pattern="[1-9][0-9]{0,17}" maxlength="18" dir="ltr"></label>
    </div>
    <div><button type="submit" class="btn btn-glass">بررسی اتصال و کاتالوگ</button></div>
  </form>
</div>
@if(is_array(session('vpstc_report')))
  @php
    $report = session('vpstc_report');
    $blockerLabels = [
      'provider_lifecycle_adapter_not_implemented' => 'تکمیل اتصال سفارش و مدیریت سرویس',
      'catalog_mapping_not_verified' => 'تطبیق منابع، مکان، سیستم‌عامل، موجودی و تعرفهٔ حساب',
      'traffic_cost_control_not_verified' => 'تأیید سقف ترافیک و جلوگیری از هزینهٔ اضافه',
      'live_provisioning_not_verified' => 'تست واقعی تحویل سرور',
      'order_reconciliation_not_implemented' => 'تکمیل کنترل سفارش تکراری و پاسخ‌های نامشخص',
      'renewal_and_billing_cancellation_not_verified' => 'تست تمدید و تأیید قطع صورتحساب پس از لغو',
      'credentials_not_configured' => 'ورود اطلاعات اتصال نمایندگی',
      'invalid_resource_id' => 'اصلاح شناسهٔ دسته یا محصول',
      'account_read_failed' => 'بررسی اطلاعات ورود، مبدأ API و دسترسی حساب',
      'account_currency_not_verified' => 'تأیید واحد پول حساب',
      'category_read_failed' => 'بررسی دسترسی کاتالوگ',
      'category_schema_not_verified' => 'تأیید ساختار پاسخ دسته‌ها',
      'product_read_failed' => 'بررسی شناسه و ساختار محصولات',
      'order_form_schema_not_verified' => 'تأیید ساختار فرم و گزینه‌های سفارش',
      'category_list_incomplete' => 'فهرست دسته‌ها ناقص است',
      'duplicate_category_id' => 'شناسهٔ تکراری در دسته‌ها',
      'product_list_incomplete' => 'فهرست محصولات ناقص است',
      'product_schema_not_verified' => 'ساختار محصولات تأیید نشده است',
      'duplicate_product_id' => 'شناسهٔ تکراری در محصولات',
      'product_category_mismatch' => 'دستهٔ محصول با مسیر کاتالوگ همخوان نیست',
      'product_list_match_not_found' => 'محصول فرم سفارش در فهرست تأیید نشد',
      'payment_method_read_failed' => 'خواندن روش‌های پرداخت موفق نبود',
      'payment_fee_read_failed' => 'خواندن کارمزدهای پرداخت موفق نبود',
      'payment_fee_schema_not_verified' => 'ساختار کارمزدهای پرداخت تأیید نشده است',
      'payment_fees_literal_array' => 'API کارمزد پرداخت را به‌جای عدد، عبارت Array برگردانده است',
      'payment_fee_amount_not_verified' => 'مبلغ کارمزد پرداخت قابل محاسبه نیست',
      'payment_fees_not_verified' => 'کارمزد روش پرداخت حساب تأیید نشده است',
      'order_product_mismatch' => 'شناسهٔ محصول فرم سفارش با درخواست همخوان نیست',
      'order_monthly_price_not_verified' => 'قیمت ماهانهٔ فرم سفارش معتبر نیست',
      'monthly_price_mismatch' => 'مبلغ ماهانه در فهرست و فرم سفارش ناسازگار است؛ تبدیل ارز حدس زده نمی‌شود',
      'monthly_setup_mismatch' => 'هزینهٔ راه‌اندازی در فهرست و فرم سفارش ناسازگار است',
      'monthly_setup_not_verified' => 'هزینهٔ راه‌اندازی تأیید نشده است',
      'metered_not_fixed_monthly' => 'محصول مصرفی به‌عنوان تعرفهٔ ثابت ماهانه وارد نمی‌شود',
      'order_paytype_not_verified' => 'نوع صورتحساب فرم سفارش تأیید نشده است',
      'category_traversal_limit_reached' => 'پیمایش دسته‌ها به سقف ایمن بررسی رسید',
      'product_read_limit_reached' => 'خواندن دسته‌های محصول به سقف ایمن بررسی رسید',
      'product_row_limit_reached' => 'تعداد محصولات به سقف ایمن بررسی رسید',
      'candidate_preview_limit_reached' => 'پیش‌نمایش محصولات به سقف ایمن بررسی رسید',
      'order_form_read_limit_reached' => 'بررسی همهٔ فرم‌ها به سقف ایمن درخواست رسید',
      'quote_read_limit_reached' => 'محاسبهٔ قیمت همهٔ محصولات به سقف ایمن درخواست رسید',
      'credit_payment_method_not_verified' => 'روش پرداخت از اعتبار حساب به‌طور یکتا تأیید نشد',
      'quote_read_failed' => 'محاسبهٔ قیمت در تأمین‌کننده موفق نبود',
      'quote_schema_not_verified' => 'ساختار محاسبهٔ قیمت تأیید نشده است',
      'quote_arithmetic_mismatch' => 'جمع اجزای هزینه با مبلغ کامل محاسبهٔ قیمت همخوان نیست',
      'quote_raw_unit_not_verified' => 'واحد اعداد خام محاسبهٔ قیمت نیازمند تطبیق با سند حساب است',
    ];
  @endphp
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>نتیجهٔ بررسی</h2></div>
    <div class="vpstc-report">
      <p>{{ !empty($report['ok']) ? 'خواندن اطلاعات حساب و کاتالوگ موفق بود.' : (!empty($report['connection_ok']) ? 'اتصال حساب تأیید شد؛ بررسی کاتالوگ کامل نشد.' : 'اتصال هنوز تأیید نشده است.') }}</p>
      <p>این بررسی فروش را فعال نمی‌کند. مشاهدهٔ محصول به معنی تأیید قیمت، تحویل یا چرخهٔ صورتحساب نیست.</p>
      <dl class="vpstc-grid">
        <div><dt>زمان بررسی</dt><dd dir="ltr">{{ $report['checked_at'] ?? '—' }}</dd></div>
        <div><dt>واحد پول حساب</dt><dd><span dir="ltr">{{ $report['currency'] ?? 'تأیید نشده' }}</span>@if(($report['currency_source'] ?? '') === 'reviewed_account_contract')<br>با تطبیق سند مالی حساب؛ فیلد ارز API خالی است.@endif</dd></div>
        <div><dt>دسته‌های مشاهده‌شده</dt><dd>{{ $report['categories_seen'] ?? '—' }}</dd></div>
        <div><dt>محصولات مشاهده‌شده</dt><dd>{{ $report['products_seen'] ?? '—' }}</dd></div>
        <div><dt>تعرفه‌های ماهانهٔ مشاهده‌شده</dt><dd>{{ $report['monthly_offers_seen'] ?? '—' }}</dd></div>
        <div><dt>نامزدهای تعرفهٔ ثابت ماهانه</dt><dd>{{ $report['fixed_monthly_candidates_seen'] ?? '—' }}</dd></div>
        <div><dt>محصولات مصرفی مشاهده‌شده</dt><dd>{{ $report['metered_candidates_seen'] ?? '—' }}</dd></div>
        <div><dt>کارمزد پرداخت تأییدشده</dt><dd>{{ !empty($report['payment_fees_verified']) ? 'بله' : 'خیر؛ مبلغ نامعلوم صفر محسوب نمی‌شود.' }}</dd></div>
        <div><dt>پیمایش دسته‌های فرعی</dt><dd>{{ !empty($report['discovery_complete']) ? 'خواندن فهرست در محدودهٔ بررسی کامل شد.' : 'کامل نشده است.' }}</dd></div>
        <div><dt>فرم سفارش</dt><dd>{{ !empty($report['order_form_checked']) ? 'خوانده شد؛ سفارشی ایجاد نشد.' : 'بررسی نشده است.' }}</dd></div>
        @if(array_key_exists('order_forms_expected', $report))
          <div><dt>فرم‌های خوانده‌شده در محدودهٔ بررسی</dt><dd>{{ fa_num($report['order_forms_checked'] ?? 0) }} از {{ fa_num($report['order_forms_expected']) }} — {{ !empty($report['order_forms_complete']) ? 'کامل' : 'ناقص' }}</dd></div>
        @endif
        @if(($report['quotes_expected'] ?? 0) > 0)
          <div><dt>محاسبهٔ آزمایشی قیمت</dt><dd>{{ fa_num($report['quotes_checked'] ?? 0) }} از {{ fa_num($report['quotes_expected']) }}؛ تطبیق جمع هزینه: {{ fa_num($report['quotes_arithmetic_verified'] ?? 0) }}</dd></div>
          <div><dt>تطبیق قیمت با سند واحد پول حساب</dt><dd>{{ fa_num($report['money_quotes_verified'] ?? 0) }} از {{ fa_num($report['quotes_expected']) }}</dd></div>
        @endif
      </dl>
      @if(!empty($report['categories']))
        <div class="vpstc-table-wrap"><table class="vpstc-table">
          <thead><tr><th>دسته / شناسه</th><th>محصولات</th><th>تعرفهٔ ثابت با دورهٔ ماهانه</th><th>مصرفی با دورهٔ ماهانه</th></tr></thead>
          <tbody>@foreach(array_slice($report['categories'], 0, 64) as $category)
            @if(!empty($category['is_leaf']))<tr>
              <td><span dir="auto">{{ $category['name'] ?? '—' }}</span> / <span dir="ltr">{{ $category['id'] ?? '—' }}</span></td>
              <td>{{ !empty($category['product_read']) ? ($category['products_seen'] ?? 0) : 'خوانده نشده' }}</td>
              <td>{{ $category['fixed_monthly_candidates_seen'] ?? '—' }}</td>
              <td>{{ $category['metered_candidates_seen'] ?? '—' }}</td>
            </tr>@endif
          @endforeach</tbody>
        </table></div>
        <p>شمارش تعرفه‌ها فقط ساختار دورهٔ مشاهده‌شده را نشان می‌دهد؛ سرور اختصاصی، مرکز دادهٔ مجازی و کانتینر خودکار به VPS تبدیل نمی‌شوند.</p>
      @endif
      @php
        $candidatePreview = array_values(array_filter((array) ($report['candidates'] ?? []), fn ($row) =>
          is_array($row) && (($row['monthly_price_raw'] ?? null) !== null || !empty($row['order_checked']))));
        // Presentation order only; this is not product eligibility or automatic import.
        usort($candidatePreview, fn ($a, $b) =>
          (int) preg_match('/\b(vps|vds)\b/i', is_string($b['category_name'] ?? null) ? $b['category_name'] : '')
          <=> (int) preg_match('/\b(vps|vds)\b/i', is_string($a['category_name'] ?? null) ? $a['category_name'] : ''));
      @endphp
      @if(!empty($candidatePreview))
        <details open>
          <summary>محصولات مشاهده‌شده و تطبیق قیمت API</summary>
          <p>این جدول پیش‌نمایش فنی است و پلنی در فروشگاه ایجاد نمی‌کند. اعداد خام دو مسیر API بدون تبدیل نمایش داده می‌شوند؛ اختلاف اعداد به‌تنهایی واحد پول یا نرخ تبدیل را ثابت نمی‌کند. مقدار ماهانه در محصول مصرفی، قیمت ثابت ماهانه محسوب نمی‌شود. مبلغ قابل پرداختِ صفر نیز ممکن است از اعتبار حساب پوشش داده شده باشد و به معنی هزینهٔ صفر نیست.</p>
          <div class="vpstc-table-wrap"><table class="vpstc-table">
            <thead><tr><th>شناسه / محصول</th><th>دسته</th><th>نوع تعرفه</th><th>مبلغ خام فهرست API</th><th>مبلغ خام فرم سفارش API</th><th>تطبیق دو مبلغ</th><th>محاسبهٔ آزمایشی قیمت</th></tr></thead>
            <tbody>
              @foreach(array_slice($candidatePreview, 0, 40) as $candidate)
                @php($effectivePaytype = !empty($candidate['order_checked']) ? ($candidate['order_paytype'] ?? null) : ($candidate['paytype'] ?? null))
                <tr>
                  <td><span dir="ltr">{{ $candidate['id'] ?? '—' }}</span><br><span dir="auto">{{ $candidate['name'] ?? '—' }}</span></td>
                  <td dir="ltr">{{ $candidate['category_id'] ?? '—' }}</td>
                  <td>{{ $effectivePaytype === 'Regular' ? 'ثابت؛ نیازمند تأیید' : ($effectivePaytype === 'Metered' ? 'مصرفی؛ قابل فروش ثابت نیست' : 'تأیید نشده') }}</td>
                  <td dir="ltr">{{ $candidate['monthly_price_raw'] ?? '—' }}@if(!empty($candidate['raw_unit_verified']))<br>{{ $candidate['catalog_raw_unit'] ?? '' }}@endif</td>
                  <td dir="ltr">{{ $candidate['order_monthly_raw'] ?? '—' }}@if(!empty($candidate['raw_unit_verified']))<br>{{ $candidate['order_raw_unit'] ?? '' }}@endif</td>
                  <td class="{{ ($candidate['price_match'] ?? null) === false && empty($candidate['price_normalized_match']) ? 'vpstc-warning' : '' }}">{{ !empty($candidate['price_normalized_match']) ? 'با سند واحد پول حساب تطبیق شد؛ آمادگی تحویل جداگانه بررسی می‌شود.' : (($candidate['price_match'] ?? null) === true ? 'برابر؛ سایر شرایط بررسی شود' : (($candidate['price_match'] ?? null) === false ? 'اعداد متفاوت؛ سند واحد پول لازم است' : 'فرم خوانده نشده یا قیمت تأیید نشده')) }}</td>
                  <td>
                    @if(!empty($candidate['quote_checked']) && is_array($candidate['quote_diagnostics'] ?? null))
                      @php($diagnostic = $candidate['quote_diagnostics'])
                      هزینهٔ کامل خام: <span dir="ltr">{{ $diagnostic['gross_raw'] ?? '—' }}</span><br>
                      {{ !empty($diagnostic['arithmetic_match']) ? 'جمع اجزای هزینه همخوان است.' : 'جمع اجزای هزینه نیازمند بررسی است.' }}<br>
                      @if(($diagnostic['payment_coverage'] ?? '') === 'covered_by_credit')از اعتبار حساب پوشش داده می‌شود؛ رایگان نیست.<br>@endif
                      {{ !empty($diagnostic['unit_verified']) ? 'واحد پول تأیید شده است.' : 'واحد عدد خام تأیید نشده است.' }}
                      @if(is_array($candidate['money_evidence'] ?? null))
                        <br>هزینهٔ کامل با مالیات: <span dir="ltr">{{ $candidate['money_evidence']['total_amount'] ?? '—' }} {{ $candidate['money_evidence']['currency'] ?? '' }}</span>
                        @if(empty($candidate['money_evidence']['configuration_verified']))<br>این محاسبه مربوط به انتخاب‌های پیش‌فرض تأمین‌کننده است؛ همهٔ گزینه‌ها تأیید نشده‌اند.@endif
                      @endif
                    @else
                      محاسبه نشده
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
          <p>{{ fa_num(count($candidatePreview)) }} محصول دارای قیمت ماهانه یا فرم بررسی‌شده در پیش‌نمایش قرار دارد. محصولات بدون تعرفهٔ ماهانه در شمارش دسته‌ها حفظ شده‌اند.</p>
          @if(count($candidatePreview) > 40)<p>۴۰ مورد نخست نمایش داده شده است؛ نمایش محدود به معنی نبودن محصولات دیگر نیست.</p>@endif
        </details>
      @endif
      @if(!empty($report['truncated']))<p class="vpstc-warning">بررسی به سقف درخواست یا داده رسیده است؛ نتیجهٔ ناقص مبنای فعال‌سازی فروش قرار نمی‌گیرد.</p>@endif
      @if(!empty($report['blockers']))<p>موارد نیازمند تکمیل:</p><ul>@foreach($report['blockers'] as $blocker)<li>{{ $blockerLabels[$blocker] ?? 'بررسی فنی تکمیلی' }}</li>@endforeach</ul>@endif
    </div>
  </div>
@endif
