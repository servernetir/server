<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * الگوی یک پیامِ سامانه — ایمیل و بله و اعلانِ پنل، یک‌جا.
 *
 * قاعدهٔ طلاییِ این کلاس: **هیچ‌وقت پیامی را قطع نکن.** اگر جدول نبود، اگر
 * الگو نبود، اگر متنش خالی بود — همان متنی که کد پاس داده استفاده می‌شود.
 * این‌طور افزودنِ این لایه نمی‌تواند اعلانی را بشکند؛ بدترین حالت این است که
 * متنِ قدیمی برود.
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'key', 'title', 'group', 'email_subject', 'email_body',
        'bale_body', 'sms_event', 'variables', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['variables' => 'array', 'is_active' => 'bool'];
    }

    /** دسته‌ها به ترتیبی که در صفحه نشان داده می‌شوند */
    public const GROUPS = [
        'account' => 'حساب کاربری',
        'billing' => 'مالی و صورت‌حساب',
        'service' => 'سرویس و سرور',
        'support' => 'پشتیبانی',
        'other'   => 'سایر',
    ];

    public function groupLabel(): string
    {
        return self::GROUPS[$this->group] ?? self::GROUPS['other'];
    }

    /**
     * رویدادهایی که واقعاً از این الگو استفاده می‌کنند، و روی کدام کانال.
     *
     * 🔴 چرا این نقشه لازم شد: صفحه ۱۲ الگو نشان می‌داد و مدیر می‌توانست هر
     * کدام را ویرایش کند، ولی چندتایشان هیچ فراخوانی نداشتند. نتیجه‌اش بدترین
     * نوعِ رابط بود: کاری می‌کنی، «ذخیره شد» می‌گیری، و هیچ اتفاقی نمی‌افتد.
     * فهرستِ زیر دستی نگه داشته می‌شود — هر بار که کلیدی را به کدی وصل کردی،
     * این‌جا هم اضافه‌اش کن.
     *
     * @var array<string,array<int,string>>
     */
    public const WIRED = [
        'paid'             => ['bale'],
        'ticket_reply'     => ['bale'],
        'password_changed' => ['bale'],
        'service_ready'    => ['bale'],
        'bank_rejected'    => ['bale'],
        'announce'         => ['bale'],
        'expiring'         => ['bale', 'email'],
        'suspended'        => ['bale', 'email'],
        'reactivated'      => ['bale', 'email'],
        // otp / welcome / invoice هنوز به هیچ فراخوانی وصل نیستند
    ];

    /** @return array<int,string> */
    public function wiredChannels(): array
    {
        return self::WIRED[$this->key] ?? [];
    }

    public function isWired(): bool
    {
        return $this->wiredChannels() !== [];
    }

    /**
     * الگوی یک رویداد — یا null اگر نبود/خاموش بود.
     *
     * `Schema::hasTable` عمداً هست: تا وقتی مهاجرت روی سرور اجرا نشده، هر
     * اعلانی که این‌جا رد می‌شود نباید ۵۰۰ بدهد.
     */
    public static function forKey(string $key): ?self
    {
        if (! Schema::hasTable('notification_templates')) {
            return null;
        }

        $row = static::query()->where('key', $key)->first();

        return ($row && $row->is_active) ? $row : null;
    }

    /**
     * جایگزینیِ متغیرها: `{name}` → مقدار.
     *
     * متغیرِ ناشناخته **دست‌نخورده** می‌مانَد نه اینکه خالی شود — اگر مدیر
     * `{invoce}` را غلط بنویسد، در پیام دیده می‌شود و اصلاحش می‌کند؛ حذفِ
     * بی‌صدا یعنی مشتری جمله‌ای ناقص می‌گیرد و کسی نمی‌فهمد.
     */
    public static function render(?string $text, array $vars): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        foreach ($vars as $k => $v) {
            $text = str_replace('{'.$k.'}', (string) $v, $text);
        }

        return $text;
    }

    /**
     * متنِ کوتاه (بله/اعلان) با متغیرهای پرشده — یا `$fallback` اگر الگو نبود.
     *
     * 🔴 محافظِ مهم: اگر بعد از جایگزینی هنوز `{چیزی}` در متن مانده باشد یعنی
     * فراخوانِ کد آن متغیر را نفرستاده. در آن حالت **متنِ کد** می‌رود، نه الگو.
     * وگرنه مشتری پیامی می‌گرفت مثل «سرورِ {service} آماده شد» — که از پیامِ
     * قدیمی هم بدتر است. این‌طور می‌شود الگوها را جلوتر از کد نوشت و
     * فراخوان‌ها را بعداً یکی‌یکی مهاجرت داد، بی‌هیچ ریسکی.
     */
    public static function body(string $key, array $vars, string $fallback): string
    {
        $tpl = static::forKey($key);

        if ($tpl === null || blank($tpl->bale_body)) {
            return $fallback;
        }

        $text = static::render($tpl->bale_body, $vars);

        return static::incomplete($text) ? $fallback : $text;
    }

    /**
     * موضوع و متنِ **ایمیل**ِ یک رویداد — یا null اگر الگو/متنی نبود.
     *
     * برخلافِ `body()` پشتیبانی نمی‌گیرد چون فراخوان‌ها دو دسته‌اند: آن‌هایی که
     * ایمیلِ اختصاصیِ خودشان را دارند (تحویلِ سرویس، کدِ ورود) و اصلاً این‌جا را
     * صدا نمی‌زنند، و آن‌هایی که **هیچ ایمیلی ندارند** و null یعنی «مثلِ قبل،
     * ایمیل نفرست». پس نبودِ الگو هرگز چیزی را خراب نمی‌کند.
     *
     * همان محافظِ `body()`: اگر بعد از جایگزینی هنوز `{چیزی}` مانده باشد، null
     * برمی‌گردد — ایمیلِ نافرستاده از ایمیلی که در آن «{service}» چاپ شده بهتر است.
     *
     * @return array{subject:string,html:string}|null
     */
    public static function email(string $key, array $vars): ?array
    {
        $tpl = static::forKey($key);

        if ($tpl === null || blank($tpl->email_body)) {
            return null;
        }

        $subject = static::render($tpl->email_subject ?: $tpl->title, $vars);
        $html = static::render($tpl->email_body, $vars);

        if (static::incomplete($subject) || static::incomplete($html)) {
            return null;
        }

        return ['subject' => $subject, 'html' => $html];
    }

    /** هنوز جای‌نگهدارِ پرنشده دارد؟ */
    private static function incomplete(string $text): bool
    {
        return (bool) preg_match('~\{[a-z_]+\}~i', $text);
    }
}
