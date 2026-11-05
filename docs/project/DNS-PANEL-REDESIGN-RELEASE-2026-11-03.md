# بازطراحیِ پنلِ مشتریِ DNS — رسیدِ انتشار — ۳ نوامبر ۲۰۲۶

وضعیت به واژگانِ AGENTS.md: **در تولید تأیید شد** (به درخواستِ صریحِ مالک، ۳ نوامبر).

- کد: `1a8a62f5` (بازطراحی) روی `develop`؛ اسکریپتِ انتشار `scripts/deploy-dns-panel-v2.sh` @ `acf95ec6`؛ پذیرشِ رندر `scripts/dns-panel-v2-render-check.php` @ `6415255d`.
- دامنه: فقط رابط کاربری. ۱۰ فایل: پنج view در `resources/views/account/dns/`، سه `lang/{fa,en,tr}/dns.php` (گروهِ تازهٔ `ux`)، `public_html/assets/css/dns.css` و `public_html/assets/js/dns.js`. هیچ controller، route، config، migration، کرون یا cache flushی نصب نشد. کپیِ داخلیِ `servernet_app/public/assets/{css,js}/dns.*` روی سرور وجود نداشت.

## تغییرِ رفتار

هیرو با فرمِ افزودنِ دامنه، آمار، راهنمای سه‌گامی و نیم‌سرورهای قابلِ کپی؛ صفحهٔ دامنه با نوارِ پیشرفت و stepper، چک‌لیستِ کارتی، ابزارِ جست‌وجو/فیلترِ نوع، توضیحِ هر نوعِ رکورد در فرم، کارتِ لمسیِ رکورد زیرِ ۷۲۰px، خطِ زمانیِ تاریخچه و ناحیهٔ حذفِ بسته؛ واردکردن با زبانه، رهاکردنِ فایل و نوارِ ثابتِ «ردیفِ انتخاب‌شده». رفع: «انتخابِ همه» در واردکردن به `pick[]` اشاره می‌کرد نه `lines[]`. `dns.css` خودکفاست (CssVariablesDefinedTest) و `prefers-reduced-motion` را رعایت می‌کند.

## شواهد

- **تست‌ها** (checkoutِ تازه روی ویندوزِ مالک، PHP 8.4.23): `tests/Feature/Dns` + `CssVariablesDefinedTest` ⇒ ۱۲۲ تست، ۸۸۴ ادعا؛ ۲ شکست هر دو در `CssVariablesDefinedTest` و هر دو در فایل‌های دیگران (`account/invoice`، `admin/accounting`، `pages/storage-catalog`، `partials/report-consultation`، `partials/seo-python-fit`) — baseline، بی‌ربط به این تغییر. هیچ فایلِ DNS در فهرستِ شکست نیست.
- **تمرین** روی HOMEِ ساختگی از نسخهٔ پایه: DRY، نصب، اجرای دوباره (NOTHING TO DO)، بازگشتِ بایت‌به‌بایت، توقف روی drift، و رد شدنِ Blade خراب در lint.
- **تولید** (ترمینالِ cPanel، کاربر `servernetcloud`، ~07:47 UTC): اجرای DRYِ اول در lint ایستاد (autoload پیش از bootstrap لود نمی‌شد؛ هیچ نوشتنی) ⇒ رفع در `acf95ec6`. DRYِ دوم: هر ۱۰ مقصد دقیقاً برابرِ نسخهٔ پایه (`da62f84f`)، `LINT OK`. نصب: `FILES OK — 10`، هشِ پس از نصب هر فایل تأیید شد؛ mode حفظ شد.
- **پذیرش**: هشِ عمومیِ `https://servernet.cloud/assets/css/dns.css` و `…/js/dns.js` (بی‌کش) با Git یکی است. رندرِ واقعیِ controller/view برای یک مشتریِ دارای zone در fa/en/tr برای index/zone/import ⇒ هر ۹ صفحه 200، همهٔ نشانه‌های ساختاری حاضر، صفر کلیدِ خام؛ `serviceOpen = open`. ۲۸ نویسهٔ فارسی در هر صفحهٔ en/tr از پوستهٔ پنل است (ثابت در هر سه صفحه، داده‌محور) نه از این views. شمارِ ` .ERROR` در `laravel.log` پیش و پس ۱۵۱۳. صفحهٔ زندهٔ `/account/dns` در مرورگرِ مالک طرحِ تازه را نشان داد.

## بازگشت

```
ROLLBACK=/home/servernetcloud/deploy-dns-panel-v2/backup-20261103-074736 \
  bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/acf95ec6f7e1b2b4709190427cbc46775f350364/scripts/deploy-dns-panel-v2.sh) acf95ec6f7e1b2b4709190427cbc46775f350364
```

پیش از نوشتن، هشِ فعلیِ هر فایل باید برابرِ نسخهٔ نصب‌شده یا نسخهٔ پیشین باشد؛ وگرنه بی‌نوشتن می‌ایستد. در Git: `git revert 1a8a62f5`.
