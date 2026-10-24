@extends('admin.layout')
@section('title', 'اتصال VPS.TC')
@section('nav_cloud', 'on')
@section('content')
<style>
.vpstc-intro{padding:0 18px 18px;color:var(--muted);line-height:2}.vpstc-form{padding:0 18px 20px;display:grid;gap:16px}.vpstc-form label{display:grid;gap:8px}.vpstc-form input{box-sizing:border-box;width:100%;padding:11px 13px;border:1px solid var(--line,#334155);border-radius:10px;background:var(--bg,#0f172a);color:inherit}.vpstc-form small{color:var(--muted);line-height:1.9}.vpstc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:16px}.vpstc-alert{padding:14px 18px;margin-bottom:16px;border:1px solid #64748b;border-radius:12px;line-height:2}.vpstc-report{padding:0 18px 18px;line-height:2}.vpstc-report dt{color:var(--muted)}.vpstc-report dd{margin:0 0 8px;overflow-wrap:anywhere}
</style>
<div class="vpstc-alert">اتصال نمایندگی VPS.TC — مرحلهٔ آماده‌سازی. سفارش و فروش عمومی هنوز فعال نیست.</div>
@if(session('success'))<div class="vpstc-alert" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="vpstc-alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="ad-panel">
  <div class="ad-panel-h"><h2>اطلاعات API نمایندگی</h2><a href="/admin/cloud" class="btn btn-glass">بازگشت به زیرساخت‌ها</a></div>
  <p class="vpstc-intro">مبدأ API را از پنل یا پشتیبانی رسمی VPS.TC دریافت کنید. نام کاربری و رمز به‌صورت رمزنگاری‌شده ذخیره می‌شوند و دوباره نمایش داده نمی‌شوند. برای تغییر مبدأ، هر دو را دوباره وارد کنید.</p>
  <form class="vpstc-form" method="post" action="{{ route('admin.cloud.vpstc.save') }}" autocomplete="off">
    @csrf
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
  <div class="ad-panel-h"><h2>بررسی اتصال و کاتالوگ</h2></div>
  <p class="vpstc-intro">این بررسی فقط اطلاعات را می‌خواند و سفارش یا پرداختی ایجاد نمی‌کند. شناسه‌های دسته و محصول اختیاری‌اند. تأیید اتصال به معنی آماده‌بودن فروش نیست.</p>
  <form class="vpstc-form" method="post" action="{{ route('admin.cloud.vpstc.preflight') }}">
    @csrf
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
    ];
  @endphp
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>نتیجهٔ بررسی</h2></div>
    <div class="vpstc-report">
      <p>{{ !empty($report['ok']) ? 'خواندن اطلاعات حساب و کاتالوگ موفق بود.' : (!empty($report['connection_ok']) ? 'اتصال حساب تأیید شد؛ بررسی کاتالوگ کامل نشد.' : 'اتصال هنوز تأیید نشده است.') }}</p>
      <p>فروش فعال نیست. این نتیجه به معنی کامل‌بودن کاتالوگ یا تأیید چرخهٔ سفارش نیست.</p>
      <dl class="vpstc-grid">
        <div><dt>زمان بررسی</dt><dd dir="ltr">{{ $report['checked_at'] ?? '—' }}</dd></div>
        <div><dt>واحد پول حساب</dt><dd dir="ltr">{{ $report['currency'] ?? 'تأیید نشده' }}</dd></div>
        <div><dt>دسته‌های مشاهده‌شده</dt><dd>{{ $report['categories_seen'] ?? '—' }}</dd></div>
        <div><dt>محصولات مشاهده‌شده</dt><dd>{{ $report['products_seen'] ?? '—' }}</dd></div>
        <div><dt>تعرفه‌های ماهانهٔ مشاهده‌شده</dt><dd>{{ $report['monthly_offers_seen'] ?? '—' }}</dd></div>
        <div><dt>فرم سفارش</dt><dd>{{ !empty($report['order_form_checked']) ? 'خوانده شد؛ سفارشی ایجاد نشد.' : 'بررسی نشده است.' }}</dd></div>
      </dl>
      @if(!empty($report['blockers']))<p>موارد نیازمند تکمیل:</p><ul>@foreach($report['blockers'] as $blocker)<li>{{ $blockerLabels[$blocker] ?? 'بررسی فنی تکمیلی' }}</li>@endforeach</ul>@endif
    </div>
  </div>
@endif
@endsection
