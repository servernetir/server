<?php

namespace App\Services\Security;

/**
 * TOTP — کدِ یک‌بارمصرفِ زمان‌محور (RFC 6238) برای Google Authenticator.
 *
 * این کلاس عمداً **بدون هیچ وابستگیِ بیرونی** نوشته شده. کلِ الگوریتم یک
 * HMAC-SHA1 روی شمارهٔ بازهٔ زمانی است و `hash_hmac` در خودِ PHP هست؛ افزودنِ
 * یک پکیجِ composer یعنی روی پروداکشنِ cPanel هم باید `composer install`
 * بزنیم، و روالِ دپلوی این پروژه فایل‌به‌فایل است نه ساختِ کامل.
 *
 * تفاوتش با `OtpService` (کدِ پیامکی/ایمیلی) بنیادی است و هر دو لازم‌اند:
 *
 *   OtpService  → کد را **ما می‌سازیم و می‌فرستیم**؛ چیزی که کاربر «دریافت»
 *                 می‌کند. اگر سیم‌کارت یا ایمیل لو برود، لو می‌رود.
 *   Totp        → کد را **گوشیِ کاربر می‌سازد**؛ هیچ‌وقت از شبکه رد نمی‌شود.
 *                 حتی اگر ایمیل و پیامکِ کاربر هر دو لو برود، مهاجم بدونِ
 *                 خودِ گوشی وارد نمی‌شود.
 *
 * ═══ ⚠️ چیزهایی که این‌جا عمدی‌اند ═══
 *
 * • `hash_equals` در `verify()` — مقایسهٔ معمولیِ رشته زمانِ اجرا را به کدِ
 *   درست نشت می‌دهد. شش‌رقمی است و شبکه نویز دارد، ولی هزینهٔ درست‌نوشتنش صفر
 *   است.
 * • پنجرهٔ ±۱ بازه (۳۰ ثانیه) — ساعتِ گوشیِ کاربر همیشه با سرور یکی نیست.
 *   پنجرهٔ بزرگ‌تر یعنی کدِ سوخته بیشتر زنده می‌مانَد؛ ±۱ همان چیزی است که
 *   Google Authenticator خودش فرض می‌کند.
 * • راز ۲۰ بایت (۱۶۰ بیت) — طولِ استانداردِ SHA-1 HMAC و همان چیزی که
 *   اپلیکیشن‌های موبایل انتظار دارند. کوتاه‌ترش کارش را می‌کند ولی دلیلی ندارد.
 */
class Totp
{
    /** طول کد — Google Authenticator فقط ۶ رقم را درست نشان می‌دهد */
    public const DIGITS = 6;

    /** طول هر بازهٔ زمانی به ثانیه */
    public const PERIOD = 30;

    /** چند بازه عقب/جلو پذیرفته شود (اختلافِ ساعتِ گوشی) */
    public const WINDOW = 1;

    /** الفبای Base32 طبق RFC 4648 — بدون padding در otpauth */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * رازِ تازه — ۲۰ بایتِ تصادفی، به شکلِ Base32.
     *
     * `random_bytes` است نه `Str::random`: این راز به‌اندازهٔ خودِ رمزِ عبور
     * حساس است و باید از منبعِ رمزنگارانه بیاید.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * کدِ همین لحظه — برای تست و برای نمایشِ «کدِ فعلی».
     *
     * ⚠️ `now()` و نه `time()`: تنها فرقشان این است که `now()` به
     * `Carbon::setTestNow()` گوش می‌دهد، و بدونِ آن هیچ تستی نمی‌تواند از یک
     * بازهٔ سی‌ثانیه‌ای به بازهٔ بعد برود — یعنی گاردِ ضدِّ تکرار عملاً
     * آزمون‌ناپذیر می‌مانَد. روی پروداکشن هر دو یک چیزند.
     */
    public static function code(string $secret, ?int $timestamp = null, int $offset = 0): string
    {
        $counter = intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD) + $offset;

        return self::hotp($secret, $counter);
    }

    /**
     * بررسیِ کدِ واردشده با پنجرهٔ ±WINDOW.
     *
     * ⚠️ این تابع **جلوی استفادهٔ دوباره از یک کد را نمی‌گیرد**. کدِ TOTP تا
     * ۳۰ ثانیه معتبر است و اگر کسی آن را در همان بازه بدزدد (از روی شانه یا با
     * فیشینگ) می‌تواند دوباره بزندش. جلوگیری از آن وظیفهٔ لایهٔ بالاتر است:
     * `HasTwoFactor::verifyTwoFactorCode()` آخرین بازهٔ مصرف‌شده را ذخیره
     * می‌کند و کدِ تکراری را رد می‌کند.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        return self::matchingTimestep($secret, $code, $timestamp) !== null;
    }

    /**
     * اگر کد درست بود، شمارهٔ بازهٔ زمانی‌اش را برمی‌گرداند؛ وگرنه null.
     *
     * لایهٔ بالاتر به همین عدد نیاز دارد تا کدِ مصرف‌شده را دوباره نپذیرد.
     */
    public static function matchingTimestep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = self::normalizeCode($code);

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        if (self::base32Decode($secret) === null) {
            return null;
        }

        $now = intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    /**
     * پاک‌سازیِ کدی که کاربر تایپ کرده.
     *
     * 🔴 ارقامِ فارسی/عربی حتماً باید تبدیل شوند. کاربرِ ایرانی روی صفحه‌کلیدِ
     * فارسی «۱۲۳۴۵۶» می‌زند و آن رشته با «123456» برابر نیست — بدونِ این
     * تبدیل، کاربر کدِ کاملاً درست را وارد می‌کند و «کد نادرست است» می‌گیرد،
     * بارها، تا قفل شود. همان تلهٔ همیشگیِ فرم‌های فارسی.
     */
    public static function normalizeCode(string $code): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $code = str_replace($fa, $en, $code);
        $code = str_replace($ar, $en, $code);

        // فاصله و خط تیره‌ای که کاربر از روی صفحهٔ اپلیکیشن کپی می‌کند
        return preg_replace('/\D/', '', $code) ?? '';
    }

    /**
     * نشانیِ otpauth که در QR می‌رود.
     *
     * ⚠️ `rawurlencode` روی برچسب لازم است: ایمیلِ کاربر `@` دارد و اگر خام
     * برود، اپلیکیشن برچسب را وسط می‌بُرد یا کلاً QR را رد می‌کند.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** رازِ Base32 با فاصلهٔ چهارتایی — برای واردکردنِ دستی وقتی دوربین کار نمی‌کند */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // ───────────────────────────── درونی ─────────────────────────────

    /** HOTP (RFC 4226) — پایهٔ TOTP */
    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);

        if ($key === null) {
            return '';
        }

        // شمارنده به‌صورت ۸ بایتِ big-endian. `pack('J')` روی PHP ۶۴بیتی دقیقاً
        // همین است و شمارندهٔ TOTP هرگز از ۶۴ بیت بیرون نمی‌زند.
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);

        // برشِ پویا (dynamic truncation) — چهار بیتِ آخرِ هش می‌گوید از کجا بخوان
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /** رشتهٔ Base32 → بایت‌ها؛ `null` یعنی رشته معتبر نیست */
    public static function base32Decode(string $secret): ?string
    {
        $secret = strtoupper(str_replace([' ', '-', '='], '', $secret));

        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            return null;
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
