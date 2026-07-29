# ServerNet — راهنمای پروژه

سایت شرکت هاستینگ **servernet.cloud** — سه‌زبانه (فارسی/انگلیسی/ترکی)، فارسی‌اول و RTL.

> این فایل نقشهٔ پروژه است. قبل از هر کار بزرگ بخوانش. اگر چیزی اینجا با کد
> نمی‌خواند، **کد درست است** — این فایل را به‌روز کن.

---

## ۱. پایه

| | |
|---|---|
| فریم‌ورک | Laravel ^13.8 روی PHP ^8.3 |
| دیتابیس | **SQLite** — `database/database.sqlite` (در git نیست) |
| ریشهٔ اپ | `ServerNet/website/` |
| مخزن | `git@github.com:servernetir/server.git` — شاخهٔ کاری **develop** |
| PHP محلی | `C:\php\php.exe` (۸.۴) — بدون Docker |
| اجرای محلی | `start-site.bat` → http://localhost:8000 |

**پروداکشن:** cPanel، اپ لاراول **بیرون از webroot** در
`/home/servernetcloud/servernet_app`، و `public_html` نقش `public/` را دارد.
PHP سرور: `/opt/cpanel/ea-php84/root/usr/bin/php`.

### ⚠️ چیزهایی که در مخزن هست ولی به سایت زنده ربطی ندارد
- `ServerNet/app/` → یک اپ **مردهٔ** Laravel 9 با Docker/MySQL. دست نزن.
- `ServerNet/README.md` → همان اپ مرده را توضیح می‌دهد و **غلط است**.
- `ServerNet/.claude/launch.json` و `scripts/update.sh` → مسیرهای macOS، کار نمی‌کنند.

---

## ۲. سه‌زبانگی — مهم‌ترین قرارداد پروژه

همهٔ صفحات عمومی در **یک closure** به نام `$site` در `routes/web.php` تعریف
می‌شوند و **سه بار** ثبت می‌گردند:

```php
Route::middleware('locale:fa')->group($site);                          // بدون prefix
Route::prefix('en')->name('en.')->middleware('locale:en')->group($site);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($site);
```

یعنی: **یک روت اضافه کن، در هر سه زبان ساخته می‌شود.**

- زبان فقط از **پیشوند URL** می‌آید — نه session، نه cookie، نه Accept-Language.
- نگاشت زبان→پیشوندِ نام در یک جا: `AppServiceProvider::LOCALES = ['fa'=>'', 'en'=>'en.', 'tr'=>'tr.']`
- در Blade **هرگز `route()` نزن** برای صفحات سایت؛ `lroute('blog.index')` بزن
  (نام خام بده، خودش پیشوند می‌گذارد).
- دادهٔ چندزبانه در config با `lc($arr)` خوانده می‌شود: `['fa'=>…,'en'=>…,'tr'=>…]`
- رشته‌های رابط کاربری: `__('ui.KEY')` از `lang/{fa,en,tr}/ui.php` — **۱۲۸۹ کلید،
  هر سه فایل دقیقاً برابر.** اگر کلیدی به یکی اضافه کردی، به هر سه اضافه کن.

### کمک‌تابع‌ها (`app/helpers.php`، از طریق composer autoload)
`lroute()` · `lc()` · `fa_num()` · `blog_date()` (شمسی برای fa) ·
`site_price()` (تومان برای fa، یورو بقیه) · `whmcs_url()` · `buy_url()` ·
`schema_ld()` · `word_count_fa()`

---

## ۳. تله‌های واقعی — اینها ما را گاز گرفته‌اند

### 🔴 `<?` در Blade یا JS = خطای ۵۰۰ فقط روی سرور
`short_open_tag` روی سرور **روشن** و محلی **خاموش** است. یک `<?` حتی داخل
کامنت جاوااسکریپت، روی سرور به‌عنوان تگ PHP اجرا می‌شود. محلی سالم، لایو ۵۰۰.
دو بار این اتفاق افتاد. برای بک‌اسلش هم: `String.fromCharCode(92)`.

### 🔴 `@context` را Blade می‌بلعد
هر `@word` در Blade یک directive است. برای JSON-LD حتماً `'@'.'context'`
بنویس یا از `schema_ld()` استفاده کن. `@json([...])` با آرایهٔ **درون‌خطی** هم
پارسر را می‌شکند — آرایه را اول در `@php` بگذار.

