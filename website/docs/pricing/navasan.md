# جریان نرخ ارز و قیمت‌گذاری نوسان

منبع نرخ خرید در ServerNet فقط جدول «نرخ فروش حواله و ارز دیجیتال» در `navasan.net` است. سه ردیف مصرف می‌شوند:

| ارز | ردیف نوسان | مصرف اصلی |
|---|---|---|
| USD | دلار آمریکا شخص | زیرساخت‌های دلاری، GPU و پرداخت |
| EUR | یورو حواله | محصولات پایه‌یورویی، هاست، سرور و دامنه‌های یورویی |
| USDT | تتر | پرداخت رمزارزی و قیمت‌های تامین‌کننده بر حسب USDT |

فرمان `php artisan fx:dollar` هر سه نرخ را در یک دریافت می‌خواند، منبع و زمان را اعتبارسنجی می‌کند و فقط وقتی هر سه معتبر باشند آن‌ها را با `source=navasan.net` ذخیره می‌کند. درخواست‌های وب هیچ تماس مستقیمی با نوسان ندارند و فقط نرخ تاییدشدهٔ ذخیره‌شده را از `ExchangeRate::current()` می‌خوانند.

## زمان‌بندی و ترتیب اجرا

Laravel Scheduler فرمان `fx:dollar` را با `everySixHours()` اجرا می‌کند. با زمان‌بندی UTC سرور، اجراها حدود ۰۰:۰۰، ۰۶:۰۰، ۱۲:۰۰ و ۱۸:۰۰ UTC، برابر با ۰۳:۳۰، ۰۹:۳۰، ۱۵:۳۰ و ۲۱:۳۰ تهران هستند. کرون سیستم باید `php artisan schedule:run` را هر دقیقه اجرا کند.

ترتیب هر نوبت:

1. `ExchangeRate::refreshAll()` نرخ USD/EUR/USDT را اتمیک منتشر می‌کند.
2. `CloudCatalogSync::reprice()` قیمت پلن‌های ابری را با هزینهٔ تامین‌کننده و نرخ همان نوبت بازسازی می‌کند.
3. `CloudPricingSnapshot` تنها پس از پایان کامل بازقیمت‌گذاری منتشر می‌شود. اگر نرخ عوض شده باشد ولی بازقیمت‌گذاری شکست بخورد، پلن ابری با قیمت قدیمی قابل فروش نیست.
4. `domains:price-book --strict` تمام صفحات کاتالوگ Openprovider را می‌خواند و یک snapshot کامل و اتمیک از همهٔ پسوندهای قابل فروش منتشر می‌کند.
5. کش صفحات عمومی پاک می‌شود تا قیمت نمایشی همان نرخ تازه را نشان دهد.

## قرارداد قیمت محصولات

محصولات معمولی قیمت تامین را در `products.base_price_eur` و هزینه راه‌اندازی را در `products.base_setup_eur` نگه می‌دارند. `ProductPricing` هنگام نمایش، سفارش و صدور صورتحساب مقدار یورویی را با نرخ EUR ذخیره‌شدهٔ نوسان و حاشیه سود تبدیل می‌کند. ستون‌های قدیمی تومانی منبع فروش نیستند.

مهاجرت `2026_10_11_120100_add_native_eur_product_prices.php` فقط یک بار داده‌های قدیمی را با `pricing_baseline_rate` به یورو تبدیل می‌کند. پس از مهاجرت، نرخ مبنای قدیمی و نرخ‌های دستی در هیچ محاسبهٔ اجرایی خوانده نمی‌شوند. نبود نرخ معتبر یعنی قیمت در دسترس نیست؛ سیستم قیمت صفر، پیش‌فرض یا تخمینی نمی‌فروشد.

قیمت قطعات و سرورهای فیزیکی نیز از مبلغ یورویی می‌آید. کاتالوگ دامنه مبلغ هر عملیات و ارز همان عملیات را از Openprovider نگه می‌دارد و با USD/EUR/USDT نوسان تبدیل می‌کند. خواننده‌های عمومی فقط snapshot را می‌خوانند و هیچ fallback دستی یا قیمت config ندارند.

## راهنمای توسعه

- برای تبدیل ارز از `ExchangeRate`, `ProductPricing`, `CloudPricing` یا `TldPriceBook` استفاده کنید؛ در controller یا Blade فرمول موازی نسازید.
- در مسیر درخواست HTTP نرخ را refresh نکنید. دریافت شبکه فقط در فرمان زمان‌بندی‌شده انجام می‌شود.
- `pricing_baseline_rate` فقط متعلق به مهاجرت داده است. کلیدهای `pricing_rate_override`, `pricing_usd_rate_override` و `pricing_usdt_rate_override` منبع نرخ نیستند.
- اگر نرخ، هزینهٔ تامین‌کننده یا snapshot معتبر موجود نیست، فروش را fail closed کنید و پیام «قیمت موقتاً در دسترس نیست» نشان دهید.
- قیمت نمایشی و مبلغ checkout باید از یک متد نهایی بیایند. برای محصولات از `Product::effectivePrice()` و برای دامنه از snapshot `TldPriceBook` استفاده کنید.

فایل‌های اصلی: `app/Services/ExchangeRate.php`, `app/Console/Commands/FetchDollar.php`, `app/Services/ProductPricing.php`, `app/Services/Cloud/CloudCatalogRepricer.php`, `app/Services/Cloud/CloudPricingSnapshot.php`, `app/Services/Domain/TldPriceBook.php` و `routes/console.php`.

## بررسی عملیاتی

```bash
php artisan fx:dollar --show
php artisan schedule:list
php artisan domains:price-book --strict
php artisan fx:dollar
```

در بررسی کد نیز نباید مصرف اجرایی از کلیدهای دستی یا `pricing_baseline_rate` بیرون از مهاجرت دیده شود. تست‌های `Navasan*`, `CloudCatalogRepricingTest` و `OpenproviderAllTldCatalogTest` قراردادهای منبع، تازگی، fail-closed و پوشش کاتالوگ را کنترل می‌کنند.
