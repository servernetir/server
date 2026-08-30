# identity-kyc — هویت، احراز و ورود

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

احراز هویت مشتری **کاملاً از احراز هویت ادمین جدا** است. جدول `users` موجود
(با ستون `role`) دست‌نخورده می‌ماند و فقط کارکنان را نگه می‌دارد؛ مشتری‌ها در
`customers` زندگی می‌کنند با guard جداگانه‌ی `customer`. دلیلش امنیتی است: یک
اشتباه در منطق نقش‌ها نباید بتواند مشتری را به پنل مدیریت برساند، و برعکس.

هر مشتری **یک** رکورد `customers` دارد (هویت ورود) و **یک یا چند** رکورد
`customer_profiles` (هویت حقوقی/حقیقی برای صدور فاکتور و ثبت دامنه). این تفکیک
عمدی است: یک انسان ممکن است هم به‌عنوان شخص حقیقی خرید کند و هم نماینده‌ی یک
شرکت باشد — با یک حساب ورود، ولی دو پروفایل جدا با مدارک جدا.

فیلدهای متفاوت حقیقی و حقوقی در **ستون‌های واقعی** ذخیره می‌شوند، نه در یک JSON
مبهم. دلیلش این است که روی کد ملی و شناسه ملی باید ایندکس یکتا بگذاریم (جلوگیری
از ثبت تکراری)، و روی آنها جستجو و گزارش می‌گیریم. ستون‌های مخصوص هر نوع
nullable هستند و یک CHECK تضمین می‌کند که برای هر نوع، فیلدهای لازمش پر باشد.

کد ملی و مدارک هویتی **داده‌ی شخصی حساس‌اند**. کد ملی رمزنگاری‌شده ذخیره می‌شود
و کنارش یک hash برای جستجو نگه می‌داریم. مدارک بیرون از webroot می‌روند و فقط
با URL امضاشده‌ی کوتاه‌عمر قابل دانلودند.

---

## جدول‌ها

### `customers`

