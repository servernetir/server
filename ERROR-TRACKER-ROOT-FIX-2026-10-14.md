# اصلاح ریشه‌ای ردیاب خطا — ۱۴ اکتبر ۲۰۲۶

## وضعیت

اصلاحات روی تولید منتشر شده‌اند. انتشار از نشست احرازشدهٔ cPanel، روی snapshot همان لحظه و با patch محدود انجام شد؛ فایل‌های کامل شاخه جایگزین نشدند. به‌ویژه نسخهٔ تازه‌تر GPU و تغییرهای اختصاصی Arvan/Windows روی تولید حفظ شدند. خطاهای تاریخی پاک نشدند تا شواهد باقی بماند؛ نتیجه بر پایهٔ اجرای تازهٔ commandها و چرخه‌های بعد از انتشار سنجیده می‌شود.

پیگیری دوم در08:07:20UTC نیز منتشر شد. این پیگیری فقط دو فایل اجرایی را تغییر داد و رفتار providerها را عمداً از هم جدا نگه داشت: انتخاب منطقهٔ fallback مخصوص Arvan و deadline مخصوص receipt غیرهم‌زمان LightNode. پس از انتشار تا آخرین مشاهده هیچ server-error تازه‌ای بعد از خط تشخیصی06:41UTC ثبت نشد. هشدار۴۵۲ پس از force-delete رسمی و اثبات GET404 از مسیر معمول بسته شد؛ ۴۵۴ مستقل و باز مانده است.

## یافته‌ها و اصلاح ریشه‌ای

### ۱. صف ظرفیت GPU (Salad)

- علت: مقدار `gpu_capacity_wait` هفده کاراکتر داشت، ولی `services.provision_status` در MariaDB برابر `VARCHAR(16)` است. SQLite این محدودیت را اعمال نمی‌کرد و تست قدیمی سبز می‌ماند.
- اثر زنده: ۳۱ خطای ثبت سفارش ساعتی GPU با `Data too long for column 'provision_status'`؛ هیچ سفارش منتظر، فاکتور، برداشت کیف یا خرید تأمین‌کننده برای آن درخواست‌ها ساخته نشد.
- اصلاح: حالت مخصوص این جریان به `gpu_wait` کوتاه شد. ستون مشترک بزرگ نشد تا قرارداد همهٔ زیرساخت‌ها و ایندکس آن برای یک provider تغییر نکند. تست صریح طول ۱۶بایتی اضافه شد.

### ۲. چرخهٔ عمر async لایت‌نود و متر ساعتی

- علت: API لایت‌نود فرمان روشن/خاموش را با task غیرهم‌زمان می‌پذیرد. `CloudProvisioner` درست آن را `building` نگه می‌داشت، اما `cloud:meter` همان `false` موقت را شکست قطعی می‌شمرد و exit code 1 می‌داد.
- اصلاح: پذیرش فرمان با `cloud_instances.meta.lifecycle_pending` و وضعیت مورد انتظار ماندگار می‌شود. متر در این حالت هزینهٔ ساعت شروع را کم نمی‌کند، سرویس را فعال اعلام نمی‌کند و خطای قطعی هم نمی‌سازد؛ اجرای بعد فقط receipt/status را آشتی می‌دهد. رد واقعی API همچنان خطا، هشدار و exit code ناموفق است.
- ایمنی: marker در موفقیت یا رد قطعی پاک می‌شود؛ عملیات تکراری لایت‌نود از جدول receipt موجود جلوگیری می‌شود.
- بازبینی PR یک لبهٔ زمانی را یافت: receipt می‌توانست در poll اول pending و در poll تأیید فوری failed شود. پاسخ LightNode اکنون شکست قطعی را با قرارداد provider-specific مشخص می‌کند؛ `CloudProvisioner` آن را pending نمی‌نشاند، marker احتمالی را پاک می‌کند و همان لحظه مسیر خطا/هشدار را اجرا می‌کند. خطای موقت سایر providerها با این شرط درگیر نمی‌شود.

### ۳. Arvan: جداسازی قرارداد کشف منطقه از API منطقه‌ای

