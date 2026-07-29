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

    <div style="grid-column:1/3;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>ذخیره</button>
    </div>
  </form>
  @endif
</div>

<style>
.set-f{ display:flex; flex-direction:column; gap:6px; font-size:13px; color:var(--muted) }
.set-f input{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:9px 12px; font:inherit }
@media(max-width:640px){ form{ grid-template-columns:1fr !important } .set-f[style*="1/3"]{ grid-column:1 !important } }
</style>

@endsection
