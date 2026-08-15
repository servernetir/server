<?php

namespace Tests\Feature;

use App\Services\RemoteRelease;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * دانلودهای «سرورنت ریموت» — از خودِ زیردامنه، نه از عددِ سخت‌کد.
 *
 * ═══ باگی که این تست‌ها از آن آمدند ═══
 *
 * صفحهٔ `/solutions/remote` چهار دکمهٔ دانلود داشت و **هر چهارتا** به آدرسِ
 * خالیِ `https://remote.servernet.cloud` می‌رفتند. سه‌تای‌شان (اندروید، مک،
 * آیفون) سکوهایی بودند که روی خودِ پورتال «به‌زودی» علامت خورده‌اند.
 *
 * یعنی مشتری روی «دانلود مک» کلیک می‌کرد، به صفحه‌ای می‌رفت که می‌گفت هنوز
 * آماده نیست، و نتیجه می‌گرفت محصول ادعاست. هیچ خطایی هم هیچ‌جا ثبت نمی‌شد —
 * لینک ۲۰۰ می‌داد و صفحه سالم بود.
 */
class RemoteDownloadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('remote_release');
    }

    /** HTMLِ واقعیِ پورتال، خلاصه‌شده تا همان چیزهایی که مهم‌اند بماند. */
    private function portalHtml(): string
    {
        return <<<'HTML'
        <section id="download">
          <div class="card">
            <h3>ویندوز</h3>
            <p>نسخه ۱.۴.۹ · ۶۴ بیت · قابل‌حمل (ZIP)</p>
            <a href="/downloads/servernet-remote-1.4.9-windows-x64.zip">دانلود مستقیم</a>
          </div>
          <div class="card">
            <h3>اندروید</h3>
            <p>نسخه ۱.۴.۹ · arm64 · ۲۹ مگابایت</p>
            <span class="soon">به‌زودی</span>
          </div>
          <div class="card"><h3>مک</h3><span class="soon">به‌زودی</span></div>
          <div class="card"><h3>آیفون</h3><span class="soon">به‌زودی</span></div>
        </section>
        HTML;
    }

    // ═══════════════ ۱) تجزیه ═══════════════

    public function test_it_reads_the_version_and_the_real_file_from_the_portal(): void
    {
        $r = (new RemoteRelease())->parse($this->portalHtml());

        $this->assertTrue($r['ok']);
        $this->assertSame('1.4.9', $r['version']);
        $this->assertSame(
            'https://remote.servernet.cloud/downloads/servernet-remote-1.4.9-windows-x64.zip',
            $r['files']['windows']
        );
    }

    /**
     * 🔴 سکویی که فایل ندارد **نباید** لینک بگیرد.
     *
     * این هستهٔ ماجراست: «به‌زودی» روی پورتال یعنی فایلی نیست، و صفحهٔ اصلی
     * حق ندارد برایش دکمهٔ دانلود بسازد.
     */
    public function test_platforms_without_a_file_stay_absent(): void
    {
        $files = (new RemoteRelease())->parse($this->portalHtml())['files'];

        $this->assertArrayHasKey('windows', $files);
        foreach (['android', 'mac', 'ios'] as $p) {
            $this->assertArrayNotHasKey($p, $files, "«{$p}» فایلی ندارد ولی لینک گرفت");
        }
    }

    /** نسخهٔ تازه روی پورتال ⇒ لینکِ تازه این‌جا، بی‌هیچ تغییرِ کدی. */
    public function test_a_new_release_flows_through_without_touching_the_code(): void
    {
        $next = str_replace('1.4.9', '2.0.0', $this->portalHtml());
        $r = (new RemoteRelease())->parse($next);

        $this->assertSame('2.0.0', $r['version']);
        $this->assertStringContainsString('servernet-remote-2.0.0-windows-x64.zip', $r['files']['windows']);
    }

    /** سکوهای تازه هم به‌محضِ انتشار تشخیص داده می‌شوند. */
    public function test_it_recognises_each_platform_by_its_file(): void
    {
        $html = '
          <a href="/downloads/servernet-remote-1.5.0-windows-x64.zip">w</a>
          <a href="/downloads/servernet-remote-1.5.0-android-arm64.apk">a</a>
          <a href="/downloads/servernet-remote-1.5.0-mac-arm64.dmg">m</a>
          <a href="/downloads/servernet-remote-1.5.0-ios.ipa">i</a>';

        $files = (new RemoteRelease())->parse($html)['files'];

        $this->assertSame(['windows', 'android', 'mac', 'ios'], array_keys($files));
        $this->assertStringEndsWith('.apk', $files['android']);
        $this->assertStringEndsWith('.dmg', $files['mac']);
    }

    public function test_an_unparseable_page_is_not_ok_rather_than_half_true(): void
    {
        $r = (new RemoteRelease())->parse('<html><body>hello</body></html>');

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['files']);
        $this->assertNull($r['version']);
    }

    // ═══════════════ ۲) صفحه ═══════════════

    /**
     * ⚠️ روی این باکس TLS محلی خراب است و تماسِ واقعی شکست می‌خورد — که دقیقاً
     * حالتِ «پورتال در دسترس نیست» را می‌سازد. صفحه باید **سالم** بیاید و به
     * لینک‌های config برگردد؛ نه خطا، نه دکمهٔ مرده، نه «به‌زودی»ِ ساختگی.
     */
    public function test_the_page_survives_an_unreachable_portal(): void
    {
        Cache::put('remote_release', ['version' => null, 'files' => [], 'ok' => false], 60);

        $html = $this->get('/solutions/remote')->assertOk()->getContent();

        $this->assertStringContainsString('sol-dl', $html);
        $this->assertStringNotContainsString('is-soon', $html,
            'وقتی نمی‌دانیم چه منتشر شده، حق نداریم چیزی را «به‌زودی» اعلام کنیم');
        $this->assertStringContainsString('remote.servernet.cloud', $html);
    }

    public function test_a_live_portal_produces_direct_links_and_honest_soon_badges(): void
    {
        Cache::put('remote_release', (new RemoteRelease())->parse($this->portalHtml()), 60);

        $html = $this->get('/solutions/remote')->assertOk()->getContent();

        $this->assertStringContainsString('servernet-remote-1.4.9-windows-x64.zip', $html);
        $this->assertStringContainsString('is-soon', $html, 'سکوهای منتشرنشده باید «به‌زودی» شوند');
        $this->assertStringContainsString('۱.۴.۹', $html, 'نسخه باید روی صفحه دیده شود');
    }

    /**
     * 🔴 منو فایل دانلود نمی‌کند — حتی وقتی پورتال زنده است.
     *
     * روزی زیرِ ردیفِ ریموت یک دکمهٔ «دانلود مستقیم» گذاشته شد تا کاربر یک کلیک
     * زودتر به فایل برسد. کارفرما درست گفت که زشت است: در ستونی که همهٔ
     * آیتم‌هایش ردیفِ یک‌شکل‌اند، یک دکمهٔ اضافه ناهمگون درمی‌آید — و آیتمِ منویی
     * که به‌جای بازکردنِ صفحه ZIP می‌دهد رفتارِ غیرمنتظره‌ای دارد.
     *
     * این تست همان تصمیم را قفل می‌کند، و **حالتِ پورتالِ زنده** را می‌سنجد چون
     * فقط در همان حالت بود که دکمه رندر می‌شد؛ سنجیدنِ حالتِ خاموش، محافظِ
     * بی‌اثری می‌شد که از برگشتنِ دکمه خبر نمی‌داد.
     */
    public function test_the_menu_never_hands_out_a_file(): void
    {
        Cache::put('remote_release', (new RemoteRelease())->parse($this->portalHtml()), 60);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('tmega-sub', $html);
        $this->assertStringNotContainsString('servernet-remote-1.4.9-windows-x64.zip', $html,
            'منو نباید به فایلِ نصب لینک بدهد');
        $this->assertStringContainsString('/solutions/remote', $html,
            'خودِ ردیفِ ریموت باید بماند — فقط دکمهٔ دانلودش برداشته شد');

        // و فایل هنوز یک جای دیگر هست، وگرنه این حذف یعنی گم‌کردنِ راهِ دانلود.
        $this->assertStringContainsString('servernet-remote-1.4.9-windows-x64.zip',
            $this->get('/solutions/remote')->assertOk()->getContent());
    }

    // ═══════════════ ۳) قراردادها ═══════════════

    /** هر ردیفِ دانلود باید `platform` داشته باشد وگرنه هرگز به فایل وصل نمی‌شود. */
    public function test_every_download_row_declares_its_platform(): void
    {
        foreach (['fa', 'en', 'tr'] as $l) {
            $rows = config("solutions.remote.{$l}.downloads");
            $this->assertNotEmpty($rows);

            foreach ($rows as $d) {
                $this->assertContains($d['platform'] ?? null, RemoteRelease::PLATFORMS,
                    "ردیفِ «{$d['t']}» در {$l} کلیدِ platform معتبر ندارد");
            }
        }
    }

    public function test_the_new_strings_exist_in_all_three_languages(): void
    {
        foreach (['fa', 'en', 'tr'] as $l) {
            foreach (['sol_dl_ver', 'sol_dl_soon', 'nav_remote_dl'] as $k) {
                $v = __("ui.{$k}", [], $l);
                $this->assertNotSame("ui.{$k}", $v, "کلیدِ {$k} در {$l} نیست");
            }
        }
    }
}
