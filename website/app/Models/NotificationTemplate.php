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
        // ⚠️ `audience` باید این‌جا باشد وگرنه mass assignment بی‌صدا دورش
        //    می‌ریزد: ردیف ساخته می‌شود ولی با پیش‌فرضِ `customer`، و اعلانِ
        //    مدیر هرگز در صفحهٔ خودش دیده نمی‌شود.
        'key', 'title', 'group', 'audience', 'email_subject', 'email_body',
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
     * روی کدام کانال‌ها این الگو **واقعاً** مصرف می‌شود.
     *
     * ═══ 🔴 چرا دیگر نقشهٔ دستی نیست ═══
     *
     * تا مرداد ۱۴۰۵ این‌جا یک آرایهٔ سخت‌کد بود با این کامنت: «دستی نگه داشته
     * می‌شود — هر بار که کلیدی را به کدی وصل کردی، این‌جا هم اضافه‌اش کن».
     * همان جمله تضمینِ کهنه‌شدن بود، و شد: نقشه می‌گفت `welcome` و `invoice`
     * «به هیچ کدی وصل نیستند»، در حالی که هر ثبت‌نام و هر سفارش دقیقاً همان
     * متن‌ها را به مشتری می‌فرستاد.
     *
     * دو دروغِ متقارن، و هر دو گران:
     *
     *   نقشه می‌گفت «مرده» ولی زنده بود  → مدیر متنِ ناقص را رها می‌کرد و
     *     همان متن به مشتری می‌رسید
     *   نقشه می‌گفت «فقط بله» ولی ایمیل هم می‌رفت → مدیر متنی داخلی/آزمایشی
     *     در ایمیل می‌نوشت با این خیال که فرستاده نمی‌شود
     *
     * حالا از **کاتالوگِ رویداد** مشتق می‌شود — همان منبعی که خودِ `Notifier`
     * از رویش تصمیم می‌گیرد. پس نقشه نمی‌تواند از واقعیت عقب بماند.
     *
     * @return array<int,string>
     */
    public function wiredChannels(): array
    {
        return static::channelsFor((string) $this->key);
    }

    /**
     * @return array<int,string>  زیرمجموعه‌ای از sms · bale · email
     */
    public static function channelsFor(string $key): array
    {
        $event = \App\Services\Notify\NotifyEvent::get($key);

        // رویدادی که در کاتالوگ نیست یا هنوز فراخوان ندارد = واقعاً مرده
        if ($event === null || ! $event['wired'] || ! \App\Services\Notify\NotifyEvent::notifiesCustomer($key)) {
            return [];
        }

        /*
        | بله و ایمیل همیشه: هر رویدادِ مشتری‌محور از `CustomerNotifier` رد
        | می‌شود و آن‌جا هر دو کانال تلاش می‌شوند.
        |
        | ⚠️ پیامک **مشروط** است: فقط اگر الگویی در پنلِ اپراتور برایش ساخته
        | شده باشد. `SignedRelaySender::TEMPLATES` تنها جایی است که این را
        | می‌داند، و `SmsTemplateRegistryTest` آن را با رجیستریِ n8n قفل کرده —
        | پس این ستون هم خودبه‌خود درست می‌مانَد.
        */
        $channels = ['bale', 'email'];

        if (in_array($key, \App\Services\Sms\SignedRelaySender::TEMPLATES, true)) {
            array_unshift($channels, 'sms');
        }

        return $channels;
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

    /**
     * هنوز جای‌نگهدارِ پرنشده دارد؟
     *
     * 🔴 الگو **باید** یونیکد باشد. نسخهٔ اولش `[a-z_]` بود و تگ‌های فارسی را
     * اصلاً نمی‌دید: پیامی با «{مشتری}»ِ پرنشده از این محافظ رد می‌شد و همان
     * چیزی بیرون می‌رفت که این متد قرار بود جلویش را بگیرد. محافظی که فقط
     * برای نیمی از ورودی‌ها کار کند، دقیقاً برای نیمهٔ دیگر ساکت است.
     *
     * ⚠️ و عمداً فقط **حرف/زیرخط/فاصله**، نه هر چیزی داخلِ آکولاد: متنِ ایمیل
     * CSS دارد و `{margin:0}` نباید «تگِ پرنشده» خوانده شود — وگرنه محافظ
     * ایمیل‌های سالم را هم جلو می‌گیرد و آن‌ها بی‌صدا فرستاده نمی‌شوند.
     */
    private static function incomplete(string $text): bool
    {
        return (bool) preg_match('~\{[\p{L}\p{M}_][\p{L}\p{M}_ ]*\}~u', $text);
    }
}