- علت: سلامت و موجودی Arvan به endpoint کشف منطقه گره خورده بود. در تولید `/ecc/v1/regions` پاسخ 500 و دو variant دیگر 404 می‌دهند، درحالی‌که endpoint احرازشدهٔ منطقه‌ای `sizes` پاسخ 200 دارد. این تفاوت مخصوص Arvan است و به Hetzner/Aeza/OVH/Proxmox/Salad/LightNode تعمیم داده نشد.
- اصلاح: اگر کشف منطقه خراب باشد، فقط برای Arvan از شناسه‌های منطقه‌ای که قبلاً در `cloud_plans` یا `cloud_instances` ثبت شده‌اند استفاده می‌شود و موجودی هر منطقه مستقیماً خوانده می‌شود.
- ایمنی ناوگان: اگر حتی یک منطقه خوانده نشود، کل snapshot اتمیک ناموفق است. `fleet:scan` در این حالت وضعیت موجود Arvan را حفظ می‌کند و سرورهای منطقهٔ خطادار را به‌اشتباه گمشده/یتیم اعلام نمی‌کند. موفقیت degraded با `catalog_degraded` از سلامت کامل کاتالوگ جداست.
- قرارداد مصرف‌کننده نیز جدا شد: fallback منطقه‌ایِ کاملاً موفق `message=''` برمی‌گرداند تا `CloudInventory` آن را خطای موجودی نداند؛ دلیل degraded فقط در `meta.catalog_degraded` و `meta.region_source` می‌ماند. خطای واقعی یا snapshot جزئی همچنان `ok=false` و پیام غیرخالی دارد.
- بازبینی پیگیری نشان داد تاریخچه باید برای کشف منبع هزینه‌زای یتیم محافظه‌کارانه بماند: منطقهٔ `cloud_plans` غیرفعال نیز اسکن می‌شود، چون ممکن است تنها شاهد محلیِ منطقه‌ای باشد که هنوز سرور billable بدون اتصال دارد. ردیف `cloud_instances.status=deleted` فقط وقتی از fallback کنار می‌رود که `provider_absence_verified_at` حاصل از GET404 همان منبع داشته باشد. دلیل این محافظ: پیاده‌سازی قدیمی plain DELETE می‌توانست ردیف محلی را deleted کند درحالی‌که provider هنوز `SOFT_DELETED` و هزینه‌زا بود. شکست خواندن هر منطقهٔ تاریخی کل snapshot را اتمیک نامعتبر می‌کند و دفتر دارایی حفظ می‌شود؛ هیچ snapshot ناقصی موفق اعلام نمی‌شود.

### ۳.۱. deadline فقط برای چرخهٔ غیرهم‌زمان LightNode

- `accepted_at` در pollهای بعدی بازنویسی می‌شد و marker می‌توانست برای همیشه pending بماند. اکنون نخستین زمان پذیرش حفظ می‌شود.
- deadline سی‌دقیقه‌ای فقط روی slug لایت‌نود اعمال می‌شود. رسیدِ دیرهنگام و شناسهٔ عملیات نگه داشته می‌شوند تا poll بعدی بتواند موفقیت واقعی را آشتی دهد؛ در deadline عملیات خرید/POST تازه انجام نمی‌شود.
- پس از deadline، مالک صورتحساب همان مسیر استاندارد شکست را ثبت می‌کند. هشدار مستقیم داخل provisioner حذف شد تا یک رخداد دو بار در ردیاب ثبت نشود. قرارداد Aeza، Arvan، Hetzner، OVH، Proxmox و Salad تغییر نکرد.

### ۳.۲. حذف نرم Arvan و `forceDelete`

