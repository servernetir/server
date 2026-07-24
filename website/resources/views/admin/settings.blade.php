@extends('admin.layout')
@section('title', 'تنظیمات')
@section('nav_settings', 'on')
@section('content')


<div class="ad-panel">
  <div class="ad-panel-h"><h2>حساب بانکی شرکت — برای «واریز به حساب»</h2></div>
  <p style="padding:0 18px;color:#96a3ba;font-size:13.5px;line-height:1.9">
    این مشخصات به مشتری نشان داده می‌شود تا واریز کند. تا وقتی شبا یا شمارهٔ حساب را وارد نکنید،
    گزینهٔ «واریز به حساب» در صفحهٔ پرداخت نمایش داده نمی‌شود.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول تنظیمات روی این سرور هنوز ساخته نشده. پس از مهاجرت فعال می‌شود.</p>
  @else
  <form method="post" action="/admin/settings" style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:720px">
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
    <div style="grid-column:1/3;display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>ذخیره</button>
    </div>
  </form>
  @endif
</div>

<style>
.set-f{ display:flex; flex-direction:column; gap:6px; font-size:13px; color:#96a3ba }
.set-f input{ background:#0f1522; border:1px solid #1e2637; border-radius:9px; color:#e7edf7; padding:9px 12px; font:inherit }
@media(max-width:640px){ form{ grid-template-columns:1fr !important } .set-f[style*="1/3"]{ grid-column:1 !important } }
</style>

@endsection
