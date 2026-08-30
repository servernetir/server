<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * سه فایلِ `ui.php` نباید از هم جدا بیفتند.
 *
 * ═══ شکستی که این‌جا قفل می‌شود ═══
 *
 * `lang/fa/ui.php` حدود ۱۸۰ کیلوبایت است و در هر تغییرِ سایت دست می‌خورد.
 * اگر کلیدی فقط به فارسی اضافه شود، نسخهٔ انگلیسی و ترکی **خطا نمی‌دهند** —
 * لاراول رشتهٔ خامِ `ui.foo_bar` را روی صفحه چاپ می‌کند. یعنی صفحه ۲۰۰ است،
 * تست‌ها سبزند، و بازدیدکنندهٔ خارجی یک شناسهٔ برنامه‌نویسی را وسطِ متن
 * می‌بیند. همان الگوی «شکست نمی‌خورد، فقط بد اتفاق می‌افتد».
 *
 * ⚠️ این تست **قفل** است نه تعمیر: امروز هر سه فایل ۲۵۳۸ کلید دارند و صفر
 * اختلاف. کارش این است که فردا هم همین بماند.
 */
class LocaleKeysNeverDriftTest extends TestCase
{
    private const LOCALES = ['fa', 'en', 'tr'];

    /** کلیدهای تودرتو را به `a.b.c` صاف می‌کند. */
    private function flat(array $rows, string $prefix = ''): array
    {
        $out = [];

        foreach ($rows as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $this->flat($value, $full);
            } else {
                $out[$full] = $value;
            }
        }

        return $out;
    }

    private function keys(string $locale): array
    {
        return $this->flat(require base_path("lang/{$locale}/ui.php"));
    }

    /** 🔴 هر کلید باید در هر سه زبان باشد. */
    public function test_every_key_exists_in_every_locale(): void
    {
        $sets = [];
        foreach (self::LOCALES as $loc) {
            $sets[$loc] = $this->keys($loc);
        }

        $this->assertGreaterThan(2000, count($sets['fa']),
            'فایلِ فارسی ناگهان لاغر شده — احتمالاً بخشی حذف شده است.');

        $problems = [];

        foreach (self::LOCALES as $a) {
            foreach (self::LOCALES as $b) {
                if ($a === $b) {
                    continue;
                }

                $missing = array_keys(array_diff_key($sets[$a], $sets[$b]));

                if ($missing !== []) {
                    $problems[] = sprintf(
                        '%d کلید در %s هست ولی %s ندارد — نمونه: %s',
                        count($missing), $a, $b, implode('، ', array_slice($missing, 0, 8))
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * 🔴 کلیدی که کد صدا می‌زند باید تعریف شده باشد.
     *
     * تعریف‌نشده یعنی رشتهٔ خامِ `ui.foo` روی صفحه چاپ می‌شود.
     *
     * ⚠️ فقط فراخوانِ **حرفیِ کامل** سنجیده می‌شود: الگوی `[,)]` بعد از کوتیشن
     * عمدی است تا `__('ui.tk_dep_'.$x)` — که کلیدش در زمانِ اجرا ساخته می‌شود —
     * وارد نشود. نسخهٔ اولِ همین آشکارساز این را نداشت و هفت «کلیدِ گمشده»
     * گزارش کرد که همه‌شان پیشوند بودند. نگهبانی که گرگ‌گرگ کند نادیده
     * می‌شود، پس دقتش از پوششش مهم‌تر است.
     */
    public function test_no_call_site_asks_for_a_key_that_does_not_exist(): void
    {
        $defined = $this->keys('fa');
        $used = [];

        foreach ($this->sourceFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));

            if (! preg_match_all('~__\(\s*[\'"]ui\.([a-zA-Z0-9_.]+)[\'"]\s*[,)]~', $code, $m)) {
                continue;
            }

            foreach ($m[1] as $key) {
                $used[$key] ??= $file;
            }
        }

        $this->assertGreaterThan(1500, count($used),
            'پویشِ فراخوان‌ها تقریباً چیزی پیدا نکرد — الگو یا مسیرها خراب است.');

        $undefined = array_diff_key($used, $defined);

        $lines = [];
        foreach (array_slice($undefined, 0, 15, true) as $key => $file) {
            $lines[] = "ui.{$key}   ←   ".str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }

        $this->assertSame([], $lines,
            "کلیدِ تعریف‌نشده (روی صفحه خام چاپ می‌شود):\n".implode("\n", $lines));
    }

    /**
     * ⚠️ ترجمه‌ای که فقط کپیِ فارسی است، ترجمه نیست.
     *
     * تشخیص عمداً **برابریِ کامل** است، نه «شاملِ حرفِ فارسی»: چند رشتهٔ
     * انگلیسیِ درست یک واژهٔ فارسی را به‌عنوانِ مثال نقل می‌کنند
     * (`«می‌شود» gibi Farsça…`) و آن‌ها ترجمه‌شده‌اند. آشکارسازِ ساده‌لوحانه
     * همان‌ها را متهم می‌کرد.
     */
    public function test_no_value_is_left_as_an_untranslated_copy(): void
    {
        $fa = $this->keys('fa');
        $copies = [];

        foreach (['en', 'tr'] as $loc) {
            foreach ($this->keys($loc) as $key => $value) {
                if (! is_string($value) || $value === '' || ! isset($fa[$key])) {
                    continue;
                }

                if ($value === $fa[$key] && preg_match('~[\x{0600}-\x{06FF}]~u', $value)) {
                    $copies[] = "{$loc}: {$key}";
                }
            }
        }

        $this->assertSame([], array_slice($copies, 0, 20),
            "مقدارِ ترجمه‌نشده (کپیِ عینِ فارسی):\n".implode("\n", array_slice($copies, 0, 20)));
    }

    /**
     * کامنت‌ها را برمی‌دارد تا مثالِ داخلِ توضیحات به‌جای فراخوان شمرده نشود.
     *
     * ⚠️ این پروژه کامنتِ سنگین دارد و چند جا **دقیقاً همین نقص** را توضیح
     * می‌دهد؛ `CloudCatalogController` در docblock می‌نویسد که `__('ui.x')`
     * خودِ «ui.x» را چاپ می‌کند. نسخهٔ اولِ این تست همان جمله را به‌عنوانِ
     * کلیدِ گمشده گزارش کرد.
     *
     * ⚠️ خطِ `//` فقط وقتی حذف می‌شود که **کلِ خط** کامنت باشد. حذفِ از هر
     * `//` تا آخرِ خط، `https://…` وسطِ کد را می‌بُرد و می‌توانست فراخوانِ
     * واقعیِ بعدش را پنهان کند — یعنی نگهبان بی‌صدا کور می‌شد.
     */
    private function withoutComments(string $src): string
    {
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        $src = preg_replace('~\{\{--.*?--\}\}~s', '', $src) ?? $src;

        $kept = [];
        foreach (explode("\n", $src) as $line) {
            if (preg_match('~^\s*(//|#(?!\[)|\*)~', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /** @return iterable<string> فایل‌هایی که ممکن است `__('ui.…')` داشته باشند. */
    private function sourceFiles(): iterable
    {
        foreach (['resources/views', 'app', 'routes', 'config'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