- OpenAPI رسمی IaaS v1 آروان برای `DELETE /regions/:region/servers/:id` پارامتر query بولی `forceDelete` را با معنای Force delete server مستند کرده است. شاهد زندهٔ سرویس۴۵۲ نشان داد DELETE عادی می‌تواند منبع را در `SOFT_DELETED` نگه دارد؛ این وضعیت توقف هزینهٔ دیسک را اثبات نمی‌کند.
- source پیگیری فقط داخل `ArvanClient` درخواست را با `forceDelete=true` می‌فرستد. پاسخ 2xx و حتی 404 خود DELETE صرفاً نتیجهٔ mutation هستند. موفقیت دو شاهد هم‌زمان می‌خواهد: GET همان شناسه404، و فهرست همان منطقه با **HTTP200 دقیق، JSON معتبر، کلید واقعی `data` از نوع آرایهٔ JSON، آرایهٔ list معتبر، شناسهٔ string غیرخالی در همهٔ ردیف‌ها و نبود همان id**. پاسخ204، HTTP200 با بدنهٔ خالی و `{"data":{}}` پذیرفته نمی‌شود؛ نوع خام JSON پیش از تبدیل انجمنی حفظ می‌شود تا object خالی با list خالی اشتباه نشود. دلیل: 404 آروان می‌تواند «سرور یا منطقه پیدا نشد» باشد. GET200 با `SOFT_DELETED` یک pending صریح می‌سازد؛ DELETE404 همراه منبع ACTIVE، region/list ناخوانا، object انجمنی، ردیف بدون id یا id غیرstring همگی fail-closed هستند.
- pending پذیرفته‌شده بدون هشدار خطای کاذب در `cloud_instances.meta.release_pending` می‌ماند، مشتری از قبل در وضعیت مرده است و دیگر شارژ نمی‌شود، و health تا ۱۵دقیقه warn است. `accepted_at` نخستین پذیرش حفظ می‌شود؛ پس از مهلت health قرمز و retry بعدی آن را به مسیر عادی failure/alert ارتقا می‌دهد. شدت health از **کل صف** محاسبه می‌شود و سقف۵۰ فقط برای نمایش لینک‌هاست؛ بنابراین خطای واقعیِ بعد از پنجاه pending تازه پنهان نمی‌شود. health نیز marker را فقط وقتی معتبر می‌داند که provider همان Arvan باشد؛ marker کهنه یا ناسازگار LightNode/سایر providerها خطا می‌ماند. موفقیت GET404 marker pending را پاک و `provider_absence_verified_at` را ثبت می‌کند. این قرارداد فقط Arvan است.
- مرزهای تازه شامل 202→GET404+regional-list، 202→SOFT_DELETED، DELETE404→GET404، DELETE404→SOFT_DELETED، DELETE404→ACTIVE، منطقهٔ ناخوانا/فهرست malformed/id نامعتبر، پاسخ204/بدنهٔ خالی/object خالی، رد force-delete، pending بدون alert، deadline واقعی، خطای پس از ردیف۵۰، marker خارجی provider، orphan منطقهٔ بازنشسته، fallback حذف تاریخی تأییدشده/تأییدنشده و گارد عدم‌تعمیم marker به LightNode است. مجموعهٔ متمرکز نهایی Arvan/release/LightNode برابر۱۲۲ تست/۵۹۱ assertion بود؛ مجموعه‌های قبلی cross-provider برابر۱۱۷/۵۸۱ و گسترده provider/billing/fleet/GPU/tracker/site برابر۲۰۳/۱۰۳۶ نیز روی PHP8.4 بدون failure/error گذشتند.
- این بخش چهار فایل اجرایی (`ArvanClient`، `CloudProvisioner`، `ProvisioningService` و `SystemHealth`) در source/PR ثبت شده اما هنگام تثبیت head هنوز روی تولید نصب نشده است. نسخهٔ زنده guard انتظار GET404 را دارد ولی DELETE آن plain و قرارداد pending کامل را ندارد. انتشار باید از snapshot تازه، با merge محدود همان چهار فایل و OPcache invalidate انجام شود؛ بازاجرای helperهای قدیمی یا جایگزینی کل فایل‌ها ممنوع است.

### ۴. دروازهٔ انتشار سایت

- سه صفحهٔ عمومی و indexableِ Storage Box در FA/EN/TR از sitemap جا افتاده بودند؛ هر سه به‌صورت صریح افزوده شدند.
- سقف `/cloud` ابتدا از عکس قدیمی ۲۱ به snapshot ۵۹ به‌روز شد. اجرای کامل تولید نشان داد راه‌اندازی عمدی Object Storage یک صفحهٔ فارسی دیگر افزوده است؛ سقف نهایی با شاهد sitemap روی ۶۰ ثبت شد. صفحهٔ شصت‌ویکم همچنان بدون تصمیم تازه رد می‌شود.
- گیت یا هشدار آن غیرفعال نشد؛ `site:gate` کامل هر ۳۵۰۷ URL را دوباره رندر کرد.

