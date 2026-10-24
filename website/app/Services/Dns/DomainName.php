<?php

namespace App\Services\Dns;

/**
 * نام‌های دامنه: نرمال‌سازی، اعتبارسنجی و تبدیلِ IDN (طراحی §5.1).
 *
 * ═══ چرا یک کلاسِ جدا ═══
 *
 * ادعای مالکیت، ساختِ zone، نامِ رکورد و مقصدِ CNAME همه باید **یک** تعریف از
 * «همان دامنه» داشته باشند. اگر یکی `Example.com.` را با دیگری `example.com`
 * یکی نداند، دو مشتری یک دامنه را می‌گیرند و ایندکسِ یکتا هم جلویش را نمی‌گیرد.
 *
 * ═══ IDN ═══
 *
 * UTS#46 غیرانتقالی (`IDNA_NONTRANSITIONAL_TO_ASCII`) — همان رفتارِ مرورگرها.
 * در حالتِ انتقالی `faß.de` به `fass.de` تبدیل می‌شود که دامنهٔ **دیگری** است.
 * اگر intl نصب نباشد، نامِ غیرِ ASCII رد می‌شود؛ حدس‌زدنِ punycode بدتر است.
 */
final class DomainName
{
    public const MAX_LENGTH = 253;

    public const MAX_LABEL = 63;

    /*
     * BIDI و CONTEXTJ همان بررسی‌های مرورگر است؛ CONTEXTJ نیم‌فاصلهٔ فارسی
     * (ZWNJ) را در جای درستش می‌پذیرد و در جای غلط رد می‌کند.
     */
    private const IDNA_FLAGS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_USE_STD3_RULES;

    /**
     * ورودیِ کاربر → نامِ ASCII، کوچک، بی‌نقطهٔ پایانی؛ یا null اگر دامنه نیست.
     *
     * `www.` عمداً حذف نمی‌شود: تشخیصِ زیردامنه کارِ `CdnZone::registrable` است
     * که به کاربر پیشنهاد می‌دهد، نه اینکه بی‌صدا نامِ دیگری را ثبت کند.
     */
    public static function normalize(string $raw): ?string
    {
        $s = self::trimUnicode($raw);

        // «https://Example.com:8443/path?q» — کپی از نوارِ آدرس رایج‌ترین ورودی است
        $s = preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $s) ?? $s;
        $s = preg_split('~[/?#]~', $s, 2)[0] ?? '';

        // «info@example.com» مبهم است (ایمیل یا اعتبارنامهٔ URL) — حدس نمی‌زنیم
        if ($s === '' || str_contains($s, '@') || str_starts_with($s, '[')) {
            return null;
        }

        if (preg_match('~^(.*):(\d{1,5})$~', $s, $m)) {
            $s = $m[1];
        }

        if (str_contains($s, ':')) {
            return null;
        }

        $s = mb_strtolower($s, 'UTF-8');

        if (str_ends_with($s, '.')) {
            $s = substr($s, 0, -1);
        }

        if ($s === '' || ! mb_check_encoding($s, 'UTF-8')) {
            return null;
        }

        if (self::hasIdn()) {
            // روی ASCII هم اجرا می‌شود: قاعدهٔ خط‌تیرهٔ جای ۳–۴ و صحتِ xn-- را ICU می‌سنجد
            $ascii = idn_to_ascii($s, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

            if ($ascii === false || ($info['errors'] ?? 0) !== 0) {
                return null;
            }

            $s = strtolower($ascii);
        } elseif (! self::isAscii($s)) {
            return null;
        }

        $labels = explode('.', $s);

        foreach ($labels as $label) {
            if (! self::isLdhLabel($label)) {
                return null;
            }

            // «ab--cd» برای پیشوندهای IDNA رزرو است؛ فقط xn-- تعریف شده
            if (substr($label, 2, 2) === '--' && ! str_starts_with($label, 'xn--')) {
                return null;
            }
        }

        return self::hasHostShape($labels, $s) ? $s : null;
    }

    /**
     * نامِ میزبانِ ASCII طبقِ RFC 1123 (حروف، عدد، خط تیره) با دست‌کم دو برچسب.
     *
     * ورودیِ Unicode را **تبدیل نمی‌کند** و رد می‌کند: فراخوان باید اول
     * `normalize()` یا `labelToAscii()` بزند تا روشن باشد چه چیزی ذخیره می‌شود.
     * یک نقطهٔ پایانی (FQDN) پذیرفته است.
     */
    public static function isValidHostname(string $name): bool
    {
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }

        if ($name === '' || ! self::isAscii($name)) {
            return false;
        }

        $name = strtolower($name);
        $labels = explode('.', $name);

        foreach ($labels as $label) {
            if (! self::isLdhLabel($label) || ! self::isValidALabel($label)) {
                return false;
            }
        }