### 🔴 CSP هر منبع خارجی را بی‌صدا بلاک می‌کند
`app/Http/Middleware/SecurityHeaders.php`. اگر CDN، فونت، iframe یا آنالیتیکس
اضافه کردی، **باید** به CSP اضافه شود وگرنه بی‌هیچ خطایی کار نمی‌کند.
(`blob:` قبلاً نبود و همهٔ ابزارهای تصویر را خراب کرده بود.)

### 🔴 منطقهٔ زمانی UTC است
`config/app.php` → `'timezone' => 'UTC'` (ثابت، از env نمی‌آید).
پس کرون‌های ۱۰:۰۰ و ۱۲:۳۰ و ۱۴:۰۰ به وقت **UTC** اجرا می‌شوند، نه تهران.

### 🟡 شمارش کلمهٔ فارسی
`str_word_count()` برای فارسی همیشه **صفر** می‌دهد. از `word_count_fa()` استفاده کن.

### 🟡 نیم‌فاصله (U+200C)
در جستجو و پاک‌سازی متن حواست باشد. حذفش «می‌شود» را به «میشود» تبدیل می‌کند.
برای جستجوی فارسی، تطبیق **واژه‌به‌واژه** بزن نه زیررشته‌ای، و ی/ک عربی را هم fold کن.

### 🟡 Vite هست ولی استفاده نمی‌شود
`vite.config.js` و Tailwind کامل پیکربندی شده‌اند ولی **هیچ صفحهٔ واقعی از آنها
استفاده نمی‌کند**. `@vite()` فقط در `welcome.blade.php` دست‌نخوردهٔ لاراول است.
CSS سایت مستقیماً در `public/assets/css/site.css` ویرایش می‌شود (~۲۱۰۰ خط).
`npm run build` هیچ تأثیری ندارد.

### 🟡 site.css در جاهایی append-only است
بعضی سلکتورها **دوبار** تعریف شده‌اند و آخری برنده است. قبل از ویرایش grep بزن؛
برای قاعدهٔ جدید، **انتهای فایل** اضافه کن.

### 🟡 SQLite همه‌کاره
cache و session و queue همگی روی `database` هستند، یعنی داخل همان فایل SQLite.
`busy_timeout` و `journal_mode` هم `null`اند → زیر بار همزمان
`database is locked` می‌دهد نه صبر.

### 🟡 کش کانفیگ روی سرور
اگر `bootstrap/cache/config.php` روی سرور باشد، **تغییرات `.env` نادیده گرفته
می‌شوند**. اگر چیزی از env اثر نکرد، اول آن فایل را چک کن.

### 🔴 دوره‌های صورت‌حساب فقط در `config/billing.php`
دوره‌ها (ماهانه/سه‌ماهه/**شش‌ماهه**/سالانه) قبلاً در **۷ جا** تکرار شده بودند و
افزودنِ یکی یعنی جاافتادن در بقیه؛ نتیجه‌اش این بود که کرونِ تمدید
(`whereIn('cycle', [...])`) سرویسِ شش‌ماهه را **هرگز فاکتور نمی‌کرد**.
حالا `Service::cycles()` / `monthsIn()` / `labelFor()` از config می‌خوانند و
مدل و کنترلر و Blade همه از همان‌ها. **جای دیگری دوره را سخت‌کد نکن.**
(یک نقشهٔ پشتیبان `Service::MONTHS_FALLBACK` هم هست تا اگر config نرسید،
`next_due_at` نال نشود و اشتراک بی‌صدا نمیرد.)

**درصدِ تخفیفِ سالانه هم یک منبع دارد:** `config/billing.cycles.yearly.discount_pct`.
صفحات بازاریابی (home/hosting) همان را نشان می‌دهند؛ قبلاً سایت ۲۰٪ تبلیغ می‌کرد و
تسویه ۱۵٪ می‌گرفت.

### 🔴 سررسید از پایانِ دوره جلو می‌رود، نه از لحظهٔ پرداخت
`PaymentService` عمداً `next_due_at` را از `next_due_at ?? activated_at` جلو
می‌برد. اگر از `now()` حساب شود، چون فاکتورِ تمدید چند روز **پیش** از سررسید صادر
می‌شود، هر دوره کوتاه‌تر می‌شود و مشتری در سال بیش از ۱۲ ماه پول می‌دهد (روی
شش‌ماهه/سالانه ۶ و ۱۲ برابر). تستش: `tests/Feature/BillingPeriodAnchorTest.php`.