هویت ورود. هرچه برای احراز هویت و امنیت حساب لازم است — نه اطلاعات صورت‌حساب.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `code` | `VARCHAR(16) NOT NULL UNIQUE` | شناسه‌ی اختصاصی مشتری که در فاکتور و تیکت نشان می‌دهیم، مثل `SN-104829`. هرگز از `id` عددی به مشتری نشان نده — تعداد کل مشتریان را لو می‌دهد. |
| `email` | `VARCHAR(191) NOT NULL UNIQUE` | |
| `email_verified_at` | `TIMESTAMP NULL` | |
| `phone` | `VARCHAR(20) NULL UNIQUE` | فرمت E.164 (`+98912...`). یکتا چون ورود با شماره داریم. |
| `phone_verified_at` | `TIMESTAMP NULL` | |
| `password` | `VARCHAR(255) NULL` | nullable چون کاربر ممکن است فقط با گوگل/اپل ثبت‌نام کرده باشد. |
| `locale` | `VARCHAR(5) NOT NULL DEFAULT 'fa'` | `fa`/`en`/`tr` — زبان همه‌ی ایمیل‌ها و پیامک‌های این مشتری. |
| `timezone` | `VARCHAR(40) NOT NULL DEFAULT 'Asia/Tehran'` | |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'active'` | `active` \| `suspended` \| `closed` |
| `two_factor_secret` | `VARBINARY(255) NULL` | رمزنگاری‌شده. |
| `two_factor_confirmed_at` | `TIMESTAMP NULL` | |
| `two_factor_recovery` | `VARBINARY(1024) NULL` | کدهای بازیابی، رمزنگاری‌شده. |
| `ip_restriction_mode` | `VARCHAR(12) NOT NULL DEFAULT 'off'` | `off` \| `warn` \| `enforce`. حالت `warn` عمدی است — قبل از قفل‌کردن حساب، چند روز فقط هشدار می‌دهیم. |
| `last_login_at` | `TIMESTAMP NULL` | |
| `last_login_ip` | `VARBINARY(16) NULL` | باینری تا IPv6 هم جا شود. |
| `failed_login_count` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | |
| `locked_until` | `TIMESTAMP NULL` | قفل موقت بعد از تلاش‌های ناموفق. |
| `created_at` / `updated_at` | `TIMESTAMP NULL` | تاریخ ایجاد کاربر که خواسته بودید. |

**ایندکس:** `UNIQUE (email)` · `UNIQUE (phone)` · `UNIQUE (code)` · `INDEX (status)`

---

### `customer_profiles`

هویتِ صورت‌حساب و ثبت دامنه. یک مشتری می‌تواند چند پروفایل داشته باشد
(خودش به‌عنوان حقیقی، و شرکتش به‌عنوان حقوقی).

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `type` | `VARCHAR(12) NOT NULL` | `individual` \| `company` |
| `is_default` | `TINYINT(1) NOT NULL DEFAULT 0` | پروفایل پیش‌فرض برای سفارش جدید. |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'draft'` | `draft` → `pending` → `verified` \| `rejected` \| `expired` |
| `reject_reason` | `TEXT NULL` | متن قابل نمایش به مشتری. باید بگوید **چه چیزی** و **چطور اصلاحش کند**. |
| `verified_at` | `TIMESTAMP NULL` | |
| `verified_by` | `BIGINT UNSIGNED NULL FK users(id)` | کدام کارمند تأیید کرد. |
| `expires_at` | `TIMESTAMP NULL` | برخی مدارک تاریخ انقضا دارند (مثلاً روزنامه‌ی رسمی). |
| **— مشترک —** | | |
| `mobile` | `VARCHAR(20) NOT NULL` | |
| `email` | `VARCHAR(191) NOT NULL` | ممکن است با ایمیل ورود فرق کند (مثلاً ایمیل مالی شرکت). |
| `country` | `CHAR(2) NOT NULL DEFAULT 'IR'` | |
| `province` | `VARCHAR(64) NULL` | |
| `city` | `VARCHAR(64) NULL` | |
| `address` | `VARCHAR(500) NOT NULL` | |
| `postal_code` | `VARCHAR(20) NULL` | برای ایران ۱۰ رقم. |
| **— حقیقی —** | | |
| `first_name` | `VARCHAR(80) NULL` | |
| `last_name` | `VARCHAR(80) NULL` | |
| `national_id_enc` | `VARBINARY(255) NULL` | **کد ملی، رمزنگاری‌شده.** هرگز خام ذخیره نشود. |
| `national_id_hash` | `CHAR(64) NULL` | SHA-256 با pepper — برای جستجو و ایندکس یکتا، بدون رمزگشایی. |
| `birth_date` | `DATE NULL` | |
| **— حقوقی —** | | |
| `company_name` | `VARCHAR(191) NULL` | |
| `company_national_id_enc` | `VARBINARY(255) NULL` | شناسه ملی، رمزنگاری‌شده. |
| `company_national_id_hash` | `CHAR(64) NULL` | |
| `registration_number` | `VARCHAR(40) NULL` | شماره ثبت. |
| `economic_code` | `VARCHAR(40) NULL` | کد اقتصادی — برای فاکتور رسمی لازم می‌شود. |
| `rep_first_name` | `VARCHAR(80) NULL` | نام نماینده. |
| `rep_last_name` | `VARCHAR(80) NULL` | |
| `rep_national_id_enc` | `VARBINARY(255) NULL` | کد ملی نماینده، رمزنگاری‌شده. |
| `rep_national_id_hash` | `CHAR(64) NULL` | |
| `rep_position` | `VARCHAR(80) NULL` | سمت. |
| `created_at` / `updated_at` | `TIMESTAMP NULL` | |

**ایندکس:** `INDEX (customer_id, type)` · `UNIQUE (national_id_hash)` ·
`UNIQUE (company_national_id_hash)` · `INDEX (status)`

> **چرا ایندکس یکتا روی hash؟** جلوگیری از اینکه یک کد ملی روی چند حساب تأییدشده
> بنشیند. اگر عمداً می‌خواهید اجازه دهید، یکتایی را به `(national_id_hash, status)`
> محدود کنید — ولی آن تصمیم را آگاهانه بگیرید.

---

### `customer_documents`

