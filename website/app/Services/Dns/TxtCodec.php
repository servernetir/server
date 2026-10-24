<?php

namespace App\Services\Dns;

/**
 * قالبِ TXT: ورودیِ کاربر ⇄ presentation formatِ ذخیره‌شده ⇄ رشته‌های منطقی (طراحی §6.1).
 *
 * ═══ چرا این‌قدر سخت‌گیر ═══
 *
 * آزمایشگاهِ PowerDNS 5.0.2 نشان داد API همهٔ escapeها را درست برنمی‌گرداند:
 * `"a\"b\\c"` به‌صورتِ `a"bc` ذخیره می‌شود (بک‌اسلش بی‌صدا می‌افتد) و `"\200"`
 * به بایت‌های `C2 80`. ولی UTF-8ِ خام و `\"` دقیقاً برمی‌گردند و مرزِ رشته‌ها
 * حفظ می‌شود. پس اگر فقط همین‌ها را بفرستیم، رشته‌ای که می‌فرستیم عیناً همانی
 * است که API پس می‌دهد و diffِ انتشار «تغییرِ شبح» نمی‌بیند. بک‌اسلش و نویسهٔ
 * کنترلی در **محتوا** رد می‌شوند، نه اینکه بی‌صدا عوض شوند.
 *
 * ═══ قالبِ ذخیره ═══
 *
 * یک یا چند رشتهٔ گیومه‌دار با یک فاصله بینشان: `"v=spf1 -all"` یا `"p1" "p2"`.
 * داخلِ رشته فقط `"` به `\"` escape می‌شود؛ هر رشته حداکثر ۲۵۵ **بایت**.
 *
 * ═══ ورودیِ کاربر ═══
 *
 *  - متنِ ساده ⇒ یک مقدارِ منطقی که روی مرزِ نویسه‌های UTF-8 به تکه‌های ≤۲۵۵ بایتی بریده می‌شود.
 *  - شروع با `"` ⇒ presentation؛ مرزِ رشته‌های کاربر حفظ می‌شود، رشته‌های چسبیده
 *    (`"a""b"` که API ردش می‌کند) با یک فاصله جدا می‌شوند، و رشتهٔ بلندتر از ۲۵۵ بایت
 *    (رایج در کپیِ DKIM از پنلِ دیگر) روی مرزِ نویسه بریده می‌شود — معنا برای
 *    SPF/DKIM همان الحاقِ رشته‌هاست، پس عوض نمی‌شود.
 */
final class TxtCodec
{
    public const MAX_STRING_BYTES = 255;

    public const MAX_TOTAL_BYTES = 4096;

    /** همهٔ کدهای خطایی که این کلاس برمی‌گرداند (کلیدِ `dns.err.<code>`) */
    public const CODES = [
        'txt_empty',        // متن خالی است
        'txt_too_long',     // params: max (بایت)
        'txt_backslash',    // بک‌اسلش در محتوا یا escapeِ نامفهوم مثل \n
        'txt_control',      // نویسهٔ کنترلی (‎<0x20، 0x7F، C1)
        'txt_encoding',     // UTF-8ِ نامعتبر
        'txt_syntax',       // گیومهٔ باز/متنِ بی‌گیومه بینِ رشته‌ها
    ];

    /**
     * ورودیِ فرم → presentationِ کانونی.
     *
     * @return array{ok:bool, value?:string, strings?:array<int,string>, code?:string, params?:array<string,mixed>}
     */
    public static function fromUserInput(string $raw, int $maxTotal = self::MAX_TOTAL_BYTES): array
    {
        // فاصله و خطِ تازهٔ دو سر از کپی/textarea می‌آید، نه از نیتِ کاربر
        $s = trim($raw, " \t\r\n");

        if ($s === '') {
            return self::fail('txt_empty');
        }

        if (! mb_check_encoding($s, 'UTF-8')) {
            return self::fail('txt_encoding');
        }

        if ($s[0] !== '"') {
            return self::fromStrings([$s], $maxTotal);
        }

        $parsed = self::parseQuoted($s);

        if (! $parsed['ok']) {
            return $parsed;
        }

        return self::fromStrings($parsed['strings'], $maxTotal);
    }