### 🟡 نامِ package در WHM = `sn_<slug>`
`Product::packageName()` منبعِ یگانه است و `createacct` همان را می‌فرستد. قبلاً
seeder نامِ پلنِ کاتالوگ («WP-5») را می‌نوشت که هیچ packageای با آن نام وجود
نداشت و **هر تحویلِ خودکار شکست می‌خورد**. پکیج‌ها روی **همهٔ** سرورهای WHM
ساخته می‌شوند، چون مشتری مکان (ایران/آلمان) را در لحظهٔ خرید انتخاب می‌کند.

---

## ۳.۵ گرافِ دانشِ پروژه (Graphify) — **اول این، بعد خواندنِ فایل**

پروژه بزرگ شده (۴۴۹ فایلِ کد). به‌جای خواندنِ فایل‌های بلند برای فهمیدنِ
«چه‌چیزی به چه‌چیزی وصل است»، یک گرافِ محلی داریم:

```bash
python -m graphify extract . --code-only   # ساخت/بازسازی — ۰ توکن، فقط AST محلی
python -m graphify cluster-only .          # ساخت GRAPH_REPORT.md و graph.html
python -m graphify update .                # فقط فایل‌های تغییرکرده (بعد از هر کارِ بزرگ)
```

**پرس‌وجوها (به‌جای Read کردنِ فایل):**

```bash
python -m graphify explain "ProvisioningService"      # همهٔ یال‌ها با file:line
python -m graphify path "PaymentService" "Service"    # کوتاه‌ترین مسیرِ وابستگی
python -m graphify query "…" --budget 4000            # جستجوی معنایی با سقفِ توکن
```

- خروجی در `graphify-out/` است و **در گیت نیست** (مشتق و بازتولیدشدنی).
- `--code-only` یعنی هیچ تماسِ API و هیچ هزینهٔ توکنی. (بدونِ آن، برای
  داکیومنت/تصویر کلیدِ LLM می‌خواهد.)
- گراف **شمارهٔ کامیت** را ذخیره می‌کند؛ اگر با `git rev-parse HEAD` نخواند،
  کهنه است → `update` بزن.
- یال‌ها برچسبِ `EXTRACTED` (واقعاً در کد هست) یا `INFERRED` (استنتاجی) دارند.
  برای تصمیمِ مهم، به `INFERRED` تنها تکیه نکن.
- ⚠️ گراف **جایگزینِ خواندنِ کد برای ویرایش نیست** — برای «کجا را باید عوض کنم»
  عالی است، ولی قبل از ویرایش، همان بخش را واقعاً بخوان.

---

## ۴. نقشهٔ کد

```
app/
  Http/Controllers/     Site, Catalog, Solution, Blog, Docs, WebTools, Lookup,
                        Tool, Chat, DomainCheck, DomainSearch, AiBuilder,
                        Careers, PanelPreview, Admin/
  Services/             AiContent, AiComments, BlogRepository, DocsRepository,
                        NetworkTools, DomainTools, Whmcs, SafeUrl,
                        HtmlSanitizer, SiteAudit, ExchangeRate
    Domain/             OpenProviderClient, DomainSearch
    Identity/           IdentityProvider (قرارداد), ZohalProvider, IranianKyc
  Models/               Post, PostTranslation, Comment, User
                        ── CMS جدید ──
                        Customer, CustomerProfile, CustomerDocument,
                        CustomerIdentity, CustomerIpRule, RegistryHandle,
                        IdentityVerification, BankAccount,
                        Currency, TaxRate, DomainQuote
  Console/Commands/     GenerateContent, PublishDue, TranslateMissing, AiStatus,
                        SeedBlogDb, SeedDocs, ImportWpBlog,
                        FetchDollar, SetupMariadb, PortLegacyData
config/                 ۲۳ فایل — سایت عمدتاً config-driven است
database/migrations/    ۹ قدیمی + ۱۰ تا با پیشوند 2026_08_01_* (CMS)
database/seeders/       BillingFoundationSeeder (ارز و مالیات)
resources/views/
  layouts/site.blade.php   تنها layout سایت عمومی
  pages/                   یک view به ازای هر بخش
  panel/                   پیش‌نمایش پنل کاربری و مدیریت
  system/setup.blade.php   ابزار آماده‌سازی دیتابیس (موقتی)
  partials/                header, footer, blog-sidebar, webtool-sidebar
  webtools/                ۴۸ ویجت ابزار
resources/content/       plan.php, docs-plan.php, webtools-seo.php
docs/billing/            طراحی CMS — ۱۰ سند
public/assets/           css/site.css, css/admin.css, css/panel.css,
                         js/*.js, font/ (IRANSans)
tests/Feature/           CustomerIdentity, DomainSearch, IranianKyc, ZohalProvider
```

