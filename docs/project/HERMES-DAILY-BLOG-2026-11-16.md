# Hermes/Araz daily trilingual blog

> Superseded in this same session: the owner requested a server cron with no ChatGPT dependency. The ChatGPT task has been disabled. See [native worker](HERMES-NATIVE-DAILY-BLOG-2026-11-16.md) for the replacement; the historical setup below is not the current execution path.
Registered: 2026-11-16. Owner explicitly authorized research, writing and automatic publication.

## Execution and current status
- The active execution worker is a scheduled **ChatGPT** task, with a daily flexible morning schedule around 08:00 Asia/Tehran, starting 2026-11-17.
- The task was created enabled. Its first execution has not yet occurred; no successful publication run is claimed by this change.
- The policy is stored in the production Growth Knowledge repository as **knowledge:18:v1**, internal/general, verified as an owner-authorized editorial policy. Retrieval was tested through the admin knowledge search and returned its three chunks.
- Verification of this policy is not human approval of future articles.
- This does not install or enable an autonomous publisher on the Hermes VM. The existing native GrowthAutopilot still uses fixed topics and separate locale drafts with human review. It was deliberately not made globally auto-publishing.
- The scheduled worker needs authorized access to the site when it runs. Current admin blog and knowledge access were verified during setup. Future access failures must be reported rather than described as a successful publication.

## Editorial operating policy
برنامه وبلاگ روزانه سه‌زبانه هرمس/آراز — مجوز صریح مالک در 2026-11-16
هدف: در هر روز تقویمی تهران یک موضوع تازه و مفید مرتبط با مخاطبان سرورنت انتخاب و یک مقاله در سه زبان فارسی، انگلیسی و ترکی منتشر شود. انتشار عادی این برنامه به تأیید مجدد روزانه نیاز ندارد.

انتخاب: پیش از نوشتن، مقالات منتشرشده و زمان‌بندی‌شده را بررسی کن. حداقل سه نامزد از خبرهای هوش مصنوعی، GPU، رایانش ابری، میزبانی و DevOps مقایسه کن؛ تازگی، ارتباط با نیاز مشتری و ارزش عملی از حجم جست‌وجوی حدسی مهم‌تر است. اولویت با خبر رسمی 24 تا 72 ساعت اخیر و حداکثر هفت روز است. تاریخ وقوع و انتشار را تفکیک کن. در نبود خبر معتبر، موضوع کاربردی به‌روز و تکرارنشده انتخاب کن و آن را خبر تازه جا نزن.

پژوهش: اطلاعیه سازنده، مستندات رسمی یا مقاله پژوهشی منبع اصلی باشد. در صورت امکان دو منبع اصلی مرتبط را تطبیق بده و لینکشان را در مقاله بگذار. متن منابع کپی نشود. واقعیت از تحلیل جدا باشد؛ ادعای آزمایش شخصی، مشتری واقعی، قیمت، تخفیف، موجودی، SLA یا قابلیت سرورنت بدون شواهد زنده ممنوع است.

نگارش: لحن روشن و طبیعی، مقدمه مستقیم، پاراگراف کوتاه، مثال کاربردی واقعی، تیترهای H2 و H3 معنادار و جمع‌بندی عملی. از کلیشه، اغراق، تکرار و پرکردن تعداد کلمات پرهیز کن. ترجمه انگلیسی و ترکی بومی و روان باشد و داده‌ها و نتیجه‌گیری هر سه زبان سازگار بماند. عنوان و توضیح متا مخصوص هر زبان، کلیدواژه طبیعی و اسلاگ لاتین توصیفی لازم است. H1 را قالب سایت می‌سازد؛ در بدنه تکرار نشود. دو تا چهار لینک داخلی واقعاً مرتبط با آدرس معتبر همان زبان کافی است؛ FAQ فقط وقتی به سؤال واقعی پاسخ می‌دهد.

انتشار: یک Post با سه PostTranslation در localeهای fa/en/tr، نه سه پست جداگانه. اول هر سه عنوان، خلاصه، متن و منابع را کامل و بازخوانی کن، سپس وضعیت انتشار را یکجا ثبت کن. اگر یکی ناقص بود، همه پیش‌نویس بمانند. از HTML ایمن و ساختار موجود سایت استفاده کن؛ اسکریپت یا Schema جعلی اضافه نکن. پس از انتشار هر سه صفحه عمومی، canonical، hreflang، عنوان، H1، لینک‌ها و حضور در وبلاگ را بررسی کن.

عدم تکرار: کلید اجرای برنامه hermes-daily-blog:YYYY-MM-DD بر اساس Asia/Tehran و اسلاگ پایدار daily-insight-YYYY-MM-DD-موضوع است. ابتدا وجود مقاله همان روز و هم‌موضوع را بررسی کن. پس از خطای نامعلوم ذخیره، پیش از تلاش دوباره وجود مقاله را بخوان. اجرای عقب‌افتاده باعث انتشار انبوه نشود؛ حداکثر یک مقاله تازه برای این برنامه در روز. پست‌ها و کمپین‌های دیگر دست‌کاری نشوند.

هزینه و صداقت: از ابزارهای متصل و دسترسی مجاز موجود استفاده کن؛ سقف هزینه فعلی تغییر نکند و هزینه API یا تصویر جدید بدون مجوز ایجاد نشود. بازبینی خودکار را تأیید انسانی جا نزن. افزایش رتبه یا معرفی توسط AI تضمین نشود. گزارش خروجی شامل موضوع، دلیل انتخاب، منابع و سه لینک واقعی باشد؛ در شکست، مانع دقیق گزارش شود و انتشار ادعا نشود.

مرز اجرا: ثبت این سند در دانش Hermes به‌تنهایی اتصال یا زمان‌بندی VM را فعال نمی‌کند. اجرای پژوهش و انتشار این برنامه در کار زمان‌بندی‌شده ChatGPT انجام می‌شود و به دسترسی مجاز سایت نیاز دارد؛ در قطع دسترسی گزارش خطا بده. زمان‌بندی و رسید اجرای واقعی جداگانه بررسی شود.

## Worker acceptance
1. Research and actually publish; a reminder or draft alone does not fulfill the task.
2. Use the existing CMS, one Post with fa/en/tr translations. Complete all three before publication.
3. Use daily-insight-YYYY-MM-DD-english-topic as the stable slug, date in Asia/Tehran. Check existing posts before retrying an uncertain save. At most one new article per day for this program; do not disturb unrelated campaigns.
4. Verify all three public pages, locale content, title, H1, canonical, hreflang, source/internal links and blog listing with available tools.
5. Report the actual three public URLs or the precise blocker. No invented success, traffic forecasts, human reviewers or test results.
6. Existing budgets remain unchanged; no new paid inference/image generation is needed for this ChatGPT worker.
7. No application code, secrets or credentials changed. No migration or full source deployment required.

## Validation
- Admin blog and cPanel sessions were accessible at setup.
- Production policy creation, verification and lexical retrieval succeeded (knowledge:18:v1#chunk-0 through #chunk-2).
- Scheduling service confirmed the task is enabled, next occurrence 2026-11-17T08:00:00+03:30 (flexible start).
- This is documentation/configuration; no application tests are required. End-to-end article publication remains to be verified on the first run.