مدارک آپلودی. چند مدرک به ازای هر پروفایل، و امکان درخواست مدرک اضافه از مشتری.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_profile_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `kind` | `VARCHAR(32) NOT NULL` | `national_card` \| `birth_cert` \| `passport` \| `articles_of_association` (اساسنامه) \| `rep_authorization` (نامه‌ی تأیید نماینده) \| `official_gazette` (روزنامه رسمی) \| `other` |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'pending'` | `requested` \| `pending` \| `accepted` \| `rejected` |
| `requested_note` | `VARCHAR(255) NULL` | وقتی ادمین مدرک اضافه می‌خواهد، اینجا می‌نویسد چه می‌خواهد. |
| `reject_reason` | `VARCHAR(255) NULL` | |
| `disk_path` | `VARCHAR(255) NOT NULL` | مسیر تصادفی روی دیسک خصوصی، **بیرون webroot**. |
| `original_name` | `VARCHAR(191) NOT NULL` | فقط برای نمایش؛ هرگز در مسیر فایل استفاده نشود. |
| `mime` | `VARCHAR(100) NOT NULL` | از محتوای فایل تشخیص داده شود، نه از هدر مرورگر. |
| `size_bytes` | `INT UNSIGNED NOT NULL` | |
| `sha256` | `CHAR(64) NOT NULL` | تشخیص آپلود تکراری. |
| `scan_status` | `VARCHAR(16) NOT NULL DEFAULT 'pending'` | `pending` \| `clean` \| `infected`. تا `clean` نشده، دانلود ممنوع. |
| `uploaded_at` | `TIMESTAMP NOT NULL` | |
| `reviewed_by` | `BIGINT UNSIGNED NULL FK users(id)` | |
| `reviewed_at` | `TIMESTAMP NULL` | |

**ایندکس:** `INDEX (customer_profile_id, kind)` · `INDEX (status)`

---

### `registry_handles`

شناسه‌های ثبت دامنه (IRNIC و بقیه). یک پروفایل ممکن است برای هر رجیستری یک
شناسه‌ی جدا داشته باشد — این‌ها قابل ادغام نیستند.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_profile_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `registry` | `VARCHAR(24) NOT NULL` | `irnic` \| `openprovider` \| `centralnic` … |
| `handle` | `VARCHAR(64) NOT NULL` | شناسه‌ای که آن رجیستری داده. |
| `role` | `VARCHAR(16) NOT NULL DEFAULT 'registrant'` | `registrant` \| `admin` \| `tech` \| `billing` |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'active'` | |
| `verified_at` | `TIMESTAMP NULL` | IRNIC خودش فرایند تأیید جدا دارد. |
| `meta` | `JSON NULL` | فیلدهای مخصوص هر رجیستری. **این تنها JSON این حوزه است** چون شکل داده‌اش را رجیستری تعیین می‌کند نه ما. |

**ایندکس:** `UNIQUE (registry, handle)` · `INDEX (customer_profile_id, registry)`

---

### `customer_identities`

ورود با گوگل، اپل و شماره تلفن.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `provider` | `VARCHAR(16) NOT NULL` | `google` \| `apple` \| `phone` |
| `provider_uid` | `VARCHAR(191) NOT NULL` | `sub` در گوگل/اپل. |
| `email_at_link` | `VARCHAR(191) NULL` | ایمیلی که موقع اتصال برگشت — اپل ممکن است relay بدهد. |
| `linked_at` | `TIMESTAMP NOT NULL` | |
| `last_used_at` | `TIMESTAMP NULL` | |

**ایندکس:** `UNIQUE (provider, provider_uid)` · `INDEX (customer_id)`

> **تله‌ی اپل:** اپل فقط **بار اول** نام و ایمیل واقعی را می‌دهد. اگر ذخیره‌شان
> نکنید، دیگر هرگز نمی‌گیریدشان. همچنین ایمیل ممکن است
> `xxx@privaterelay.appleid.com` باشد که برای تماس مالی به درد نمی‌خورد — پس
> ایمیل جدا باید تأیید شود.

---

### `customer_ip_rules`

محدودسازی ورود به IP یا رنج مشخص.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `cidr` | `VARCHAR(43) NOT NULL` | تک IP یا رنج: `1.2.3.4/32`، `10.0.0.0/8`، IPv6 هم پشتیبانی شود. |
| `label` | `VARCHAR(64) NULL` | «دفتر تهران» |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | |
| `created_at` | `TIMESTAMP NOT NULL` | |

