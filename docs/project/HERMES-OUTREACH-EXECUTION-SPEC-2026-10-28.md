# قرارداد اتصال اجرایی Hermes به outreach — ۲۸ اکتبر ۲۰۲۶

این سند یک مشخصهٔ توسعه و تست است، نه ادعای نصب یا اختیار اجرایی. هیچ credential یا scope تازه‌ای ایجاد نمی‌کند.

## وضعیت مشاهده‌شده

- `HermesSupervisor` در پنل زنده ساعتی اجرا می‌شود، اما فقط صف‌ها، نتیجه‌های نیازمند رسیدگی و تازگی دانش را مشاهده می‌کند.
- رسید زندهٔ 2026-10-28T08:02:45Z وضعیت `needs_attention` داشت: یک `knowledge_candidates` و یک `crm_paused`.
- API داخلی Hermes در پنل «نیازمند تنظیم» است؛ نامزد برنامهٔ دوساله هنوز بررسی انسانی نشده است.
- `/admin/seo` صف آمادهٔ ارسال صفر، چهار ارسال تاریخی، یک خطای `unreachable` و صفر لغو اشتراک نمایشی داشت. این اعداد نتیجهٔ چرخهٔ امروز نیستند.

## شکاف‌های fail-closed

1. `AuditReport` فقط `isStale()` دارد؛ `expires_at` و `revoked_at`، ابطال/چرخش لینک و منع دسترسی گزارش منقضی وجود ندارد.
2. `OutreachContact::isSendable()` فقط pending/report/unsubscribe را می‌سنجد و اعتبار دسترسی گزارش را بررسی نمی‌کند.
3. `SeoOutreachController::sendNext()` ابتدا `Mail::send()` و سپس `status=sent` را ثبت می‌کند. crash پس از پذیرش SMTP و پیش از update، نتیجهٔ نامعلوم و امکان ارسال تکراری می‌سازد.
4. `sendOne()` برای ارسال موفق ردیف/operation receipt پایدار با کلید یکتای عملیات ایجاد نمی‌کند.
5. `sent` فقط قبول‌شدن عملیات محلی است؛ delivery، bounce، reply، paid+provisioned و درآمد وضعیت‌های جدا ندارند.
6. سقف ۵ شروع/۲ پیگیری، یک پیگیری بعد از ۷ روز کاری و توقف ۹۰روزه باید در backend enforce شود؛ اتکا به شمارش UI کافی نیست.

## قرارداد حداقل پیاده‌سازی

### داده

- `audit_reports.expires_at`, `audit_reports.revoked_at`, `audit_reports.rotated_at`.
- جدول/مدل `growth_operations` با کلید یکتای `campaign_id + organization_id + contact_stage`، وضعیت‌های:
  `planned | authorized | enqueued | accepted | delivered | bounced | replied | suppressed | unknown | failed`.
- `attempt_id` یکتا، `provider_message_id` nullable، `last_receipt_at`، `next_followup_at` و `cooldown_until`.
- اطلاعات تماس و متن پیام در CRM خصوصی؛ log و Git فقط شناسه‌های غیرحساس و شمارش‌ها.

### مرز اجرایی

1. planner فقط proposal بسازد.
2. adapter کم‌اختیار پیش از authorize، سازمان/دامنه، consent-or-basis، suppression، bounce، unsubscribe، unknown و دسترسی گزارش را بخواند.
3. transaction رکورد operation را با کلید یکتا بسازد؛ تکراری به همان رکورد برگردد.
4. outbox پایدار در همان transaction نوشته شود.
5. sender از outbox با `attempt_id` ثابت ارسال کند.
6. اگر نتیجه provider معلوم نیست، operation برابر `unknown` و retry برابر false شود تا بررسی انسانی/receipt قطعی.
7. follow-up فقط با receipt ارسال قبلی، حداقل ۷ روز کاری و سقف روزانه؛ پس از یک پیگیری بدون پاسخ `cooldown_until = +90 days`.
8. emergency pause باید authorize و enqueue تازه را ببندد، اما ingestion رسیدهای قبلی و unsubscribe را باز نگه دارد.

### گزارش خصوصی

- `ReportController::show()` برای token منقضی، باطل یا قدیمیِ rotateشده پاسخ 404 بدون افشای علت بدهد.
- revoke/rotate فقط برای مدیر، با CSRF و audit receipt.
- `sendOne()` و `sendNext()` بلافاصله پیش از outbox، `report->isAccessible()` را دوباره بررسی کنند.
- backfill برای گزارش‌های قبلی باید مهلت صریح و fail-closed داشته باشد.

## آزمون‌های پذیرش

- report فعال 200؛ expired/revoked/old-token برابر 404؛ هدرهای noindex/no-store/no-referrer حفظ شوند.
- restart قبل و بعد از enqueue فقط یک operation و یک attempt بسازد.
- crash پس از provider acceptance و پیش از receipt، وضعیت `unknown` بسازد و retry نکند.
- receipt پذیرفته‌شده delivery یا فروش شمرده نشود.
- unsubscribe/bounce در هر کمپین مانع authorize شود.
- گزارش منقضی/باطل هرگز وارد outbox نشود.
- سقف روزانه ۵ شروع/۲ پیگیری و بازه ۷ روز کاری/۹۰ روز cooldown در test clock قفل شود.
- knowledge candidate و API «نیازمند تنظیم» مانع اجرای مستقل VM باشند.
- تست مصنوعی paid+provisioned فقط از رکورد مالی/تحویل معتبر عبور کند؛ UTM یا reply فروش محسوب نشود.

## ترتیب rollout

