# کلاینت حداقل‌اختیار Growth برای هرمس — ۱ نوامبر ۲۰۲۶

این بسته یک گام توسعه‌ای برای اثبات مسیر **VM هرمس ← HMAC ← مرکز رشد** است؛ نصب یا اتصال زنده نیست. کلاینت فقط دو عمل خواندنی `curriculum` و `oversight` را روی origin ثابت `https://console.servernet.cloud` اجرا می‌کند. هیچ عمل `candidate`، `feedback`، ارسال مشتری، انتشار، مالی یا ابزار اجرایی در allowlist نیست.

## قرارداد

- بدنهٔ دقیق `{}` با `POST` امضا می‌شود؛ timestamp، nonce، path و SHA-256 بدنه با قرارداد `X-Growth-*` همسان‌اند.
- کلید حداقل ۳۲ بایت از caller تزریق می‌شود؛ این بسته credential نمی‌سازد، env نمی‌خواند و کلید/امضا را log یا برنمی‌گرداند.
- HTTPS-only، origin/path ثابت، redirect خاموش، timeout ده‌ثانیه و سقف پاسخ یک MiB است.
- پاسخ فقط وقتی پذیرفته می‌شود که HTTP 200، JSON معتبر، `data` آرایه و `executed_actions=[]` باشد.
- timeout، 409 و 5xx خودکار retry نمی‌شوند؛ caller باید رسید و نتیجهٔ نامعلوم را تطبیق دهد.

## آزمون ایزوله

```bash
php infra/hermes/growth-client-20261101/test.php
```

transport ساختگی امضای دقیق و URL بدون query را بازبینی می‌کند و رد عمل نوشتنی، nonce نامعتبر، 503 بدون retry، JSON/contract نامعتبر و پاسخ بزرگ را می‌سنجد. آزمون هیچ شبکه، دیتابیس، مدل پولی یا دادهٔ مشتری ندارد.

پذیرش CI شماره۱ روی head کد `f96b3a7cfbdadde11f3dc631d1df1a6ba6e09cb1` با PHP 8.4.27 انجام شد: lint هر دو فایل و ۲۰ assertion موفق. این نتیجه فقط قرارداد مصنوعی است و اتصال زنده یا نصب VM نیست.


## preflight بدون راز

پیش از هر نصب، `preflight.php` فقط آمادگی محلی را fail-closed می‌سنجد: PHP 8.4+، cURL و primitiveهای رمزنگاری، اختلاف ساعت با epoch همتای احرازشده، مسیر canonical و غیر symlink، hash دقیق، مالک مورد انتظار و ممنوع‌بودن write برای group/world روی هر سه فایل client، cycle و caller. هر سه فایل باید در یک پوشهٔ canonical مشترک باشند تا `require`های مبتنی بر `__DIR__` در caller به نسخهٔ دیگری اشاره نکنند. هیچ env، secret یا credential خوانده نمی‌شود و هیچ درخواست شبکه‌ای انجام نمی‌شود.

```bash
php infra/hermes/growth-client-20261101/preflight.php \
  --client=/absolute/path/HermesGrowthReadClient.php \
  --expected-client-sha=<sha256> \
  --cycle=/absolute/path/GrowthReadCycle.php \
  --expected-cycle-sha=<sha256> \
  --caller=/absolute/path/owner-growth-caller.php \
  --expected-caller-sha=<sha256> \
  --expected-uid=<numeric-uid> \
  --peer-epoch=<authenticated-peer-epoch> \
  --max-skew=60
```

خروجی فقط facts غیرمحرمانه و checkهای true/false است. `ready` صرفاً آمادگی نصب محلی است؛ وجود secret، پذیرش HMAC دوطرفه، سلامت VM یا اجرای کمپین را ثابت نمی‌کند. هر سه فایل runtime که caller بارگذاری می‌کند باید مسیر canonical، مالک، مجوز و SHA-256 مورد انتظار داشته باشند. خروجی `not_ready` با exit 2 و ورودی نامعتبر با exit 64 متوقف می‌شوند.


## caller واقعی با رسید یکتا

`owner-growth-caller.php` قطعهٔ اجراییِ فقط‌خواندنی است، اما خودش هیچ کلید یا دسترسی‌ای ایجاد نمی‌کند. اپراتور باید مسیر canonical یک key-file ازپیش‌موجود با مالک دقیق و mode `0400` یا `0600` و یک پوشهٔ رسید ازپیش‌موجود با mode دقیق `0700` را بدهد. caller یک `run-id` یکتا می‌پذیرد، ابتدا `curriculum` و سپس `oversight` را حداکثر یک بار فراخوانی می‌کند و دادهٔ پاسخ را در رسید ذخیره نمی‌کند؛ فقط SHA-256 دادهٔ هر پاسخ ثبت می‌شود.

```bash
php infra/hermes/growth-client-20261101/owner-growth-caller.php \
  --key-file=/absolute/private/path/growth.key \
  --receipt-dir=/absolute/private/path/receipts \
  --expected-uid=<numeric-uid> \
  --run-id=hg-YYYYMMDDTHHMMSSZ-<unique>
```

وجود همان فایل رسید اجرای مجدد را پیش از شبکه متوقف می‌کند. `accepted` فقط پذیرش قرارداد دو پاسخ خواندنی است؛ تحویل کمپین، ارسال، فروش یا سلامت کل VM نیست. هر failure یا 503/timeout به `unknown` با `retry_allowed=false` تبدیل و رسید آن نیز نگه‌داری می‌شود تا اپراتور نتیجه را تطبیق دهد. stdout فقط run-id/status را دارد و key، امضا، response data، مسیرها و اطلاعات مشتری را چاپ نمی‌کند.

آزمون ایزوله:

```bash
php infra/hermes/growth-client-20261101/cycle-test.php
```

transport ساختگی موفقیت دو عمل، رسید mode `0600`، نبود secret/data در رسید، قفل اجرای تکراری پیش از network، نتیجهٔ نامعلوم بدون retry، پاک‌سازی خطای حساس و رد key/directory ناامن را می‌سنجد.

## مرز نصب

پیش از نصب باید محل دقیق caller روی VM هرمس، نسخه PHP/cURL، secret store موجود و همسان‌بودن clock بررسی شوند. کلید `growth_hermes_key` باید جداگانه توسط اپراتور مجاز در هر دو سمت تنظیم شود؛ این بسته کلید تازه یا دسترسی گسترده ایجاد نمی‌کند. پذیرش زنده نیازمند یک فراخوانی `curriculum` و یک `oversight` با رسید دوطرفه است. موفقیت CLI مصنوعی، سلامت VM یا اجرای مستقل کمپین نیست.
