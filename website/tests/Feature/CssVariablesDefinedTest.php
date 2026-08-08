<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * هیچ `var(--X)` نباید به متغیرِ تعریف‌نشده اشاره کند.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * در `/admin/payment-accounts` ورودی‌های فرم را با `var(--bg-2, #0f1520)`
 * استایل دادم. متغیرِ `--bg-2` وجود ندارد (نامِ واقعی `--surface2` است)، پس
 * fallbackِ تیره **همیشه** اعمال می‌شد — در لایت‌مود هم. کاربر ورودی‌های سیاه
 * روی صفحهٔ روشن می‌دید و هیچ خطایی هم در کار نبود.
 *
 * و بدترش: `--accent` در ده جا استفاده می‌شد و هیچ‌جا تعریف نشده بود، **بدونِ
 * fallback**. var()ِ نامعتبر یعنی مرورگر کلِ آن اعلان را بی‌صدا دور می‌اندازد؛
 * نتیجه‌اش در کنسولِ ابری دکمه‌ای بود با متنِ سفید و بی‌پس‌زمینه — یعنی نامرئی.
 *
 * ⚠️ چرا CSS این کلاس خطا خطرناک است: نه کامپایل می‌شود، نه لینت پیش‌فرضی
 * دارد، نه در تستِ «صفحه ۲۰۰ می‌دهد» دیده می‌شود. تنها راهِ گرفتنش همین است.
 */
class CssVariablesDefinedTest extends TestCase
{
    private const SHEETS = ['site.css', 'admin.css', 'panel.css'];

    /**
     * متغیرهایی که عمداً **در HTML** ست می‌شوند (`style="--w:40%"`).
     * ⚠️ فهرست عمداً کوتاه است؛ هر افزودنی باید توجیه داشته باشد، وگرنه این
     * تست به‌مرور به یک فهرستِ استثناء تبدیل می‌شود که دیگر چیزی نمی‌گیرد.
     */
    private const SET_INLINE = ['--w', '--bk', '--av-h', '--csl-h', '--rtl'];

    /** متغیرهایی که مرورگر خودش می‌دهد یا از قالبِ دست‌نخوردهٔ لاراول‌اند */
    private const EXTERNAL = [
        '--mono', '--default-font-feature-settings', '--default-font-variation-settings',
        '--default-mono-font-feature-settings', '--default-mono-font-variation-settings',
    ];

    private function strip(string $s): string
    {
        // ⚠️ کامنت‌ها باید حذف شوند: نسخهٔ اولِ همین بررسی، دو موردِ **از قبل
        //    رفع‌شده** را که فقط نامشان در کامنت آمده بود، باگِ زنده گزارش کرد.
        $s = preg_replace('~/\*.*?\*/~s', '', $s);

        return (string) preg_replace('~\{\{--.*?--\}\}~s', '', $s);
    }

    private function definedVars(): array
    {
        $all = [];

        foreach (self::SHEETS as $f) {
            preg_match_all('~(--[a-zA-Z0-9_-]+)\s*:~',
                $this->strip(file_get_contents(public_path('assets/css/'.$f))), $m);
            $all = array_merge($all, $m[1]);
        }

        return array_unique($all);
    }

    /** 🔴 ادعای اصلی */
    public function test_every_css_variable_used_is_defined_somewhere(): void
    {
        $defined = array_merge($this->definedVars(), self::SET_INLINE, self::EXTERNAL);

        $files = array_merge(
            array_map(fn ($f) => public_path('assets/css/'.$f), self::SHEETS),
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
        );

        $bad = [];

        foreach ($files as $path) {
            $src = $this->strip(file_get_contents($path));

            if (! str_contains($src, 'var(--')) {
                continue;
            }

            // متغیرهایی که همان فایل خودش تعریف می‌کند (style درون‌خطی هم)
            preg_match_all('~(--[a-zA-Z0-9_-]+)\s*:~', $src, $own);
            $known = array_merge($defined, $own[1]);

            preg_match_all('~var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,([^();]*))?\)~', $src, $uses, PREG_SET_ORDER);

            foreach ($uses as $u) {
                if (in_array($u[1], $known, true)) {
                    continue;
                }

                $bad[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                    .' → var('.$u[1].(isset($u[2]) && trim($u[2]) !== '' ? ', '.trim($u[2]) : '').')';
            }
        }

        $bad = array_values(array_unique($bad));

        $this->assertSame([], $bad,
            "\nمتغیرِ CSSِ تعریف‌نشده — بی‌fallback یعنی اعلانِ دورانداخته، و با\n"
            ."fallbackِ تیره یعنی رنگِ تیره در لایت‌مود:\n  ".implode("\n  ", $bad));
    }

    /**
     * ⚠️ حتی متغیرِ **تعریف‌شده** هم نباید fallbackِ رنگیِ سخت‌کد داشته باشد.
     *
     * `var(--surface2, #0f1520)` امروز درست کار می‌کند، ولی روزی که کسی نامِ
     * متغیر را عوض کند، بی‌صدا به همان رنگِ تیره برمی‌گردد — و دقیقاً همان
     * باگ برمی‌گردد، این بار بدونِ اینکه تستِ بالا بگیردش.
     */
    public function test_no_colour_fallback_is_hardcoded_in_a_var(): void
    {
        $bad = [];

        $files = array_merge(
            array_map(fn ($f) => public_path('assets/css/'.$f), self::SHEETS),
            glob(resource_path('views/**/*.blade.php')) ?: [],
        );

        foreach ($files as $path) {
            $src = $this->strip(file_get_contents($path));

            preg_match_all('~var\(\s*(--[a-zA-Z0-9_-]+)\s*,\s*(#[0-9a-fA-F]{3,8})\s*\)~', $src, $m, PREG_SET_ORDER);

            foreach ($m as $u) {
                /*
                | ⚠️ متغیرهای درون‌خطی استثنا هستند و باید باشند.
                |
                | `var(--bk, #64748B)` رنگِ نشانِ بانک است که هر ردیف با
                | `style="--bk:…"` مقدارِ خودش را می‌دهد؛ آن hex «رنگِ تم» نیست،
                | «مقدارِ پیش‌فرض وقتی بانک رنگ ندارد» است. اگر این‌جا استثنا
                | نشوند، تست مجبورمان می‌کند چیزی را «درست» کنیم که خراب نیست —
                | و تستی که آدم را به تغییرِ بی‌مورد وادار کند، خودش خاموش می‌شود.
                */
                if (in_array($u[1], self::SET_INLINE, true)) {
                    continue;
                }

                $bad[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → var('.$u[1].', '.$u[2].')';
            }
        }

        $bad = array_values(array_unique($bad));

        $this->assertSame([], $bad,
            "\nرنگِ سخت‌کد به‌عنوان fallback — با تم عوض نمی‌شود:\n  ".implode("\n  ", $bad));
    }
}