**ایندکس:** `INDEX (customer_id, is_active)`

> ⚠️ **خطر قفل‌شدن مشتری بیرون از حساب.** اگر مشتری IP خانه‌اش را ثبت کند و
> IP پویا داشته باشد، فردا نمی‌تواند وارد شود. سه محافظ لازم است:
> ۱) حالت `warn` پیش‌فرض باشد نه `enforce`
> ۲) موقع افزودن قاعده، IP فعلی خودش خودکار پیشنهاد شود
> ۳) یک راه بازیابی از طریق ایمیل/پیامک تأییدشده که همیشه کار کند

---

### `legal_documents` و `legal_acceptances`

پذیرش شرایط استفاده و سیاست حریم خصوصی — با **نسخه‌بندی**.

`legal_documents`:

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `kind` | `VARCHAR(24) NOT NULL` | `terms` \| `privacy` \| `sla` \| `aup` |
| `version` | `VARCHAR(16) NOT NULL` | `2026-07-01` یا `v3` |
| `locale` | `VARCHAR(5) NOT NULL` | متن هر سه زبان جدا. |
| `body` | `MEDIUMTEXT NOT NULL` | متن کامل در همان لحظه. |
| `sha256` | `CHAR(64) NOT NULL` | اثر انگشت متن. |
| `published_at` | `TIMESTAMP NULL` | |

`legal_acceptances`:

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `legal_document_id` | `BIGINT UNSIGNED NOT NULL FK` | |
| `accepted_at` | `TIMESTAMP NOT NULL` | |
| `ip` | `VARBINARY(16) NOT NULL` | |
| `user_agent` | `VARCHAR(255) NULL` | |

**ایندکس:** `UNIQUE (customer_id, legal_document_id)`

> **چرا متن کامل ذخیره می‌شود؟** اگر فردا شرایط را عوض کنید و مشتری ادعا کند
> «من این را نپذیرفتم»، باید بتوانید **دقیقاً همان متنی** که او دید را نشان
> دهید. لینک به صفحه‌ی فعلی کافی نیست — آن صفحه عوض شده.

---

### `customer_sessions` و `customer_audit`

`customer_sessions`: نشست‌های فعال با IP و دستگاه، تا مشتری بتواند نشست‌های دیگر
را ببندد. `customer_audit`: لاگ فقط-افزودنی از عملیات حساس (تغییر رمز، تغییر
ایمیل، افزودن قاعده‌ی IP، تأیید/رد مدرک، دسترسی کارمند به مدارک).

---

## تصمیم‌های کلیدی

**guard جدا برای مشتری، جدول جدا از `users`**

پنل مدیریت و پنل مشتری دو دنیای متفاوت با سطح دسترسی کاملاً متفاوت‌اند. با یک
جدول مشترک، هر باگ در منطق `role` تبدیل به ارتقای سطح دسترسی می‌شود.

*رد شد:* استفاده از همان `users` با نقش `customer` — ساده‌تر بود ولی ریسکش
ارزشش را نداشت.

**فیلدهای حقیقی/حقوقی ستون واقعی، نه JSON**

روی کد ملی و شناسه ملی ایندکس یکتا و جستجو لازم داریم؛ داخل JSON نه ایندکس
درست کار می‌کند نه یکتایی. تنها JSON این حوزه `registry_handles.meta` است، چون
شکلش را رجیستری تعیین می‌کند نه ما.

**جدایی `customers` از `customer_profiles`**

یک انسان می‌تواند هم شخص حقیقی باشد هم نماینده‌ی شرکت. با یک جدول تخت، یا باید
دو حساب ورود بسازد (بد) یا مدارک شرکتی و شخصی قاطی می‌شوند (بدتر).

**کد ملی رمزنگاری‌شده + hash جداگانه**

رمزنگاری برای محرمانگی، hash برای ایندکس یکتا و جستجو. اگر فقط رمزنگاری کنید،
جستجو یعنی رمزگشایی کل جدول.

**`code` عمومی به‌جای `id`**

شناسه‌ی `SN-104829` را به مشتری نشان می‌دهیم. اگر `id` عددی نشان دهید، هر کسی
با ثبت‌نام دو حساب پشت‌سرهم می‌فهمد چند مشتری دارید.

---

## ریسک‌ها

