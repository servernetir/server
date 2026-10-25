<section class="ad-panel" id="payment-languages">
  <div class="ad-panel-h"><h2>روش‌های پرداخت بر اساس زبان سایت</h2></div>
  <p class="set-lead">برای هر زبان، روش‌های مجاز را تیک بزنید. این انتخاب روی نمایش و شروع پرداخت جدید اعمال می‌شود. فعال‌بودن و پیکربندی درگاه، ارز فاکتور و شرایط اسنپ‌پی همچنان لازم است.</p>
  <form method="post" action="/admin/settings/payment-visibility" style="padding:0 18px 18px">
    @csrf
    <div class="set-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr))">
      @foreach(\App\Services\Payment\PaymentVisibility::LOCALES as $locale => $language)
        <fieldset style="min-width:0;border:1px solid var(--line);border-radius:10px;padding:16px">
          <legend style="padding:0 8px">{{ $language }} <span dir="ltr">({{ $locale }})</span></legend>
          @foreach(\App\Services\Payment\PaymentVisibility::METHODS as $method => $label)
            <input type="hidden" name="visibility[{{ $locale }}][{{ $method }}]" value="0">
            <label style="display:flex;align-items:center;gap:10px;padding:8px 0;cursor:pointer">
              <input type="checkbox" name="visibility[{{ $locale }}][{{ $method }}]" value="1"
                     @checked((bool) old('visibility.'.$locale.'.'.$method, $paymentVisibility[$locale][$method]))>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </fieldset>
      @endforeach
    </div>
    <p class="set-lead" style="padding:14px 0">زبان، زبان فعال صفحهٔ سایت است؛ این تنظیم محدودیت جغرافیایی یا تابعیت نیست. خاموش‌کردن یک روش، پیگیری و تسویهٔ پرداخت‌های قبلی را متوقف نمی‌کند. با برداشتن همهٔ تیک‌های یک زبان، هیچ روش جدیدی برای دریافت وجه نمایش داده نمی‌شود؛ موجودی قبلی کیف پول و کوپن هدیه قابل استفاده می‌مانند.</p>
    <button class="btn btn-primary" type="submit">ذخیرهٔ روش‌های پرداخت هر زبان</button>
  </form>
</section>
