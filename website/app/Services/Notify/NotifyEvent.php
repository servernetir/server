<?php

namespace App\Services\Notify;

/**
 * کاتالوگِ رویدادهای اطلاع‌رسانی — **تنها منبعِ حقیقت**.
 *
 * ═══ چرا کاتالوگ، نه فراخوانِ پراکنده ═══
 *
 * تا امروز هر رویداد جداگانه به یک `CustomerNotifier::event()` وصل می‌شد و
 * پوشش «به‌خاطرسپردنی» بود. نتیجه‌اش دقیقاً همان چیزی شد که انتظار می‌رود:
 * الگوهای `welcome` و `invoice` سال‌ها در پنل بودند، مدیر می‌توانست متنشان را
 * ویرایش کند و «ذخیره شد» بگیرد — و **هیچ کدی هرگز صدایشان نمی‌زد**. همان
 * الگوی «شکست نمی‌خورد، فقط اتفاق نمی‌افتد» که این پروژه بارها خورده.
 *
 * با این کاتالوگ، پوشش **اثبات‌پذیر** می‌شود نه به‌خاطرسپردنی:
 * `NotificationCoverageTest` می‌سنجد که هر رویدادِ `wired` واقعاً فراخوان دارد
 * و هر فراخوان یک کلیدِ شناخته‌شده است.
 *
 * ═══ ستون‌ها ═══
 *
 *   title     نامِ فارسی — در پنل و در اعلانِ مدیر دیده می‌شود
 *   group     دسته‌بندیِ نمایشی
 *   audience  `customer` · `admin` · `both`
 *   vars      نامِ **دقیقِ** متغیرها
 *   wired     آیا کدی این رویداد را شلیک می‌کند
 *
 * ⚠️ `vars` باید دقیقاً همان چیزی باشد که فراخوان می‌فرستد. هر دو خوانندهٔ
 * الگو اگر بعد از جایگزینی هنوز `{چیزی}` ببینند، عمداً الگو را کنار می‌گذارند
 * — یعنی متغیرِ اضافه در این فهرست، الگو را برای همیشه بی‌اثر می‌کند و مدیر
 * متن را ویرایش می‌کند و هیچ اتفاقی نمی‌افتد.
 *
 * ⚠️ کلیدِ پیامک هم همین است. `SmsDispatcher::event()` این نام را مستقیم به
 * الگوی اپراتور نگاشت می‌کند، پس تغییرِ نام یعنی پیامکِ بی‌صدا نرفته.
 */
final class NotifyEvent
{
    public const CUSTOMER = 'customer';

    public const ADMIN = 'admin';

    public const BOTH = 'both';

