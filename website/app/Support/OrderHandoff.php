<?php

namespace App\Support;

/**
 * لینکِ امضاشدهٔ تحویلِ سفارش از سایت به console — ممیزی ۶.
 *
 * ═══ چه چیزی حل می‌کند ═══
 *
 * · SN-ORDER-001 (QA): کاربر روی /order دوره را انتخاب می‌کند و console دوباره
 *   می‌پرسد — «بازگشاییِ تصمیم»؛ تخفیفِ ۲۰٪ِ سالانه سیستماتیک از دست می‌رود.
 * · مدیر امنیت: لینکِ بی‌امضا = اگر روزی کسی قیمت را از URL بخواند، دستکاریِ
 *   قیمت. این‌جا **هیچ قیمتی در لینک نیست** — فقط sku/cycle/exp و امضای HMAC
 *   روی همان‌ها. console قیمت را از دیتابیس می‌سازد، همیشه.
 * · مشاور کسب‌وکار: «شرطِ هفت‌روزهٔ CMS» — اولین کامیتِ متقابلِ واقعی بین دو
 *   مرز. (console همین کدبیس است؛ مرز دامنه‌ای است، نه مخزنی.)
 *
 * ═══ چرا sid و ref در امضا نیستند (شورا — رشد/CTO) ═══
 *
 * صفحهٔ /order کش می‌شود (PageCache، ۶۰ ثانیه). اگر sid سمتِ سرور و داخلِ
 * امضا ساخته می‌شد، همهٔ بازدیدکننده‌های یک دقیقه **یک sid** می‌گرفتند و قیف
 * بی‌معنا می‌شد؛ و ref (مبدأ) هم فقط در مرورگر معلوم است. پس:
 *   · سرور فقط `sku|cycle|exp` را امضا می‌کند — همان که می‌تواند کش شود؛
 *   · مرورگر در لحظهٔ کلیک `sid` (crypto.getRandomValues) و `ref`
 *     (document.referrer → سطل) را به لینک می‌چسباند؛
 *   · console هر دو را فقط با regex می‌پذیرد و برای اتصالِ رویدادها به کار
 *     می‌برد — هیچ تصمیمِ قیمتی/حقوقی به آن‌ها وابسته نیست، پس امضا لازم ندارند.
 *
 * ⚠️ امضای نامعتبر یا منقضی **خرید را نمی‌بندد** — فقط نادیده گرفته می‌شود و
 * console با پیش‌فرض باز می‌شود + یک رویدادِ handoff_invalid با دلیل. لینکی
 * که ساعت‌ها در تب بازمانده ۴۰۳ بدهد، دقیقاً همان «دیوارِ» بی‌دلیل است.
 */
class OrderHandoff
{
    /**
     * عمرِ لینک (ثانیه) — ۲۴ ساعت: برابرِ hard_ttlِ کشِ صفحه، تا لینکی که از
     * نسخهٔ STALE آمده هم هنوز معتبر باشد؛ «دو ساعت» برای تبِ شبانه کم بود.
     */
    public const TTL = 86400;

    /** الگوهای پذیرشِ پارامترهای مرورگرساخته */
    public const SID_RE = '/^[a-z0-9]{8,32}$/';

    public const REF_RE = '/^[a-z0-9_-]{1,32}$/';

    /** @return array<string,string> پارامترهای امضاشده، آمادهٔ http_build_query */
    public static function params(string $sku, string $cycle, ?int $exp = null): array
    {
        $exp = $exp ?? (time() + self::TTL);

        $p = [
            'sku'   => $sku,
            'cycle' => $cycle,
            'exp'   => (string) $exp,
        ];
        $p['sig'] = self::sign($p);

        return $p;
    }

    /** URLِ کاملِ تحویل به console برای یک دوره (sid/ref را مرورگر اضافه می‌کند). */
    public static function url(string $sku, string $cycle): string
    {
        return console_lroute('account.order', $sku).'?'.http_build_query(self::params($sku, $cycle));
    }

    /**
     * راستی‌آزماییِ پارامترهای رسیده — null یعنی نادیده بگیر (نه خطا).
     *
     * @param  array<string,mixed>  $q
     * @param  string|null  $reason  خروجی: missing | sku | cycle | expired | tampered
     * @return array{sku:string,cycle:string,ref:string,sid:string}|null
     */
    public static function verify(array $q, string $expectedSku, array $validCycles, ?string &$reason = null): ?array
    {
        $reason = null;

        foreach (['sku', 'cycle', 'exp', 'sig'] as $k) {
            if (! isset($q[$k]) || ! is_string($q[$k]) || $q[$k] === '') {
                $reason = 'missing';

                return null;
            }
        }

        if ($q['sku'] !== $expectedSku) {
            $reason = 'sku';

            return null;
        }

        if (! in_array($q['cycle'], $validCycles, true)) {
            $reason = 'cycle';

            return null;
        }

        if (! ctype_digit($q['exp'])) {
            $reason = 'tampered';

            return null;
        }

        $expected = self::sign(['sku' => $q['sku'], 'cycle' => $q['cycle'], 'exp' => $q['exp']]);

        if (! hash_equals($expected, (string) $q['sig'])) {
            $reason = 'tampered';

            return null;
        }

        // انقضا بعد از امضا سنجیده می‌شود تا «منقضی» فقط برای لینکِ اصیل گزارش شود
        if ((int) $q['exp'] < time()) {
            $reason = 'expired';

            return null;
        }

        return [
            'sku'   => $q['sku'],
            'cycle' => $q['cycle'],
            'ref'   => self::clean($q['ref'] ?? null, self::REF_RE),
            'sid'   => self::clean($q['sid'] ?? null, self::SID_RE),
        ];
    }

    /** پارامترِ مرورگرساخته: یا با الگو می‌خوانَد یا خالی — هرگز خام به لاگ نمی‌رود. */
    public static function clean(mixed $v, string $re): string
    {
        return (is_string($v) && preg_match($re, $v)) ? $v : '';
    }

    /** @param array<string,string> $p */
    private static function sign(array $p): string
    {
        $msg = implode('|', [$p['sku'], $p['cycle'], $p['exp']]);

        // APP_KEY هر دو میزبان یکی است (یک اپ، دو دامنه) — امضا دو طرف می‌خوانَد
        return substr(hash_hmac('sha256', $msg, (string) config('app.key')), 0, 40);
    }
}
