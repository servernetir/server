<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * هیچ لینکِ **دنبال‌شدنی** از صفحهٔ عمومی به `/account/*` نرود.
 *
 * 🔴 چرا: `robots.txt` کلِ `/account` را Disallow کرده، ولی دکمهٔ خریدِ هر پلن
 * یک نشانیِ یکتا می‌سازد (`?location=…&plan=…&cycle=…`). گوگل کشفشان می‌کند،
 * نمی‌تواند بخزدشان، و در صفِ «Blocked by robots.txt» می‌نشاندشان. در
 * Search Console (۱۰ نوامبر ۲۰۲۶) آن صف **۳٬۳۳۷** نشانی داشت — تقریباً
 * هم‌اندازهٔ ۳٬۱۴۷ صفحهٔ واقعی که هنوز «Discovered – currently not indexed»
 * بودند و اصلاً خزیده نشده بودند.
 *
 * ⚠️ خرابی‌اش کاملاً خاموش است: صفحه ۲۰۰ است، دکمه کار می‌کند، مشتری چیزی
 * نمی‌بیند. تنها نشانه‌اش عددی در گزارشی است که کسی هر روز باز نمی‌کند — و
 * `/cloud` و `/cloud/{location}` و `/vps/hourly` از قبل درست بودند، پس
 * «یکی‌شان را دیدم یعنی همه درست‌اند» هم جواب نمی‌داد.
 */
class AccountLinksAreNofollowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /** @return array<int,array{0:string,1:bool}> هر انکرِ `/account/` با اینکه nofollow دارد یا نه */
    private function accountAnchors(string $html): array
    {
        preg_match_all('~<a\b[^>]*>~i', $html, $m);

        $out = [];
        foreach ($m[0] as $tag) {
            if (! str_contains($tag, '/account/')) {
                continue;
            }
            $out[] = [$tag, (bool) preg_match('~\brel="[^"]*\bnofollow\b~i', $tag)];
        }

        return $out;
    }

    private function seedGermany(int $plans = 8): void
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'], [
            'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true,
        ]);

        for ($i = 1; $i <= $plans; $i++) {
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => 'ref'.$i,
                'provider_location' => 'fsn', 'location_code' => 'de-falkenstein',
                'public_name' => 'CV-'.$i, 'slug' => 'cv-'.$i.'c-4g-40d-de-falkenstein',
                'vcpu' => $i, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
                'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents' => 400 + $i, 'price_eur_cents' => 600 + $i,
                'price_irt' => 600000 + $i, 'is_active' => true, 'in_stock' => true,
            ]);
        }
    }

    /** 🔴 همان صفحه‌ای که GSC نمونه‌هایش را از آن گرفته بود */
    public function test_the_vps_country_page_nofollows_every_account_link(): void
    {
        $this->seedGermany();

        $anchors = $this->accountAnchors($this->get('/vps/germany')->assertOk()->getContent());

        $this->assertNotEmpty($anchors, 'صفحهٔ کشور باید دکمهٔ خرید داشته باشد — وگرنه این تست چیزی نمی‌سنجد');

        foreach ($anchors as [$tag, $hasNofollow]) {
            $this->assertTrue($hasNofollow, 'لینکِ دنبال‌شدنی به مسیرِ مسدودِ robots: '.$tag);
        }
    }

    /** هر سه زبان، چون قالب یکی است ولی رندرِ هر زبان جداست */
    public function test_every_locale_nofollows_too(): void
    {
        $this->seedGermany(3);

        foreach (['/vps/germany', '/en/vps/germany', '/tr/vps/germany'] as $url) {
            foreach ($this->accountAnchors($this->get($url)->assertOk()->getContent()) as [$tag, $ok]) {
                $this->assertTrue($ok, $url.' — '.$tag);
            }
        }
    }

    /**
     * ⚠️ ادعای منفی و مهم‌تر از بقیه: nofollowِ **کور** همین قالب، صفحاتِ
     * `/order/{sku}` را هم می‌بست — صفحاتی که ممیزی ۷ عمداً ایندکس‌پذیر و در
     * نقشهٔ سایت کرد. آن‌جا لینک باید دنبال‌شدنی بماند.
     */
    public function test_order_summary_links_stay_followable(): void
    {
        $html = $this->get('/hosting/linux')->assertOk()->getContent();

        preg_match_all('~<a\b[^>]*href="[^"]*/order/[^"]*"[^>]*>~i', $html, $m);

        $this->assertNotEmpty($m[0], 'صفحهٔ هاست باید به خلاصهٔ سفارش لینک بدهد');

        foreach ($m[0] as $tag) {
            $this->assertDoesNotMatchRegularExpression(
                '~\brel="[^"]*\bnofollow\b~i',
                $tag,
                'صفحهٔ سفارش ایندکس‌پذیر است و لینکِ داخلی‌اش نباید nofollow شود: '.$tag
            );
        }
    }
}
