<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * یک <td> باید سلولِ جدول بمانَد.
 *
 * ═══ باگی که این تست از آن آمد ═══
 *
 * `.cust-act` و `.ad-row-act` — ستونِ عملیاتِ جدول‌های پنل — `display:flex`
 * داشتند. ولی یک <td> با display:flex **دیگر سلولِ جدول نیست**: مرورگر آن را
 * از شبکهٔ ستون‌ها بیرون می‌گذارد و در یک سلولِ ناشناس می‌پیچد. نتیجه:
 * دکمه‌ها با سرستونِ خودشان یک‌راستا نبودند، خطِ زیرِ ردیف تکه‌تکه می‌شد، و
 * ستونِ عملیات از بدنهٔ جدول «می‌زد بیرون» — در هفت جدولِ مختلفِ پنل.
 *
 * هیچ خطایی هیچ‌جا ثبت نمی‌شد و صفحه ۲۰۰ می‌داد؛ فقط با نگاه‌کردن دیده می‌شد.
 *
 * ⚠️ این تست عمداً **کلاسِ خاصی را نام نمی‌برد**. اگر فقط همان دو کلاس را
 * می‌سنجید، فردا کلاسِ سومی همین اشتباه را می‌کرد و تست سبز می‌مانْد. پس
 * قاعده را می‌سنجد: «هر کلاسی که روی td/th نشسته، حق ندارد display غیرجدولی
 * بگیرد».
 */
class TableCellLayoutTest extends TestCase
{
    /** مقادیری که یک سلول را از جدول جدا می‌کنند. */
    private const BREAKS_THE_TABLE = ['flex', 'grid', 'block', 'inline-block', 'inline-flex', 'inline-grid', 'inline'];

    public function test_no_class_used_on_a_table_cell_is_given_a_non_table_display(): void
    {
        $cellClasses = $this->classesUsedOnCells();
        $this->assertNotEmpty($cellClasses, 'هیچ کلاسی روی td/th پیدا نشد — الگوی جستجو خراب شده');

        $violations = [];

        foreach ($this->cssRules() as [$selectorList, $block, $where]) {
            if (! preg_match('/(?:^|[;{\s])display\s*:\s*([a-z-]+)/i', $block, $m)) {
                continue;
            }
            $display = strtolower($m[1]);
            if (! in_array($display, self::BREAKS_THE_TABLE, true)) {
                continue;
            }

            foreach (explode(',', $selectorList) as $selector) {
                // فقط آخرین compound مهم است: `.x .y{}` دربارهٔ .y است نه .x
                $parts = preg_split('/\s*[\s>+~]\s*/', trim($selector));
                $subject = end($parts);
                if ($subject === false || $subject === '') {
                    continue;
                }

                foreach ($cellClasses as $class) {
                    if (preg_match('/\.'.preg_quote($class, '/').'(?![-\w])/', $subject)) {
                        $violations[] = "{$where}: «{$subject}» کلاسِ .{$class} را display:{$display} می‌کند";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "یک <td>/<th> از جدول جدا شده:\n".implode("\n", array_unique($violations)));
    }

    /** کلاس‌هایی که در ویوها روی <td> یا <th> نشسته‌اند. */
    private function classesUsedOnCells(): array
    {
        $classes = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all('/<t[dh]\b[^>]*\bclass="([^"]+)"/i', (string) file_get_contents($file), $m);
            foreach ($m[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) as $c) {
                    // مقادیرِ بلیدی مثل {{ $x }} کلاسِ ثابت نیستند
                    if ($c !== '' && ! str_contains($c, '{') && preg_match('/^[-\w]+$/', $c)) {
                        $classes[$c] = true;
                    }
                }
            }
        }

        return array_keys($classes);
    }

    /** هر قاعدهٔ CSS پروژه: از فایل‌های css و از <style>های درون‌خطیِ بلید. */
    private function cssRules(): array
    {
        $sources = [];

        foreach (glob(base_path('public/assets/css/*.css')) as $css) {
            $sources[basename($css)] = (string) file_get_contents($css);
        }

        foreach ($this->bladeFiles() as $file) {
            $html = (string) file_get_contents($file);
            if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m)) {
                $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                $sources[$rel] = implode("\n", $m[1]);
            }
        }

        $rules = [];
        foreach ($sources as $where => $css) {
            $css = preg_replace('#/\*.*?\*/#s', '', $css);           // کامنت‌ها
            $css = preg_replace('/@media[^{]*\{/', '', (string) $css); // بدنهٔ مدیا هم قاعده است

            preg_match_all('/([^{}]+)\{([^{}]*)\}/', (string) $css, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $sel = trim($hit[1]);
                if ($sel === '' || str_starts_with($sel, '@')) {
                    continue;
                }
                $rules[] = [$sel, $hit[2], $where];
            }
        }

        return $rules;
    }

    private function bladeFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
