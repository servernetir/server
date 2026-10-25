@extends('admin.layout')
@section('title', 'سفارش‌های OVHcloud')
@section('nav_cloud', 'on')
@section('content')
<div class="ad-panel">
  <div class="ad-panel-h"><h2>خرید و تحویل OVHcloud</h2><a href="/admin/cloud">بازگشت به زیرساخت‌ها</a></div>
  <div style="padding:18px;line-height:2">
    <p>کلید API: <b>{{ $configured ? 'ذخیره‌شده' : 'تنظیم نشده' }}</b> · اتصال آخر: <b>{{ $connection }}</b> {{ $checkedAt }}<br>
      خرید خودکار: <b>{{ $enabled ? 'فعال' : 'خاموش' }}</b> · دروازهٔ انتشار: {{ $deploymentGate ? 'باز' : 'بسته' }}<br>
      موجودی قابل خرج: تأیید نشده؛ روش پرداخت پیش‌فرض {{ \App\Models\Setting::get('ovh_default_payment_present') === '1' ? 'دیده شده است' : 'هنوز تأیید نشده است' }}.
    </p>
    <p>خرید اولیهٔ VPS ماهانه، Linux استاندارد Ubuntu/Debian/AlmaLinux/Rocky، بدون افزونه. هزینه شامل مالیات تأمین‌کننده و کارمزد است؛ سود نمایش‌داده‌شده برآوردی است و جای صورتحساب نهایی تأمین‌کننده را نمی‌گیرد.</p>
    <form method="post" action="/admin/cloud/ovh/check">@csrf<button class="ad-btn">بررسی اتصال و روش پرداخت (فقط خواندنی)</button></form>
    <form method="post" action="/admin/cloud/ovh/catalogue">@csrf<button class="ad-btn">همگام‌سازی فقط کاتالوگ OVH (بدون خرید)</button></form>
    <p>بعد از تغییر حاشیه یا کارمزد، کاتالوگ را تازه کنید؛ تا آن زمان فروش تازهٔ این زیرساخت بسته است.</p>
    <form method="post" action="/admin/cloud/ovh/settings" style="display:grid;gap:10px;margin-top:18px">@csrf
      <label>سقف هر سفارش (سنتِ ارز حساب)<input name="max_order_minor" type="number" min="1" max="1000000" required value="{{ \App\Models\Setting::get('ovh_max_order_minor', '0') }}"></label>
      <label>حاشیه سود نسبت به بهای تمام‌شده (%)<input name="margin_pct" type="number" min="0" max="500" step="0.01" required value="{{ app(\App\Services\Cloud\CloudPricing::class)->marginPct('ovh') }}"></label>
      <label><input type="checkbox" name="previous_checked" value="1" @checked(\App\Models\Setting::get('ovh_previous_order_checked') === '1')> پرداخت، برگشت اعتبار و سفارش خارجی تست قبلی را بررسی و تطبیق داده‌ام.</label>
      <label><input type="checkbox" name="purchase_enabled" value="1" @checked($enabled)> خرید خودکار فعال باشد (دروازهٔ انتشار نیز باید باز باشد).</label>
      <input name="reason" required minlength="10" maxlength="300" placeholder="دلیل تغییر و مرجع بررسی سفارش قبلی">
      <button class="ad-btn">ثبت تنظیمات با تاریخچه</button>
    </form>
  </div>
</div>
@if(! $ready)<div class="ad-panel" style="padding:18px">دفتر سفارش هنوز مهاجرت نشده؛ خرید خاموش می‌ماند.</div>@else
<div class="ad-panel" style="overflow:auto">
  <div class="ad-panel-h"><h3>دفتر پایدار سفارش‌ها</h3></div>
  <table class="ad-table"><thead><tr><th>سرویس / دفتر</th><th>مرحله</th><th>سبد / سفارش / منبع</th><th>خرید / فروش خالص</th><th>سود برآوردی</th><th>مالی / پیگیری</th></tr></thead><tbody>
  @forelse($rows as $o)<tr>
    <td><a href="/admin/cloud/ovh?order={{ $o->id }}">#{{ $o->service_id }} / #{{ $o->id }}</a></td>
    <td>{{ $o->phase }}<br><small>{{ $o->error_code }}</small></td>
    <td dir="ltr">{{ $o->cart_id ?: '—' }}<br>{{ $o->order_id ?: '—' }}<br>{{ $o->server_ref ?: '—' }}</td>
    <td dir="ltr">{{ $o->cost_minor !== null ? number_format($o->cost_minor / 100, 2).' '.$o->currency : '—' }}<br>{{ $o->revenue_minor !== null ? number_format($o->revenue_minor).' minor '.$o->revenue_currency : '—' }}</td>
    <td>{{ $o->profit_irt !== null ? number_format($o->profit_irt).' تومان' : '—' }}</td>
    <td>{{ $o->financial_state }}<br>{{ $o->attempts }} تلاش · {{ $o->next_attempt_at ? stime($o->next_attempt_at) : '—' }}</td>
  </tr>@empty<tr><td colspan="6">سفارشی در دفتر تازه ثبت نشده است. سفارش‌های قدیمی باید جداگانه بررسی شوند.</td></tr>@endforelse
  </tbody></table>{{ $rows->links() }}