    /**
     * رشته‌های منطقیِ (escapeشده نیستند) → presentationِ کانونی، با همهٔ قاعده‌ها.
     * واردکنندهٔ فایلِ zone هم از همین می‌گذرد تا دو مسیر یک قاعده داشته باشند.
     *
     * @param  array<int,string>  $strings
     * @return array{ok:bool, value?:string, strings?:array<int,string>, code?:string, params?:array<string,mixed>}
     */
    public static function fromStrings(array $strings, int $maxTotal = self::MAX_TOTAL_BYTES): array
    {
        $out = [];
        $total = 0;

        foreach (array_values($strings) as $str) {
            $str = (string) $str;

            if (($code = self::contentError($str)) !== null) {
                return self::fail($code);
            }

            $total += strlen($str);

            foreach (self::split($str) as $part) {
                $out[] = $part;
            }
        }

        if ($total === 0) {
            return self::fail('txt_empty');
        }

        if ($total > $maxTotal) {
            return self::fail('txt_too_long', ['max' => $maxTotal]);
        }

        return ['ok' => true, 'value' => self::canonical($out), 'strings' => $out];
    }

    /**
     * presentation (ذخیره‌شده یا پاسخِ API) → رشته‌های منطقی. هرگز خطا نمی‌دهد:
     * برای مقایسه/diff است و ورودیِ خراب هم باید چیزی قابلِ مقایسه بدهد.
     * escapeها طبقِ RFC 1035 باز می‌شوند (`\DDD` بایت، `\X` خودِ X).
     *
     * @return array<int,string>
     */
    public static function decode(string $presentation): array
    {
        $s = $presentation;
        $len = strlen($s);
        $out = [];
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $i++;

                continue;
            }

            $quoted = $c === '"';
            if ($quoted) {
                $i++;
            }

            $buf = '';

            while ($i < $len) {
                $c = $s[$i];

                if ($quoted && $c === '"') {
                    $i++;
                    break;
                }

                if (! $quoted && ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" || $c === '"')) {
                    break;
                }

                if ($c === '\\' && $i + 1 < $len) {
                    if (preg_match('~\G[0-9]{3}~', $s, $m, 0, $i + 1)) {
                        $buf .= chr(min(255, (int) $m[0]));
                        $i += 4;
                    } else {
                        $buf .= $s[$i + 1];
                        $i += 2;
                    }

                    continue;
                }

                $buf .= $c;
                $i++;
            }