---

## ۵. بخش‌های اصلی

### الف) صفحات محصول و راهکار — کاملاً config-driven، بدون دیتابیس
- `config/hosting.php` و `config/catalog/` → `CatalogController` → یک view مشترک
- `config/solutions.php` → `/solutions/{slug}`
- منوها در `config/servernet.php`
- **افزودن محصول جدید:** یک آیتم در config + (در صورت نیاز) روت در `$site`

### ب) CMS — بلاگ و پایگاه دانش
- جدول `posts` + `post_translations`؛ ستون `type` تفکیک می‌کند: `blog` یا `kb`
- `BlogRepository` (type=blog) و `DocsRepository` (type=kb)
- زمان‌بندی انتشار با `status` + `published_at`
- پنل ادمین با احراز هویت و نقش (`User::role === 'admin'`)
- سیستم نظرات با تعدیل هوش مصنوعی (`AiComments`)

### ج) ابزارهای وب‌مستر — `/webtools` (۴۸ ابزار)
**همه ۱۰۰٪ سمت کاربر.** هیچ داده‌ای به سرور نمی‌رود، هیچ هزینهٔ سروری ندارد.

- کاتالوگ در `config/webtools.php`: `categories → tools`
- محتوای سئو در `resources/content/webtools-seo.php` (مقدمه/گام‌ها/پرسش‌ها × ۳ زبان)
- ویجت‌ها در `resources/views/webtools/{slug}.blade.php`
- سایدبار همهٔ ابزارها: `partials/webtool-sidebar.blade.php`
- هر صفحه: WebApplication + FAQPage + HowTo + BreadcrumbList JSON-LD

**افزودن ابزار جدید — هر ۴ قدم لازم است:**
1. ویجت در `resources/views/webtools/{slug}.blade.php`
2. ثبت در `config/webtools.php` (وگرنه **مسیر ندارد و نامرئی است**)
3. کلیدهای `ui.*` در **هر سه** فایل زبان — کلید جامانده یعنی کاربر متن خام می‌بیند
4. محتوای سئو در `webtools-seo.php`

کلاس‌های CSS آماده که باید استفاده شوند:
`.wt-pane .wt-ta .wt-input-lg .wt-fields .wt-chk .wt-bar .wt-status
.wt-two .wt-io .wt-out-row .wt-grid .btn .btn-glass .btn-primary`

### د) ابزارهای شبکه — `/lookup` (سمت سرور)
DNS، DNSSEC، انتشار، reverse، SSL، **اسکن پورت**، پینگ.
- `config/lookup.php` → `LookupController` → `POST /api/lookup`
- `NetworkTools.php` منطق واقعی؛ `SafeUrl.php` محافظ SSRF
- اسکن پورت: پورت دلخواه می‌گیرد (`443` / `80,443` / `8000-8010`)، سقف ۳۲ پورت،
  بودجهٔ ۲۴ ثانیه؛ پورت‌های نرسیده «اسکن‌نشده» علامت می‌خورند نه «بسته»

> **این دو سیستم جدا هستند.** `/webtools` مرورگری، `/lookup` سروری.

### ه) هوش مصنوعی
- دو ارائه‌دهندهٔ سازگار با OpenAI: **GapGPT** و **DeepSeek**
- مسیریابی بر اساس هدف در `config/services.php → ai_routing`
  (`translate`, `article`, `comments`, `seo`)
- اگر کلید ارائه‌دهندهٔ انتخابی نباشد، خودکار به gapgpt برمی‌گردد
- **حتماً SSE streaming** — گذرگاه پشت Cloudflare است و درخواست بی‌خروجی
  حدود ۱۰۰ ثانیه‌ای ۵۰۴ می‌گیرد
- خروجی با جداکنندهٔ `###TAG###` نه JSON — چون بریدگی، کل JSON را نابود می‌کرد

---