</div>
@if($selected)
<div class="ad-panel" style="padding:18px;line-height:2">
  <h3>دفتر #{{ $selected->id }} · سرویس #{{ $selected->service_id }}</h3>
  <p>{{ $selected->error_message }}</p>
  @foreach(['reconcile' => 'پیگیری و تطبیق خواندنی', 'retry' => 'تلاش ایمن پیش از خرید', 'resume' => 'ادامهٔ صف برای همین سفارش', 'pause' => 'توقف پیگیری خودکار'] as $act => $label)
  <form method="post" action="/admin/cloud/ovh/orders/{{ $selected->id }}" style="display:flex;gap:8px;margin:8px 0;flex-wrap:wrap">@csrf
    <input type="hidden" name="action" value="{{ $act }}"><input name="reason" required minlength="10" maxlength="300" placeholder="دلیل و مرجع بررسی">
    <button class="ad-btn" @disabled($act === 'retry' && ! $selected->canRetry())>{{ $label }}</button>
  </form>@endforeach
  @if($selected->checkout_sent_at && ! $selected->order_id)
  <form method="post" action="/admin/cloud/ovh/orders/{{ $selected->id }}">@csrf
    <input type="hidden" name="action" value="link"><input type="number" name="external_order_id" min="1" required placeholder="شماره سفارش واقعی OVH">
    <input name="reason" required minlength="10" maxlength="300" placeholder="مدرک تطبیق سبد، مبلغ و مشخصات">
    <label><input type="checkbox" name="verified_cart" value="1" required> تأیید می‌کنم این شماره سفارش دقیقاً از سبد ثبت‌شدهٔ همین دفتر ایجاد شده است.</label>
    <button class="ad-btn">ثبت ارتباط برای بررسی (بدون پرداخت)</button>
  </form>@endif
  @if(! $selected->order_id && ! $selected->payment_sent_at && ! $selected->server_ref && $selected->phase !== 'refunded')
  <form method="post" action="/admin/cloud/ovh/orders/{{ $selected->id }}" style="margin-top:14px">@csrf
    <input type="hidden" name="action" value="reject_refund"><input name="reason" required minlength="10" maxlength="300" placeholder="مدرک مستقل نبود سفارش، پرداخت و سرور در OVH">
    <label><input type="checkbox" name="verified_no_external_order" value="1" required> نبود سفارش/پرداخت/منبع خارجی را مستقلاً بررسی کرده‌ام.</label>
    <button class="ad-btn">لغو و برگشت وجه از مسیر مالی موجود</button>
  </form>@endif
  <table class="ad-table"><thead><tr><th>زمان</th><th>عملیات</th><th>مرحله</th><th>مدیر</th><th>توضیح</th></tr></thead><tbody>
  @foreach($selected->events as $event)<tr><td>{{ stime($event->created_at) }}</td><td>{{ $event->event }}</td><td>{{ $event->phase }}</td><td>{{ $event->actor_id ?: 'سیستم' }}</td><td>{{ $event->message }}</td></tr>@endforeach
  </tbody></table>
</div>@endif
@endif
@if($legacy->isNotEmpty())<div class="ad-panel" style="padding:18px"><h3>سرویس‌های پیشین نیازمند بررسی</h3>
  @foreach($legacy as $s)<p><a href="/admin/customers/{{ $s->customer_id }}">سرویس #{{ $s->id }}</a> · {{ $s->status }} · {{ $s->provision_status }} · {{ $s->provision_error }}</p>@endforeach
  <p>وجود خطا به‌تنهایی نبود سفارش خارجی یا برگشت وجه را ثابت نمی‌کند.</p>
</div>@endif
@endsection
