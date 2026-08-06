<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * رلهٔ پیامک از راهِ بله.
 *
 * ═══ چرا این مسیر ═══
 *
 * آی‌پی‌پنل به آی‌پیِ غیرِ ایرانی سرویس نمی‌دهد و سرورِ اصلیِ ما در آلمان است.
 * بله (کپیِ APIِ تلگرام) از خارج در دسترس است، پس زنجیره این می‌شود:
 *
 *     این پروژه → رباتِ فرستنده → گروهِ خصوصیِ بله
 *       → رباتِ گیرنده → وب‌هوکِ n8n → آی‌پی‌پنل → موبایلِ مشتری
 *
 * ═══ چه چیزی این‌جا **نیست** و عمدی است ═══
 *
 * • کلیدِ APIِ آی‌پی‌پنل
 * • کدِ واقعیِ الگو (`pattern_code`) — فقط نامِ منطقی مثلِ `otp` می‌رود.
 *   ترجمهٔ نامِ منطقی به کدِ الگو کارِ n8n است. دلیلش همان درسی است که
 *   `QueuedSmsSender` هم رعایتش می‌کند: الگوها در پنلِ اپراتور ساخته می‌شوند و
 *   نگه‌داشتنِ فهرستشان در دو جا یعنی دیر یا زود یکی کهنه می‌شود و پیامک
 *   بی‌صدا نمی‌رود.
 * • تأییدِ OTP — تولید و تأییدِ کد کاملاً در همین پروژه می‌مانَد. n8n فقط
 *   پیام‌رسان است.
 *
 * ═══ ⚠️ هشدارِ امنیتیِ ثبت‌شده ═══
 *
 * بدنه با Base64URL کدگذاری و با HMAC-SHA256 **امضا** می‌شود. امضا جلوی
 * **جعل** را می‌گیرد، ولی Base64 رمزنگاری نیست: هر کسی که به آن گروهِ بله
 * دسترسی پیدا کند — و خودِ بله — با یک `base64 -d` شمارهٔ موبایل و **کدِ ورودِ**
 * هر مشتری را می‌بیند.
 *
 * این ایراد به کارفرما گزارش شد و ایشان آگاهانه این معماری را انتخاب کردند.
 * برای بستنش دو راه هست و هیچ‌کدام معماری را عوض نمی‌کند:
 *   ۱) گروهِ بله فقط دو عضو داشته باشد (دو ربات) و هرگز آدمی اضافه نشود
 *   ۲) بدنه پیش از Base64 با AES-256-GCM رمز شود (کلیدِ مشترک با n8n)
 * اگر روزی راهِ دوم را خواستید، فقط `encode()` این کلاس و یک گامِ decrypt در
 * n8n عوض می‌شود؛ بقیهٔ زنجیره دست‌نخورده می‌مانَد.
 *
 * ═══ چرا درایور، نه سرویسِ مستقل ═══
 *
 * اسپکِ اولیه یک کلاسِ مستقل می‌خواست و جایگزینیِ تک‌تکِ فراخوان‌های آی‌پی‌پنل.
 * ولی این پروژه از قبل قراردادِ `SmsSender`/`SupportsPatterns` و یک رجیستریِ
 * درایور در `AppServiceProvider` دارد. با پیاده‌سازی به‌شکلِ درایور، **هیچ**
 * فراخوانی در کد عوض نمی‌شود — `SmsDispatcher::event/otp/raw` همان‌طور کار
 * می‌کنند و فقط مقصدشان عوض می‌شود. یعنی سطحِ تغییر از ده‌ها نقطه به یک خطِ
 * `.env` می‌آید، و هیچ نقطهٔ فراخوانی از قلم نمی‌افتد.
 */
class BaleRelaySender implements SmsSender, SupportsPatterns
{
    /** نام‌های منطقیِ مجاز — هر چیزِ دیگری رد می‌شود */
    public const TEMPLATES = [
        'otp', 'welcome', 'invoice', 'paid', 'service_ready', 'expiring', 'ticket_reply',
    ];

