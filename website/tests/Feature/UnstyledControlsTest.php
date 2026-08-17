<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * هیچ **کنترلِ تعاملی** نباید کلاسی داشته باشد که هیچ‌جا تعریف نشده.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * دکمهٔ «+ فروش سرویس جدید» در `/admin/customers/{id}` کلاسِ `ad-btn` داشت و
 * آن کلاس **در هیچ شیتی تعریف نشده بود**. نتیجه: یک دکمهٔ خامِ خاکستریِ
 * مرورگر وسطِ پنل. کلاسِ CSSِ نبود نه خطا می‌دهد، نه در لاگ می‌آید، نه تستِ
 * «صفحه ۲۰۰ داد» می‌گیردش — تنها راهِ دیدنش نگاه‌کردن به صفحه است، و کارفرما
 * پیش از ما دیدش.
 *
 * سه موردِ دیگر هم با همین جستجو پیدا شدند و بدتر بودند چون روی **مسیرِ پول**
 * نشسته بودند: `pnl-input` روی `<select>` و `<input>`ِ صفحهٔ تسویهٔ دامنه.
 *
 * ═══ چرا فقط کنترل‌های تعاملی و نه هر کلاسی ═══
 *
 * چند `<div>`ِ پوششی هم کلاسِ بی‌تعریف دارند (`blog-main`، `docs-toc-in`، …).
 * آنها قلّابِ چیدمان‌اند و نبودِ قاعده برایشان **بی‌ضرر** است؛ اضافه‌کردنِ CSSِ
 * دلبخواهی به آنها ظاهرِ امروزِ درست را عوض می‌کند — یعنی تست ما را وادار به
 * خرابکاری می‌کرد. تستی که آدم را به تغییرِ بی‌مورد وادار کند، خودش خاموش
 * می‌شود.
 *
 * دکمه و ورودی فرق دارند: آنجا نبودِ استایل **همیشه** دیده می‌شود.
 */
class UnstyledControlsTest extends TestCase
{
    /*
    | ⚠️ قاعده «**هیچ** کلاسِ تعریف‌شده‌ای ندارد» است، نه «همهٔ کلاس‌هایش
    | تعریف شده‌اند».
    |
    | نسخهٔ اولِ همین تست سخت‌گیرانه بود و شش موردِ سالم را قرمز کرد:
    |     class="pnl-btn quick"        → pnl-btn استایل دارد، quick قلّابِ JS است
    |     class="btn btn-glass tpl-var"→ btn استایل دارد، tpl-var قلّابِ JS است
    |     class="ad-pick cl-pick"      → همین الگو
    | یعنی می‌خواست قلّاب‌های جاوااسکریپت را حذف کنیم — کاری که هیچ‌چیز را بهتر
    | نمی‌کرد. تستی که آدم را به خرابکاری وادار کند، خودش خاموش می‌شود.
    |
    | چیزی که واقعاً خراب است این است: کنترلی که **هیچ** منبعِ استایلی ندارد —
    | نه کلاسِ تعریف‌شده، نه `style=` درون‌خطی. همان چیزی که «+ فروش سرویس
    | جدید» بود.
    */

    /** @return array<string,bool> */
    private function definedClasses(): array
    {
        $all = [];

        // شیت‌های واقعی
        foreach (glob(public_path('assets/css/*.css')) ?: [] as $file) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            preg_match_all('~\.([A-Za-z][A-Za-z0-9_-]+)~', $css, $m);
            $all = array_merge($all, $m[1]);
        }

        /*
        | ⚠️ استایلِ درون‌خطیِ خودِ Blade هم «تعریف» است.
        |
        | این پروژه عمداً چند دسته را آن‌جا نگه می‌دارد — مثلاً `fin-*` در
        | `admin/partials/finance-styles.blade.php`. نسخهٔ اولِ همین بررسی این
        | را نمی‌دید و ۷۶ موردِ «بی‌تعریف» گزارش کرد که ۶۲تایشان سالم بودند.
        */
        foreach ($this->blades() as $file) {
            $src = (string) file_get_contents($file);

            if (preg_match_all('~<style[^>]*>(.*?)</style>~s', $src, $blocks)) {
                foreach ($blocks[1] as $block) {
                    preg_match_all('~\.([A-Za-z][A-Za-z0-9_-]+)~', $block, $m);
                    $all = array_merge($all, $m[1]);
                }
            }
        }

        return array_fill_keys($all, true);
    }

    /** @return list<string> */
    private function blades(): array
    {
        $out = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    public function test_no_button_or_input_carries_an_undefined_class(): void
    {
        $defined = $this->definedClasses();
        $bad = [];

        foreach ($this->blades() as $file) {
            $src = (string) file_get_contents($file);

            // فقط تگ‌هایی که کاربر با آنها کار می‌کند
            preg_match_all('~<(button|input|select|textarea)\b[^>]*>~i', $src, $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('~class="([^"{}@]+)"~', $tag, $cm)) {
                    continue;   // کلاسِ داینامیک یا بی‌کلاس — قضاوت‌پذیر نیست
                }

                // استایلِ درون‌خطی هم استایل است (مثلِ دکمهٔ «حذف»ِ قرمز)
                if (str_contains($tag, 'style="')) {
                    continue;
                }

                $classes = array_values(array_filter(
                    preg_split('~\s+~', trim($cm[1])) ?: [],
                    fn ($c) => $c !== '' && preg_match('~^[A-Za-z][A-Za-z0-9_-]+$~', $c),
                ));

                if ($classes === []) {
                    continue;
                }

                $styled = false;

                foreach ($classes as $class) {
                    if (isset($defined[$class])) {
                        $styled = true;
                        break;
                    }
                }

                if (! $styled) {
                    $bad[] = basename($file).' → class="'.implode(' ', $classes).'"';
                }
            }
        }

        $bad = array_values(array_unique($bad));
        sort($bad);

        $this->assertSame([], $bad,
            "\nکنترلی با کلاسِ تعریف‌نشده — بی‌هیچ خطایی خام رندر می‌شود:\n  "
            .implode("\n  ", $bad));
    }
}
