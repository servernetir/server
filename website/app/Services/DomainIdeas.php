<?php

namespace App\Services;

/**
 * پیشنهادگر نام دامنه — ورودی: توصیف کسب‌وکار (فارسی یا انگلیسی)،
 * خروجی: نام‌های کوتاه برنددار + وضعیت ثبت.
 *
 * دو منبع پیشنهاد:
 *   ۱) مدل هوش مصنوعی (مسیر purpose='ideas' از services.ai_routing)
 *   ۲) اگر مدل در دسترس نبود یا خروجی نامعتبر داد: مولد قطعی محلی
 *      (ترانویسی فارسی→لاتین + پیشوند/پسوندهای رایج) — ابزار هرگز نمی‌میرد.
 *
 * ⚠️ وضعیت ثبت از NS دامنه می‌آید و فقط **یک‌طرفه** قطعی است:
 * «NS دارد» یعنی قطعاً ثبت شده؛ «NS ندارد» یعنی نامعلوم، نه آزاد.
 * دامنه‌ی ثبت‌شده‌ی بی‌NS وجود دارد، پس ادعای «آزاد» فقط کار استعلام
 * زنده‌ی رجیسترار است (دکمه‌ی «بررسی و ثبت» → /domains).
 * همان قاعده‌ی DomainCheckController: «نمی‌دانم» صادقانه‌تر از حدس اشتباه است.
 */
class DomainIdeas extends AiContent
{
    /** حداکثر پیشنهاد در هر پاسخ */
    public const MAX_IDEAS = 24;

    /** چند نام اول برای بررسی NS (هر بررسی یک درخواست DoH است) */
    private const NS_CHECKS = 24;

    /**
     * @return array{ok: bool, items: array<int, array{name: string, domain: string, taken: bool|null}>, source: string}
     */
    public function suggest(string $description): array
    {
        $names = $this->fromAi($description);
        $source = 'ai';

        if ($names === []) {
            $names = self::fallbackNames($description);
            $source = 'fallback';
        }

        if ($names === []) {
            return ['ok' => false, 'items' => [], 'source' => $source];
        }

        $taken = $this->nsTaken(array_slice($names, 0, self::NS_CHECKS));

        $items = [];
        foreach ($names as $name) {
            $items[] = [
                'name'   => $name,
                'domain' => $name.'.com',
                // true = قطعاً ثبت شده · null = نامعلوم (هرگز «آزاد» ادعا نمی‌شود)
                'taken'  => ($taken[$name] ?? false) ? true : null,
            ];
        }

        return ['ok' => true, 'items' => $items, 'source' => $source];
    }

    /* ============================================================= هوش مصنوعی */

    private function fromAi(string $description): array
    {
        $this->purpose = 'ideas';

        if (! $this->enabled()) {
            return [];
        }

        $sys = 'You are a domain-name brainstorming expert. From the business description you receive '
            .'(it may be in Persian), invent short, brandable domain name candidates.'."\n"
            .'Rules: return EXACTLY '.self::MAX_IDEAS.' lines. Each line is ONE name WITHOUT any TLD: '
            .'lowercase latin letters and digits only, 3-15 characters, no spaces, hyphens allowed but avoid them. '
            .'Prefer invented brandable words, blends and short compounds over generic keyword strings. '
            .'No numbering, no commentary, no quotes — names only, one per line.';

        $out = $this->call($sys, mb_substr($description, 0, 300), 600, 60);
        if ($out === null) {
            return [];
        }

        return self::parseNames($out);
    }

