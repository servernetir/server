<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * نسخه و لینک‌های دانلودِ «سرورنت ریموت» — از خودِ `remote.servernet.cloud`.
 *
 * ═══ مشکلی که این کلاس حل می‌کند ═══
 *
 * صفحهٔ `/solutions/remote` چهار دکمهٔ دانلود داشت (ویندوز، اندروید، مک، آیفون)
 * و **هر چهارتا** به آدرسِ خالیِ پورتال می‌رفتند. یعنی:
 *
 *   ۱) هیچ‌کدام واقعاً چیزی دانلود نمی‌کردند؛ کاربر روی صفحهٔ دیگری می‌افتاد و
 *      باید دوباره دنبالِ فایل می‌گشت.
 *   ۲) سه‌تای‌شان محصولی را تبلیغ می‌کردند که **هنوز وجود ندارد** — پورتال برای
 *      اندروید و مک و آیفون «به‌زودی» می‌گوید. این از لینکِ خراب بدتر است:
 *      مشتری کلیک می‌کند، «به‌زودی» می‌بیند، و نتیجه می‌گیرد کلِ محصول ادعاست.
 *   ۳) شمارهٔ نسخه هیچ‌جای سایتِ اصلی نبود، پس با هر انتشارِ تازه باید دستی
 *      عوض می‌شد — و نمی‌شد.
 *
 * ═══ راه‌حل ═══
 *
 * منبعِ حقیقت **خودِ پورتال** است. این‌جا صفحه‌اش خوانده و نسخه و لینکِ هر
 * سکو از آن استخراج می‌شود، پس انتشارِ نسخهٔ تازه روی زیردامنه خودبه‌خود روی
 * سایتِ اصلی هم دیده می‌شود.
 *
 * 🔴 قاعدهٔ حاکم بر کلِ این کلاس: **این یک صفحهٔ بازاریابی است، نه یک کارِ
 * پس‌زمینه.** هیچ خرابی‌ای در زیردامنه حق ندارد صفحهٔ اصلی را کند یا خراب کند.
 * پس مهلت کوتاه است، همه‌چیز در `try` است، شکست هم **کش می‌شود** (وگرنه
 * زیردامنهٔ خاموش به هر بازدیدکننده چند ثانیه تأخیر اضافه می‌کند)، و در
 * بدترین حالت به مقادیرِ `config/solutions.php` برمی‌گردیم.
 */
class RemoteRelease
{
    public const PORTAL = 'https://remote.servernet.cloud';

    /** کلیدِ سکوها — همان ترتیبی که در صفحه دیده می‌شود. */
    public const PLATFORMS = ['windows', 'android', 'mac', 'ios'];

    /** موفق ۶ ساعت تازه می‌مانَد؛ انتشارِ تازه حداکثر با این تأخیر دیده می‌شود. */
    private const TTL_OK = 21600;

    /**
     * ⚠️ شکست هم کش می‌شود، ولی کوتاه.
     *
     * بی‌این، زیردامنهٔ خاموش یعنی **هر** بازدیدکنندهٔ صفحه یک تماسِ ناموفقِ
     * چندثانیه‌ای را تاوان می‌دهد. ۱۰ دقیقه هم کوتاه است که انتشارِ تازه دیر
     * دیده نشود، هم بلند است که سیلِ درخواست نسازد.
     */
    private const TTL_FAIL = 600;

    private const TIMEOUT = 4;

    /**
     * @return array{version:?string, files:array<string,string>, ok:bool}
     */
    public function info(): array
    {
        try {
            return Cache::remember('remote_release', self::TTL_OK, function () {
                $parsed = $this->scrape();

                if (! $parsed['ok']) {
                    // شکست را کوتاه‌مدت نگه دار، نه شش ساعت
                    Cache::put('remote_release', $parsed, self::TTL_FAIL);
                }

                return $parsed;
            });
        } catch (\Throwable) {
            // کشِ این پروژه روی دیتابیس است و یک قطعیِ گذرا نباید صفحه را بخواباند
            return ['version' => null, 'files' => [], 'ok' => false];
        }
    }

