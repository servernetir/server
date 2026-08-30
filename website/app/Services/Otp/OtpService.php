<?php

namespace App\Services\Otp;

use App\Models\OtpChallenge;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SupportsPatterns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * کد یک‌بارمصرف — دروازهٔ قبل از هر کار پرهزینه.
 *
 * سه سقف مستقل دارد و هر سه لازم‌اند، چون هر کدام جلوی یک حملهٔ متفاوت را
 * می‌گیرند:
 *
 *   سقف روی مقصد   → کسی نمی‌تواند یک شماره را بمباران پیامکی کند
 *   سقف روی IP     → کسی نمی‌تواند با هزار شمارهٔ مختلف اعتبار ما را بسوزاند
 *   سقف روی تلاش   → کسی نمی‌تواند کد شش‌رقمی را حدس بزند (۱۰ تلاش، نه ۱۰۰۰۰۰۰)
 *
 * کد خام هرگز ذخیره نمی‌شود؛ فقط hmac با کلید اپ.
 */
class OtpService
{
    public const LENGTH      = 6;
    public const TTL_MINUTES = 3;

    /** حداکثر ارسال برای یک مقصد در یک ساعت */
    public const MAX_PER_DESTINATION = 5;

    /** حداکثر ارسال از یک IP در یک روز */
    public const MAX_PER_IP = 20;

    /** حداکثر تلاش برای وارد کردن کد یک چالش */
    public const MAX_ATTEMPTS = 10;

    /** فاصلهٔ لازم بین دو ارسال به یک مقصد (ثانیه) */
    public const RESEND_COOLDOWN = 60;

    /**
     * هدفِ کد → نامِ الگوی **اختصاصیِ** پیامک. هدفی که این‌جا نباشد الگوی
     * عمومیِ `otp` را می‌گیرد.
     *
     * ═══ 🔴 خرابی‌ای که این نقشه برای رفعش ساخته شد ═══
     *
     * حذفِ سرویس از روزِ اول هدفِ جدای خودش را داشت (`service_terminate`) و
     * جداییِ **اعتبارسنجی** درست کار می‌کرد — کدِ حذف هرگز ورود نمی‌داد. ولی
     * `issue()` برای هر هدفی همان `sendOtp()` را صدا می‌زد، و آن همیشه الگوی
     * `otp` را می‌فرستد. یعنی مشتری‌ای که داشت سرورش را **برای همیشه پاک
     * می‌کرد**، پیامکی می‌گرفت که می‌گفت «کد ورود». بدترین لحظهٔ ممکن برای
     * ابهام: کاربر یا فکر می‌کند کسی به حسابش وارد شده، یا کد را جدی نمی‌گیرد.
     *
     * ⚠️ نامِ الگو باید در **هر سه** جا باشد وگرنه پیامک بی‌صدا نمی‌رود:
     *   `SignedRelaySender::TEMPLATES` · `SignedRelaySender::OTP_TEMPLATES`
     *   · `relay/n8n/verify-and-map-template.js`
     * (`SmsTemplateRegistryTest` هر سه را با هم قفل کرده.)
     *
     * ⚠️ کدِ واقعیِ الگو **این‌جا نیست و نباید باشد** — فقط نامِ منطقی. ترجمهٔ
     * نام به کدِ اپراتور کارِ n8n است؛ نگه‌داشتنِ کد در دو جا یعنی دیر یا زود
     * یکی کهنه می‌شود.
     */
    public const SMS_TEMPLATES = [
        'service_terminate' => 'otp_service_delete',
    ];

    public function __construct(private SmsSender $sms) {}