### ۵. رندر قالب خارج از session وب

- در حین اعتبارسنجی یک خطای تازهٔ `Undefined variable $errors` در قالب صفحهٔ سرور ابری ثبت شد. این قالب در درخواست عادی web یک `ViewErrorBag` دارد، اما رندر CLI/ممیزی الزاماً آن را دریافت نمی‌کند.
- شرط نمایش خطا اکنون وجود error-bag را هم کنترل می‌کند. این تغییر UI، دادهٔ سرویس یا رفتار هیچ provider را عوض نمی‌کند و view cache پس از انتشار پاک شد.

### ۶. نویز ۴۰۴ بدون پنهان‌کردن لینک خراب واقعی

- aliasهای قدیمی sitemap با 301 تک‌پرش به `/sitemap.xml` می‌رسند.
- حدس‌های روشن SSRF به metadata و fingerprintهای Atlassian/OpenCart/Magento/Drupal از ردیاب ۴۰۴ حذف می‌شوند.
- URLهای محتوای قدیمی فارسی، feedها و لینک‌های محصولی ناشناخته عمداً فیلتر نشدند؛ مقصد معتبر برای آنها باید با دادهٔ مهاجرت تعیین شود و redirect حدسی به خانه یا بلاگ ساخته نشد.

## مواردی که عمداً بسته نشدند

- سرویس ۴۵۴ همچنان `terminated/releasing` است. نبودن نام قطعی آن در فهرست کلی به‌تنهایی توقف هزینه را ثابت نمی‌کند؛ تا GET404 همان شناسه و بررسی منابع جانبی، گزینهٔ «دستی حذف شد» زده نمی‌شود.
- سرویس۴۵۲ دیگر جزو موارد باز نیست: در08:36:33UTC `forceDelete=true` رسمی فقط برای همان هویت پذیرفته شد؛ در08:38:19 GET جزئیات404 و پنج فهرست منطقه‌ای کامل/خالی بود، موجودی کلی target=false و برداشت پس از لغو صفر ثبت شد. در08:40:57 `releaseAndTrack` معمول سرویس را `terminated/none` و instance را `deleted` کرد؛ `markReleasedManually=false` و ماشین تازه صفر بود.
- چهار کار چرخهٔ عمر دستی سرویس‌های ۱۲، ۳۴، ۴۴ و ۱۵۵ نیازمند اقدام انسانی نزد تأمین‌کننده‌اند و خودکار علامت موفق نخوردند.
- `/admin/support-autopilot` در تولید 404 است. پیاده‌سازی آن در checkout مشترک مجموعه‌ای جدا و هنوز untracked است؛ برای جلوگیری از قاطی‌شدن کار ناتمام دیگری، در این اصلاح وارد نشد و 404 آن نیز فیلتر نشد.
- خطاهای `SHOW COLUMNS ... LIKE ?` در05:46UTC و انتخاب ستون ناموجود در06:09UTC بدون app frame، از probeهای تشخیصی همان روز بودند؛ رشتهٔ آنها در منبع برنامه یا snapshot زنده پیدا نشد. FatalError ساعت06:41 با متن `Fresh acceptance receipt mismatch` نیز guard عمدیِ پذیرش GPU در `Command line code` بود، نه درخواست مشتری. بدون شاهد تکرار از ترافیک مشتری، تغییر سراسری schema inspection انجام نشد.

## اعتبارسنجی

