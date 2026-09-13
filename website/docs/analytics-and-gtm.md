# مستندات رویدادهای تحلیلی و گوگل تگ منیجر (GTM & GA4 DataLayer)

این سند راهنمای جامع ساختار کدهای رهگیری تحلیلی، دیتالایر (DataLayer) استاندارد تجارت الکترونیک گوگل آنالیتیکس ۴ (GA4 E-commerce) و نحوه گسترش آن برای رویدادهای آینده در پلتفرم سرورنت است.

---

## ۱. معماری و ساختار فایل‌ها

کدهای ردیابی در پروژه به ۴ بخش تفکیک شده‌اند:

1. **کانفیگ سرور (`config/services.php`):**
   - کلید `services.gtm.id` حاوی کانتینر آیدی GTM است و مقدار پیش‌فرض آن `GTM-MRSC7BF7` می‌باشد.
   - برای تغییر در محیط‌های مختلف می‌توان مقدار `GTM_CONTAINER_ID` را در فایل `.env` مقداردهی کرد.

2. **اسکریپت‌های کلاینت (Views & Partials):**
   - `resources/views/partials/gtm-head.blade.php`: لود ایمن اسکریپت GTM در تگ `<head>` به همراه تزریق آبجکت `window.dataLayer` پیش از لود کانتینر، و بررسی سشن فلش‌شده لاراول (`session('dataLayerPurchase')`).
   - `resources/views/partials/gtm-body.blade.php`: تگ استاندارد `<noscript>` برای مرورگرهای فاقد جاوااسکریپت بلافاصله بعد از تگ `<body>`.
   - لایوت عمومی سایت در `resources/views/layouts/site.blade.php` هر دو پارشیال فوق را در جایگاه استاندارد خود فراخوانی می‌کند.

3. **سرویس دیتالایر بک‌اند (`app/Services/Analytics/DataLayerService.php`):**
   - متد استاتیک `DataLayerService::flashPurchase($payment)` وظیفه استانداردسازی اطلاعات خرید و ذخیره موقت در Flash Session (`dataLayerPurchase`) را برعهده دارد.
   - متد کمکی `buildPurchasePayload($payment)` ساختار استاندارد رویداد خرید GA4 را بر اساس اطلاعات پرداخت، فاکتور و آیتم‌های سبد خرید تولید می‌کند.

4. **تست‌های واحد و اعتبارسنجی:**
   - `tests/Feature/GtmAndAnalyticsTest.php` وجود و صحت تزریق GTM و ایونت‌های خرید را در لایوت و کنترلر اعتبارسنجی می‌کند.

---

## ۲. مشخصات رویداد خرید (`purchase`)

فرمت دیتالایر ارسال شده مطابق با مستندات رسمی Google Analytics 4 Ecommerce است:

```javascript
window.dataLayer.push({
    event: 'purchase',
    ecommerce: {
        transaction_id: 'PAY-1403-XXXXX', // یا شناسه پرداخت / تراکنش
        value: 1250000,                  // مبلغ پرداختی فاکتور (تومان/ریال)
        currency: 'IRT',                 // واحد پولی سیستم
        tax: 0,
        shipping: 0,
        items: [
            {
                item_id: 'SRV-102',
                item_name: 'سرور مجازی آلمان - پلن پرسرعت NVMe',
                price: 1250000,
                quantity: 1,
                item_category: 'vps'
            }
        ]
    }
});
```

---

## ۳. هوک‌های فعال در چرخه خرید (Touchpoints)

رویداد خرید در دو نقطه حساس از `app/Http/Controllers/Account/PaymentController.php` هوک شده است:
1. **پس از پرداخت موفق از طریق درگاه بانکی:** در اکشن بازگشت از درگاه (`settle`) پس از تایید موفق فاکتور:
   ```php
   if ($outcome->ok && ! $outcome->alreadySettled && $outcome->payment !== null) {
       DataLayerService::flashPurchase($outcome->payment);
   }
   ```
2. **پس از پرداخت از اعتبار کیف پول (Credit Pay):** پس از کسر موجودی و تسویه فاکتور:
   ```php
   $latestPayment = $invoice->payments()->latest('id')->first();
   if ($latestPayment !== null) {
       DataLayerService::flashPurchase($latestPayment);
   }
   ```

> ⚠️ **نکته مهم برای توسعه‌دهندگان:**  
> هنگام ریفکتور، تغییر نام متدهای کنترلر پرداخت، یا افزودن روش‌های پرداخت جدید (مثل کیف پول رمزارز، کارت به کارت یا درگاه‌های ارزی جدید)، حتماً فراخوانی `DataLayerService::flashPurchase($payment)` را پس از تسویه موفق اعمال کنید تا گزارش‌های مالی مارکتینگ بدون قطعی باقی بمانند.

---

## ۴. راهنمای افزودن رویدادهای آینده (Future Events)

برای افزودن رویدادهای جدید GA4 در توسعه‌های بعدی، الگوی معماری زیر را دنبال کنید:

### ۴.۱. افزودن رویداد در سمت بک‌اند (فلش به سشن بعدی)
در صورتی که رویدادی پس از یک عملیات ریدایرکت رخ می‌دهد (مانند ثبت‌نام موفق `sign_up` یا ورود `login`):
1. متدی جدید به `DataLayerService.php` اضافه کنید:
   ```php
   public static function flashSignUp(User $user): void
   {
       session()->flash('dataLayerSignUp', [
           'event' => 'sign_up',
           'method' => 'mobile_otp',
           'user_id' => (string) $user->id,
       ]);
   }
   ```
2. در `resources/views/partials/gtm-head.blade.php` اسکریپت رندر آن را اضافه کنید:
   ```blade
   @if(session()->has('dataLayerSignUp'))
   <script>
       window.dataLayer = window.dataLayer || [];
       window.dataLayer.push({!! json_encode(session('dataLayerSignUp'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!});
   </script>
   @endif
   ```

### ۴.۲. افزودن رویداد در سمت کلاینت (جاوااسکریپت و تعاملی)
برای ایونت‌هایی که نیازی به ریدایرکت ندارند (مانند کلیک روی افزودن به سبد خرید `add_to_cart`، کپی آی‌پی سرور، یا ارسال تیکت):
- مستقیماً در اسکریپت دکمه یا کامپوننت فرانت‌اند رویداد را روی `dataLayer` پوش کنید:
  ```javascript
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({
      event: 'add_to_cart',
      ecommerce: {
          currency: 'IRT',
          value: planPrice,
          items: [{
              item_id: planId,
              item_name: planTitle,
              price: planPrice,
              quantity: 1
          }]
      }
  });
  ```