۱. migration و model gate گزارش؛ ۲. آزمون‌های ReportController/OutreachContact؛ ۳. operation+outbox با fixture مصنوعی؛ ۴. adapter Hermes با کمترین اختیار و HMAC موجود؛ ۵. dry-run؛ ۶. یک pilot واقعی پس از بررسی انسانی دانش و کنترل مقصد؛ ۷. پایش unknown/bounce/unsubscribe. تا پایان گام ۶ عنوان «Hermes خودش outreach را اجرا می‌کند» نادرست است.


## تطبیق تکمیلی کد — ۲۹ اکتبر ۲۰۲۶

مرجع بازبینی کد: `87215a5d1d6ca1f05b28d355b57d339201181854`؛ این بخش ممیزی منبع و طرح پذیرش است، نه تست اجراشده یا وضعیت زنده تولید.

### شکاف مسیر ارسال تکی و اتصال سیاست

در `website/app/Http/Controllers/Admin/SeoOutreachController.php::sendOne()`، پیام با `outreach: false` و `unsubscribeUrl: null` ساخته می‌شود. این رفتار برای گزارش درخواستی مشتری ممکن است کاربرد جدا داشته باشد؛ **نباید از همین مسیر برای outreach تازه استفاده شود**. مجوز تماس مالک به‌تنهایی نوع پیام را در backend تغییر نمی‌دهد. استفاده از send-one برای عبور از کنترل‌های کمپین یا قراردادن راه لغو صرفاً در note، جای suppression پایدار را نمی‌گیرد.

هسته `website/app/Services/Growth/HermesGrowthPolicy.php` در develop موجود است، اما کنترلر فوق آن را فراخوانی نمی‌کند. وجود این کلاس سقف backend را در sendOne/sendNext اثبات نمی‌کند. ارسال تکی suppression را می‌خواند، ولی transaction/outbox/کلید سازمان و کنترل سقف و unknown هنوز باید در adapter مشترک هر دو ورودی برقرار شوند. محدودیت ۳۰۰ رکورد import سقف روزانه مجاز outreach نیست.

### برش توسعه بعدی با آزمون رفتاری مشترک

هدف: یک مرز authorize مشترک پیش از audit هزینه‌دار، enqueue یا ارسال؛ مسیر گزارشِ مستقیماً درخواستی مشتری باید با شاهد درخواست و purpose مشخص از outreach جدا شود، نه با boolean انتخابی کلاینت. هیچ مجوز، کلید یا timer تازه‌ای برای این کار لازم نیست.

|شناسه پذیرش|ورودی مصنوعی/رخداد|نتیجه لازم در هر دو مسیر|
|---|---|---|
|O-01|history/suppression/unknown ناموجود یا خواندن ناموفق|رد تصمیم؛ صفر audit شبکه‌ای و صفر ارسال؛ علت داخلی غیرحساس|
|O-02|۵ تماس تازه یا۲ پیگیری از سقف امروز مصرف/رزرو شده|رد عملیات بعدی در transaction؛ import یا send-one میان‌بُر نباشد|
|O-03|دو درخواست هم‌زمان برای همان سازمان/مرحله با حروف یا www متفاوت دامنه|یک operation یکتا و حداکثر یک attempt؛ نرمال‌سازی با قرارداد دامنه واقعی، نه strip حدسی عمومی|
|O-04|گیرنده احرازشده ولی optout پایدار در دسترس نیست|هیچ outreach صادر نشود؛ درخواست بعدی عدم تماس در تمام کمپین‌ها مؤثر باشد|
|O-05|report فعال هنگام صف‌گذاری، سپس revoked/expired پیش از dispatch|sender مجدداً gate را بخواند؛ صفر ارسال؛ عملیات به blocked/suppressed مناسب برود|
|O-06|SMTP پذیرفته، سپس crash پیش از ذخیره receipt|unknown؛ همان operation دوباره ارسال نشود؛ accepted به delivered ارتقا نیابد|
|O-07|دانش candidate یا API بدون تنظیم، با پاسخ موفق آزمون assess|اجرای مستقل VM مسدود بماند؛ ارزیابی ماشینی جای human review را نگیرد|
|O-08|emergency pause پس از authorize و قبل dispatch|صفر ارسال تازه؛ ingestion receipt و لغو تماس همچنان فعال|

این جدول تکمیل مشخصه قبلی است؛ implementation/fixture/تست اجراشده محسوب نمی‌شود. تست روی Laravel و MariaDB دورریختنی با Mail/Network fake لازم است. command پذیرش موجود را همراه تست‌های تازه shared adapter اجرا کنید؛ تستی که فقط نام کلاس در متن کنترلر را می‌جوید کافی نیست.

### وضعیت این نوبت و ترتیب تحویل

۱. ابتدا expiry/revoke و محدودیت دسترسی گزارش با backfill صریح؛ ۲. مرز مشترک purpose/authorization؛ ۳. outbox و رزرو اتمیک سقف و attempt؛ ۴. تست‌های بالا و dry-run؛ ۵. پذیرش VM با هویت واقعی و دانش بررسی‌شده انسانی؛ ۶. pilot مجاز.

محیط این نوبت shell سالم دارد، ولی `php -v` با `php: command not found` پایان یافت؛ خطای قدیمی راه‌انداز نشست دیگر، مانع امروز نیست. تست PHP اجرا نشد و هیچ وابستگی نصب، schema تغییر یا انتشار تولید انجام نشد. هر دو پنل زنده به login برگشتند؛ از این مشاهده نمی‌توان وضعیت deployment یا human review امروز را استنتاج کرد.