## ۶. زمان‌بندی (کرون) — به وقت UTC

```
هر ساعت   content:publish-due
۱۰:۰۰     content:generate --limit=3 --days=2          (بلاگ، ۱۰۲ موضوع)
۱۲:۳۰     content:translate-missing --limit=2
۱۴:۰۰     content:generate --limit=2 --plan=docs-plan --daily   (پایگاه دانش، ۱۰۱ موضوع)
```

روی سرور فقط یک خط کرون لازم است (در هدر `routes/console.php` مستند شده).
**افزودن کار جدید:** یک `Schedule::command(...)` در همان فایل؛ کاری روی سرور لازم نیست.

---

## ۷. دپلوی

```
۱) کد را کامیت و به develop پوش کن
۲) فایل‌های تغییرکرده را با cPanel Fileman UAPI آپلود کن
   (مسیرهای نبود را خودکار می‌سازد)
۳) روی سایت زنده تست کن
```

**نکات:**
- `.env` سرور را **نخوان و ویرایش نکن** — کلید API دارد
- مهاجرت دیتابیس روی پروداکشن با `GET /system/db/{DEPLOY_TOKEN}` انجام می‌شود؛
  آن روت seeder هم اجرا می‌کند، پس مهاجرت‌ها باید کنار seed امن باشند
- **توکن را در URL نگذار** مگر ناچار — در لاگ سرور و Cloudflare و تاریخچهٔ
  مرورگر ثبت می‌شود. یک بار کلید API این‌طور لو رفت.
- `public/index.php` مخزن با نسخهٔ سرور فرق دارد (مسیرهای نسبی متفاوت‌اند چون
  اپ بیرون webroot است). ویرایشش اینجا به سرور نمی‌خورد.

---

## ۸. تست — درس گران‌قیمت

**کد ۲۰۰ یعنی هیچ.** بارها صفحه سالم برگشته ولی جاوااسکریپتش مرده بوده.

- ابزارها را با **مقدار مرجع** بسنج: SHA-256("abc")، رقم کنترلی EAN-13،
  Nowruz ۱۴۰۵، کنتراست ۲۱:۱
- برای رندر بدون HTTP: صفحه را داخل خود لاراول رندر کن
  (`$kernel->handle(Request::create(...))`) — هم خطای Blade می‌گیرد هم کلید خام
- **محیط محلی به سرور نمی‌رسد** (نه curl نه مرورگر). تست جاوااسکریپت فقط روی
  سایت زنده ممکن است.
- ⚠️ **این ماشین به هر پورتی «وصل» می‌شود.** هرگز اسکن پورت را از اینجا تست نکن؛
  همه را «باز» گزارش می‌کند. یک بار به اشتباه به کاربر گفتم MySQL‌شان باز است.
- مرورگر خودکار رویداد scroll و rAF نمی‌فرستد و بعد از resize استایل را دوباره
  اعمال نمی‌کند. با CSSOM یا استایل درون‌خطی راستی‌آزمایی کن.

---

## ۸.۵ مسیرهای موقتی — قبل از راه‌اندازی واقعی حذف شوند

اینها برای توسعه لازم‌اند چون به SSH سرور دسترسی نداریم و مهاجرت‌ها از طریق
مرورگر اجرا می‌شوند:

| مسیر | کار | وضعیت امنیتی |
|---|---|---|
| `/system/setup` | اجرای مهاجرت و انتقال داده روی MariaDB | فرم عمومی، عمل پشت `DEPLOY_TOKEN` با POST |
| `/system/db-status` | آمادگی دیتابیس | عمومی ولی فقط بولین — بدون شمارش و بدون نام |
| `/system/db/{token}` | مهاجرت + seed قدیمی | توکن در URL ← **بدترین‌شان، اول این حذف شود** |
| `/system/content/{token}` | تولید محتوای دستی | توکن در URL |
| `/panel-preview*` | پیش‌نمایش طراحی پنل | داده ثابت، بدون دیتابیس |

**قاعده:** توکن هرگز نباید در مسیر URL باشد — در لاگ سرور و Cloudflare و
تاریخچهٔ مرورگر ثبت می‌شود. یک بار کلید DeepSeek همین‌طور لو رفت.
`/system/setup` با POST کار می‌کند و الگوی درست است؛ دو مسیر قدیمی باید به
همان شکل درآیند یا حذف شوند.

