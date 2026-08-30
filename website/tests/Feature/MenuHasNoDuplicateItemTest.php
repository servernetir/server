<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هیچ آیتمی دو بار در یک تبِ منو نیاید.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * «سرور مجازی ایران» در تبِ سرور **دو بار** بود: یک بار در گروهِ «سرور مجازی»
 * (از config) و یک بار در «موقعیت مکانی» (که `SiteMenu` با کشورهای زندهٔ
 * کاتالوگ پر می‌کند). حذفِ تکراری فقط داخلِ همان گروه انجام می‌شد، پس ایران —
 * که در گروهِ دیگری بود — دیده نمی‌شد و دوباره اضافه می‌گشت.
 *
 * 🔴 هیچ‌چیز خراب نبود: هر دو لینک به `/vps/iran` می‌رفتند و صفحه ۲۰۰ بود.
 * برای همین ماه‌ها ماند و فقط چشمِ کارفرما گرفتش. تستِ «۲۰۰ می‌دهد؟» چنین
 * چیزی را هرگز نمی‌گیرد؛ ادعا باید «یکتا بودن» باشد.
 */
class MenuHasNoDuplicateItemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🔴 ایران باید **فروشِ زنده** داشته باشد وگرنه این تست تو‌خالی است.
     *
     * گروهِ «موقعیت مکانی» را `SiteMenu` از کشورهایی پر می‌کند که پلنِ
     * قابلِ فروش دارند. در محیطِ تستِ خالی هیچ کشوری زنده نیست، پس آن شاخه
     * اصلاً اجرا نمی‌شود و تکراری هرگز ساخته نمی‌شود — نسخهٔ اولِ این تست
     * با برگرداندنِ خودِ باگ هم سبز ماند و آزمونِ جهش گرفتش.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Cache::flush();

        \App\Models\CloudLocation::create([
            'code' => 'ir-thr', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true,
        ]);

        \App\Models\CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-ir',
            'location_code' => 'ir-thr', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-ir',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    /**
     * برچسب‌هایی که در یک تب دو بار به **همان** مقصد می‌روند.
     *
     * ⚠️ عمداً برچسب **و** مقصد با هم کلید می‌شوند، نه فقط مقصد.
     *
     * دو برچسبِ متفاوت که به یک صفحه می‌روند اشکال ندارد و در همین منو عمدی
     * است: «سرور مجازی اشتراکی» و «همهٔ سرورهای مجازی» هر دو به `/cloud`
     * می‌روند — یکی محصول است و دیگری لینکِ فراگیر. نسخهٔ اولِ این تست فقط
     * مقصد را می‌سنجید و همان را هم تکراری می‌خواند، یعنی نگهبانی می‌شد که
     * سرِ یک تصمیمِ درست هشدار می‌دهد.
     */
    private function duplicatesIn(array $section): array
    {
        $seen = [];

        foreach ((array) ($section['groups'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $it) {
                $target = $it['slug'] ?? json_encode($it['route'] ?? null);

                if ($target === null || $target === 'null') {
                    continue;
                }

                $key = ($it['fa'] ?? '').' → '.$target;
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($seen, fn ($n) => $n > 1));
    }

    /** 🔴 تبِ سرور — همان‌جا که ایران دو بار بود. */
    public function test_the_server_tab_lists_each_item_once(): void
    {
        $dupes = $this->duplicatesIn(app(\App\Services\SiteMenu::class)->mega()['vps'] ?? []);

        $this->assertSame([], $dupes, "آیتمِ تکراری در تبِ سرور:\n".implode("\n", $dupes));
    }

    /** و همین قاعده برای هر تبِ دیگر. */
    public function test_no_tab_repeats_an_item(): void
    {
        $bad = [];

        foreach (app(\App\Services\SiteMenu::class)->mega() as $tab => $section) {
            foreach ($this->duplicatesIn((array) $section) as $d) {
                $bad[] = "{$tab}: {$d}";
            }
        }

        $this->assertSame([], $bad, "آیتمِ تکراری:\n".implode("\n", $bad));
    }

    /**
     * ⚠️ و روی خودِ HTML — تنها چیزی که کاربر واقعاً می‌بیند.
     *
     * منو دو بار رندر می‌شود (دسکتاپِ `mega-group` و موبایلِ `acc-in`)، پس هر
     * آیتمِ سالم **دقیقاً دو بار** می‌آید. سه بار یعنی همان تکراریِ واقعی.
     *
     * 🔴 شمارش عمداً به ظرفِ منو محدود است. نسخهٔ اول کلِ صفحه را می‌شمرد و
     * لینکِ مشروعِ کارتِ محصول و فوتر را هم تکراری می‌خواند — مثبتِ کاذبی که
     * نگهبان را بی‌اعتبار می‌کرد.
     */
    public function test_the_rendered_menu_shows_each_link_at_most_twice(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // فقط لینک‌هایی که داخلِ ظرفِ منو هستند
        preg_match_all('~class="[^"]*(?:mega-group|acc-in)[^"]*"[\s\S]*?(?=class="[^"]*(?:mega-group|acc-in)|</nav>)~i', $html, $blocks);

        $counts = [];
        foreach ($blocks[0] as $block) {
            preg_match_all('~href="[^"]*?(/vps/[a-z0-9-]+)"~i', $block, $m);
            foreach ($m[1] as $href) {
                $counts[$href] = ($counts[$href] ?? 0) + 1;
            }
        }

        $this->assertNotEmpty($counts, 'هیچ لینکِ منویی پیدا نشد — الگو کهنه شده');

        $tooMany = array_keys(array_filter($counts, fn ($n) => $n > 2));

        $this->assertSame([], $tooMany,
            'این مقصدها بیش از دو بار در منو آمده‌اند: '.implode('، ', $tooMany));
    }
}
