<?php

namespace App\Services\Mail;

use App\Services\HtmlSanitizer;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * HTMLِ یک نامهٔ ورودی، آمادهٔ نمایش در پنل.
 *
 * ═══ 🔴 چرا `HtmlSanitizer` به‌تنهایی بس نیست ═══
 *
 * آن کلاس برای مقاله‌های خودمان نوشته شده و کارِ خودش را درست می‌کند (script،
 * style، on*، طرحِ javascript:). ولی نامهٔ ورودی یک تفاوتِ بنیادی دارد:
 * **نویسنده‌اش دشمن است.** دو چیز اضافه لازم است که در مقاله معنا ندارند:
 *
 * ۱) **تصویرِ بیرونی = پیکسلِ ردیاب.** یک `<img src="https://…/o.gif?u=123">`
 *    در لحظهٔ باز شدنِ نامه به فرستنده می‌گوید: این نشانی زنده است، خوانده شد،
 *    ساعتش این بود، و آی‌پیِ سرورِ ما این. برای اسپمر این تأیید طلاست و
 *    نتیجه‌اش موجِ بعدیِ اسپم است. پس تصویر **پیش‌فرض بسته** است و کاربر با
 *    یک دکمه بازش می‌کند — همان کاری که جیمیل و اوت‌لوک می‌کنند.
 *
 * ۲) **لینک باید بی‌ارجاع باز شود.** بدونِ `noopener`، صفحهٔ مقصد به تبِ پنل
 *    دست دارد؛ بدونِ `no-referrer`، نشانیِ کاملِ صفحهٔ پنل (که شناسهٔ نامه
 *    دارد) در لاگِ سایتِ ناشناس می‌نشیند.
 *
 * ⚠️ `style` و `bgcolor` و چیدمانِ جدولیِ نامه‌ها را نگه نمی‌داریم (فهرستِ
 * مجازِ `HtmlSanitizer` ندارد). یعنی خبرنامه‌ها ساده‌تر از اصل دیده می‌شوند.
 * این را عمداً نگه داشتیم: CSSِ دلخواهِ فرستنده روی صفحهٔ پنل، هم می‌تواند
 * چیدمان را بشکند و هم با `position:fixed` روی دکمه‌های واقعیِ پنل بنشیند.
 */
class MailHtmlSanitizer
{
    /** فقط این‌ها را می‌شود در نامه به‌عنوان تصویر نشان داد. */
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

    /**
     * نشانهٔ موقتِ تصویرِ درون‌خطی.
     *
     * 🔴 چرا لازم شد: `HtmlSanitizer::safeUrl()` فقط http/https/mailto/tel را
     * می‌پذیرد، پس `src="cid:logo"` را **پیش از** رسیدن به این کلاس پاک
     * می‌کرد و لوگوی هر نامه‌ای بی‌صدا گم می‌شد. مسیرِ نسبی از آن فیلتر رد
     * می‌شود، پس `cid:` را موقتاً به این شکل درمی‌آوریم و بعد برمی‌گردانیم.
     *
     * شماره‌ها از نگاشتی می‌آیند که خودمان ساخته‌ایم؛ اگر نامه‌ای همین شکل را
     * جعل کند، شماره‌اش در نگاشت نیست و مثلِ هر تصویرِ ناشناس بسته می‌شود.
     */
    private const CID_MARK = '/__cid__/';

    /**
     * @param  callable(string):?string|null  $cidUrl  نشانیِ پیوستِ درون‌خطی از روی Content-ID
     * @return array{html:string, blocked:int}
     */
    public static function clean(string $html, bool $allowImages = false, ?callable $cidUrl = null): array
    {
        [$html, $cids] = self::stashCids($html);

        $safe = HtmlSanitizer::clean($html);

        if ($safe === '') {
            return ['html' => '', 'blocked' => 0];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__mail">'.$safe.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__mail');

        if (! $root) {
            return ['html' => '', 'blocked' => 0];
        }

        $xp = new DOMXPath($doc);
        $blocked = 0;

        foreach (iterator_to_array($xp->query('.//img', $root)) as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $src = trim($img->getAttribute('src'));
            $resolved = self::resolveSrc($src, $allowImages, $cidUrl, $cids);

            if ($resolved === null) {
                $blocked++;
                self::defuse($img, $src);

                continue;
            }

            $img->setAttribute('src', $resolved);
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('referrerpolicy', 'no-referrer');
        }

        foreach (iterator_to_array($xp->query('.//a', $root)) as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }

            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer nofollow');
            $a->setAttribute('referrerpolicy', 'no-referrer');
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ['html' => trim($out), 'blocked' => $blocked];
    }

    /**
     * نشانیِ نهاییِ تصویر، یا `null` یعنی نشان نده.
     *
     * @param  callable(string):?string|null  $cidUrl
     */
    private static function resolveSrc(string $src, bool $allowImages, ?callable $cidUrl, array $cids): ?string
    {
        if ($src === '') {
            return null;
        }

        // تصویرِ درون‌خطیِ خودِ نامه: بیرون نمی‌رود، پس ردیابی هم ندارد — ولی
        // تا وقتی کاربر تصاویر را باز نکرده، بی‌دلیل واکشیِ سنگین نمی‌کنیم.
        if (str_starts_with($src, self::CID_MARK)) {
            $n = substr($src, strlen(self::CID_MARK));

            if (! $allowImages || $cidUrl === null || ! isset($cids[$n])) {
                return null;
            }

            return $cidUrl($cids[$n]);
        }

        if (! $allowImages) {
            return null;
        }

        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));

