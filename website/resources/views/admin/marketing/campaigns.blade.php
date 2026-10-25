@extends('admin.layout')
@section('title','کمپین‌ها و تخفیف‌ها')
@section('nav_marketing','on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/campaigns.css') }}">@endsection
@section('content')
<div class="cmp"><div class="cmp-head"><div><h1>کمپین‌ها و تخفیف‌ها</h1><p>پیشنهاد هدفمند، بودجه مشخص و نتیجه قابل اندازه‌گیری</p></div><div class="cmp-links"><a href="/admin/marketing">بازاریابی هوشمند</a>@if($ready)<a class="ad-btn primary" href="/admin/marketing/campaigns/create">ساخت کمپین</a>@endif</div></div>
@if(session('ok'))<p class="cmp-notice" role="status">{{ session('ok') }}</p>@endif
@if($errors->any())<div class="cmp-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(!$ready)<p class="cmp-notice">مهاجرت موتور کمپین روی این نصب اجرا نشده است.</p>
@else
@if(!in_array($smsDriver,config('marketing.sms_drivers'),true))
<p class="cmp-notice" role="status">درایور فعلی پیامک «{{ $smsDriver }}» ارسال تبلیغاتی متن آزاد را در این موتور پشتیبانی نمی‌کند. صدور و نمایش پیشنهادها مستقل است؛ پیامک‌های کمپین تا آماده‌شدن مسیر مجاز ارسال نمی‌شوند.</p>
@endif
<div class="cmp-grid">
<div class="cmp-card"><h2>آخرین اجرای زمان‌بند</h2><p dir="ltr">{{ $lastTick ?: 'ثبت نشده' }}</p></div>
<div class="cmp-card"><h2>پیام‌های با نتیجه نامشخص</h2><p class="cmp-value">{{ $unknown }}</p></div>
<div class="cmp-card"><h2>پرداخت‌های نیازمند تطبیق</h2><p class="cmp-value">{{ $reconciliation }}</p></div>
</div>
<div class="cmp-card cmp-table"><table><thead><tr><th>کمپین</th><th>وضعیت / محرک</th><th>بودجه / تعهد / مصرف</th><th>پیامک</th><th></th></tr></thead><tbody>
@forelse($campaigns as $campaign)<tr><td>{{ $campaign->name }}</td><td>{{ $campaign->status }} / {{ $campaign->trigger }}</td><td>{{ number_format($campaign->budget) }} / {{ number_format($campaign->held) }} / {{ number_format($campaign->spent) }} {{ $campaign->currency_code }}</td><td>{{ $campaign->messages_paused ? 'متوقف' : 'مجاز در چارچوب سیاست ارسال' }}</td><td><a href="/admin/marketing/campaigns/{{ $campaign->id }}">جزئیات و پیش‌نمایش</a></td></tr>
@empty<tr><td colspan="5">هنوز کمپینی ساخته نشده است.</td></tr>@endforelse
</tbody></table></div>{{ $campaigns->links() }}
@can('marketing.send')
<details class="cmp-card"><summary>سیاست پیامک بازاریابی و توقف اضطراری</summary>
<p>این تنظیمات فقط پیام‌های تبلیغاتی را کنترل می‌کنند. فعال‌سازی، نیازمند بررسی کشور مخاطب، رضایت ثبت‌شده، تعرفه معتبر و درایور مستقیم دارای پشتیبانی متن آزاد است. صف و رله الگوهای خدماتی برای تبلیغات استفاده نمی‌شوند.</p>
<form method="post" action="/admin/marketing/campaigns/sms-policy" class="cmp-form">@csrf
<div class="cmp-grid">
<label>ارسال تبلیغاتی<select name="enabled"><option value="0">متوقف</option><option value="1" @selected(\App\Models\Setting::get('marketing_sms_enabled') === '1')>فعال</option></select></label>
<label>بررسی شرایط ارسال ایران<select name="country_reviewed"><option value="0">تأیید نشده</option><option value="1" @selected(\App\Models\Setting::get('marketing_sms_country_reviewed') === '1')>توسط مدیر تأیید شده</option></select></label>
<label>هزینه هر بخش پیامک (تومان)<input type="number" name="segment_cost_irt" min="1" value="{{ \App\Models\Setting::get('marketing_sms_segment_cost_irt') }}"></label>
<label>بودجه روزانه پیامک (تومان)<input type="number" name="daily_budget" min="0" required value="{{ \App\Models\Setting::get('marketing_sms_daily_budget','0') }}"></label>
<label>سقف پیام روزانه<input type="number" name="daily_limit" min="0" max="10000" required value="{{ \App\Models\Setting::get('marketing_sms_daily_limit','100') }}"></label>
</div><label>دلیل تغییر<input name="reason" required maxlength="190"></label>
<button class="ad-btn" type="submit">ثبت سیاست ارسال</button>
</form></details>
@endcan
@if(auth('web')->user()?->isAdmin())
<details class="cmp-card"><summary>دسترسی‌های تیم بازاریابی</summary><form method="post" action="/admin/marketing/campaigns/permissions" class="cmp-form">@csrf
<label>کارشناس پشتیبانی<select name="user_id" required>@foreach(\App\Models\User::where('role','support')->get(['id','name']) as $staff)<option value="{{ $staff->id }}">{{ $staff->name }}</option>@endforeach</select></label>
<div class="cmp-actions">@foreach(['manage'=>'ساخت و ویرایش','publish'=>'فعال‌سازی و صدور','send'=>'ارسال پیام','finance'=>'گزارش مالی'] as $key=>$label)<label class="cmp-check"><input type="checkbox" name="abilities[]" value="{{ $key }}">{{ $label }}</label>@endforeach</div>
<label>دلیل تغییر<input name="reason" required maxlength="190"></label><button class="ad-btn" type="submit">جایگزینی دسترسی‌ها</button><p>مدیر دسترسی کامل دارد. انتخاب خالی، تمام دسترسی‌های اختصاصی بازاریابی این کارشناس را برمی‌دارد.</p>
</form></details>@endif
@endif</div>
@endsection