            $out[] = $buf;
        }

        return $out;
    }

    /** برای نمایش در پنل: رشته‌ها پشتِ هم — همان چیزی که SPF/DKIM می‌خوانند */
    public static function display(string $presentation): string
    {
        return implode('', self::decode($presentation));
    }

    /**
     * مقدارِ پیش‌فرضِ فرمِ ویرایش. تک‌رشته ⇒ متنِ ساده؛ چندرشته ⇒ presentation
     * تا ذخیرهٔ دوباره مرزها را عوض نکند (رکوردِ دیگری روی سیم نشود).
     */
    public static function forEdit(string $presentation): string
    {
        $parts = self::decode($presentation);

        if (count($parts) === 1) {
            $p = $parts[0];

            // متنی که با گیومه شروع شود یا فاصلهٔ دو سر داشته باشد، ساده خوانده نمی‌شود
            if ($p !== '' && $p[0] !== '"' && trim($p, " \t") === $p) {
                return $p;
            }
        }

        return self::canonical($parts);
    }

    /**
     * رشته‌ها → `"…" "…"`؛ فقط `"` (و بک‌اسلش، که در محتوای مجاز هرگز نیست) escape می‌شود.
     * UTF-8 خام می‌ماند: API همان را دقیقاً برمی‌گرداند، ولی `\DDD` را نه.
     *
     * @param  array<int,string>  $strings
     */
    public static function canonical(array $strings): string
    {
        $q = [];

        foreach ($strings as $s) {
            $q[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s).'"';
        }

        return implode(' ', $q);
    }

    /** برابریِ دو presentation در سطحِ سیم (مرزِ رشته‌ها مهم است، فاصله و escape نه) */
    public static function equals(string $a, string $b): bool
    {
        return self::decode($a) === self::decode($b);
    }

    /**
     * escapeهای یک توکنِ presentation را باز می‌کند (برای واردکنندهٔ فایلِ zone).
     * `\"` و `\;` و `\DDD` طبقِ RFC؛ بک‌اسلش پیش از حرف/عدد ناقص (مثل `\n`) رد
     * می‌شود چون معمولاً escapeِ زبانِ C است و «حرف n» نیتِ کاربر نبوده.
     *
     * @return array{ok:bool, value?:string, code?:string, params?:array<string,mixed>}
     */
    public static function unescape(string $raw): array
    {
        if (! str_contains($raw, '\\')) {
            return ['ok' => true, 'value' => $raw];
        }

        $len = strlen($raw);
        $buf = '';

        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];

            if ($c !== '\\') {
                $buf .= $c;

                continue;
            }

            if ($i + 1 >= $len) {
                return self::fail('txt_backslash');
            }

            if (preg_match('~\G[0-9]{3}~', $raw, $m, 0, $i + 1)) {
                $n = (int) $m[0];

                if ($n > 255) {
                    return self::fail('txt_syntax');
                }

                $buf .= chr($n);
                $i += 3;

                continue;
            }

            $next = $raw[$i + 1];

            if (ctype_alnum($next)) {
                return self::fail('txt_backslash');
            }

            $buf .= $next;
            $i++;
        }

        return ['ok' => true, 'value' => $buf];
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    /**
     * ورودیِ کاربر که با `"` شروع شده: فقط رشته‌های گیومه‌دار با فاصلهٔ اختیاری.
     * متنِ بی‌گیومه بینشان خطاست، نه رشتهٔ جدا: `"quoted" phrase` معمولاً یک جمله
     * است و تکه‌کردنِ بی‌صدایش به چهار رشته («quotedphrase…» در نمایش) بدتر از خطاست.
     *
     * @return array{ok:bool, strings?:array<int,string>, code?:string, params?:array<string,mixed>}
     */
    private static function parseQuoted(string $s): array
    {
        $len = strlen($s);
        $strings = [];
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $i++;

                continue;
            }

            if ($c !== '"') {
                return self::fail('txt_syntax');
            }

            $j = $i + 1;
            $closed = false;

            while ($j < $len) {
                if ($s[$j] === '\\') {
                    $j += 2;

                    continue;
                }

                if ($s[$j] === '"') {
                    $closed = true;
                    break;
                }

                $j++;
            }

            if (! $closed) {
                return self::fail('txt_syntax');
            }

            $u = self::unescape(substr($s, $i + 1, $j - $i - 1));

            if (! $u['ok']) {
                return $u;
            }

            $strings[] = $u['value'];
            $i = $j + 1;
        }

        return ['ok' => true, 'strings' => $strings];
    }

    /** null یعنی محتوای مجاز: UTF-8ِ معتبرِ چاپی، فاصله و `"` */
    private static function contentError(string $s): ?string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            return 'txt_encoding';
        }

        if (str_contains($s, '\\')) {
            return 'txt_backslash';
        }

        // \p{Cc}: ‎0x00–0x1F، 0x7F و C1 (U+0080–U+009F) — همان‌هایی که API به escape می‌برد
        if (preg_match('~\p{Cc}~u', $s)) {
            return 'txt_control';
        }

        return null;
    }

    /**
     * یک رشتهٔ منطقی → تکه‌های ≤۲۵۵ بایتی، هرگز وسطِ یک نویسهٔ چندبایتی.
     *
     * @return array<int,string>
     */
    private static function split(string $s): array
    {
        if (strlen($s) <= self::MAX_STRING_BYTES) {
            return [$s];
        }

        $out = [];
        $buf = '';

        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            if (strlen($buf) + strlen($ch) > self::MAX_STRING_BYTES) {
                $out[] = $buf;
                $buf = '';
            }

            $buf .= $ch;
        }

        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /** @return array{ok:false, code:string, params:array<string,mixed>} */
    private static function fail(string $code, array $params = []): array
    {
        return ['ok' => false, 'code' => $code, 'params' => $params];
    }
}