        // 🔴 `data:` حتی با اجازهٔ کاربر هم نه: `data:image/svg+xml` یک سندِ
        // اجراشدنی است، نه عکس. `HtmlSanitizer` آن را می‌گیرد؛ این‌جا هم
        // می‌گیریم تا اگر روزی فهرستِ آن کلاس عوض شد، این مسیر باز نشود.
        return in_array($scheme, ['http', 'https'], true) ? $src : null;
    }

    /**
     * تصویرِ بسته را به یک نشانهٔ دیدنی تبدیل کن.
     *
     * ⚠️ حذفِ کاملِ تگ بدترین کار است: کاربر نمی‌فهمد چیزی آن‌جا بوده و
     * خبرنامه‌ای که تمامش تصویر است، به‌نظر «نامهٔ خالی» می‌آید و آدم فکر
     * می‌کند پنل خراب است.
     */
    private static function defuse(DOMElement $img, string $src): void
    {
        $alt = trim($img->getAttribute('alt'));

        $img->removeAttribute('src');
        $img->removeAttribute('width');
        $img->removeAttribute('height');
        $img->setAttribute('data-blocked', '1');
        $img->setAttribute('title', $alt !== '' ? $alt : 'تصویرِ بسته');

        if ($alt === '') {
            $img->setAttribute('alt', 'تصویرِ بسته');
        }
    }

    /**
     * `src="cid:x"` → `src="/__cid__/0"`، و نگاشتِ شماره به Content-ID.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    private static function stashCids(string $html): array
    {
        $map = [];

        $out = preg_replace_callback(
            '~(<img\b[^>]*?\bsrc\s*=\s*)(["\'])\s*cid:([^"\']+)\2~i',
            function (array $m) use (&$map): string {
                $n = (string) count($map);
                $map[$n] = trim($m[3], " <>\t");

                return $m[1].$m[2].self::CID_MARK.$n.$m[2];
            },
            $html
        );

        return [$out ?? $html, $map];
    }

    /**
     * نسخهٔ متنیِ سادهٔ یک HTML — برای بخشِ `text/plain`ِ نامهٔ خروجی.
     *
     * 🔴 چرا لازم است: نامهٔ فقط-HTML امتیازِ اسپمِ بالاتری می‌گیرد، و ساعتِ
     * هوشمند و اعلانِ گوشی و کلاینتِ متنی همین بخش را نشان می‌دهند. کاربر
     * هرگز این متن را نمی‌نویسد؛ از همان چیزی که در ادیتور نوشته ساخته می‌شود.
     *
     * ⚠️ ترتیبِ جایگزینی مهم است: اول تگ‌های بلوکی به خطِ تازه تبدیل می‌شوند،
     * بعد بقیه حذف. برعکسش، کلِ نامه یک پاراگرافِ بی‌نفس می‌شود.
     */
    public static function toText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $s = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $s = preg_replace('~<li\b[^>]*>~i', "\n• ", $s) ?? $s;
        $s = preg_replace('~<br\s*/?>~i', "\n", $s) ?? $s;
        // ⚠️ `li` عمداً این‌جا نیست: `<li>` بالاتر خودش خطِ تازه گذاشته، و
        // شمردنِ دوباره‌اش بینِ هر بندِ فهرست یک خطِ خالی می‌انداخت.
        $s = preg_replace('~</(p|div|h[1-6]|ul|ol|blockquote|tr)>~i', "\n", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // سه خطِ خالی پشتِ هم از `<div><p>` های تودرتو می‌آید، نه از نیتِ نویسنده.
        $s = preg_replace('~[ \t]+~u', ' ', $s) ?? $s;
        $s = preg_replace('~\n{3,}~u', "\n\n", $s) ?? $s;

        return trim($s);
    }

    /** نوعِ فایلی که می‌شود درون‌خط نشانش داد. بقیه فقط دانلود می‌شوند. */
    public static function isDisplayableImage(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::IMAGE_MIMES, true);
    }
}
