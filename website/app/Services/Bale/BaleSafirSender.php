<?php

namespace App\Services\Bale;

use App\Support\ErrorTracker;
use App\Support\IranianMobile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * سفیرِ بله — پیام به **شمارهٔ موبایل**، بی‌نیاز به ورودِ کاربر به ربات.
 *
 * ═══ چرا این مسیر همه‌چیز را عوض می‌کند ═══
 *
 * راهِ قبلی (`BaleSender` روی APIِ ربات) `chat_id` می‌خواست، و `chat_id` فقط
 * وقتی به دست می‌آمد که کاربر **خودش** وارد ربات شود و شماره‌اش را به اشتراک
 * بگذارد. عملاً یعنی کانالِ بله برای اکثریتِ مشتری‌ها خاموش بود و صفحهٔ کدِ
 * ورود مجبور بود بنویسد «وارد ربات شوید و شماره‌تان را به اشتراک بگذارید».
 *
 * سفیر مستقیم با شماره کار می‌کند. پس بله از یک «کانالِ اختیاری برای عدهٔ کم»
 * به یک **مسیرِ دومِ واقعی** تبدیل می‌شود — که وقتی پیامک نمی‌رسد (فیلتر،
 * اپراتور، خارج از ایران) تفاوتِ بین ورودِ موفق و مشتریِ پشتِ درِ بسته است.
 *
 * ═══ مرزِ استفاده ═══
 *
 * ⚠️ فقط پیام‌های **سمتِ مشتری** از این‌جا می‌روند. اعلانِ مدیر و پیامِ گروهِ
 * داخلی همچنان از `BaleSender` (APIِ ربات) می‌روند: آن‌ها به `chat_id` مشخص و
 * پایدار می‌روند، هزینهٔ سفیر ندارند، و قاطی‌کردنشان یعنی یک خطای اعتبار در
 * سفیر، هشدارهای داخلی را هم می‌خواباند.
 *
 * ═══ 🔴 خواندنِ پاسخ ═══
 *
 * موفقیت یعنی `error_data` **خالی یا نال** باشد. هر شکلِ ناشناخته‌ای شکست
 * شمرده می‌شود (fail-closed) — همان درسی که امروز گران تمام شد: پاسخِ موفقِ
 * اپراتور با شکلِ اشتباه خوانده شد و پیامکِ رفته «شکست» گزارش شد، و در جای
 * دیگری هر بدنهٔ ناشناختهٔ ۲۰۰ «موفق» شمرده می‌شد.
 */
class BaleSafirSender
{
    /** کاربر اصلاً حسابِ بله ندارد — نتیجهٔ **عادی** است، نه خرابی */
    private const NOT_A_BALE_USER = 17;

    /** اعتبار تمام شده — کلِ کانال می‌خوابد و باید بلند گزارش شود */
    private const PAYMENT_REQUIRED = 20;

    public function __construct(
        private ?string $key,
        private ?int $botId,
        private string $base = 'https://safir.bale.ai',
    ) {}

    public function enabled(): bool
    {
        return filled($this->key) && $this->botId !== null && $this->botId > 0;
    }

    /**
     * کدِ یک‌بارمصرف — از مسیرِ اختصاصیِ خودِ سفیر (`otp_message`).
     *
     * ⚠️ عمداً از `text()` جدا است: سفیر برای کد قالبِ ویژهٔ خودش را دارد و
     * کلاینتِ بله آن را متفاوت نشان می‌دهد (قابلِ کپی، بی‌پیش‌نمایشِ لینک).
     * فرستادنِ کد به‌صورتِ متنِ معمولی هم می‌رسد، ولی تجربهٔ بدتری می‌دهد.
     */
    public function otp(string $mobile, string $code): bool
    {
        return $this->post($mobile, ['otp_message' => ['otp' => $code]], 'otp');
    }

    /** پیامِ متنیِ معمولی به مشتری */
    public function text(string $mobile, string $text, string $context = 'notify'): bool
    {
        return $this->post($mobile, ['message' => ['text' => $text]], $context);
    }

    // ───────────────────────── درونی ─────────────────────────