همه در `robots.txt` مسدود و با `X-Robots-Tag: noindex` سرو می‌شوند.

**زمان حذف:** بعد از اینکه پنل واقعی راه افتاد و راهی برای اجرای مهاجرت
(SSH یا دیپلوی خودکار) داشتیم.

---

## ۹. کارهای باز / بدهی فنی

- 🔴 **کلیدهای WHMCS API در `.env` محلی به‌صورت متن ساده‌اند** — باید چرخانده شوند
- فقط view خطای ۴۰۴ داریم؛ ۵۰۰ و ۵۰۳ صفحهٔ پیش‌فرض لاراول را نشان می‌دهند
  (بدون طراحی سایت و بدون فارسی)
- روت‌های `/system/*` خودشان می‌گویند «موقتی، بعد از دپلوی حذف شود» — هنوز هستند
- CSP هنوز `unsafe-inline` برای script دارد (مهاجرت به nonce انجام نشده)
- `README.md` ریشه غلط است و اپ مرده را توضیح می‌دهد

---

## ۱۰. CMS اختصاصی (جایگزین WHMCS) — وضعیت زنده

> 📐 طراحی کامل در `docs/billing/` است. این بخش می‌گوید **چه چیزی واقعاً ساخته
> شده** و **چه چیزی مانده**. اگر با کد نمی‌خواند، کد درست است.

### وضعیت یک‌نگاهی

| | |
|---|---|
| تست | ۴۰ تست · ۱۰۸ ادعا · همه سبز |
| مهاجرت | ۱۰ فایل نوشته و تست‌شده |
| دیتابیس سایت | **هنوز SQLite** — سوییچ انجام نشده |
| MariaDB | متصل، ولی جدول‌های فاز اول رویش ساخته نشده |

### ✅ ساخته و تست‌شده

**پایهٔ پول** — `currencies` · `exchange_rates` · `tax_rates`
هر مبلغ `BIGINT` در واحد فرعی. تومان (exponent 0، گرد به ۱۰٬۰۰۰) و یورو
(exponent 2). هیچ float و هیچ DECIMAL برای پول. مالیات داده‌محور: ایران ۱۰٪،
خارج ۰٪، **مستقل از روش پرداخت**.

**هویت مشتری** — `customers` · `customer_profiles` · `customer_documents` ·
`registry_handles` · `customer_identities` · `customer_ip_rules` ·
`legal_documents` · `legal_acceptances` · `customer_sessions`

- guard جدای `customer` از guard ادمین. تستی هست که ثابت می‌کند ورود مشتری
  هیچ دسترسی‌ای در guard `web` نمی‌دهد.
- حقیقی/حقوقی در `customer_profiles` جدا از `customers`، چون یک انسان می‌تواند
  هم شخص حقیقی باشد هم نمایندهٔ شرکت.
- کد ملی رمزنگاری‌شده + HMAC کلیددار برای ایندکس یکتا و جستجو بدون رمزگشایی.
- شناسهٔ عمومی `SN-104829` — `id` عددی هرگز به مشتری نشان داده نمی‌شود.
- پذیرش شرایط با **متن کامل و نسخه‌بندی**، تا بشود ثابت کرد کاربر دقیقاً چه
  چیزی را پذیرفته.

**احراز هویت ایرانی (زحل)** — `identity_verifications` · `bank_accounts`

جریان ثبت‌نام: کد ملی + تاریخ تولد + موبایل → شاهکار → استعلام هویت →
**نام از ثبت احوال می‌آید، از کاربر پرسیده نمی‌شود.**

جریان بانکی: کارت ۱۶ رقمی → استعلام صاحب کارت → تطبیق با نام رسمی →
اگر نخواند رد و هیچ‌چیز ذخیره نمی‌شود؛ اگر بخواند شبا و شماره حساب ذخیره و
**نام قفل می‌شود**.

**دامنه (OpenProvider)** — `domain_quotes`
جستجو → استعلام زنده → تبدیل به تومان با نرخ روز → درصد سود → استعلام با
پنجرهٔ اعتبار ۱۵ دقیقه.

**نرخ ارز زنده** — `ExchangeRate` از alanchand.com، کران ساعتی، USD و EUR.

### ✅ ساختهٔ کامل و زنده (به‌روزرسانی — تیر ۱۴۰۵)

پنل مدیریت و پنل کاربری **واقعی و روی دیتابیس** ساخته شدند (نه پیش‌نمایش).
مهم‌ترین‌ها:

- **پنل مدیریت واقعی:** مشتریان (پروندهٔ کامل، وضعیت، رمز، حذفِ محافظت‌شده)،
  فروش سرویس + پیش‌فاکتور، اعلان‌ها (پیامک/بله/**ایمیل**)، هزینه‌ها، تراکنش‌ها
  و اعتبار (+ **درآمد به تفکیک ارز**)، واریز به حساب، تنظیمات + مهر شرکت.
- **پنل کاربری واقعی:** داشبورد، سرویس‌ها، فروشگاه، فاکتورها (+ پرینت A4)،
  پروفایل، **صفحهٔ امنیت** (رمز با OTP، قوانین IP allow/deny + اعمالِ پیوسته،
  توکنِ API فقط‌خواندنی)، فعالیت + IP/دستگاه/مکانِ ژئو.
- **ورود:** OTP موبایل‌اول/ایمیل؛ کاربر ناموجود → هدایت به ثبت‌نام. **ورود ادمین
  دومرحله‌ای (OTP ایمیل).**
- **پرداخت:** زرین‌پال/بله/واریز به حساب/کریپتو(به‌زودی)، انتخابِ کارتی.
- **API مشتری:** `/api/v1/{me,services,invoices,credit}` با توکنِ Bearer (بی‌نشست).
- **پشتِ Cloudflare:** `trustProxies` تنظیم شد → IP واقعیِ کاربر همه‌جا.

**سیستم فروش + تحویلِ خودکار (Provisioning) — ساخته و زنده:**
- `servers` (رمزِ API رمزنگاری‌شده) + `/admin/servers` + آزمون اتصال.
- درایورها در `app/Services/Provisioning/`: قرارداد `Provisioner` + `WhmClient`/
  `WhmProvisioner` (WHM API 1) + `DirectAdminClient`/`DirectAdminProvisioner` +
  `ManualProvisioner` (VPS/اختصاصی/Plesk). همه **idempotent**.
- `products` + `/admin/products` (پکیج‌ها) + فروشگاهِ مشتری `/account/store` →
  سفارش → پیش‌فاکتور → پرداخت → **تحویلِ خودکار** روی سرور → اطلاعاتِ ورود در پنل.
- قلّابِ تحویل: `PaymentService::applyPaid` سرویس را `awaiting_provision` می‌کند؛
  کرونِ **`provision:run`** (هر دقیقه) می‌سازدش. تماسِ شبکه‌ای بیرونِ وب‌هوکِ درگاه.
- روی همین زیرساختِ SQLite/cPanel کار می‌کند (بدون worker مجزا).

### ⏳ در دست کار / مانده

| کار | وضعیت |
|---|---|
| سوییچ به MariaDB | هنوز SQLite؛ برای حجمِ فروشگاهِ واقعی باید سوییچ شود |
| اجرای مهاجرت‌ها روی سرور | جدول‌های تازه (servers/products/…) باید با روتِ مهاجرت ساخته شوند |
| احراز هویت ایرانی (فرم KYC) | موتورش آماده، رابط کامل نشده |
| اتصال OpenProvider | کد ۱۹۶ — اعتبارنامه/IP (کاربر از پنل OpenProvider قفل است) |
| درایورِ خودکارِ Plesk | فعلاً دستی؛ نیاز به تستِ روی سرورِ Pleskِ واقعی |
| بومی‌سازیِ کاملِ پنل | فقط صفحهٔ امنیت en/tr شده؛ بقیهٔ پنل فارسی |
| یکپارچگیِ کاتالوگِ سایتِ اصلی با فروشگاه | صفحاتِ بازاریابیِ config-driven هنوز به WHMCS خارجی لینک‌اند |
| زیردامنهٔ `console.` | DNS ست شده، در cPanel اضافه نشده |

---

### 🔴 چیزهایی که قبل از باز کردن ثبت‌نام حتماً لازم است

**۱) هر ثبت‌نام ۸۱٬۰۰۰ تومان خرج دارد**
شاهکار ۱۳٬۰۰۰ + استعلام هویت ۶۸٬۰۰۰. بدون محافظ، ثبت‌نام جعلی مستقیم پول
می‌سوزاند. لازم است:
- تأیید موبایل با OTP **قبل** از هر تماس پولی
- محدودیت نرخ روی IP
- استعلام هویت شاید فقط موقع اولین خرید، نه لحظهٔ ثبت‌نام

**۲) SQLite برای فروشگاه کافی نیست**
session و cache و صف روی همان فایل‌اند. زیر بار همزمان `database is locked`
می‌دهد نه صبر.

---

### قراردادهای این حوزه (تله‌های واقعی)

**زحل روی خطا هم HTTP 200 می‌دهد.** نتیجهٔ واقعی در فیلد `result` است:
۱ موفق · ۴ توکن غیرفعال · ۵ سرویس در دسترس نیست · ۶ پارامتر نادرست.
هرگز به کد HTTP تکیه نکن.

**OpenProvider هم روی خطا HTTP 500 می‌دهد** با خطای واقعی در فیلد `code`.
`196` یعنی احراز هویت رد شد (یا IP مجاز نیست).

**Cloudflare هم روی خطا HTTP 200 می‌دهد** — نتیجهٔ واقعی در `success` بدنه و
پیام در `errors[0].message`. (`app/Services/Dns/CloudflareDns.php`)

**زیردامنهٔ رایگان بدون رکورد DNS بالا نمی‌آید.** nameserverهای دامنه روی
Cloudflare است، پس zone محلیِ WHM را دنیا نمی‌بیند. پس از تحویلِ موفق،
`ProvisioningService::pointFreeSubdomain()` رکورد `A` را می‌سازد.
- توکن Cloudflare را **مدیر** در `/admin/settings` وارد می‌کند و با
  `Setting::putSecret()` **رمزنگاری‌شده** ذخیره می‌شود (در `.env` نیست).
- `proxied=false` عمدی است: پروکسی Cloudflare هم AutoSSL cPanel را می‌شکند هم
  FTP/ایمیل را.
- خطای DNS **هرگز** تحویل را شکست‌خورده نمی‌کند؛ نتیجه در `provision_meta['dns']`
  و لاگ فعالیت می‌نشیند.
- **فهرست برچسب‌های رزرو** در `config/servernet.subdomain_reserved` است. بدون آن
  مشتری می‌توانست `console` یا `mail` را بگیرد و زیردامنهٔ حساس ما را به هاست
  خودش بنشاند (راه فیشینگ). زیردامنهٔ تکراری هم رد می‌شود.

**WHM مقدار `0` را برای `quota` و `bwlimit` رد می‌کند** («نامحدود» = رشتهٔ
`unlimited`). مشخصات کاتالوگ برای ایمیل/بکاپ فضا را «10 GB» خالی می‌نویسند، پس
`parseLimits` باید «اندازهٔ خالی» را هم به‌عنوان quota بشناسد. اگر package از قبل
باشد، `editpkg` زده می‌شود تا حدومرزِ غلطِ قبلی اصلاح شود.

**قیمت پرمیوم دامنه در شاخهٔ جداگانه است:** `premium.price.create`، نه
`price.reseller`. اگر شاخهٔ اشتباه را بخوانی، دامنهٔ ۲۵۰۰ دلاری را ۱۲ دلار
می‌فروشی.

**فرق «سرویس خراب» با «کاربر رد شد» را نگه دار.** به کسی نگو هویتش رد شد
وقتی فقط توکن ما غیرفعال است.

**هر تماس با زحل پول است.** ورودی نامعتبر را محلی رد کن؛ شماره حساب را فقط
بعد از تأیید تطابق نام بگیر؛ `card_to_iban` خودش نام صاحب کارت را می‌دهد پس
استعلام جداگانه لازم نیست.

**PAN کامل کارت ذخیره نمی‌شود** — فقط BIN و چهار رقم آخر. نگهداری شماره کارت
کامل ما را مشمول الزامات PCI می‌کند بدون هیچ سودی.

### کلیدهای .env این حوزه

```
MARIADB_HOST / MARIADB_PORT / MARIADB_DATABASE / MARIADB_USERNAME / MARIADB_PASSWORD
   ← عمداً جدا از DB_* تا آماده‌سازی سایت را نیندازد

ZOHAL_TOKEN              احراز هویت ایرانی
OPENPROVIDER_USERNAME    ایمیل ورود، نه RID
OPENPROVIDER_PASSWORD
DOMAIN_MARGIN_PCT        پیش‌فرض ۲۵
DEPLOY_TOKEN             فقط حروف انگلیسی و رقم
```
