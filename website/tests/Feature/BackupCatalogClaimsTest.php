<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔴 صفحهٔ محصول یک **وعدهٔ فروش** است، نه متنِ بازاریابی.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 * چهار پلنِ هاست بکاپ چیپِ «S3-Compatible» داشتند و هیچ پیاده‌سازیِ S3 در
 * مخزن نبود. مشتری BK-500 خرید، شش دقیقه بعد اطلاعاتِ اتصالِ S3 خواست، و
 * جوابی وجود نداشت. وجه کامل برگشت.
 *
 * حالا تحویل از Storage Boxِ هتزنر است و داکیومنتِ رسمیِ خودشان می‌گوید تنها
 * پروتکل‌هایش SSH/SFTP/SCP/FTP/FTPS/SMB/WebDAV است — **S3 ندارد**.
 *
 * ⚠️ این تست روی «متن قشنگ است» ادعا ندارد؛ روی این ادعا دارد که صفحه چیزی
 * را وعده ندهد که مسیرِ تحویلش وجود ندارد.
 */
class BackupCatalogClaimsTest extends TestCase
{
    public function test_the_backup_catalog_never_promises_s3_again(): void
    {
        $backup = (array) config('hosting.products.backup');

        $this->assertNotSame([], $backup, 'بلوکِ backup در config/hosting.php نیست.');

        $json = json_encode($backup, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('S3', $json,
            'صفحهٔ هاست بکاپ دوباره S3 وعده می‌دهد، در حالی که Storage Box آن را ندارد. '
            .'همان خرابی‌ای که وجهِ یک مشتری را برگرداند.');
    }

    /**
     * ادعاهایی که با دادهٔ واقعیِ هتزنر نمی‌خوانند و **هیچ‌کدام خطا تولید
     * نمی‌کنند** — فقط مشتری چیزی می‌خرد که آن‌طور نیست:
     *
     *  · «AES-256» — هتزنر برای Storage Box رمزنگاریِ سمتِ سرور تبلیغ نمی‌کند.
     *    آنچه واقعاً هست: انتقالِ رمزنگاری‌شده + رمزنگاریِ سمتِ خودِ مشتری.
     *  · «فنلاند/Helsinki» — مکانِ تحویل در config فقط fsn1 (آلمان) است.
     *  · «اسنپ‌شات N روزه» — سقفِ هتزنر **تعداد** است نه روز
     *    (bx11 = ۱۰ دستی + ۱۰ خودکار). ادعای روزمحور در جنس غلط است.
     */
    public function test_the_backup_catalog_matches_the_real_storage_box(): void
    {
        $json = json_encode((array) config('hosting.products.backup'), JSON_UNESCAPED_UNICODE);

        $forbidden = [
            'AES-256'   => 'رمزنگاریِ سمتِ سرور که هتزنر ارائه نمی‌دهد',
            'فنلاند'    => 'مکانی که تحویل نمی‌دهیم',
            'Finland'   => 'مکانی که تحویل نمی‌دهیم',
            'Helsinki'  => 'مکانی که تحویل نمی‌دهیم',
            'روزه'      => 'اسنپ‌شاتِ روزمحور — سقفِ هتزنر تعداد است نه روز',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString($needle, $json,
                "صفحهٔ هاست بکاپ «{$needle}» ادعا می‌کند: {$why}.");
        }
    }

    /**
     * پلنی که در `hetzner_storage.plans` نگاشت دارد، تحویلِ خودکار می‌شود —
     * پس مشخصاتش باید همان چیزی باشد که واقعاً تحویل می‌شود.
     *
     * ⚠️ اندیسِ آرایه معنا دارد: اسلاگِ پکیج `{گروه}-{اندیس+۱}` است
     * (`SeedHostingProducts`) و دکمهٔ خرید هم از همان فرمول ساخته می‌شود.
     * پس حذفِ یک پلن از وسطِ آرایه، دکمهٔ خریدِ بقیه را به محصولِ اشتباه وصل
     * می‌کند — این تست آن ترتیب را هم قفل می‌کند.
     */
    public function test_the_mapped_plans_keep_their_catalog_position(): void
    {
        $plans = (array) config('hosting.products.backup.plans');
        $map   = (array) config('provisioning.hetzner_storage.plans');

        foreach ($map as $planKey => $boxType) {
            // sn_backup_3 → اندیسِ ۲ در آرایهٔ کاتالوگ
            $this->assertSame(1, preg_match('/^sn_backup_(\d+)$/', $planKey, $m),
                "کلیدِ نگاشت «{$planKey}» با قاعدهٔ sn_{گروه}_{شماره} نمی‌خواند.");

            $idx = (int) $m[1] - 1;

            $this->assertArrayHasKey($idx, $plans,
                "پلنِ نگاشت‌شدهٔ «{$planKey}» در کاتالوگ جایگاهی ندارد — دکمهٔ خرید به محصولِ اشتباه می‌خورد.");

            $specs = json_encode($plans[$idx]['specs'] ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsStringIgnoringCase('S3', $specs,
                "مشخصاتِ «{$planKey}» هنوز S3 ادعا می‌کند ولی روی {$boxType} تحویل می‌شود.");
        }
    }
}
