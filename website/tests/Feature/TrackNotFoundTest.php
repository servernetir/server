<?php

namespace Tests\Feature;

use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فیلترِ ۴۰۴ — چه چیزی ثبت شود و چه چیزی نه.
 *
 * 🔴 چرا مهم است: روی سرورِ زنده ۱۴۴ ردیفِ ۴۰۴ ثبت شده بود و تقریباً همه‌شان
 * اسکنرِ وب‌شل بودند (`xxx.php`، `w3lls.php`، `sql.php`، …). آن سیل، لاگ را چنان
 * پر می‌کرد که ۴۰۴های واقعی — یعنی لینک‌های خرابی که باید درست شوند — گم می‌شدند.
 *
 * قاعدهٔ کلیدی: این سایت لاراول است و **هیچ روتِ `.php` ندارد**، پس هر ۴۰۴ی که
 * به `.php` ختم شود بی‌استثنا رباتی است.
 */
class TrackNotFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ErrorTracker::clear();
    }

    protected function tearDown(): void
    {
        ErrorTracker::clear();
        parent::tearDown();
    }

    private function urls(): array
    {
        return array_column(ErrorTracker::recent(200, 'notfound'), 'url');
    }

    /** 🔴 اسکنرِ وب‌شل نباید ثبت شود */
    public function test_php_shell_probes_are_not_recorded(): void
    {
        foreach ([
            '/xxx.php', '/w3lls.php', '/sql.php', '/wp-omao.php', '/manager.phP',
            '/index2.php', '/epinyins.php?p=', '/modules/mod_simplefileuploadv1.3/elements/filemanager.php',
        ] as $p) {
            $this->get($p)->assertNotFound();
        }

        $this->assertSame([], $this->urls(), 'هیچ‌کدام از این‌ها لینکِ خرابِ واقعی نیستند');
    }

    public function test_other_script_extensions_are_filtered_too(): void
    {
        foreach (['/shell.asp', '/x.aspx', '/a.jsp', '/b.cgi', '/c.pl', '/d.phtml'] as $p) {
            $this->get($p)->assertNotFound();
        }

        $this->assertSame([], $this->urls());
    }

    /** بازماندهٔ نقشهٔ سایتِ وردپرسیِ دامنهٔ قدیمی — ربات است، نه لینکِ ما */
    public function test_wordpress_sitemaps_are_filtered(): void
    {
        $this->get('/wp-sitemap-users-1.xml')->assertNotFound();
        $this->get('/wp-sitemap-posts-liquid-header-1.xml')->assertNotFound();

        $this->assertSame([], $this->urls());
    }

    /**
     * 🔴 `wp-json` و اسکنرهای سرویسِ ویندوزی — بیشترین نویزِ لاگِ زنده.
     *
     * فهرست `wp-login` و `wp-admin` را داشت ولی **`wp-json` را نه**، و همان یک
     * قلم بیشترین ردیف‌های تازه را می‌ساخت. کارفرما ۱۵۰ ردیف می‌دید و فرض
     * می‌کرد ۱۵۰ باگ دارد؛ در واقع ۸ خطای واقعی بود زیرِ کوهی از کاوشِ ربات.
     *
     * ⚠️ ردیابی که نویزش از سیگنالش بیشتر باشد، خوانده نمی‌شود — و آن‌وقت
     * همان ۸ خطای واقعی هم دیده نمی‌شوند. فیلتر، بخشی از کارکردِ ابزار است نه
     * آرایشِ آن.
     */
    public function test_wp_json_and_service_scanners_are_filtered(): void
    {
        foreach (['/wp-json/batch/v1', '/wp-json/wp/v2/users',
            '/RDWeb/Pages/en-US', '/owa/auth/logon.aspx', '/_ignition/execute-solution',
            '/telescope/requests', '/server-status'] as $p) {
            $this->get($p)->assertNotFound();
        }

        $this->assertSame([], $this->urls(),
            'کاوشِ ربات نباید ثبت شود — وگرنه خطای واقعی زیرش گم می‌شود');
    }

    public function test_the_classic_probe_list_still_works(): void
    {
        foreach (['/.env', '/.git/config', '/phpmyadmin', '/actuator/health', '/minishell'] as $p) {
            $this->get($p)->assertNotFound();
        }

        $this->assertSame([], $this->urls());
    }

    /**
     * 🔴 مهم‌ترین ادعا: ۴۰۴ِ **واقعی** باید ثبت شود.
     *
     * فیلترِ بیش‌ازحد پهن یعنی لینکِ خرابِ واقعی هم گم می‌شود — و آن‌وقت این
     * ابزار هیچ فایده‌ای ندارد. این همان مسیرهایی است که در لاگِ زنده دیده شد
     * و واقعاً باید بررسی شوند.
     */
    public function test_real_broken_links_are_still_recorded(): void
    {
        $this->get('/marketing')->assertNotFound();
        $this->get('/en/blog/kubernetes-for-beginners/comment')->assertNotFound();

        $urls = $this->urls();

        $this->assertCount(2, $urls);
        $this->assertStringContainsString('/marketing', implode(' ', $urls));
        $this->assertStringContainsString('kubernetes', implode(' ', $urls));
    }

    /** ⚠️ فایلِ ۴۰۴ جداست — نباید در سطلِ خطاهای واقعی بیفتد */
    public function test_404s_never_pollute_the_error_bucket(): void
    {
        $this->get('/marketing')->assertNotFound();

        $this->assertSame([], ErrorTracker::recent(50, 'error'));
    }
}
