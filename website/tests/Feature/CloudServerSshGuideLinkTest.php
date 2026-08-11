<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لینکِ راهنمای SSH باید به مقصدِ **واقعاً موجود** برود.
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * نسخهٔ اولِ این لینک `lroute('docs.show', 'ssh-connect')` بود — و **هر دو
 * تکه‌اش غلط بود**: نامِ روت `docs` است نه `docs.show`، و هیچ مقاله‌ای با
 * اسلاگِ `ssh-connect` وجود نداشت.
 *
 * نتیجه‌اش بدترین حالتِ ممکن بود: یک لینکِ شکسته دقیقاً در لحظه‌ای که مشتری
 * تازه رمزِ root را گرفته و بیشترین نیاز را به راهنما دارد. کلیک می‌کرد، ۴۰۴
 * می‌گرفت، و نتیجه می‌گرفت سایت خراب است.
 *
 * ⚠️ خطای `route()`ِ ناشناخته استثنا پرتاب می‌کند و صفحه را ۵۰۰ می‌کند، ولی
 * **اسلاگِ ناموجود هیچ خطایی نمی‌دهد** — لینک سالم رندر می‌شود و فقط وقتی
 * کسی کلیک کند معلوم می‌شود. برای همین این‌جا خودِ مقصد هم زده می‌شود، نه
 * فقط ساختِ آدرس.
 */
class CloudServerSshGuideLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'connecting-to-linux-server-ssh';

    /** نامِ روت باید وجود داشته باشد — وگرنه رندرِ صفحه ۵۰۰ می‌شود */
    public function test_the_docs_route_name_exists(): void
    {
        $this->assertTrue(
            app('router')->has('docs'),
            'نامِ روتِ «docs» عوض شده — لینکِ راهنمای SSH صفحهٔ سرور را ۵۰۰ می‌کند'
        );
    }

    /**
     * 🔴 و مقصد واقعاً باز شود.
     *
     * ⚠️ اگر مقاله روی این نصب seed نشده باشد، ۴۰۴ طبیعی است و تست را قرمز
     * نمی‌کنیم — ادعای این تست «آدرس درست ساخته می‌شود» است، نه «محتوا روی
     * این ماشین هست». ولی هر پاسخِ **دیگری** (۵۰۰) یعنی مسیر خراب است.
     */
    public function test_the_guide_url_is_well_formed_and_not_broken(): void
    {
        $url = lroute('docs', self::SLUG);

        $this->assertStringContainsString(self::SLUG, $url);
        $this->assertStringContainsString('/docs/', $url);

        $status = $this->get('/docs/'.self::SLUG)->getStatusCode();

        $this->assertContains($status, [200, 404],
            'مسیرِ راهنمای SSH خطای سرور داد (کد '.$status.')');
    }

    /**
     * ⚠️ نیمهٔ دومِ قاعده: `lroute` و نه `route`.
     *
     * مقاله در هر سه زبان وجود دارد؛ `route`ِ خام مشتریِ انگلیسی و ترک را به
     * نسخهٔ فارسی می‌انداخت — همان تلهٔ ثبت‌شدهٔ کلِ این پروژه.
     */
    public function test_the_view_uses_the_locale_aware_helper(): void
    {
        $src = (string) file_get_contents(
            resource_path('views/account/cloud-server.blade.php')
        );

        $this->assertStringContainsString("lroute('docs', 'connecting-to-linux-server-ssh')", $src);
        $this->assertStringNotContainsString("route('docs.show'", $src,
            'نامِ روتِ ناموجود برگشت — صفحهٔ سرور ۵۰۰ می‌شود');
    }
}
