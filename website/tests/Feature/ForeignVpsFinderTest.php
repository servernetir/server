<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * «کدام کشور برای سرور خارج؟» — /vps/compare-locations.
 *
 * قفل‌ها:
 *   ۱) سه زبان، حتی با کاتالوگِ خالی، بی‌کلیدِ خامِ config
 *   ۲) قیمت همان عددِ مدل است؛ ایران و GPU و کشورِ بی‌پلن نمایش داده نمی‌شوند
 *   ۳) 🔴 مرزِ /aup: صفحه و موضوعاتِ خوشهٔ مقالات هیچ ادعای VPN/فیلترشکن ندارند
 *   ۴) دادهٔ JS از @json می‌آید (بی‌موجودیتِ HTML در بلوکِ inline)
 *   ۵) راه‌های رسیدن: منو، نقشهٔ سایت، llms.txt
 */
class ForeignVpsFinderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function seedCatalogue(): void
    {
        foreach ([
            ['ir-tehran', 'IR', 'Tehran'], ['de-frankfurt', 'DE', 'Frankfurt'],
            ['nl-amsterdam', 'NL', 'Amsterdam'], ['fi-helsinki', 'FI', 'Helsinki'],
        ] as $i => [$code, $cc, $city]) {
            CloudLocation::create(['code' => $code, 'country' => $cc, 'city' => $city, 'is_active' => true, 'sort' => $i]);
        }

        $plan = fn (string $loc, string $slug, int $irt, int $eur) => CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => $slug, 'location_code' => $loc,
            'public_name' => 'CV-2-4', 'slug' => $slug,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cost_eur_cents' => 300, 'price_eur_cents' => $eur, 'price_irt' => $irt,
            'is_active' => true, 'in_stock' => true,
        ]);

        $plan('ir-tehran', 'cv-ir', 500000, 400);
        $plan('de-frankfurt', 'cv-de', 1440000, 570);
        $plan('nl-amsterdam', 'cv-nl', 1210000, 490);
        // فنلاند مکان دارد ولی پلنِ فروختنی ندارد ⇒ نباید نمایش داده شود
    }

    public function test_it_renders_in_three_languages_even_with_an_empty_catalogue(): void
    {
        foreach (['/vps/compare-locations', '/en/vps/compare-locations', '/tr/vps/compare-locations'] as $p) {
            $html = $this->get($p.'?qa=1')->assertOk()->getContent();
            $this->assertStringNotContainsString('foreign_vps.', $html);
            $this->assertSame(1, preg_match_all('~<h1[\s>]~', $html), "$p باید دقیقاً یک h1 داشته باشد");
        }

        $this->get('/vps/compare-locations?qa=1')->assertSee('کدام کشور برای کار شما بهتر است');
        $this->get('/tr/vps/compare-locations?qa=1')->assertSee('hangi ülke daha uygun');
    }

    public function test_live_prices_and_only_sellable_foreign_countries_are_listed(): void
    {
        $this->seedCatalogue();

        $html = $this->get('/vps/compare-locations?qa=1')->getContent();

        $this->assertStringContainsString(fa_num(number_format(1210000)).' تومان', $html);
        $this->assertStringContainsString('آلمان', $html);
        $this->assertStringContainsString('هلند', $html);
        $this->assertStringNotContainsString(fa_num(number_format(500000)), $html, 'ایران در صفحهٔ «خارج» نیست');

        preg_match('~<table class="vc-table">.*?</table>~s', $html, $t);
        $this->assertStringNotContainsString('فنلاند', $t[0] ?? '', 'کشورِ بی‌پلن نباید در جدول باشد');

        $en = $this->get('/en/vps/compare-locations?qa=1')->getContent();
        $this->assertStringContainsString('€4.90', $en, 'en قیمتِ یوروییِ خودِ پلن را نشان می‌دهد');
    }

    public function test_the_inline_script_gets_data_through_json_not_escaped_entities(): void
    {
        $this->seedCatalogue();

        $html = $this->get('/vps/compare-locations?qa=1')->getContent();
        $this->assertMatchesRegularExpression('~<script>\s*\(function \(\) \{\s*var D = \{~', $html);

        preg_match('~var D = (\{.*?\});\n~s', $html, $m);
        $this->assertNotEmpty($m, 'بلوکِ داده پیدا نشد');
        $this->assertStringNotContainsString('&quot;', $m[1]);
        $data = json_decode($m[1], true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data['rows']);
        $this->assertSame(['DE', 'NL'], collect($data['rows'])->pluck('iso')->sort()->values()->all());
    }

    public function test_no_circumvention_claims_on_the_page_or_in_the_article_cluster(): void
    {
        $this->seedCatalogue();

        $banned = '~(vpn|وی\s*پی\s*ان|فیلترشکن|فیلتر\s*شکن|proxy|پروکسی|v2ray|دور\s*زدن\s*فیلتر|تحریم\s*شکن|bypass|circumvent)~iu';

        foreach (['/vps/compare-locations', '/en/vps/compare-locations', '/tr/vps/compare-locations'] as $p) {
            $html = $this->get($p.'?qa=1')->getContent();
            preg_match('~<main[^>]*>(.*)</main>~s', $html, $main);
            $this->assertDoesNotMatchRegularExpression($banned, strip_tags($main[1] ?? $html), "$p");
        }

        $cfg = json_encode(config('foreign_vps'), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression($banned, preg_replace('~/aup~', '', $cfg));

        $plan = require base_path('resources/content/blog-1405.php');
        $cluster = collect($plan)->filter(fn ($r) => in_array($r['slug'], [
            'foreign-vps-location-guide', 'dev-environment-on-foreign-vps', 'first-hour-new-vps-security',
            'buying-foreign-server-with-rial', 'forex-vps-broker-proximity', 'windows-vps-remote-desktop-secure',
            'vps-traffic-bandwidth-explained', 'check-new-server-ip-reputation', 'cdn-for-site-hosted-abroad',
            'mtr-traceroute-network-path', 'dedicated-vs-shared-vcpu', 'migrate-site-to-foreign-vps',
        ], true));

        $this->assertCount(12, $cluster, 'هر دوازده موضوعِ خوشه باید در برنامه باشند');
        foreach ($cluster as $r) {
            $this->assertDoesNotMatchRegularExpression($banned, $r['fa'].' '.$r['keyword'].' '.$r['brief'], $r['slug']);
            $this->assertMatchesRegularExpression('~^2026-(09|10)-\d\d$~', (string) $r['date'], $r['slug']);
        }
    }

    public function test_every_use_case_weight_and_country_has_three_languages(): void
    {
        $uses = array_keys(config('foreign_vps.uses'));

        foreach (config('foreign_vps.countries') as $iso => $c) {
            $this->assertSame([], array_diff($uses, array_keys($c['uses'])), "$iso همهٔ کاربردها را وزن نداده");
            foreach (['fa', 'en', 'tr'] as $l) {
                $this->assertNotEmpty($c[$l] ?? null, "$iso/$l");
            }
        }

        foreach (['meta', 'ui', 'faq'] as $block) {
            $fa = config("foreign_vps.$block.fa");
            foreach (['en', 'tr'] as $l) {
                if ($block === 'faq') {
                    $this->assertNotEmpty(config("foreign_vps.faq.$l"));
                    continue;
                }
                $this->assertSame([], array_diff(array_keys($fa), array_keys(config("foreign_vps.$block.$l"))), "$block/$l");
            }
        }
    }

    public function test_it_is_reachable_from_menu_sitemap_and_llms(): void
    {
        $this->assertStringContainsString("'vps.compare'", file_get_contents(config_path('servernet.php')));
        $this->get('/sitemap.xml')->assertOk()->assertSee('/vps/compare-locations', false);
        $this->get('/llms.txt')->assertOk()->assertSee('/vps/compare-locations', false);
    }
}
