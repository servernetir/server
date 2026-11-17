# ServerNet Studio — حساب مشترک و کیف پول

نسخهٔ مشتری ServerNet Studio محصولی با رابط اختصاصی ServerNet و فونت IRANSans است.
مخزن رابط و سرویس مشتری: `servernetir/servernet-studio`، پوشهٔ `servernet/`.
این تغییر فقط قرارداد حساب در اپ Laravel را اضافه می‌کند و به‌طور پیش‌فرض خاموش است.

## ورود و ثبت‌نام

Studio کاربر را به `/account/studio/authorize` کنسول هدایت می‌کند. middlewareهای ورود مشتری، وضعیت حساب، محدودیت IP و CSRF فعلی حفظ شده‌اند. کاربر در خود کنسول وارد یا ثبت‌نام می‌شود؛ Studio رمز یا OTP او را نمی‌گیرد.
پس از تأیید دسترسی، code یک‌بارمصرف ۶۰ثانیه‌ای با PKCE S256 به callback از پیش تنظیم‌شده برمی‌گردد. فقط backend Studio با client secret و verifier می‌تواند آن را با کلید کوتاه‌عمرِ همان مشتری تعویض کند.
کلید به پروژهٔ اختصاصی `servernet-studio` و فقط سه scope مدل، چت و خواندن موجودی متصل است؛ بودجه/وضعیت پروژه، سقف تعداد کلیدها و admission موجود حفظ می‌شوند. پروژهٔ غیرفعال دوباره روشن نمی‌شود.

## مسیرها

| مسیر | احراز و اثر |
| --- | --- |
| GET/POST `/account/studio/authorize` و نسخه‌های en/tr | نشست مشتری؛ GET نمایش مجوز، POST با CSRF صدور code |
| POST `/api/studio/token` | client secret و PKCE؛ مصرف اتمیک code؛ صدور کلید محدود، بدون برداشت پول |
| GET `/v1/studio/account` | Bearer و scope صریح `ai:wallet:read`؛ موجودی دفتر و رزرو از Wallet، مصرف ماهانه از ai_usage |
| POST `/v1/studio/revoke` | همان کلید محدود؛ فقط ابطال کلید خودش |

شناسه مشتری از توکن/نشست می‌آید و از query/body پذیرفته نمی‌شود. واحد پول پاسخ **تومان، IRT** است؛ مقدار توکن مصرفی مستقل از مبلغ است.
هیچ کسر/شارژ/دفتر مالی تازه‌ای در این تغییر ایجاد نشده است. درخواست مدل همچنان از `/v1/chat/completions` و `AiCaller` عبور می‌کند. شارژ فقط در `/account/topup` کنسول انجام می‌شود.

## نصب و بازگشت

متغیرهای موردنیاز: `SERVERNET_STUDIO_ENABLED=false`، `SERVERNET_STUDIO_CLIENT_SECRET` تصادفی حداقل۳۲نویسه و `SERVERNET_STUDIO_REDIRECT_URI=https://<studio-host>/auth/callback`.
secret باید در backend هر دو سمت یکسان و خصوصی باشد. هیچ secret واقعی در مخزن یا build مرورگر قرار نگیرد. ابتدا migration جدید، نصب دو سمت، تطبیق HTTPS/callback و پذیرش خصوصی انجام شود؛ سپس feature روشن شود. لاگ callback و token endpoint نباید query/body حاوی code/secret را ثبت کند.
برای توقف، feature را خاموش و کلیدهای Studio را از پنل مشتری ابطال کنید. rollback با revert کد و خاموش‌ماندن feature است؛ جدول grant و سابقهٔ مالی حذف نشوند.

## شواهد فعلی

مبنای Laravel: `develop@b76ba2eb03b903508080261e65131c37d92502c8`.
۱۲فایل PHP با parser دستور زبان بررسی شدند. PHP در محیط محلی در دسترس نبود؛ تست‌های Laravel محلی اجرا نشده‌اند. `StudioSsoTest` و گردش CI اختصاصی برای تأیید قرارداد افزوده شدند؛ نتیجهٔ CI باید جدا ثبت شود.
در نسخهٔ مشتری Python،۲۷آزمون شامل connector قبلی و ورود/جداسازی/CSRF/کیف پول/استریم/بازپخش و عدم ارسال مجدد موفق شدند. build مستقل رابط مشتری نیز موفق شد.
هیچ تنظیم، migration یا درخواست پولی روی تولید اجرا نشده است.