    private const VERSION = 1;

    private const PREFIX = 'SMS_RELAY_V1:';

    public function __construct(
        private ?string $botToken,
        private ?string $chatId,
        private ?string $secret,
        private string $base = 'https://tapi.bale.ai',
    ) {}

    public function enabled(): bool
    {
        return filled($this->botToken) && filled($this->chatId) && filled($this->secret);
    }

    public function name(): string
    {
        return 'bale-relay';
    }

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
    private function push(string $template, string $mobile, array $params): ?bool
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $normalised = $this->mobile($mobile);
        } catch (\InvalidArgumentException $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['template' => $template]);

            return false;
        }

        $payload = [
            'version'    => self::VERSION,
            'template'   => $template,
            'mobile'     => $normalised,
            'params'     => $this->params($params),
            // 🔴 برای idempotency در سمتِ n8n: بله ممکن است یک پیام را دوبار
            //    تحویل دهد و بی‌این شناسه، مشتری دو پیامک می‌گیرد و ما دو بار
            //    هزینه می‌دهیم.
            'request_id' => (string) Str::uuid(),
            'issued_at'  => time(),
        ];

        try {
            $res = Http::asJson()->acceptJson()->timeout(10)->retry(2, 500)
                ->post(rtrim($this->base, '/')."/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text'    => $this->encode($payload),
                ]);

            // ⚠️ بله مثلِ خیلی از APIهای این حوزه روی خطا هم ۲۰۰ می‌دهد؛
            //    نتیجهٔ واقعی در فیلدِ `ok` بدنه است.
            if (! $res->successful() || $res->json('ok') !== true) {
                \App\Support\ErrorTracker::note('notify', 'رلهٔ بله پیام را نپذیرفت', [
                    'template' => $template,
                    'status'   => $res->status(),
                    'body'     => mb_substr($res->body(), 0, 200),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['template' => $template, 'area' => 'bale-relay']);

            return false;
        }
    }

    /** `SMS_RELAY_V1:{payloadBase64Url}.{hmac}` */
    private function encode(array $payload): string
    {
        $json = json_encode($payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        // ⚠️ امضا روی **رشتهٔ Base64** زده می‌شود، نه روی JSON خام. اگر روی JSON
        //    بزنیم، هر تفاوتِ ریزِ کدگذاری در سمتِ n8n امضا را می‌شکند — همان
        //    دامی که در امضای OVH هم گرفتارش شدیم.
        $sig = hash_hmac('sha256', $b64, (string) $this->secret);

        return self::PREFIX.$b64.'.'.$sig;
    }

    /**
     * @param  array<string,scalar>  $params
     * @return array<string,string>
     */
    private function params(array $params): array
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
     * ⚠️ ارقامِ فارسی و عربی هم پذیرفته می‌شوند: کاربر معمولاً از صفحه‌کلیدِ
     * فارسی تایپ می‌کند و `preg_replace('/\D+/')` آن‌ها را **پاک** می‌کرد، نه
     * تبدیل — یعنی شمارهٔ درست به «خالی» می‌رسید و پیامک بی‌صدا نمی‌رفت.
     */
    private function mobile(string $mobile): string
    {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $mobile = str_replace(array_merge($fa, $ar), array_merge(range(0, 9), range(0, 9)), $mobile);

        $d = preg_replace('/\D+/', '', $mobile) ?? '';

        foreach (['0098' => 4, '98' => 2, '0' => 1] as $prefix => $len) {
            if (str_starts_with($d, (string) $prefix)) {
                $d = substr($d, $len);
                break;
            }
        }

        if (! preg_match('/^9\d{9}$/', $d)) {
            throw new \InvalidArgumentException('شمارهٔ موبایلِ ایرانی معتبر نیست: '.mb_substr($mobile, 0, 20));
        }

        return '+98'.$d;
    }
}