- ۱۳۸ آزمون مرتبط و ۱۳۷۲ assertion پیش از انتشار موفق بود. پس از ادغام نهایی `develop`، مجموعهٔ انتخابی ۹۴ test method و ۱۲۳۷ assertion با exit0 گذشت؛ اجرای WSL روی درایو Windows هشدارهای محیطی `file_get_contents` را گزارش کرد و به‌عنوان اجرای پاکِ بدون هشدار ثبت نمی‌شود. اجرای نهایی همان source با PHP8.4 بومی، ۲۰۰ تست و ۲۱۴۵ assertion را در ۱۴۲ ثانیه با صفر failure/error/skip گذراند؛ شمارها بین suiteها همپوشانی دارند.
- پوشش شامل GPU capacity، LightNode receipt/power، متر ساعتی، Arvan health/inventory، FleetScanner، release retry، sitemap/release-gate و فیلتر 404 است.
- PHP8.4 lint همهٔ فایل‌های اجرایی منتشرشده موفق، cacheها پاک و `git diff --check` پاک است.
- اجرای اول `site:gate` روی تولید فقط `RG-BUDGET /cloud 60 > 59` را یافت. پس از ثبت صفحهٔ عمدی Object Storage، اجرای کامل دوم در06:46:45UTC روی همان ۳۵۰۷ URL با exit0 و همهٔ بندها سبز پایان یافت.
- `fleet:scan` در06:48:14UTC exit0 داد: ۶۲ دیده‌شده، ۳۲ متصل، ۲۷ بی‌صاحب، ۳ سرویس بسته و صفر ناپدید. سه ماشین آروان در UI ماندند و زمان آخرین اسکن تازه شد؛ شکست discovery منطقه‌ای دیگر snapshot را ناقص اعلام نکرد.
- PHP-FPM با `opcache.validate_timestamps=0` کار می‌کند؛ بنابراین پس از انتشار، مسیر احرازشدهٔ داخلی در07:00:22UTC با توکن فقط در حافظه فراخوانی شد. رسید خصوصی HTTP200، `opcache_reset=true` و موفقیت `config:clear`، `route:clear`، `view:clear` و `cache:clear` را ثبت کرد؛ هیچ توکن یا URL احرازشده‌ای در این سند ثبت نشده است.
- چون پاک‌سازی application cache نشانگر آخرین اسکن را حذف می‌کند، `fleet:scan` یک بار دیگر اجرا شد. UI در07:02UTC همان ۶۲/۳۲/۲۷/۳/۰ و سه ماشین Arvan را ثبت کرد؛ این آخرین snapshot قابل مشاهده پس از فعال‌شدن کد در PHP-FPM است.
- اصلاح بازبینی LightNode با تست تازهٔ «pending سپس failed» روی PHP8.4 گذشت: ۴ تست و ۳۳ assertion برای مجموعهٔ power confirmation، صفر failure/error. دو فایل اجرایی در07:12:02UTC با `git apply --check`، پشتیبان مستقل، lint و rollback خودکار نصب شدند. رسید نهایی PHP-FPM در07:12:20UTC دوباره HTTP200 و `opcache_reset=true` بود.
- آخرین `fleet:scan` پس از همان reset در07:13:33UTC با exit0 تمام شد: ۶۲ دیده‌شده، ۳۲ متصل، ۲۷ بی‌صاحب، ۳ سرویس بسته، صفر ناپدید و صفر حذف از دفتر؛ UI ساعت۱۰:۴۳ تهران و سه ماشین Arvan را نشان داد.
- اصلاح کانال پیام Arvan با ۲۹ تست و ۸۵ assertion، شامل assertion مستقیمِ نبودن Arvan در `CloudInventory.errors`، با صفر failure/error گذشت. فایل اجرایی در07:22:00UTC با guard و پشتیبان مستقل نصب شد؛ reset نهایی PHP-FPM در07:22:14UTC HTTP200 و `opcache_reset=true` داد.
- اسکن نهایی پس از همان reset در07:23:29UTC با exit0 همان ۶۲/۳۲/۲۷/۳/۰، صفر حذف از دفتر و حفظ سه ماشین Arvan را ثبت کرد.
- چرخه‌های طبیعی پس از reset نهایی نیز ثبت شدند: `cloud:meter` در07:25:18، `services:lifecycle` و `services:renew-due` در07:30:04UTC همگی `errors=0` و `pending=0` داشتند. متر طبیعی یک سرویس را شارژ کرد و چهار مورد را بی‌نیاز از کسر دانست؛ `cloud:meter` دستی اجرا نشد تا کسر مالی تکراری ایجاد نشود.
- مجموعهٔ تجمیعی نهایی روی source نهایی و PHP8.4 بومی، شامل اصلاح‌های receipt لایت‌نود و قرارداد پیام Arvan/CloudInventory، با ۲۲۰ تست و ۲۲۰۶ assertion در ۹۵ ثانیه و صفر failure/error/skip گذشت.
- دو قالب مستقل صف GPU که هم‌زمان در `develop` ادغام و روی تولید نصب شدند حفظ شدند؛ آزمون همان تغییر ۳۸ تست/۴۰۳ assertion بود. فعال‌سازی مشترک نهایی وب در07:45:00UTC دوباره HTTP200 و `opcache_reset=true` داد. چون `cache:clear` نشانگر ناوگان را پاک می‌کند، اسکن در07:47:04UTC با exit0 همان ۶۲/۳۲/۲۷/۳/۰، صفر حذف از دفتر و سه ماشین Arvan را دوباره ثبت کرد.
- بعد از همین reset مشترک، `services:renew-due` در07:45:03، `services:lifecycle` در07:45:04 و `cloud:meter` در07:45:25UTC به‌طور طبیعی اجرا شدند؛ هر سه `errors=0` و دو فرمان دارای شمار pending برابر صفر بودند. متر طبیعی یک سرویس را شارژ کرد و سه مورد را رد کرد؛ فرمان مالی دستی اجرا نشد.
- پیگیری deadline/fallback با ۳۵ تست و ۱۳۲ assertion متمرکز آغاز شد و مجموعهٔ تجمیعی source همان مرحله ۲۲۴ تست/۲۲۳۵ assertion را با PHP8.4 بومی، صفر failure/error/skip گذراند. پس از بازبینی force-delete و ادغام تازه‌ترین `develop`، مجموعهٔ نهایی مرتبط ۲۰۳ تست/۱۰۳۶ assertion را با صفر failure/error گذراند؛ تست متمرکز آزادسازی/Arvan برابر۶۲/۲۲۵ و cross-provider با LightNode برابر۱۱۷/۵۸۱ بود و `git diff --check` و lint چهار فایل اجرایی پاک است.
- پس از یافته‌های آخر بازبینی، اعتبارسنجی HTTP200/JSON/data با حفظ نوع خام، محاسبهٔ شدت از تمام صف، رد marker خارجی provider و حفظ منطقهٔ پلن بازنشسته اضافه شد. مجموعهٔ متمرکز Arvan/release/LightNode با سناریوی پاسخ204، بدنهٔ خالی، object خالی، خطای ردیف۵۱، marker ناسازگار LightNode و orphan منطقهٔ بازنشسته برابر۱۲۲ تست/۵۹۱ assertion و صفر failure/error گذشت. اجرای بسیار گسترده‌تر ۳۱۴تستی نیز ۳۱۲ مورد/۱۴۷۰ assertion را گذراند؛ دو تست قدیمی `BillingRenewalSafetyTest` به‌دلیل mock ناقص `lifecyclePending` شکستند و چون فایل اجرایی مرتبط با آن‌ها در این تغییر دست نخورده، به‌عنوان شاهد سبز این انتشار شمرده نشدند.
- دو فایل `ArvanClient.php` و `CloudProvisioner.php` در08:07:20UTC از patch محدود نصب شدند. reset احرازشدهٔ وب HTTP200 و `opcache_reset=true` داد. چرخه‌های طبیعی `services:lifecycle` در08:10:04 و `cloud:meter` در08:10:14UTC هر دو error0/pending0 و عملیات مالی صفر داشتند؛ `cloud:meter` دستی اجرا نشد.
- اسکن ناوگان08:07:45–08:08:42UTC با exit0 ثبت کرد: seen62، attached32، orphan27، service-dead3، missing0 و removed-from-ledger1. حذف فقط ردیف کهنهٔ `infra_assets` توسط reconciliation استاندارد بود؛ VM/provider resource، `cloud_instances`، سرویس و دادهٔ مشتری حذف نشد. وضعیت تاریخی Arvan شامل ۴۳ `deleted`، دو `error`، یک `off`، سه `running` و دو `unknown` باقی ماند؛ snapshot دارایی جاری دو ردیف attached دارد.
- بررسی فقط‌خواندنی08:14UTC API Arvan با `ok=true`، پیام خالی، `catalog_degraded=false` و منبع `provider-discovery` دو سرور برگرداند و برای نام‌های قطعی۴۵۲/۴۵۴ match نداشت. این شاهد برای بستن پرونده کافی نیست: endpoint جزئیات۴۵۲ `SOFT_DELETED` است. یک تغییر آزمایشی که «غیبت از list» را موفقیت حذف می‌گرفت پیش از انتشار کنار گذاشته و در Git revert شد؛ نسخهٔ زنده تغییر نکرد. درایور زنده پذیرش DELETE را پایان کار نمی‌داند و تا 404 جزئیات، صف را باز نگه می‌دارد.
- شاهد سخت نهایی۴۵۲ عمداً پس از آن مرحله آمد: force-delete رسمی HTTP200، سپس GET جزئیات همان شناسه404؛ فهرست‌های servers، volumes، os-volumes، floating IPها و ports همان منطقه همگی HTTP200/کامل/صفر بودند. موجودی کلی مستقیم provider دو ماشین دیگر و target=false داشت. پس از این شاهد، `releaseAndTrack` مسیر استاندارد را بست؛ هیچ موفقیت دستی یا استنتاج از list به‌تنهایی در کار نبود.