    /**
     * پارس خروجی مدل — تابع خالص. هر خطی که نام معتبر نیست دور ریخته می‌شود؛
     * خروجی مدل قابل‌اعتماد نیست و ممکن است شماره، توضیح یا TLD داشته باشد.
     */
    public static function parseNames(string $out): array
    {
        $names = [];
        foreach (preg_split('/[\r\n]+/', $out) ?: [] as $line) {
            // شماره‌گذاری و علامت‌های فهرست را بردار، TLD چسبیده را جدا کن
            $line = strtolower(trim($line));
            $line = preg_replace('/^[\s\d.\-*)•]+/u', '', $line) ?? '';
            $line = preg_replace('/\.(com|net|org|io|ir|co|dev|shop|online)\b.*$/', '', $line) ?? '';
            $line = trim($line, " \t.\u{200C}");

            if (self::validName($line) && ! in_array($line, $names, true)) {
                $names[] = $line;
            }
            if (count($names) >= self::MAX_IDEAS) {
                break;
            }
        }

        return $names;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{1,13}[a-z0-9])$/', $name)
            && ! str_contains($name, '--');
    }

    /* ============================================================= مولد محلی */

    /** پیشوند/پسوندهای ترکیب — ترتیب ثابت است تا خروجی قطعی و تست‌پذیر بماند */
    private const AFFIXES = [
        ['', 'ino'], ['', 'hub'], ['my', ''], ['', '24'], ['get', ''],
        ['', 'land'], ['', 'ly'], ['top', ''], ['', 'plus'], ['', 'zone'],
    ];

    /**
     * مولد قطعی بدون AI: کلیدواژه‌های لاتین‌شده + ترکیب‌های ثابت.
     * تابع خالص است و در تست با مقدار مرجع سنجیده می‌شود.
     */
    public static function fallbackNames(string $description): array
    {
        $latin = self::transliterate($description);

        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $latin) ?: [],
            fn ($w) => strlen($w) >= 3 && strlen($w) <= 12
                && ! in_array($w, ['the', 'and', 'for', 'with', 'online', 'baraye', 'yek'], true)
        ));
        $words = array_slice(array_unique($words), 0, 3);

        if ($words === []) {
            return [];
        }

        $names = [];
        $push = function (string $n) use (&$names): void {
            if (DomainIdeas::validName($n) && ! in_array($n, $names, true) && count($names) < self::MAX_IDEAS) {
                $names[] = $n;
            }
        };

        // خود کلمه‌ها و چسبیده‌ی دوتایی‌شان
        foreach ($words as $w) {
            $push($w);
        }
        if (isset($words[1])) {
            $push($words[0].$words[1]);
            $push($words[1].$words[0]);
        }

        // ترکیب با پیشوند/پسوندهای ثابت
        foreach (self::AFFIXES as [$pre, $suf]) {
            $push($pre.$words[0].$suf);
        }

        return $names;
    }

    /**
     * ترانویسی سرانگشتی فارسی → لاتین — تابع خالص.
     * قرار نیست بی‌نقص باشد؛ فقط باید کلیدواژه‌ی قابل‌ترکیب بسازد.
     */
    public static function transliterate(string $text): string
    {
        static $map = [
            'ا' => 'a', 'آ' => 'a', 'أ' => 'a', 'إ' => 'e', 'ب' => 'b', 'پ' => 'p',
            'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
            'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's',
            'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => '',
            'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g',
            'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'و' => 'o', 'ه' => 'h', 'ة' => 'h',
            'ی' => 'i', 'ي' => 'i', 'ئ' => 'i', 'ء' => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            "\u{200C}" => '',   // نیم‌فاصله وسط کلمه است، نه جداکننده
        ];

        return strtolower(strtr($text, $map));
    }

    /* ============================================================= وضعیت ثبت */

    /**
     * کدام نام‌ها روی ‎.com قطعاً ثبت شده‌اند؟ (NS دارد ⇒ ثبت شده)
     * موازی با curl_multi تا کل بررسی ≈ کندترین درخواست باشد.
     *
     * @return array<string, bool>
     */
    protected function nsTaken(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($names as $name) {
            $ch = curl_init('https://dns.google/resolve?name='.urlencode($name.'.com').'&type=NS');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
                CURLOPT_USERAGENT      => 'ServerNet-Ideas/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $taken = [];
        foreach ($handles as $name => $ch) {
            $raw = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $has = false;
            if (is_string($raw) && $code === 200) {
                $d = json_decode($raw, true);
                foreach (($d['Answer'] ?? []) as $row) {
                    if ((int) ($row['type'] ?? 0) === 2) {
                        $has = true;
                        break;
                    }
                }
            }
            $taken[$name] = $has;
        }
        curl_multi_close($mh);

        return $taken;
    }
}
