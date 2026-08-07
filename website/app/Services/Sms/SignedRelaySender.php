<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * پایهٔ رله‌های امضادار — «چه چیزی فرستاده می‌شود» جدا از «از کجا فرستاده می‌شود».
 *
 * ═══ چرا این کلاس هست ═══
 *
 * دو حاملِ متفاوت داریم و هر دو **دقیقاً همان پاکت** را می‌برند:
 *
 *   BaleRelaySender  → گروهِ خصوصیِ بله → رباتِ گیرنده → n8n
 *   N8nRelaySender   → مستقیم به وب‌هوکِ n8n
 *
 * اگر هر کدام نسخهٔ خودش از ساختِ پاکت و امضا را داشت، دیر یا زود یکی عوض
 * می‌شد و دیگری نه — و نتیجه‌اش `bad_signature` در سمتِ n8n بود: پیامکی که
 * بی‌هیچ خطای قابل‌فهمی نمی‌رود. پس منطق این‌جاست و زیرکلاس فقط `deliver()`
 * دارد.
 *
 * ═══ چه چیزی این‌جا **نیست** و عمدی است ═══
 *
 * • کلیدِ APIِ آی‌پی‌پنل
 * • کدِ واقعیِ الگو — فقط نامِ منطقی مثلِ `otp` می‌رود؛ ترجمه‌اش کارِ n8n است.
 *   نگه‌داشتنِ فهرستِ الگوها در دو جا یعنی دیر یا زود یکی کهنه می‌شود و
 *   پیامک بی‌صدا نمی‌رود.
 * • تأییدِ OTP — تولید و تأیید کاملاً در همین پروژه می‌مانَد؛ n8n فقط پیام‌رسان است.
 */
abstract class SignedRelaySender implements SmsSender, SupportsPatterns
{
    /**
     * نام‌های منطقیِ مجاز — باید **دقیقاً** برابرِ رجیستریِ n8n باشد.
     *
     * ═══ 🔴 خرابی‌ای که این فهرست ساخت ═══
     *
     * تا مرداد ۱۴۰۵ این فهرست هفت‌تایی و کهنه بود — از پیکربندیِ قدیمیِ
     * آی‌پی‌پنل مانده بود و با چهارده الگویی که واقعاً ساخته شده بودند تقریباً
     * هیچ هم‌پوشانی نداشت. نتیجه: از ۲۴ رویدادِ وصل‌شده، **فقط سه تا** پیامکشان
     * می‌رفت.
     *
     * دو نیمهٔ خرابی، هر دو بی‌صدا:
     *
     *   نامِ این‌جا نبود ولی الگو بود  → `sendPattern` نال می‌داد → دیسپچر
     *     سراغِ متنِ آزاد می‌رفت → `send()` عمداً `false` می‌دهد → هیچ پیامکی
     *     نمی‌رفت و هیچ خطایی هم تولید نمی‌شد
     *   نامِ این‌جا بود ولی الگو نبود → n8n `unknown_template` می‌گفت و پیام
     *     دور ریخته می‌شد (`paid` — تأییدِ پرداخت — دقیقاً همین بود)
     *
     * ⚠️ **هر تغییری این‌جا باید هم‌زمان در `relay/n8n/verify-and-map-template.js`
     * انجام شود.** `SmsTemplateRegistryTest` هر دو را می‌خوانَد و اگر یکی جلوتر
     * برود قرمز می‌شود — چون تنها نشانهٔ دیگرش، پیامکی است که ماه‌ها بی‌صدا
     * نمی‌رود.
     *
     * ⚠️ رویدادی که این‌جا نیست، پیامک ندارد ولی **ایمیل و بله‌اش می‌رود**.
     * فهرستِ آن‌ها در همان تست صریح ثبت شده تا افزودنِ رویدادِ تازه یک تصمیمِ
     * آگاهانه باشد، نه فراموشی.
     */
    public const TEMPLATES = [
        'otp',
        'welcome',
        'invoice',
        'payment_due',
        'service_ordered',
        'service_failed',
        'renewed',
        'data_deletion_due',
        'terminated',
        'domain_registered',
        'domain_renewed',
        'ticket_new',
        'ticket_closed',
        'ticket_survey',

        // ── دورِ دوم ──
        'paid',
        'service_ready',
        'expiring',
        'suspended',
        'reactivated',
        'ticket_reply',
        'domain_expiring',
        'domain_expired',
        'bank_rejected',
    ];

    protected const VERSION = 1;

    protected const PREFIX = 'SMS_RELAY_V1:';

    /** رازِ مشترک با n8n — امضا با آن ساخته می‌شود */
    abstract protected function secret(): string;

    /**
     * پاکتِ آمادهٔ امضاشده را برسان.
     *
     * استثنا پرتاب کن یا `false` بده؛ پایه خودش ثبتش می‌کند.
     */
    abstract protected function deliver(string $envelope): bool;

    public function hasPattern(string $event): bool
    {
        return in_array(strtolower(trim($event)), self::TEMPLATES, true);
    }

