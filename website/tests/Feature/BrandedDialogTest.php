<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔴 کارفرما: «آلارم‌ها را با alertِ سادهٔ مرورگر نشان نده؛ مثلِ بقیهٔ سایت
 * قشنگ و بااستایل نشان بده.»
 *
 * دیالوگ و توستِ برنددار از قبل در `partials/ui-dialog.blade.php` بود و هر دو
 * layout (پنل و مدیریت) هم آن را include می‌کردند — ولی چند صفحه هنوز
 * `confirm()` و `alert()`ِ خامِ مرورگر را صدا می‌زدند. نتیجه: کاربر وسطِ یک
 * رابطِ فارسیِ RTL، یک جعبهٔ خاکستریِ سیستمی می‌دید.
 *
 * این تست **سورس** را می‌پاید نه رندر را، چون باگ در نوشتنِ صفحهٔ تازه رخ
 * می‌دهد: یک `onsubmit="return confirm(...)"` تازه، بی‌هیچ خطا، دوباره جعبهٔ
 * زشت را برمی‌گرداند.
 */
class BrandedDialogTest extends TestCase
{
    /** @return array<string,string> مسیرِ نسبی => محتوا */
    private function blades(): array
    {
        $root = resource_path('views');
        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $out[$rel] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /** فایلِ خودِ دیالوگ در توضیحاتش این واژه‌ها را دارد و باید مستثنا بماند */
    private const SELF = 'partials/ui-dialog.blade.php';

    public function test_no_native_browser_confirm_in_any_view(): void
    {
        $bad = [];

        foreach ($this->blades() as $rel => $src) {
            if ($rel === self::SELF) {
                continue;
            }

            // `snConfirm(` نباید تطبیق کند، پس مرزِ واژه می‌گذاریم
            if (preg_match('~(?<![A-Za-z0-9_$])confirm\s*\(~', $src)) {
                $bad[] = $rel;
            }
        }

        $this->assertSame([], $bad,
            'به‌جای confirm() از data-confirm یا snConfirm() استفاده کن: '.implode(', ', $bad));
    }

    public function test_no_native_browser_alert_in_any_view(): void
    {
        $bad = [];

        foreach ($this->blades() as $rel => $src) {
            if ($rel === self::SELF) {
                continue;
            }

            if (preg_match('~(?<![A-Za-z0-9_$.])alert\s*\(~', $src)) {
                $bad[] = $rel;
            }
        }

        $this->assertSame([], $bad,
            'به‌جای alert() از snToast() استفاده کن: '.implode(', ', $bad));
    }

    /** هر دو layout باید دیالوگ را داشته باشند وگرنه snConfirm تعریف‌نشده است */
    public function test_both_layouts_include_the_dialog_partial(): void
    {
        $blades = $this->blades();

        foreach (['panel/layout.blade.php', 'admin/layout.blade.php'] as $layout) {
            $this->assertArrayHasKey($layout, $blades);
            $this->assertStringContainsString("@include('partials.ui-dialog')", $blades[$layout],
                "بدونِ این include، snConfirm/snToast تعریف نشده‌اند و دکمه بی‌صدا کار نمی‌کند ($layout)");
        }
    }

    /** صفحهٔ کنترلِ سرور باید دیالوگِ برنددار داشته باشد، نه هیچ‌چیز */
    public function test_server_power_off_still_asks_for_confirmation(): void
    {
        $src = $this->blades()['account/cloud-server.blade.php'];

        $this->assertStringContainsString('data-confirm="{{ __(\'ui.cs_confirm_off\') }}"', $src,
            'خاموش‌کردنِ سرور نباید بی‌پرسش انجام شود');
        $this->assertStringContainsString('data-confirm-danger', $src);
    }
}
