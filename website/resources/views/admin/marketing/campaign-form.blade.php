@extends('admin.layout')
@section('title','ساخت کمپین بازاریابی')
@section('nav_marketing','on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/campaigns.css') }}"><script defer src="{{ asset_ver('assets/js/campaign-wizard.js') }}"></script>@endsection
@section('content')
@php
  $v = fn($name,$fallback='') => old($name,$fallback);
  $r = $campaign?->rule ?? [];
  $a = $campaign?->audience ?? [];
  $auto = $campaign?->automation ?? [];
  $msg = $campaign?->message ?? [];
@endphp
<div class="cmp"><div class="cmp-head"><div><h1>{{ $campaign ? 'ویرایش پیش‌نویس' : 'کمپین تازه' }}</h1><p>هر پیشنهاد، قبل از فعال‌سازی، با مخاطبان و تعهد بودجه پیش‌نمایش می‌شود.</p></div><a href="/admin/marketing/campaigns">بازگشت</a></div>
@if($errors->any())<div class="cmp-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form class="cmp-form cmp-card" method="post" action="/admin/marketing/campaigns{{ $campaign ? '/'.$campaign->id : '' }}" data-campaign-wizard>
@csrf
<ol class="cmp-steps" data-steps><li>۱ · هدف</li><li>۲ · مخاطب</li><li>۳ · پیشنهاد</li><li>۴ · شرایط و بودجه</li><li>۵ · پیام و زمان</li><li>۶ · بازبینی</li></ol>
<fieldset><legend>هدف کمپین</legend>
<label>نام کمپین<input name="name" required maxlength="190" value="{{ $v('name',$campaign?->name) }}"></label>
<div class="cmp-grid">
<label>محرک<select name="trigger">@foreach(['manual'=>'صدور دستی / کد عمومی','birthday'=>'تولد','signup'=>'ثبت‌نام در ۲۴ ساعت اخیر','first_purchase'=>'اولین خرید در ۲۴ ساعت اخیر','inactive'=>'عدم خرید','scheduled'=>'کمپین زمان‌بندی‌شده'] as $key=>$text)<option value="{{ $key }}" @selected($v('trigger',$campaign?->trigger ?: 'manual') === $key)>{{ $text }}</option>@endforeach</select></label>
<label>منطقه زمانی<input name="timezone" required value="{{ $v('timezone',$campaign?->timezone ?: 'Asia/Tehran') }}" dir="ltr"></label>
<label>ارز و واحد مبالغ<select name="currency_code">@foreach(['IRT'=>'تومان صحیح (IRT)','IRR'=>'ریال صحیح (IRR)','EUR'=>'سنت یورو (EUR)','USD'=>'سنت دلار (USD)','TRY'=>'قروش لیر (TRY)'] as $key=>$text)<option value="{{ $key }}" @selected($v('currency_code',$campaign?->currency_code ?: 'IRT') === $key)>{{ $text }}</option>@endforeach</select></label>
</div><p>سالگرد تولد شمسی و میلادی مطابق ترجیح مشتری محاسبه می‌شود. اجرای عقب‌افتاده فقط در روز جاری جبران می‌شود.</p>
</fieldset>
<fieldset><legend>مخاطبان واجد شرایط</legend><div class="cmp-grid">
<label>شناسه مشتریان (اختیاری، جدا با ویرگول)<input name="customer_ids" value="{{ $v('customer_ids',implode(',',$a['customer_ids'] ?? [])) }}" dir="ltr"></label>
<label>تگ‌های لازم (جدا با ویرگول)<input name="tags" value="{{ $v('tags',implode(',',$a['tags'] ?? [])) }}"></label>
<label>وضعیت سرویس فعال<select name="active_service"><option value="">مهم نیست</option><option value="1" @selected($v('active_service',$a['active_service'] ?? '') === true)>دارد</option><option value="0" @selected($v('active_service',$a['active_service'] ?? '') === false)>ندارد</option></select></label>
<label>حداقل تعداد خرید<input type="number" name="min_purchases" min="0" value="{{ $v('min_purchases',$a['min_purchases'] ?? '') }}"></label>
<label>حداکثر تعداد خرید<input type="number" name="max_purchases" min="0" value="{{ $v('max_purchases',$a['max_purchases'] ?? '') }}"></label>
<label>سرویس خریداری‌شده (شناسه پلن، جدا با ویرگول)<input name="service_plans" value="{{ $v('service_plans',implode(',',$a['service_plans'] ?? [])) }}" dir="ltr"></label>
</div>
<div class="cmp-actions">@foreach(['fa'=>'فارسی','en'=>'English','tr'=>'Türkçe'] as $key=>$text)<label class="cmp-check"><input type="checkbox" name="locales[]" value="{{ $key }}" @checked(in_array($key,$v('locales',$r['locales'] ?? ['fa']),true))>{{ $text }}</label>@endforeach</div>
</fieldset>
<fieldset><legend>پیشنهاد و خدمات مجاز</legend>
<div class="cmp-grid">
<label>نوع تخفیف<select name="discount_type"><option value="percent" @selected($v('discount_type',$r['type'] ?? 'percent') === 'percent')>درصدی</option><option value="fixed" @selected($v('discount_type',$r['type'] ?? '') === 'fixed')>مبلغ ثابت</option></select></label>
<label>مقدار (درصد عادی یا مبلغ در واحد انتخاب‌شده)<input name="discount_value" required inputmode="decimal" value="{{ $v('discount_value',isset($r['value']) ? ($r['type'] === 'percent' ? $r['value']/100 : $r['value']) : '') }}"></label>
<label>سقف تخفیف هر سفارش<input type="number" name="max_discount" required min="1" value="{{ $v('max_discount',$r['max_discount'] ?? '') }}"></label>
<label>حداقل مبلغ اقلام مجاز<input type="number" name="min_purchase" required min="0" value="{{ $v('min_purchase',$r['min_purchase'] ?? 0) }}"></label>
<label>خانواده خدمات (جدا با ویرگول)<input name="families" placeholder="vps,shared,plesk" value="{{ $v('families',implode(',',$r['families'] ?? [])) }}" dir="ltr"></label>
<label>زیرساخت (جدا با ویرگول)<input name="providers" value="{{ $v('providers',implode(',',$r['providers'] ?? [])) }}" dir="ltr"></label>
<label>کشور (کد دوحرفی، جدا با ویرگول)<input name="countries" placeholder="DE,IR" value="{{ $v('countries',implode(',',$r['countries'] ?? [])) }}" dir="ltr"></label>
<label>پلن‌های مجاز (جدا با ویرگول)<input name="plans" value="{{ $v('plans',implode(',',$r['plans'] ?? [])) }}" dir="ltr"></label>
</div>
<label>محصولات مشخص (خالی یعنی همه محصولات واجد شرایط)<select name="product_ids[]" multiple size="6">@foreach($products as $product)<option value="{{ $product->id }}" @selected(in_array($product->id,array_map('intval',$v('product_ids',$r['product_ids'] ?? [])),true))>{{ $product->name }} · #{{ $product->id }}</option>@endforeach</select></label>
<div class="cmp-actions">@foreach(['purchase'=>'خرید سرویس','renewal'=>'تمدید'] as $key=>$text)<label class="cmp-check"><input type="checkbox" name="operations[]" value="{{ $key }}" @checked(in_array($key,$v('operations',$r['operations'] ?? ['purchase']),true))>{{ $text }}</label>@endforeach</div>
<p>مسیرهای متصل: فاکتور محصولات و سرور ابری دوره‌ای. شارژ کیف پول، مصرف ساعتی، GPU مصرفی، AI Gateway، دامنه و احراز هویت هنوز به این موتور متصل نیستند و تخفیف نمی‌گیرند. هزینه راه‌اندازی و ردیف‌های جانبی مشمول نیستند.</p>
<label class="cmp-check"><input type="checkbox" name="stackable" value="1" @checked($v('stackable',$r['stackable'] ?? false))>ترکیب با تخفیف‌های قبلی ردیف (یک کمپین در هر فاکتور)</label>
</fieldset>
<fieldset><legend>شرایط، ظرفیت و بودجه</legend><div class="cmp-grid">
<label>بودجه کل تخفیف<input type="number" name="budget" required min="1" value="{{ $v('budget',$campaign?->budget) }}"></label>
<label>حداکثر مصرف کل<input type="number" name="max_uses" required min="1" value="{{ $v('max_uses',$campaign?->max_uses ?: 1) }}"></label>
<label>حداکثر مصرف هر مشتری (کد عمومی)<input type="number" name="max_per_customer" required min="1" max="100" value="{{ $v('max_per_customer',$campaign?->max_per_customer ?: 1) }}"></label>
<label>سقف تخفیف هر مشتری<input type="number" name="per_customer_cap" required min="1" value="{{ $v('per_customer_cap',$r['per_customer_cap'] ?? '') }}"></label>
<label>حداقل حاشیه سود (۱۰۰ = یک درصد)<input type="number" name="min_margin_bp" required min="0" max="10000" value="{{ $v('min_margin_bp',$r['min_margin_bp'] ?? 0) }}"></label>
<label>اعتبار از زمان صدور (ساعت)<input type="number" name="valid_hours" required min="1" max="2160" value="{{ $v('valid_hours',$auto['valid_hours'] ?? 72) }}"></label>
<label>تاریخ شروع (شمسی)<x-admin-jdate name="start_date" :value="old('start_date',$campaign?->starts_at?->setTimezone($campaign->timezone)->toDateString())" /></label>
<label>ساعت شروع<input type="time" name="start_time" required value="{{ $v('start_time',$campaign?->starts_at?->setTimezone($campaign->timezone)->format('H:i') ?: '09:00') }}"></label>
<label>تاریخ پایان (شمسی)<x-admin-jdate name="end_date" :value="old('end_date',$campaign?->ends_at?->setTimezone($campaign->timezone)->toDateString())" /></label>
<label>ساعت پایان<input type="time" name="end_time" required value="{{ $v('end_time',$campaign?->ends_at?->setTimezone($campaign->timezone)->format('H:i') ?: '23:59') }}"></label>
</div>
<label class="cmp-check"><input type="checkbox" name="subsidy_approved" value="1" @checked($v('subsidy_approved',$r['subsidy_approved'] ?? false))>مجوز یارانه محدود در چارچوب بودجه، حتی با هزینه تأمین نامشخص یا حاشیه کمتر</label>
<label>دلیل مجوز یارانه<input name="subsidy_reason" maxlength="190" value="{{ $v('subsidy_reason',$r['subsidy_reason'] ?? '') }}"></label>
<p>هزینه نامشخص صفر نیست. بدون هزینه تأمین معتبر یا مجوز یارانه، تخفیف اعمال نمی‌شود. کد شخصی هنگام صدور به‌اندازه سقف تخفیف از بودجه تعهد می‌گیرد.</p>
</fieldset>
<fieldset><legend>پیام و زمان‌بندی</legend><div class="cmp-grid">
<label>نام خدمت در پیام<input name="service_name" required maxlength="100" value="{{ $v('service_name',$msg['service_name'] ?? '') }}"></label>
<label>صفحه مقصد داخل کنسول<input name="landing_path" required value="{{ $v('landing_path',$msg['landing_path'] ?? '/account/cloud-store') }}" dir="ltr"></label>
<label>ساعت صدور روزانه<input type="time" name="at" required value="{{ $v('at',$auto['at'] ?? '09:00') }}"></label>
<label>یادآوری چند ساعت قبل از انقضا (اختیاری)<input type="number" name="reminder_hours" min="1" value="{{ $v('reminder_hours',$auto['reminder_hours'] ?? '') }}"></label>
<label>عدم خرید (روز)<input type="number" name="inactive_days" min="1" value="{{ $v('inactive_days',$auto['inactive_days'] ?? 30) }}"></label>
<label>حداکثر تعداد پیامک کمپین<input type="number" name="message_limit" required min="0" value="{{ $v('message_limit',$msg['limit'] ?? 0) }}"></label>
<label>بودجه پیامک کمپین (تومان)<input type="number" name="message_budget" required min="0" value="{{ $v('message_budget',$msg['budget'] ?? 0) }}"></label>
</div>
<label class="cmp-check"><input type="checkbox" name="message_enabled" value="1" @checked($v('message_enabled',$msg['enabled'] ?? false))>صف‌کردن پیامک پیشنهاد برای مشتریان دارای رضایت روشن</label>
<label>متن پیامک<textarea name="template" maxlength="1000">{{ $v('template',$msg['template'] ?? '') }}</textarea></label>
<p dir="ltr">@verbatim{{first_name}} · {{discount_label}} · {{service_name}} · {{coupon_code}} · {{expires_at_local}} · {{redeem_url}} · {{unsubscribe_instructions}}@endverbatim</p>
<p>شش متغیر شرایط، خدمت، کد، انقضا، لینک و لغو دریافت در پیام فعال الزامی‌اند. پیامک تا تأیید سیاست ارسال و رفع توقف پیام‌های کمپین ارسال نمی‌شود.</p>
</fieldset>
<fieldset><legend>بازبینی و پیش‌نمایش</legend><p>با ثبت، کمپین به‌صورت پیش‌نویس ذخیره می‌شود. در صفحه بعد تعداد مخاطبان، نمونه پیام، تعداد بخش‌های پیامک و حداکثر تعهد تخفیف را می‌بینید. فعال‌سازی مرحله مستقلی است.</p><div class="cmp-notice">در این مرحله هیچ کد، تعهد تخفیف یا ارسال پیامک واقعی ایجاد نمی‌شود.</div></fieldset>
<div class="cmp-actions"><button class="ad-btn" type="button" data-prev>مرحله قبل</button><button class="ad-btn primary" type="button" data-next>مرحله بعد</button><button class="ad-btn primary" type="submit" data-save>ثبت پیش‌نویس و پیش‌نمایش</button></div>
</form></div>
@endsection