    private function post(string $mobile, array $messageData, string $context): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        // ⚠️ سفیر `09…` را با کدِ ۸ رد می‌کند؛ باید `989…` باشد
        $phone = IranianMobile::bare($mobile);

        if ($phone === null) {
            return false;
        }

        try {
            $res = Http::asJson()->acceptJson()->timeout(10)
                /*
                | 🔴 فقط روی **قطعیِ ارتباط** تلاشِ دوباره، و بی‌throw.
                |
                | نسخهٔ قبلی `retry(2, 400)` بود، یعنی پیش‌فرضِ لاراول: هر پاسخِ
                | ناموفق — از جمله ۴۰۰ — یک استثنا می‌شد، **دوباره فرستاده
                | می‌شد**، و در نهایت پرتاب می‌شد. سه پیامدِ واقعی داشت:
                |
                | ۱) شرطِ `! $res->successful()` پایین‌تر برای ۴xx عملاً **مرده**
                |    بود؛ همه‌چیز از `catch` رد می‌شد و متنی که در ردیابِ خطا
                |    می‌نشست پیامِ خامِ لاراول بود: «HTTP request returned status
                |    code 400: …». دقیقاً همان ردیفِ بی‌کلاس و بی‌آدرسی که در
                |    پنل دیده شد.
                | ۲) `handleErrors()` هرگز برای پاسخِ ۴xx اجرا نمی‌شد. یعنی اگر
                |    سفیر «اعتبار تمام شد» (کدِ ۲۰) را با وضعیتِ غیرِ ۲۰۰ بدهد،
                |    کلِ کانالِ بله می‌خوابد و **تنها هشداری که برای همین ساخته
                |    شده هرگز شلیک نمی‌کند**. همان قاعدهٔ CLAUDE.md: ناظری که
                |    دقیقاً وقتِ خرابی ساکت شود، از نبودش بدتر است.
                | ۳) تلاشِ دوباره همان `request_id` را می‌فرستد (عمدی، پایین را
                |    بخوان)، پس سفیر آن را «قبلاً پردازش شده» می‌خوانَد و ۴۰۰
                |    می‌دهد — برای پیامی که **رفته بود**.
                |
                | ۴۰۰ هرگز با تکرار ۲۰۰ نمی‌شود. «نشنیدیم» با «نه گفت» یکی نیست.
                */
                ->retry(2, 400, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, false)
                ->withHeaders(['api-access-key' => (string) $this->key])
                ->post(rtrim($this->base, '/').'/api/v3/send_message', [
                    // 🔴 ضدِّ تکرار: اگر تلاشِ دوباره‌ای رخ دهد، سفیر با همین
                    //    شناسه پیام را دو بار نمی‌فرستد. بی‌آن، هر retry یک
                    //    پیامِ تکراری و یک هزینهٔ تکراری است.
                    'request_id'   => (string) Str::uuid(),
                    'bot_id'       => $this->botId,
                    'phone_number' => $phone,
                    'message_data' => $messageData,
                ]);

            if (! $res->successful()) {
                return $this->handleUnsuccessful($res, $context);
            }

            $errors = $res->json('error_data');

            /*
            | ✔ موفقیت = **هم** `message_id` باشد **هم** `error_data` خالی.
            |
            | ⚠️ فقط «error_data خالی است» کافی نیست: بدنهٔ ناشناخته‌ای مثلِ
            | پاسخِ پروکسی یا صفحهٔ خطای HTML هم `error_data` ندارد و آن‌وقت
            | «موفق» شمرده می‌شد. نسخهٔ اولِ همین کلاس دقیقاً همین سوراخ را
            | داشت — و تست گرفتش. طبقِ مستندات، پاسخِ موفق **همیشه**
            | `message_id` دارد.
            */
            if (($errors === null || $errors === []) && filled($res->json('message_id'))) {
                return true;
            }

            if (! is_array($errors) || $errors === []) {
                $this->fail($context, 'پاسخِ ناشناختهٔ سفیر: '.mb_substr($res->body(), 0, 120));

                return false;
            }

            return $this->handleErrors($errors, $context);
        } catch (\Throwable $e) {
            $this->fail($context, $e->getMessage());

            return false;
        }
    }

    /**
     * پاسخِ غیرِ ۲۰۰ — **از بدنه** تصمیم بگیر، نه از عددِ وضعیت.
     *
     * ═══ چرا ═══
     *
     * تا امروز هر وضعیتِ غیرِ ۲۰۰ فقط یک متن می‌شد و بدنه دور ریخته می‌شد. پس
     * کدهای خودِ سفیر (۱۷ «کاربر بله ندارد» که باید ساکت باشد، ۲۰ «اعتبار تمام
     * شد» که باید بلند فریاد بزند) هر وقت با وضعیتِ ۴xx می‌آمدند **نادیده**
     * می‌شدند — یعنی خوابیدنِ کلِ کانال می‌توانست بی‌صدا رد شود.
     *
     * همان تلهٔ ثبت‌شده در CLAUDE.md، وارونه: «زحل روی خطا هم ۲۰۰ می‌دهد، هرگز
     * به کدِ HTTP تکیه نکن.» این‌جا سفیر روی وضعیتِ خطا هم بدنهٔ معنادار می‌دهد.
     */
    private function handleUnsuccessful(\Illuminate\Http\Client\Response $res, string $context): bool
    {
        /*
        | 🔴 «این درخواست قبلاً پردازش شده» یعنی پیام **رفته**، نه اینکه نرفته.
        |
        | `request_id` را عمداً بینِ تلاش‌ها ثابت نگه می‌داریم تا سفیر پیام را
        | دو بار نفرستد. پس پاسخِ «تکراری است» دقیقاً همان چیزی است که خواسته
        | بودیم — خواندنش به‌عنوانِ شکست یعنی گزارشِ خرابی برای پیامی که رسیده،
        | و همان چیزی است که در ردیابِ خطای پروداکشن دیده شد.
        |
        | ⚠️ عمداً باریک: فقط وقتی بدنه صریح همین را بگوید. هر ۴۰۰ِ دیگری
        | مثلِ گذشته شکست است — بدترین حالتِ این شرط، برگشتن به رفتارِ امروز.
        */
        $body = (string) $res->body();

        if ($res->status() === 400 && preg_match('~processed\s+request\s+id~i', $body)) {
            return true;
        }

        $errors = $res->json('error_data');

        if (is_array($errors) && $errors !== []) {
            return $this->handleErrors($errors, $context);
        }

        $this->fail($context, 'سفیر کدِ '.$res->status().' داد: '.mb_substr($body, 0, 160));

        return false;
    }

    /** @param array<int,array<string,mixed>> $errors */
    private function handleErrors(array $errors, string $context): bool
    {
        $code = (int) ($errors[0]['code'] ?? 0);
        $desc = (string) ($errors[0]['description'] ?? '');

        /*
        | 🔴 «کاربر بله ندارد» خطا نیست.
        |
        | بخشِ بزرگی از مشتری‌ها بله ندارند و این کاملاً عادی است. اگر ثبتش
        | کنیم، ردیابِ خطا با نویز پر می‌شود و مدیر از روزِ دوم نگاهش نمی‌کند —
        | و آن‌وقت خطاهای **واقعی** هم دیده نمی‌شوند. بدتر از نداشتنِ هشدار.
        */
        if ($code === self::NOT_A_BALE_USER) {
            return false;
        }

        /*
        | 🔴 نبودِ اعتبار یعنی کانالِ بله برای **همه** خوابیده.
        |
        | این تنها خطایی است که به کشِ عمومی هم می‌رود، چون تفاوتش با بقیه این
        | است که خودبه‌خود درست نمی‌شود و تا شارژ نشود هیچ پیامی نمی‌رود.
        */
        if ($code === self::PAYMENT_REQUIRED) {
            Cache::put('bale:safir_error', [
                'reason' => 'اعتبارِ سفیرِ بله تمام شده — هیچ پیامی به مشتری نمی‌رود',
                'at'     => now()->toIso8601String(),
            ], now()->addDay());
        }

        $this->fail($context, "سفیر ردش کرد ({$code}): ".mb_substr($desc, 0, 120));

        return false;
    }

    private function fail(string $context, string $reason): void
    {
        ErrorTracker::note('notify', $reason, ['area' => 'bale-safir', 'context' => $context]);
    }
}