    /**
     * ارسال کد. اگر چالش فعالی هست و هنوز خنک نشده، دوباره نمی‌فرستد.
     */
    public function issue(string $channel, string $destination, string $purpose, ?string $ip): OtpIssue
    {
        $destination = $this->normalize($channel, $destination);

        if ($destination === '' || ($channel === 'email' && ! filter_var($destination, FILTER_VALIDATE_EMAIL))) {
            return OtpIssue::fail($channel === 'email' ? 'ایمیل معتبر نیست' : 'شمارهٔ موبایل معتبر نیست');
        }

        /*
        | ۱) سقفِ آی‌پی — فقط برای مسیرهای **پیش از داشتنِ حساب**.
        |
        | 🔴 قبلاً روی همهٔ purposeها بود، و این پشتِ CGNAT فاجعه می‌سازد:
        | اپراتورهای موبایلِ ایران هزاران مشترک را از یک آی‌پیِ خروجی رد
        | می‌کنند. بعد از ۲۰ کد در ۲۴ ساعت، **همهٔ** کاربرانِ آن آی‌پی می‌افتادند
        | — از جمله مشتریِ پولی که فقط می‌خواست رمزش را عوض کند یا سرورش را
        | حذف کند. یعنی سقفی که برای جلوگیری از سوءاستفاده گذاشته شده بود،
        | مشتریِ واقعی را از کارهای حساسِ حسابِ خودش قفل می‌کرد.
        |
        | ⚠️ برای مسیرهای داخلِ حساب، محافظِ واقعی جای دیگری است: کاربر باید
        | از قبل وارد شده باشد. سقفِ مقصد (پایین) هم همچنان برقرار است.
        */
        $preAccount = in_array($purpose, ['register', 'login'], true);

        if ($preAccount && $ip !== null && $this->countForIp($ip) >= self::MAX_PER_IP) {
            return OtpIssue::fail(
                'تعداد درخواست‌ها از این شبکه زیاد بوده است. اگر از اینترنت همراه استفاده می‌کنید '
                .'شبکهٔ دیگری را امتحان کنید، یا با پشتیبانی تماس بگیرید.'
            );
        }

        // ۲) سقف مقصد
        if ($this->countForDestination($destination, $purpose) >= self::MAX_PER_DESTINATION) {
            return OtpIssue::fail('برای این شماره دفعات زیادی کد فرستاده شده. یک ساعت دیگر تلاش کنید.');
        }

        // ۳) خنک‌کننده — چالش فعال موجود
        $active = $this->activeChallenge($destination, $purpose);

        if ($active !== null) {
            $since = (int) abs($active->updated_at?->diffInSeconds(now()) ?? PHP_INT_MAX);

            if ($since < self::RESEND_COOLDOWN) {
                $wait = self::RESEND_COOLDOWN - $since;

                // شماره در پیام می‌آید تا اگر کاربر شماره‌اش را عوض کرده و
                // باز این را دید، بفهمد پیام دربارهٔ کدام شماره است
                return OtpIssue::fail(
                    'کد قبلی برای '.$destination.' هنوز معتبر است. '
                    .$wait.' ثانیه دیگر می‌توانید کد تازه بخواهید.',
                    retryAfter: $wait,
                );
            }
        }

        $code = $this->randomCode();

        $challenge = OtpChallenge::create([
            'channel'     => $channel,
            'destination' => $destination,
            'purpose'     => $purpose,
            'code_hash'   => $this->hash($code),
            'attempts'    => 0,
            'resends'     => ($active->resends ?? -1) + 1,
            'ip'          => $ip,
            'expires_at'  => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // sendOtp و نه send — مسیر الگو، که اپراتور فوری تحویل می‌دهد.
        // با مسیر پیام آزاد، کد سه‌دقیقه‌ای ما اغلب منقضی می‌رسید.
        //
        // نمایش کد روی صفحه (حالت آزمایشی) عمداً حذف شد: کارفرما خواست کد
        // «به هیچ عنوان» روی صفحه نیاید. حالا همه‌ی شماره‌ها پیامک واقعی
        // می‌گیرند و درایور صف، اگر chat_id بله موجود باشد، هم‌زمان بله هم
        // می‌فرستد.
        $sent = match ($channel) {
            'sms'   => $this->sendSms($destination, $code, $purpose),
            'email' => $this->sendEmail($destination, $code, $purpose),
            default => false,
        };

        if (! $sent) {
            /*
            | 🔴 منقضی می‌شود، **حذف نمی‌شود**.
            |
            | نسخهٔ قبلی `$challenge->delete()` می‌زد و این یک حفرهٔ پولی ساخت:
            | `countForDestination()` روی همین ردیف‌ها می‌شمارد، پس هر ارسالِ
            | ناموفق ردِ خودش را پاک می‌کرد و سقفِ «۵ بار در ساعت» **هرگز بالا
            | نمی‌رفت**. یعنی حلقهٔ نامحدودِ درخواستِ کد.
            |
            | و بدتر: «ناموفق» همیشه یعنی «نرفت» نیست. یک بار پیامک واقعاً رفت
            | و چون پاسخِ اپراتور با شکلِ اشتباه خوانده شد «شکست» شمرده شد —
            | پیامکِ پول‌دار رفت، کد پاک شد، و شمارنده هم بالا نرفت. سه خسارت
            | از یک اشتباه.
            |
            | ⚠️ `activeChallenge()` روی `expires_at > now()` فیلتر می‌کند، پس
            | ردیفِ منقضی نه در خنک‌کننده دخالت می‌کند و نه با کدِ سوخته قابلِ
            | استفاده است — ولی در سقفِ مقصد **شمرده می‌شود**، که همان چیزی است
            | که می‌خواهیم.
            */
            $challenge->forceFill(['expires_at' => now()->subSecond()])->save();

            return OtpIssue::fail($channel === 'email'
                ? 'ارسال ایمیل انجام نشد. کمی بعد دوباره تلاش کنید یا از موبایل استفاده کنید.'
                : 'ارسال پیامک انجام نشد. سرویس پیامک موقتاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید.');
        }

        // بله موازی — اگر کاربر بله را وصل کرده باشد، کد آن‌جا هم می‌رود.
        // best-effort و بی‌خطر: هرگز جریان ثبت‌نام را نمی‌شکند.
        if ($channel === 'sms') {
            // مسیرِ اختصاصیِ کدِ سفیر — بی‌نیاز به ورودِ کاربر به ربات
            app(\App\Services\Bale\BaleNotifier::class)->otp($destination, $code);

            // ایمیلِ موازی هم: پیامک گاهی نمی‌رسد (اپراتور/فیلتر) و کاربر پشتِ
            // درِ بسته می‌ماند. اگر این شماره مشتریِ ثبت‌شده‌ای با ایمیل باشد،
            // همان کد به ایمیلش هم می‌رود. کاملاً best-effort.
            try {
                /*
                | 🔴 فقط برای حسابِ **فعال**، و هرگز در ثبت‌نام.
                |
                | سناریوی واقعی که این را لازم کرد: شخصِ الف ثبت‌نام را نیمه‌کاره
                | رها می‌کند و یک ردیفِ `pending` با شمارهٔ P و ایمیلِ خودش
                | می‌مانَد. بعداً شخصِ ب — که همان شماره را گرفته (اپراتور شماره
                | را بازچرخانده) — ثبت‌نام می‌کند. پیامک درست به P می‌رفت، ولی
                | **همان کد** به ایمیلِ الف هم می‌رفت: یعنی الف می‌توانست حسابِ
                | ب را تصاحب کند.
                |
                | ⚠️ در ثبت‌نام اصلاً ایمیلِ موازی نمی‌فرستیم، چون هنوز هیچ
                | ایمیلی به این شماره **تعلق ندارد**.
                */
                if ($purpose !== 'register') {
                    $email = \App\Models\Customer::where('phone', $destination)
                        ->where('status', 'active')
                        ->value('email');

                    if (filled($email)) {
                        $this->sendEmail((string) $email, $code);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return new OtpIssue(true, challengeId: $challenge->id, expiresAt: $challenge->expires_at);
    }

    /**
     * بررسی کد. مصرف‌شده یعنی مصرف‌شده — تأیید دوباره ممکن نیست.
     */
    public function verify(string $channel, string $destination, string $purpose, string $code): OtpCheck
    {
        $destination = $this->normalize($channel, $destination);
        $challenge   = $this->activeChallenge($destination, $purpose);

        if ($challenge === null) {
            return OtpCheck::fail('کدی برای این شماره فعال نیست. دوباره درخواست کد بدهید.');
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            return OtpCheck::fail('تعداد تلاش‌ها زیاد شد. کد تازه بخواهید.');
        }

        /*
        | 🔴 `increment()` برچسبِ `updated_at` را تازه می‌کند — و همان برچسب
        | لنگرِ خنک‌کنندهٔ ۶۰ ثانیه است.
        |
        | نتیجه‌اش این بود که هر کدِ **غلط**، ۶۰ ثانیه به انتظارِ «ارسال دوباره»
        | اضافه می‌کرد. کاربری که سه بار اشتباه تایپ می‌کرد، از عمرِ سه‌دقیقه‌ایِ
        | کد چیزی برایش نمی‌مانْد و در عین حال نمی‌توانست کدِ تازه بخواهد —
        | یعنی دقیقاً وقتی گیر می‌کرد که بیشتر از همیشه به کدِ تازه نیاز داشت.
        |
        | `timestamps = false` جلوی این را می‌گیرد؛ شمارنده بالا می‌رود ولی
        | ساعتِ خنک‌کننده سرِ جای ارسال می‌مانَد.
        */
        $challenge->timestamps = false;
        $challenge->increment('attempts');
        $challenge->timestamps = true;

        if (! hash_equals($challenge->code_hash, $this->hash($this->digits($code)))) {
            /*
            | ⚠️ رسیدن به سقفِ تلاش یعنی این چالش **سوخته** است. اگر منقضی‌اش
            | نکنیم، `activeChallenge()` هنوز برش می‌دارد و خنک‌کننده می‌گوید
            | «کدِ قبلی هنوز معتبر است» — یعنی کاربر نه می‌تواند این را وارد
            | کند و نه کدِ تازه بگیرد. بن‌بستِ کامل.
            */
            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                $challenge->forceFill(['expires_at' => now()->subSecond()])->save();
            }

            return OtpCheck::fail('کد وارد شده درست نیست.');
        }

        $challenge->forceFill(['verified_at' => now()])->save();

        return new OtpCheck(true, challenge: $challenge);
    }

    /**
     * باطل کردن کدهای فعال یک مقصد.
     *
     * وقتی کاربر شماره‌اش را عوض می‌کند، کدی که به شمارهٔ قبلی رفته باید
     * همان‌جا بمیرد. دو دلیل:
     *
     *   • آن شماره ممکن است اصلاً مال کس دیگری باشد (غلط تایپی)، و کد زنده
     *     ماندنش یعنی سه دقیقه پنجرهٔ سوءاستفاده
     *   • خنک‌کنندهٔ شمارهٔ قدیمی نباید جلوی شمارهٔ تازه را بگیرد
     *
     * منقضی می‌کنیم و حذف نمی‌کنیم، تا سقف شمارش و ردِ بازرسی دست‌نخورده
     * بماند — وگرنه کسی می‌توانست با عوض‌کردن پیاپی شماره، سقف را دور بزند.
     */
    public function abandon(string $channel, string $destination, string $purpose): void
    {
        $destination = $this->normalize($channel, $destination);

        if ($destination === '') {
            return;
        }

        OtpChallenge::where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()->subSecond(), 'updated_at' => now()]);
    }

    /**
     * آیا این مقصد اخیراً برای این کار تأیید شده؟
     * جریان ثبت‌نام از این استفاده می‌کند تا مطمئن شود مرحلهٔ پیامک واقعاً
     * طی شده و کسی مستقیم به مرحلهٔ استعلام پولی نپریده است.
     */
    public function recentlyVerified(string $channel, string $destination, string $purpose, int $withinMinutes = 30): bool
    {
        return OtpChallenge::where('channel', $channel)
            ->where('destination', $this->normalize($channel, $destination))
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();
    }

    /** شمارهٔ موبایل ایرانی به شکل 09xxxxxxxxx */
    public function normalize(string $channel, string $destination): string
    {
        if ($channel === 'email') {
            return mb_strtolower(trim($destination));
        }

        $d = $this->digits($destination);

        $iran = match (true) {
            str_starts_with($d, '0098') => '0'.substr($d, 4),
            str_starts_with($d, '98')   => '0'.substr($d, 2),
            str_starts_with($d, '9')    => '0'.$d,
            default                     => $d,
        };

        if (preg_match('/^09\d{9}$/', $iran) === 1) {
            return $iran;
        }

        /*
        | بین‌المللی (E.164) — مشتریِ خارجی حالا موبایل می‌دهد (۵ شهریور ۱۴۰۵).
        |
        | ورودی می‌تواند «+90…»، «0090…» یا «90…» باشد؛ خروجی همیشه «+رقم‌ها».
        | شمارهٔ ملیِ بی‌کدِ کشور (مثلاً 05xx ترکیه) عمداً رد می‌شود: کشور را
        | نمی‌شود حدس زد و حدسِ غلط یعنی کدِ تأییدِ کسی به گوشیِ کسِ دیگر.
        | 98 هم این‌جا رد می‌شود — ایرانی همان بالا با قالبِ ۰۹ پذیرفته شده.
        */
        $intl = str_starts_with($d, '00') ? substr($d, 2) : $d;

        if (preg_match('/^[1-9]\d{7,14}$/', $intl) === 1 && ! str_starts_with($intl, '98')) {
            return '+'.$intl;
        }

        return '';
    }

    private function activeChallenge(string $destination, string $purpose): ?OtpChallenge
    {
        return OtpChallenge::where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->whereNull('verified_at')
            ->latest('id')
            ->first();
    }

    private function countForDestination(string $destination, string $purpose): int
    {
        return OtpChallenge::where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    private function countForIp(string $ip): int
    {
        return OtpChallenge::where('ip', $ip)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    private function randomCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * آیا این مقصد در فهرست شماره‌های آزمایشی است؟
     * فهرست از .env می‌آید (OTP_TEST_NUMBERS) و پیش‌فرض خالی است — یعنی
     * روی نصب تازه هیچ شماره‌ای این مسیر را نمی‌رود.
     */
    public function isTestDestination(string $destination): bool
    {
        $raw = (string) config('services.sms.test_numbers', '');

        if ($raw === '') {
            return false;
        }

        $list = array_filter(array_map(
            fn (string $n) => $this->normalize('sms', trim($n)),
            explode(',', $raw),
        ));

        return in_array($destination, $list, true);
    }

    /** نامِ الگوی پیامکی که این هدف با آن فرستاده می‌شود */
    public static function smsTemplateFor(string $purpose): string
    {
        return self::SMS_TEMPLATES[$purpose] ?? 'otp';
    }

    /**
     * ارسال کد با پیامک — با الگوی اختصاصیِ همان هدف، اگر داشته باشد.
     *
     * ⚠️ چرا `sendPattern` و نه `sendOtp`: `sendOtp` نامِ الگو را **سخت‌کد**
     * `otp` دارد (و باید داشته باشد — ورود پرتکرارترین مسیر است). پس برای هدفِ
     * اختصاصی باید صریح نامِ الگو را بدهیم.
     *
     * ⚠️ `null` از `sendPattern` یعنی «این درایور چنین الگویی ندارد» (یا خاموش
     * است). در آن حالت **عمداً** به الگوی عمومی برمی‌گردیم: پیامکِ با متنِ
     * «کد ورود» گیج‌کننده است، ولی نرسیدنِ هیچ کدی یعنی مشتری نمی‌تواند سرورِ
     * خودش را حذف کند. و بی‌خطر است، چون کدِ صادرشده برای این هدف **هیچ‌جا جز
     * همین هدف** تأیید نمی‌شود (`verify()` روی `purpose` فیلتر می‌کند).
     */
    private function sendSms(string $destination, string $code, string $purpose): bool
    {
        /*
        | 🔴 شمارهٔ بین‌المللی از Amazon SNS می‌رود، نه از اپراتورِ ایرانی.
        | درایورهای ایرانی شمارهٔ +90 را یا رد می‌کنند یا بی‌صدا می‌خورند؛
        | مسیریابی روی خودِ شکلِ مقصد است (+ = E.164) تا هیچ پیکربندی‌ای
        | نتواند کدِ مشتریِ خارجی را به رلهٔ ایرانی بفرستد.
        */
        if (str_starts_with($destination, '+')) {
            return app(\App\Services\Sms\SnsSender::class)->sendOtp($destination, $code);
        }

        $template = self::SMS_TEMPLATES[$purpose] ?? null;

        if ($template !== null && $this->sms instanceof SupportsPatterns) {
            $sent = $this->sms->sendPattern($destination, $template, ['code' => $code]);

            if ($sent !== null) {
                return $sent;
            }
        }

        return $this->sms->sendOtp($destination, $code);
    }

    /**
     * ارسال کد با ایمیل — جایگزینِ موبایل وقتی کاربر به شماره‌اش دسترسی ندارد.
     * اگر میلر پیکربندی نشده باشد throw می‌شود و false برمی‌گردانیم تا کاربر
     * پیام روشن بگیرد، نه یک شکستِ خاموش.
     */
    private function sendEmail(string $email, string $code, string $purpose = 'login'): bool
    {
        try {
            // mailer('smtp') صریح، نه میلرِ پیش‌فرض: روی سرور MAIL_MAILER اغلب
            // log است (برای بقیهٔ اعلان‌ها)، ولی کدِ ورود باید حتماً واقعی برود.
            // قالبِ برنددارِ سرورنت، به زبانِ جاری. اگر SMTP پیکربندی نباشد
            // throw می‌کند و false برمی‌گردانیم.
            \Illuminate\Support\Facades\Mail::mailer('smtp')->to($email)->send(
                new \App\Mail\OtpMail($code, self::TTL_MINUTES, app()->getLocale(), $purpose),
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('ارسال ایمیل کد ورود انجام نشد', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }
}

final readonly class OtpIssue
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?int $challengeId = null,
        public ?Carbon $expiresAt = null,
        public ?int $retryAfter = null,
        /** فقط برای شماره‌های آزمایشی — روی شماره‌های واقعی همیشه null است */
        public ?string $debugCode = null,
    ) {}

    public static function fail(string $error, ?int $retryAfter = null): self
    {
        return new self(false, $error, retryAfter: $retryAfter);
    }
}

final readonly class OtpCheck
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?OtpChallenge $challenge = null,
    ) {}

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }
}
