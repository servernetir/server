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
        'welcome' => [
            'title' => 'خوش‌آمد پس از ثبت‌نام', 'group' => 'account',
            'audience' => self::BOTH, 'vars' => ['name'], 'wired' => true,
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
