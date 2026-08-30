<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * مسیرِ `config()`ای که وجود ندارد، **خطا نمی‌دهد** — `null` می‌دهد.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * بلوکِ `bale_relay` در `config/services.php` کنارِ `ippanel` و `kavenegar`
 * **داخلِ** آرایهٔ `sms` نشست، ولی `AppServiceProvider` مسیرِ سطحِ بالای
 * `services.bale_relay` را می‌خواند:
 *
 *     .env درست ✓ → env() درست ✓ → config() خالی ✗
 *       ⇒ BaleRelaySender::enabled() کاذب
 *       ⇒ سقوطِ بی‌صدا به LogSmsSender
 *
 * سایت می‌گفت «کد ارسال شد» و هیچ پیامکی نمی‌رفت. نه استثنایی، نه لاگی.
 *
 * ⚠️ و هیچ‌کدام از ۱۱۸۶ تست نگرفتش، چون همه مقدار را با `config([...])` دستی
 * ست می‌کردند و `config()` **هر مسیری را که نام ببری می‌سازد** — یعنی تست،
 * مسیرِ غلط را خودش به‌وجود می‌آورد و سبز می‌شد.
 *
 * ═══ چرا فقط فراخوان‌های **بی‌پیش‌فرض** ═══
 *
 * `config('billing.tax_percent', 10)` عمداً کلید ندارد: پیش‌فرض همان‌جا نوشته
 * شده و رفتار قطعی است. ولی `config('services.sms.bale_relay.secret')` بدونِ
 * پیش‌فرض یعنی نویسنده **انتظار داشته مقداری باشد** — و اگر نباشد، `null` در
 * جریان می‌افتد و چند لایه پایین‌تر به یک خرابیِ خاموش تبدیل می‌شود.
 *
 * پس همین «پیش‌فرض دارد یا نه» تفکیک‌کنندهٔ دقیقِ عمد از اشتباه است.
 */
class ConfigPathIntegrityTest extends TestCase
{
    /**
     * فقط کدِ **تولید** اسکن می‌شود.
     *
     * ⚠️ `config/` عمداً بیرون است: یک فایلِ config که از config دیگری می‌خواند،
     * در لحظهٔ بوت ممکن است هنوز بارگذاری نشده باشد و این تست نمی‌تواند دربارهٔ
     * ترتیبش قضاوت کند.
     *
     * ⚠️ `tests/` هم بیرون است، و این حذفِ راحت‌طلبانه نیست: تست حق دارد **نبودنِ**
     * یک مسیر را بسنجد (`assertNull(config('services.bale_relay'))` دقیقاً برای
     * همین نوشته شده تا بلوک در دو جا تعریف نشود). اگر تست‌ها را هم اسکن کنیم،
     * همان ادعای درست به‌عنوانِ خطا گزارش می‌شود و این فایل به‌سرعت به یک تستِ
     * پرِ استثنا و بی‌اعتبار تبدیل می‌شود.
     */
    private const ROOTS = ['app', 'routes', 'database', 'resources'];

    public function test_no_code_reads_a_config_path_that_does_not_exist(): void
    {
        $missing = [];

        foreach ($this->phpFiles() as $path) {
            $src = file_get_contents($path);

            /*
            | فقط `config('a.b.c')` بدونِ آرگومانِ دوم.
            |
            | `\)` بلافاصله بعد از نقل‌قول یعنی پیش‌فرضی در کار نیست. با کاما،
            | فراخوان پیش‌فرض دارد و از دیدِ این تست عمدی است.
            */
            if (! preg_match_all("~config\(\s*'([a-z0-9_]+(?:\.[a-zA-Z0-9_]+)+)'\s*\)~", $src, $m)) {
                continue;
            }

            foreach (array_unique($m[1]) as $key) {
                if (! config()->has($key)) {
                    $missing[] = $key.'  ←  '.$this->relative($path);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            "\nاین مسیرها در هیچ فایلِ config نیستند و بی‌پیش‌فرض خوانده می‌شوند،\n"
            ."پس `null` می‌دهند و کد بی‌صدا به مسیرِ پشتیبان می‌افتد:\n\n"
            .implode("\n", array_unique($missing))."\n");
    }

    /** @return iterable<string> */
    private function phpFiles(): iterable
    {
        foreach (self::ROOTS as $dir) {
            $base = base_path($dir);

            if (! is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