## ماشین‌ها، شبکه و persistence

- ماشین تغییرکرده: برنامهٔ Laravel روی core (حساب cPanel برنامه). هیچ guest یا VM تأمین‌کننده‌ای ساخته، حذف، روشن یا خاموش نشد.
- route شبکه، NAT، firewall، DNS، VPN، port-forward، host cron و state عامل‌های country-routing/port-forward هیچ تغییری نکردند.
- persistence فقط در فایل‌های برنامه و config است؛ migration یا تغییر schema ندارد. marker عملیات async داخل JSON موجود `cloud_instances.meta` ذخیره می‌شود.
- هیچ کلید خصوصی، توکن، رمز، URL کامل VPN یا UUID احراز مشتری در گزارش ثبت نشده است.

## انتشار و بازگشت

انتشار اصلی در چهار مرحلهٔ محافظت‌شده و پیگیری deadline/fallback در یک مرحلهٔ جدا انجام شد. مرحلهٔ اول هفت فایل را پس از `git apply --check` در `/home/servernetcloud/error-tracker-rootfix-20261014/before-live/` پشتیبان گرفت؛ مرحلهٔ دوم config بودجه و قالب را در `before-followup/` ذخیره کرد؛ اصلاح بازبینی LightNode دو فایل اجرایی را در `before-review-followup/` و اصلاح پیام Arvan یک فایل را در `before-arvan-review/` پشتیبان گرفتند. پیگیری آخر دو فایل را در `before-timeout-followup/` نگه داشت. همهٔ مراحل lint/guard و در خطا rollback داشتند؛ هش patchها و گزارش‌های preflight/deploy/validation در همان پوشهٔ خصوصی است. تلاش بعدی `before-arvan-absent-reconcile/` در preflight به‌دلیل تفاوت محافظتی source زنده متوقف شد، patch نشد و فقط backup/diagnostic دارد. `php` پیش‌فرض حساب 8.2 بود و Composer آن را رد کرد؛ تمام فرمان‌های Laravel با باینری صریح PHP8.4 اجرا شدند. host cron تغییر نکرد.

بازگشت باید فقط نه فایل یکتای انتشار اصلی و سپس دو فایل پیگیری را با ترتیب معکوس از پوشه‌های پشتیبان مربوط برگرداند، سپس با PHP8.4 `optimize:clear` و reset وب OPcache اجرا شود. `before-arvan-absent-reconcile/` منبع rollback نیست چون هیچ patchی از آن نصب نشد. rollback نباید فایل GPU/زمان‌بند تازه‌تر، سرویس‌ها یا ماشین‌های مشتری را لمس کند. بازگشت marker نیاز به پاک‌سازی داده ندارد؛ نسخهٔ قبلی کلید ناشناختهٔ JSON را نادیده می‌گیرد.