    /** لینکِ دانلودِ یک سکو، یا `null` اگر هنوز منتشر نشده. */
    public function fileFor(string $platform): ?string
    {
        return $this->info()['files'][$platform] ?? null;
    }

    public function version(): ?string
    {
        return $this->info()['version'];
    }

    /* ---------------------------------------------------------------- */

    /**
     * صفحهٔ پورتال را می‌خواند و نسخه و فایل‌ها را بیرون می‌کشد.
     *
     * ⚠️ چرا با regex و نه یک API: پورتال هیچ manifestِ JSON ندارد (بررسی شد).
     * تا وقتی دارد، همین کافی است — و چون خروجی همیشه با `config` جبران
     * می‌شود، تغییرِ قالبِ آن صفحه بدترین حالتش «لینک‌های قبلی» است نه صفحهٔ
     * خراب.
     */
    private function scrape(): array
    {
        $html = $this->get(self::PORTAL.'/');

        return $html === null
            ? ['version' => null, 'files' => [], 'ok' => false]
            : $this->parse($html);
    }

    /**
     * تجزیهٔ HTMLِ پورتال — **عمومی و بی‌شبکه**، تا بشود تستش کرد.
     *
     * ⚠️ جداکردنش از `scrape()` تزئینی نیست: با HTTP داخلِ همان متد، تنها راهِ
     * تستِ تجزیه یک تماسِ واقعی به اینترنت بود — یعنی تستی که روی ماشینِ
     * بی‌اینترنت (یا با CAِ خراب، مثلِ همین باکس) قرمز می‌شود و کسی جدی‌اش
     * نمی‌گیرد.
     */
    public function parse(string $html): array
    {
        $files = [];
        // نامِ فایل الگوی ثابتی دارد: servernet-remote-<نسخه>-<سکو>.<پسوند>
        if (preg_match_all('~href=["\']([^"\']*?/downloads/[^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $href) {
                $platform = $this->platformOf($href);
                if ($platform !== null && ! isset($files[$platform])) {
                    $files[$platform] = $this->absolute($href);
                }
            }
        }

        return [
            'version' => $this->versionOf($html),
            'files'   => $files,
            'ok'      => $files !== [],
        ];
    }

    /** سکو را از نامِ فایل تشخیص می‌دهد. */
    private function platformOf(string $href): ?string
    {
        $h = strtolower($href);

        return match (true) {
            str_contains($h, 'windows') || str_ends_with($h, '.exe') || str_ends_with($h, '.msi') => 'windows',
            str_contains($h, 'android') || str_ends_with($h, '.apk')                              => 'android',
            str_contains($h, 'mac') || str_contains($h, 'darwin') || str_ends_with($h, '.dmg')    => 'mac',
            str_contains($h, 'ios') || str_ends_with($h, '.ipa')                                  => 'ios',
            default                                                                               => null,
        };
    }

    /**
     * نسخه را ترجیحاً از **نامِ فایل** می‌خوانَد نه از متنِ صفحه.
     *
     * ⚠️ متنِ فارسی رقمِ فارسی دارد («نسخه ۱.۴.۹») و نامِ فایل همیشه لاتین است.
     * خواندن از نامِ فایل یعنی هیچ تبدیلِ رقمی لازم نیست و اگر روزی متنِ صفحه
     * عوض شد، چیزی نمی‌شکند.
     */
    private function versionOf(string $html): ?string
    {
        if (preg_match('~servernet-remote-(\d+(?:\.\d+)+)~i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function absolute(string $href): string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        return self::PORTAL.'/'.ltrim($href, '/');
    }

    private function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'ServerNet-Site/1.0 (+https://servernet.cloud)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($body === false || $code < 200 || $code >= 300) ? null : (string) $body;
    }
}