**مدارک هویتی روی سرور = بالاترین ارزش برای مهاجم**

تصویر کارت ملی و اساسنامه‌ی شرکت، طلای خالص برای کلاهبرداری هویتی است.
→ دیسک خصوصی بیرون webroot، مسیر تصادفی، اسکن ویروس قبل از دسترسی، URL امضاشده‌ی
کوتاه‌عمر، لاگ هر دانلود با نام کارمند، و حذف خودکار مدارک رد‌شده بعد از مدت مشخص.

**قفل‌شدن مشتری با قاعده‌ی IP**

مشتری با IP پویا خودش را بیرون می‌اندازد و تیکت هم نمی‌تواند بزند.
→ پیش‌فرض `warn`، پیشنهاد خودکار IP فعلی، و مسیر بازیابی که همیشه کار می‌کند.

**ورود با اپل و ایمیل relay**

`@privaterelay.appleid.com` برای فاکتور و اطلاع‌رسانی مالی قابل اتکا نیست.
→ ایمیل تماس جدا و تأییدشده الزامی شود قبل از اولین خرید.

**تأیید دستی مدارک گلوگاه می‌شود**

شما گفتید می‌خواهید بدون نیروی انسانی کار کند، ولی KYC ذاتاً قضاوت انسانی
می‌خواهد.
→ سرویس‌های کم‌ریسک (هاست اشتراکی ارزان) بدون احراز کامل فعال شوند و فقط
دامنه‌ی `.ir` و سرویس‌های گران احراز کامل بخواهند. تأیید خودکار الگوی کد ملی و
کد پستی هم بار اولیه را کم می‌کند.

**همان کد ملی روی چند حساب**

سوءاستفاده از تخفیف اولین خرید، یا فرار از تعلیق.
→ ایندکس یکتا روی hash، و هشدار به ادمین وقتی مدرکی با `sha256` تکراری آپلود شود.

---

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Contracts\Identity;

/**
 * هر نوع پروفایل (حقیقی/حقوقی) قواعد اعتبارسنجی و مدارک لازم خودش را دارد.
 * افزودن نوع جدید = یک کلاس، بدون تغییر در فرم‌ها یا مهاجرت.
 */
interface ProfileType
{
    /** 'individual' | 'company' */
    public static function key(): string;

    /** قواعد اعتبارسنجی لاراول برای این نوع. */
    public function rules(): array;

    /** مدارکی که برای رسیدن به وضعیت verified لازم‌اند. */
    public function requiredDocuments(): array;

    /** بررسی‌های خودکاری که قبل از صف بررسی انسانی اجرا می‌شوند. */
    public function autoChecks(CustomerProfile $profile): AutoCheckResult;

    /** فیلدهایی که رمزنگاری می‌شوند. */
    public function encryptedFields(): array;
}

final readonly class AutoCheckResult
{
    public function __construct(
        public bool $passed,
        /** @var string[] پیام‌های قابل نمایش به مشتری */
        public array $problems = [],
        /** اگر true، بدون بررسی انسانی تأیید شود */
        public bool $autoApprove = false,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Identity;

/**
 * ورود اجتماعی. افزودن ارائه‌دهنده = یک کلاس + یک ردیف کانفیگ.
 */
interface SocialProvider
{
    /** 'google' | 'apple' */
    public static function key(): string;

    public function redirectUrl(string $locale): string;

    /** @throws SocialAuthFailed */
    public function handleCallback(array $request): SocialIdentity;
}

final readonly class SocialIdentity
{
    public function __construct(
        public string $providerUid,   // sub
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        /** اپل فقط بار اول نام و ایمیل می‌دهد — این را جدی بگیر */
        public bool $isFirstAuthorization,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Identity;

/**
 * تصمیم‌گیری دربارهٔ محدودیت IP. جدا از میان‌افزار نگه داشته شده
 * تا بشود مستقل تستش کرد — این منطقی است که می‌تواند مشتری را بیرون بیندازد.
 */
interface IpGate
{
    /** 'allow' | 'warn' | 'deny' */
    public function decide(Customer $customer, string $ip): string;

    /** آیا این IP با هیچ‌کدام از قاعده‌های فعال می‌خواند؟ */
    public function matches(Customer $customer, string $ip): bool;
}
```
