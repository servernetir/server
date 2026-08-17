<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منوی مدیریت باید بر اساسِ **محصول** گروه‌بندی باشد، نه تاریخِ ساخت.
 *
 * تا امروز همهٔ آیتم‌ها پشتِ سرِ هم بودند به همان ترتیبی که ساخته شده بودند.
 * «پکیج‌های فروش» (که پکیجِ هاست است) کنارِ «سرورِ فیزیکی» می‌نشست و
 * «سرورهای تحویل» (کنترل‌پنل‌های هاست) کنارِ «زیرساختِ ابری» — مدیر باید هر بار
 * کلِ فهرست را می‌خواند تا چیزی را پیدا کند.
 *
 * ⚠️ این تست ترتیبِ **دقیق** را قفل نمی‌کند (که هر جابه‌جاییِ بی‌ضرر قرمز شود)،
 * بلکه فقط عضویت را می‌سنجد: هر آیتم زیرِ سرگروهِ درستِ خودش باشد. قفلِ
 * زیادی‌سخت، بازچینیِ بعدی را به یک تستِ شکسته تبدیل می‌کند و کسی دیگر جدی‌اش
 * نمی‌گیرد.
 */
class AdminMenuGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function nav(): string
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'nv'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);

        $html = $this->actingAs($admin)->get('/admin/customers')->assertOk()->getContent();

        preg_match('~<nav\b[^>]*>(.*?)</nav>~is', $html, $m);

        return $m[1] ?? $html;
    }

    /** موقعیتِ یک رشته در منو — برای سنجشِ «زیرِ کدام سرگروه است» */
    private function pos(string $nav, string $needle): int
    {
        $p = mb_strpos($nav, $needle);

        $this->assertNotFalse($p, "«{$needle}» در منو پیدا نشد");

        return (int) $p;
    }

    /** سرگروه‌های محصول باید وجود داشته باشند */
    public function test_the_product_groups_exist(): void
    {
        $nav = $this->nav();

        foreach (['کسب‌وکار', 'هاست', 'سرور', 'دامنه', 'مالی', 'سیستم'] as $g) {
            $this->assertStringContainsString('>'.$g.'</div>', $nav, "سرگروهِ «{$g}» نیست");
        }
    }

    /**
     * 🔴 پکیجِ فروش و کنترل‌پنل‌ها زیرِ «هاست» — نه وسطِ سرور و دامنه.
     *
     * هر دو مالِ هاستینگ‌اند: `products` پکیجِ WHM است و `servers` همان
     * کنترل‌پنل‌هایی که روی آن‌ها ساخته می‌شود.
     */
    public function test_hosting_items_sit_under_the_hosting_group(): void
    {
        $nav = $this->nav();

        $hosting = $this->pos($nav, '>هاست</div>');
        $server  = $this->pos($nav, '>سرور</div>');

        foreach (['/admin/servers', '/admin/products'] as $href) {
            $at = $this->pos($nav, $href);

            $this->assertGreaterThan($hosting, $at, "«{$href}» بالاتر از سرگروهِ هاست است");
            $this->assertLessThan($server, $at, "«{$href}» از گروهِ هاست بیرون افتاده");
        }
    }

    /** ابری و فیزیکی هر دو «سرور»اند — یکی مجازی، یکی سخت‌افزار */
    public function test_server_items_sit_under_the_server_group(): void
    {
        $nav = $this->nav();

        $server = $this->pos($nav, '>سرور</div>');
        $domain = $this->pos($nav, '>دامنه</div>');

        foreach (['/admin/cloud', '/admin/server-shop'] as $href) {
            $at = $this->pos($nav, $href);

            $this->assertGreaterThan($server, $at, "«{$href}» بالاتر از سرگروهِ سرور است");
            $this->assertLessThan($domain, $at, "«{$href}» از گروهِ سرور بیرون افتاده");
        }
    }

    /**
     * ⚠️ نیمهٔ دومِ هر بازچینیِ منو: **هیچ صفحه‌ای از دسترس خارج نشود.**
     *
     * جابه‌جاییِ آیتم‌ها وسوسه‌انگیزترین جا برای گم‌کردنِ یک لینک است — و صفحهٔ
     * بی‌لینک عملاً وجود ندارد، بی‌آنکه هیچ خطایی بدهد.
     */
    public function test_no_admin_page_lost_its_link(): void
    {
        $nav = $this->nav();

        foreach ([
            '/admin/calendar', '/admin/customers',
            '/admin/tickets', '/admin/broadcasts', '/admin/servers',
            '/admin/products', '/admin/cloud', '/admin/server-shop',
            '/admin/exit-infra', '/admin/domains', '/admin/finance',
            '/admin/transactions', '/admin/bank-transfers', '/admin/errors',
            '/admin/status', '/admin/settings', '/admin/users',
        ] as $href) {
            $this->assertStringContainsString($href, $nav, "لینکِ «{$href}» از منو گم شد");
        }
    }

    /**
     * 🔴 چهار صفحه از منو **برداشته** شدند و داخلِ تنظیمات رفتند — پس قاعدهٔ
     * «هیچ صفحه‌ای از دسترس خارج نشود» برایشان شکلِ دیگری دارد.
     *
     * ⚠️ صرفِ نبودنشان در منو کافی نیست که تست را رها کنیم: اگر روزی تبِ
     * مقصد هم عوض شود یا آن بخش از تب بیفتد، صفحه واقعاً ناپدید می‌شود و
     * هیچ‌جا خطایی نمی‌دهد. پس ادعا به **مقصدِ تازه** منتقل شد، نه حذف.
     */
    public function test_the_merged_pages_are_reachable_inside_settings(): void
    {
        $nav = $this->nav();

        foreach ([
            '/admin/payment-accounts' => 'accounts',
            '/admin/crypto-wallets'   => 'accounts',
            '/admin/costs'            => 'costs',
            '/admin/templates'        => 'messages',
        ] as $old => $tab) {
            $this->assertStringNotContainsString('href="'.$old.'"', $nav,
                "«{$old}» داخلِ تنظیمات رفته و نباید در منو تکرار شود");

            // مسیرِ قدیمی هنوز زنده است و به تبِ درست می‌رود (بوکمارک نمی‌شکند)
            $admin = User::create([
                'name' => 'مدیر', 'email' => 'mv'.random_int(1000, 9999).'@example.test',
                'password' => bcrypt('secret-for-test'), 'role' => 'admin',
            ]);
            $this->actingAs($admin)->get($old)->assertRedirect('/admin/settings?tab='.$tab);
        }
    }

    /**
     * 🔴 «احراز هویت» هم از منو برداشته شد — پس مثلِ چهار صفحهٔ بالا، ادعایش
     * به **مقصدِ تازه** منتقل می‌شود، نه اینکه حذف شود.
     *
     * ═══ چرا حذفِ ساده غلط بود ═══
     *
     * برداشتنِ خطِ `/admin/verifications` از فهرستِ بالا، تست را سبز می‌کرد و
     * دقیقاً همان چیزی را از دست می‌داد که آن فهرست برای نگه‌داشتنش هست: اگر
     * فردا کسی دکمه را از صفحهٔ مشتریان هم بردارد، آن صفحه بی‌هیچ خطایی
     * ناپدید می‌شود و هیچ‌کس خبردار نمی‌شود.
     *
     * ⚠️ ادعا **دو نیمه** دارد و هر دو لازم است: راهِ رسیدن هست، و شمارشِ
     * در انتظار هم رویش دیده می‌شود. دکمهٔ بی‌شمارش یعنی صفِ پر و پنلِ ساکت.
     */
    public function test_verifications_moved_out_of_the_nav_but_stayed_reachable(): void
    {
        $this->assertStringNotContainsString('/admin/verifications', $this->nav(),
            '«احراز هویت» زیرمجموعهٔ مشتریان است و نباید در نوارِ کناری تکرار شود');

        $admin = User::create([
            'name' => 'مدیر', 'email' => 'vf'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);

        $html = (string) $this->actingAs($admin)->get('/admin/customers')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/verifications', $html,
            'راهِ رسیدن به صفِ احراز هویت از فهرستِ مشتریان قطع شد');

        // و خودِ صفحه هنوز زنده است (بوکمارکِ قدیمی نمی‌شکند)
        $this->actingAs($admin)->get('/admin/verifications')->assertOk();
    }
}