    /**
     * @var array<string,array{title:string,group:string,audience:string,vars:array<int,string>,wired:bool}>
     */
    public const ALL = [
        // ───────────────── حساب و ورود ─────────────────
        'otp' => [
            'title' => 'کد ورود یک‌بارمصرف', 'group' => 'account',
            'audience' => self::CUSTOMER, 'vars' => ['code'], 'wired' => true,
        ],
        /*
        | 🔴 مخاطبش فقط **مشتری** است، نه هر دو.
        |
        | `RegisterController` در همان لحظه یک اعلانِ جداگانه و کامل‌ترِ
        | «مشتریِ جدید ثبت‌نام کرد» به مدیر می‌فرستد (نام، شناسه، موبایل، ایمیل،
        | نوعِ حقیقی/حقوقی، لینکِ پرونده). با `BOTH`، مدیر برای **یک** رخداد دو
        | پیام می‌گرفت و دومی — «خوش‌آمد پس از ثبت‌نام» — چیزی به اولی اضافه
        | نمی‌کرد.
        |
        | ⚠️ و بهایش از یک پیامِ اضافه بیشتر است: کارفرما پرسید «این پیامی است
        | که باید به مشتری می‌رفت و اشتباهی به من رسیده؟» — یعنی اعلانِ تکراری
        | باعث شد به سلامتِ کلِ کانالِ خوش‌آمد شک کند. اعلانی که خواننده را به
        | شک بیندازد، از نبودنش بدتر است.
        |
        | (پیامِ مشتری سرِ جایش می‌رود؛ این تغییر فقط رونوشتِ مدیر را برمی‌دارد.)
        */
        'welcome' => [
            'title' => 'خوش‌آمد پس از ثبت‌نام', 'group' => 'account',
            'audience' => self::CUSTOMER, 'vars' => ['name'], 'wired' => true,
        ],
        'password_changed' => [
            'title' => 'تغییر رمز عبور', 'group' => 'account',
            'audience' => self::CUSTOMER, 'vars' => [], 'wired' => true,
        ],

        // ───────────────── صورت‌حساب ─────────────────
        'invoice' => [
            'title' => 'صدور پیش‌فاکتور', 'group' => 'billing',
            'audience' => self::BOTH, 'vars' => ['number', 'amount', 'link'], 'wired' => true,
        ],
        'paid' => [
            'title' => 'تأیید پرداخت', 'group' => 'billing',
            'audience' => self::BOTH, 'vars' => ['amount'], 'wired' => true,
        ],
        'payment_due' => [
            'title' => 'رسیدن موعد پرداخت', 'group' => 'billing',
            'audience' => self::BOTH, 'vars' => ['number', 'amount', 'days', 'link'], 'wired' => true,
        ],
        'bank_rejected' => [
            'title' => 'رد رسید واریز بانکی', 'group' => 'billing',
            'audience' => self::CUSTOMER, 'vars' => ['reason'], 'wired' => true,
        ],
        /*
        | 🔴 رسیدِ واریز **منتظرِ مدیر** می‌مانَد — پس مدیر باید بداند.
        |
        | مشتری پول را فرستاده و تا تأییدِ دستیِ مدیر، سرویسش راه نمی‌افتد.
        | تا امروز هیچ اعلانی نداشت: تنها راهِ فهمیدن این بود که مدیر خودش
        | `/admin/bank-transfers` را باز کند. یعنی همان الگوی همیشگیِ این
        | پروژه — «شکست نمی‌خورد، فقط اتفاق نمی‌افتد» — این‌بار روی پولِ
        | واریزشدهٔ مشتری.
        |
        | ⚠️ مخاطب فقط مدیر است: مشتری همان لحظه در پنل «ثبت شد، در انتظارِ
        | بررسی» را می‌بیند و پیامِ دوم فقط تکرار است.
        */
        'bank_receipt' => [
            'title' => 'رسیدِ واریز، در انتظارِ تأیید', 'group' => 'billing',
            'audience' => self::ADMIN, 'vars' => ['number', 'amount'], 'wired' => true,
        ],

        // ───────────────── چرخهٔ عمرِ سرویس ─────────────────
        // هاست، سرور، لایسنس، نرم‌افزار و خدمات همگی `Service`اند، پس یک
        // مجموعه رویداد همه را پوشش می‌دهد و نامِ محصول در `service` می‌آید.
        'service_ordered' => [
            'title' => 'ثبت سفارش سرویس', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service', 'amount'], 'wired' => true,
        ],
        'service_ready' => [
            'title' => 'تحویل سرویس / سرور', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service', 'ip'], 'wired' => true,
        ],
        'service_failed' => [
            'title' => 'شکست در تحویل سرویس', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service', 'reason'], 'wired' => true,
        ],
        /*
        | سفارشی که محافظِ سوءاستفاده برای بازبینیِ دستی نگه داشته.
        |
        | 🔴 `vars` عمداً فقط `service` است. علتِ محافظ («بیش از N سرور در ۲۴
        | ساعت») **هرگز** به مشتری نمی‌رود: عددِ سقف را گفتن یعنی به مهاجم یاد
        | بدهیم یکی زیرش بماند. علت فقط در سطرهای اعلانِ مدیر می‌رود.
        |
        | ⚠️ متغیرِ اضافه این‌جا الگو را برای همیشه می‌کُشد — `NotificationTemplate`
        | اگر بعد از جایگزینی هنوز `{چیزی}` ببیند متنِ الگو را کنار می‌گذارد.
        */
        'service_hold' => [
            'title' => 'نگه‌داشتنِ سفارش برای بازبینی', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service'], 'wired' => true,
        ],
        'expiring' => [
            'title' => 'یادآوری تمدید (۷ / ۳ / ۱ روز)', 'group' => 'service',
            'audience' => self::CUSTOMER, 'vars' => ['service', 'days', 'amount', 'link'], 'wired' => true,
        ],
        'renewed' => [
            'title' => 'تمدید موفق سرویس', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service', 'until'], 'wired' => true,
        ],
        'suspended' => [
            'title' => 'تعلیق به‌دلیل عدم تمدید', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service'], 'wired' => true,
        ],
        'reactivated' => [
            'title' => 'رفع تعلیق پس از پرداخت', 'group' => 'service',
            'audience' => self::CUSTOMER, 'vars' => ['service'], 'wired' => true,
        ],
        /*
        | ⚠️ این یکی از همه حساس‌تر است: پس از آن، دادهٔ مشتری **برنمی‌گردد**.
        | باید زودتر و چندباره اطلاع داده شود، و اعلانِ مدیر هم لازم است چون
        | حذف تصمیمِ آگاهانهٔ آدم است نه خودکار.
        */
        'data_deletion_due' => [
            'title' => 'هشدار حذف دائمی داده', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service', 'days'], 'wired' => true,
        ],
        'terminated' => [
            'title' => 'خاتمهٔ سرویس و حذف داده', 'group' => 'service',
            'audience' => self::BOTH, 'vars' => ['service'], 'wired' => true,
        ],

        // ───────────────── دامنه ─────────────────
        'domain_registered' => [
            'title' => 'ثبت موفق دامنه', 'group' => 'domain',
            'audience' => self::BOTH, 'vars' => ['domain', 'until'], 'wired' => true,
        ],
        'domain_expiring' => [
            'title' => 'یادآوری انقضای دامنه', 'group' => 'domain',
            'audience' => self::CUSTOMER, 'vars' => ['domain', 'days', 'amount', 'link'], 'wired' => true,
        ],
        'domain_renewed' => [
            'title' => 'تمدید موفق دامنه', 'group' => 'domain',
            'audience' => self::BOTH, 'vars' => ['domain', 'until'], 'wired' => true,
        ],
        'domain_expired' => [
            'title' => 'انقضای دامنه', 'group' => 'domain',
            'audience' => self::BOTH, 'vars' => ['domain'], 'wired' => true,
        ],
        'domain_transfer' => [
            'title' => 'انتقال دامنه', 'group' => 'domain',
            // ⚠️ `wired: false` — انتقالِ دامنه هنوز پیاده نشده. عمداً در
            //    کاتالوگ می‌مانَد تا فراموش نشود و تست صریح بگوید وصل نیست.
            'audience' => self::BOTH, 'vars' => ['domain', 'status'], 'wired' => false,
        ],

        // ───────────────── پشتیبانی ─────────────────
        'ticket_new' => [
            'title' => 'ثبت تیکت جدید', 'group' => 'support',
            'audience' => self::BOTH, 'vars' => ['number', 'subject'], 'wired' => true,
        ],
        'ticket_reply' => [
            'title' => 'پاسخ به تیکت', 'group' => 'support',
            'audience' => self::CUSTOMER, 'vars' => ['number'], 'wired' => true,
        ],
        /*
        | 🔴 پاسخِ **مشتری** رویدادِ جداست، نه همان `ticket_reply`.
        |
        | گزارشِ کارفرما: «تیکتِ تازه اعلان می‌آید، ولی وقتی مشتری به پاسخِ من
        | دوباره جواب می‌دهد هیچ خبری نمی‌شود.» دو علت داشت و هر دو لازم بود:
        |
        |   ۱) `Account\TicketController::reply()` اصلاً هیچ اعلانی شلیک نمی‌کرد
        |   ۲) و حتی اگر می‌کرد، `ticket_reply` مخاطبش **مشتری** است — یعنی
        |      «کارمند جواب داد، به مشتری خبر بده». جهتش برعکسِ چیزی است که
        |      لازم بود.
        |
        | ⚠️ پس نامش را عوض نکن و مخاطبِ `ticket_reply` را هم به `both` نبر:
        | آن‌وقت هر پاسخِ **خودِ مدیر** هم یک اعلان به خودش می‌فرستد — و اعلانی
        | که به خودت می‌فرستی، بقیه را هم بی‌ارزش می‌کند.
        */
        'ticket_customer_reply' => [
            'title' => 'پاسخِ تازهٔ مشتری در تیکت', 'group' => 'support',
            'audience' => self::ADMIN, 'vars' => ['number', 'subject'], 'wired' => true,
        ],
        'ticket_closed' => [
            'title' => 'بستن تیکت', 'group' => 'support',
            'audience' => self::CUSTOMER, 'vars' => ['number'], 'wired' => true,
        ],
        'ticket_survey' => [
            'title' => 'نظرسنجی پس از بستن تیکت', 'group' => 'support',
            'audience' => self::CUSTOMER, 'vars' => ['number', 'link'], 'wired' => true,
        ],

        // ───────────────── عمومی ─────────────────
        'announce' => [
            'title' => 'اطلاعیهٔ گروهی', 'group' => 'other',
            'audience' => self::CUSTOMER, 'vars' => ['title', 'body'], 'wired' => true,
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function has(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }

    /** مدیر هم باید خبردار شود؟ */
    public static function notifiesAdmin(string $key): bool
    {
        return in_array(self::ALL[$key]['audience'] ?? '', [self::ADMIN, self::BOTH], true);
    }

    public static function notifiesCustomer(string $key): bool
    {
        return in_array(self::ALL[$key]['audience'] ?? '', [self::CUSTOMER, self::BOTH], true);
    }

    /** @return array<int,string> */
    public static function vars(string $key): array
    {
        return self::ALL[$key]['vars'] ?? [];
    }

    /** رویدادهایی که هنوز فراخوانی ندارند — صفحهٔ الگوها از این می‌گوید */
    public static function unwired(): array
    {
        return array_keys(array_filter(self::ALL, fn ($e) => ! $e['wired']));
    }
}
