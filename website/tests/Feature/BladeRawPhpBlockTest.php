<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * بلوکِ خامِ PHP در بلید — و تله‌ای که یک صفحهٔ ۵۰۰ ساخت.
 *
 * ═══ چرا این کلاس وجود دارد ═══
 *
 * لاراول پیش از هر کامپایلِ دیگری، بلوک‌های خامِ PHP را با یک الگوی
 * **تنبل** بیرون می‌کشد که از **اولینِ** آن دایرکتیو در فایل شروع می‌کند و تا
 * **اولین** پایان‌دهنده پیش می‌رود.
 *
 * 🔴 نتیجه‌اش این است: اگر فایلی بالاترش از فرمِ تک‌خطی (پرانتزدار) استفاده
 * کرده باشد و پایین‌تر یک بلوکِ چندخطی اضافه شود، الگو آن تک‌خطی را به
 * پایان‌دهندهٔ بلوکِ پایینی جفت می‌کند و **هرچه بینشان است** — ده‌ها خط HTML —
 * داخلِ یک بلوکِ PHP بلعیده می‌شود.
 *
 * ⚠️ این دقیقاً در `admin/settings/pricing.blade.php` رخ داد: یک تک‌خطی در
 * خط ۲۱ و یک بلوکِ تازه در خط ۹۹، و **۷۳۰۱ بایت** HTML داخلِ PHP رفت. صفحه
 * `syntax error, unexpected end of file` داد و کلِ تبِ نرخ‌گذاری ۵۰۰ شد.
 *
 * ⚠️ چرا با چشم پیدا نمی‌شود: هر دو فرم جداگانه کاملاً درست‌اند، هر دو در
 * ده‌ها فایلِ سالمِ همین پروژه به کار رفته‌اند، و شمارشِ بازوبسته هم می‌خوانَد.
 * فقط **ترتیبشان در یک فایل** خراب است.
 *
 * ادعای این تست عمداً تنگ است: نه مخلوط‌بودنِ دو فرم را ممنوع می‌کند (۱۲ فایلِ
 * سالم همین حالا هر دو را دارند)، بلکه فقط حالتی را می‌گیرد که بدنهٔ بلوکِ
 * استخراج‌شده با پرانتز شروع شود — نشانهٔ قطعیِ اینکه یک تک‌خطی بلعیده شده.
 */
class BladeRawPhpBlockTest extends TestCase
{
    /** همان الگوی لاراول برای استخراجِ بلوکِ خام. */
    private const RAW_BLOCK = '/(?<!@)@php(.*?)@endphp/s';

    public function test_no_blade_file_swallows_an_inline_php_directive_into_a_raw_block(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $this->assertDirectoryExists($root);

        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());
            if (! preg_match_all(self::RAW_BLOCK, $body, $matches)) {
                continue;
            }

            foreach ($matches[1] as $block) {
                // بدنه‌ای که با پرانتز باز می‌شود یعنی آغازگرِ این بلوک، فرمِ
                // تک‌خطی بوده و هرچه تا پایان‌دهنده آمده بلعیده شده است.
                if (preg_match('/^[ \t]*\(/', $block)) {
                    $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname())
                        .' ('.strlen($block).' bytes swallowed)';
                    break;
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            'یک دایرکتیوِ تک‌خطیِ PHP داخلِ بلوکِ خام بلعیده شده؛ آن صفحه هنگام رندر '
            ."۵۰۰ می‌دهد.\nیا بلوک را بردارید و محاسبه را به کنترلر ببرید، یا در همان "
            .'فایل فقط از یکی از دو فرم استفاده کنید.');
    }
}
