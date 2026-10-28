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