    /**
     * ارسالِ الگو.
     *
     * ⚠️ `null` یعنی «الگویی برای این رویداد ندارم» و `SmsDispatcher` آن‌وقت
     * سراغِ متنِ آزاد می‌رود. `false` یعنی «داشتم و نشد» — و دیسپچر عمداً
     * دوباره تلاش نمی‌کند تا یک پیامِ ناموفق دو بار هزینه نکند.
     */
    public function sendPattern(string $mobile, string $event, array $values): ?bool
    {
        $event = strtolower(trim($event));

        if (! $this->hasPattern($event)) {
            return null;
        }

        // ⚠️ الگوی بی‌متغیر بی‌معناست: اپراتور جای‌نگهدارِ پرنشده را رد می‌کند
        if ($values === []) {
            return null;
        }

        return $this->push($event, $mobile, $values);
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        return $this->push('otp', $mobile, ['code' => $code]) === true;
    }

    /**
     * متنِ آزاد.
     *
     * 🔴 عمداً **پشتیبانی نمی‌شود** و `false` می‌دهد.
     *
     * این رله فقط نامِ منطقیِ الگو را می‌فرستد؛ متنِ آزاد در سمتِ n8n به هیچ
     * الگویی نمی‌خورد و اپراتورِ ایرانی هم پیامِ آزاد را ساعت‌ها در صف نگه
     * می‌دارد یا رد می‌کند. برگرداندنِ `false` صادقانه‌تر از فرستادنِ چیزی است
     * که هرگز نمی‌رسد — و `CustomerNotifier` خودش ایمیل و بله را جدا می‌فرستد،
     * پس پیام به مشتری می‌رسد.
     */
    public function send(string $mobile, string $text): bool
    {
        return false;
    }

    // ───────────────────────── درونی ─────────────────────────

    /** @param array<string,scalar> $params */
    protected function push(string $template, string $mobile, array $params): ?bool
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $normalised = $this->mobile($mobile);
        } catch (\InvalidArgumentException $e) {
            $this->fail($template, $e->getMessage());

            return false;
        }

        $payload = [
            'version'    => static::VERSION,
            'template'   => $template,
            'mobile'     => $normalised,
            'params'     => $this->params($params),
            // 🔴 برای idempotency در سمتِ n8n: حامل ممکن است یک پیام را دوبار
            //    تحویل دهد و بی‌این شناسه، مشتری دو پیامک می‌گیرد و ما دو بار
            //    هزینه می‌دهیم.
            'request_id' => (string) Str::uuid(),
            // ⚠️ n8n پاکتِ خارج از پنجرهٔ زمانی را رد می‌کند (ضدِّ بازپخش)
            'issued_at'  => time(),
        ];

        try {
            if ($this->deliver($this->encode($payload))) {
                return true;
            }

            $this->fail($template, 'حاملِ رله پیام را نپذیرفت');

            return false;
        } catch (\Throwable $e) {
            $this->fail($template, $e->getMessage());

            return false;
        }
    }

    /**
     * ثبتِ شکست در **هر دو** جا.
     *
     * 🔴 `sms:last_error` لازم است چون `/system/sms-status` عمومی است و تنها
     * پنجره‌ای است که بی‌ورود به پنل نشان می‌دهد چرا پیامک نرفت. ردیابِ خطا
     * پشتِ ورودِ مدیر است، و در عیب‌یابیِ «پیامک نمی‌رود» معمولاً همان لحظه
     * دمِ دست نیست.
     *
     * ⚠️ پیامِ خطا نباید مقدارِ حساس داشته باشد — این‌جا فقط نامِ الگو و متنِ
     * خطای حامل می‌نشیند، نه شماره و نه کد.
     */
    protected function fail(string $template, string $reason): void
    {
        \App\Support\ErrorTracker::note('notify', $reason, [
            'template' => $template,
            'area'     => $this->name(),
        ]);

        Cache::put('sms:last_error', [
            'driver'   => $this->name(),
            'template' => $template,
            'reason'   => mb_substr($reason, 0, 300),
            'at'       => now()->toIso8601String(),
        ], now()->addDay());
    }

    /**
     * `SMS_RELAY_V1:{payloadBase64Url}.{hmac}`
     *
     * ⚠️ امضا روی **رشتهٔ Base64** زده می‌شود، نه روی JSON خام. اگر روی JSON
     * بزنیم، هر تفاوتِ ریزِ کدگذاری در سمتِ n8n امضا را می‌شکند — همان دامی
     * که در امضای OVH هم گرفتارش شدیم.
     */
    protected function encode(array $payload): string
    {
        $json = json_encode($payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $sig = hash_hmac('sha256', $b64, $this->secret());

        return static::PREFIX.$b64.'.'.$sig;
    }

    /**
     * @param  array<string,scalar>  $params
     * @return array<string,string>
     */
    protected function params(array $params): array
    {
        $out = [];

        foreach ($params as $k => $v) {
            $k = trim((string) $k);

            if ($k === '' || ! (is_scalar($v) || $v === null)) {
                continue;
            }

            $out[$k] = (string) $v;
        }

        return $out;
    }

    /**
     * موبایلِ ایرانی به E.164.
     *
     * ⚠️ منطقِ تبدیل در `App\Support\IranianMobile` است، نه این‌جا. همین
     * نرمال‌ساز را سفیرِ بله هم لازم دارد (با قالبِ متفاوت)، و دو نسخهٔ جدا
     * یعنی دیر یا زود یکی ارقامِ فارسی را جا می‌اندازد و همان کانال بی‌صدا
     * خاموش می‌شود.
     */
    protected function mobile(string $mobile): string
    {
        $e164 = \App\Support\IranianMobile::e164($mobile);

        if ($e164 === null) {
            throw new \InvalidArgumentException('شمارهٔ موبایلِ ایرانی معتبر نیست: '.mb_substr($mobile, 0, 20));
        }

        return $e164;
    }
}