        return self::hasHostShape($labels, $name);
    }

    /**
     * فقط برای نمایش: `xn--mgbtj4c7ad63e.ir` → `فروشگاه.ir`.
     * برچسبی که تبدیل نشود همان‌طور می‌مانَد — نمایش نباید خطا بسازد.
     */
    public static function toUnicode(string $name): string
    {
        if (! self::hasIdn() || ! str_contains(strtolower($name), 'xn--')) {
            return $name;
        }

        $out = [];

        foreach (explode('.', $name) as $label) {
            if (str_starts_with(strtolower($label), 'xn--')) {
                $u = idn_to_utf8(strtolower($label), IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46, $info);
                $out[] = ($u !== false && ($info['errors'] ?? 0) === 0) ? $u : $label;
            } else {
                $out[] = $label;
            }
        }

        return implode('.', $out);
    }

    /**
     * آیا `$name` زیرِ `$zone` است؟ — بی‌حساسیت به بزرگی‌وکوچکی و نقطهٔ پایانی.
     *
     * طبقِ RFC 1034 هر دامنه زیردامنهٔ خودش هم هست؛ برای «اکیداً پایین‌تر»
     * `$orSelf=false` بدهید. مقایسه روی مرزِ برچسب است: `badexample.com`
     * زیرِ `example.com` نیست.
     */
    public static function isSubdomainOf(string $name, string $zone, bool $orSelf = true): bool
    {
        $name = self::bare($name);
        $zone = self::bare($zone);

        if ($name === '' || $zone === '') {
            return false;
        }

        if ($name === $zone) {
            return $orSelf;
        }

        return str_ends_with($name, '.'.$zone);
    }

    /**
     * نامِ نسبی برای نمایش و فایلِ zone: apex → `@`، بیرون از zone → خودِ نام.
     */
    public static function relative(string $fqdn, string $zone): string
    {
        $fqdn = self::bare($fqdn);
        $zone = self::bare($zone);

        if ($fqdn === $zone) {
            return '@';
        }

        return str_ends_with($fqdn, '.'.$zone) ? substr($fqdn, 0, -strlen($zone) - 1) : $fqdn;
    }

    /**
     * یک برچسب → ASCII. برچسبِ ASCII فقط کوچک می‌شود (زیرخط و `*` دست نمی‌خورند،
     * چون نامِ رکورد آن‌ها را لازم دارد)؛ برچسبِ Unicode با UTS#46 تبدیل می‌شود.
     * null یعنی برچسب قابلِ تبدیل نیست.
     */
    public static function labelToAscii(string $label): ?string
    {
        if ($label === '') {
            return null;
        }

        if (self::isAscii($label)) {
            return strtolower($label);
        }

        if (! self::hasIdn() || ! mb_check_encoding($label, 'UTF-8')) {
            return null;
        }

        $ascii = idn_to_ascii($label, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

        if ($ascii === false || ($info['errors'] ?? 0) !== 0 || str_contains($ascii, '.')) {
            return null;
        }

        return strtolower($ascii);
    }

    /**
     * برچسبِ `xn--` باید punycodeِ معتبر باشد؛ بقیه همیشه true.
     * بی‌intl نمی‌شود سنجید و true برمی‌گردد (رد کردنِ همهٔ xn--ها بدتر است).
     */
    public static function isValidALabel(string $label): bool
    {
        $label = strtolower($label);

        if (! str_starts_with($label, 'xn--') || ! self::hasIdn()) {
            return true;
        }

        $back = idn_to_ascii($label, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

        return $back !== false && ($info['errors'] ?? 0) === 0 && strtolower($back) === $label;
    }

    /** برچسبِ LDH: ۱ تا ۶۳ نویسه، بی‌خط‌تیره در ابتدا و انتها */
    public static function isLdhLabel(string $label): bool
    {
        return strlen($label) <= self::MAX_LABEL
            && (bool) preg_match('~^[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?$~', $label);
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    /**
     * دست‌کم دو برچسب و TLDِ غیرعددی — وگرنه `1.2.3.4` هم «دامنه» می‌شد و
     * ادعای مالکیت روی یک IP ساخته می‌شد.
     */
    private static function hasHostShape(array $labels, string $name): bool
    {
        return count($labels) >= 2
            && strlen($name) <= self::MAX_LENGTH
            && ! ctype_digit((string) end($labels));
    }

    private static function bare(string $name): string
    {
        $name = strtolower(trim($name));

        return str_ends_with($name, '.') ? substr($name, 0, -1) : $name;
    }

    private static function isAscii(string $s): bool
    {
        return ! preg_match('~[^\x00-\x7F]~', $s);
    }

    private static function hasIdn(): bool
    {
        return function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46');
    }

    /**
     * فاصله‌های نامرئی هم بریده می‌شوند (NBSP، ZWSP، BOM و نشانه‌های جهت):
     * کپی از پیام‌رسان‌ها و Word آن‌ها را دو سرِ متن می‌گذارد. ZWNJ وسطِ نام
     * دست نمی‌خورد چون در دامنهٔ فارسی معنا دارد.
     */
    private static function trimUnicode(string $s): string
    {
        $t = preg_replace('~^[\s\x{00A0}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}\x{FEFF}]+$~u', '', $s);

        return $t ?? trim($s);
    }
}
