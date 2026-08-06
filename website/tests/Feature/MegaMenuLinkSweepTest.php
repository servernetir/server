<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * جاروی **کلِ** منوی اصلی — هر تب، هر گروه، هر آیتم، در هر سه زبان.
 *
 * ═══ چرا ═══
 *
 * لینکِ مرده در منو بدترین نوعِ ایراد است: مشتری می‌بیندش، رویش کلیک می‌کند و
 * به بن‌بست می‌خورد — و ما هیچ خطایی در لاگ نمی‌بینیم چون ۴۰۴ خطا نیست.
 * کارفرما همین را چند بار گزارش کرد («لینک‌های ایراد دار»، «منو دامنه درست
 * نیست»).
 *
 * ⚠️ این تست از **خودِ منو** شروع می‌کند، نه از فهرستِ دستیِ URLها. پس هر آیتمِ
 * تازه‌ای که فردا به `config/servernet.mega` اضافه شود خودکار پوشش می‌گیرد، و
 * فهرستِ تست هرگز کهنه نمی‌شود.
 */
class MegaMenuLinkSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * هر لینکِ منو، با برچسب و مسیرش.
     *
     * ساختارِ مگامنو دو شکلِ آیتم دارد و هر دو باید دیده شوند:
     *   `route` → [نامِ روت، پارامترها]
     *   `slug`  → صفحهٔ کاتالوگِ همان تب
     *
     * @return array<int,array{label:string,url:string}>
     */
    private function links(string $locale): array
    {
        app()->setLocale($locale);

        $mega = app(\App\Services\SiteMenu::class)->mega();
        $out = [];

        foreach ($mega as $tabKey => $tab) {
            if (! is_array($tab)) {
                continue;
            }

            foreach ($tab['groups'] ?? [] as $group) {
                foreach ($group['items'] ?? [] as $it) {
                    $url = $this->urlFor($it, (string) $tabKey);

                    if ($url !== null) {
                        $out[] = ['label' => (string) ($it[$locale] ?? $it['fa'] ?? '?'), 'url' => $url];
                    }
                }
            }
        }

        return $out;
    }

    private function urlFor(array $item, string $tabKey): ?string
    {
        if (isset($item['route'])) {
            $route = (array) $item['route'];

            return lroute($route[0], $route[1] ?? []);
        }

        if (isset($item['href'])) {
            $href = (string) $item['href'];

            // لینکِ بیرونی را نمی‌سنجیم؛ در دستِ ما نیست
            return str_starts_with($href, 'http') ? null : $href;
        }

        if (isset($item['slug'])) {
            $category = $tabKey === 'vps' ? 'vps' : $tabKey;

            return lroute('catalog', ['category' => $category, 'slug' => $item['slug']]);
        }

        return null;
    }

    // ═══════════════ جارو ═══════════════

    /** 🔴 هیچ آیتمِ منو نباید به بن‌بست برسد */
    public function test_every_menu_item_opens_in_every_language(): void
    {
        $broken = [];
        $checked = 0;

        foreach (['fa', 'en', 'tr'] as $loc) {
            foreach ($this->links($loc) as $link) {
                $checked++;
                $status = $this->get($link['url'])->getStatusCode();

                if ($status !== 200) {
                    $broken[] = "[$loc] {$link['label']} → {$link['url']} = $status";
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'منو تقریباً خالی است — جارو بی‌معنا می‌شود');
        $this->assertSame([], $broken, "\nلینکِ شکسته در منو:\n".implode("\n", $broken));
    }

    /**
     * گروهِ دامنه باید به سامانهٔ **خودمان** برود.
     *
     * ⚠️ این آیتم‌ها قبلاً آزمایشی به WHMCSِ بیرونی وصل بودند. مشتری از منو
     * بیرونِ کنسول می‌رفت و خرید آن‌جا تمام می‌شد — یعنی نه فاکتورِ ما، نه
     * تحویلِ خودکارِ ما، نه تاریخچه‌ای در پنلِ مشتری.
     */
    public function test_the_domain_menu_points_at_our_own_system(): void
    {
        $offsite = [];

        foreach ($this->links('fa') as $link) {
            if (! str_contains($link['url'], 'domain')) {
                continue;
            }

            foreach (['cart.php', 'domainchecker.php', 'clientarea.php', 'my.servernet.ir'] as $needle) {
                if (str_contains($link['url'], $needle)) {
                    $offsite[] = "{$link['label']} → {$link['url']}";
                }
            }
        }

        $this->assertSame([], $offsite,
            "\nلینکِ دامنه به سامانهٔ بیرونی می‌رود:\n".implode("\n", $offsite));
    }

    /** صفحهٔ جستجوی دامنه باید واقعاً در منو باشد، وگرنه کسی پیدایش نمی‌کند */
    public function test_the_domain_search_page_is_reachable_from_the_menu(): void
    {
        $urls = array_column($this->links('fa'), 'url');

        $this->assertContains(lroute('domain.search'), $urls,
            'جستجوی دامنه در منو نیست — اصلی‌ترین صفحهٔ فروشِ دامنه پنهان می‌مانَد');
    }
}
