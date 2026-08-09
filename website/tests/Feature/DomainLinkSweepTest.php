<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══ هر لینکِ دامنه، در هر سه زبان، باید باز شود ═══
 *
 * کارفرما خواست کلِ سفرِ دامنه یکدست باشد: منو، صفحهٔ جستجو، لینک‌های زیرِ
 * همان بخش، و پنل. این فایل همان سفر را می‌پیماید و دو چیز را می‌سنجد:
 *
 *   ۱) لینک **باز می‌شود** (نه ۴۰۴، نه ۵۰۰)
 *   ۲) لینک **پیشوندِ زبانِ خودش** را دارد
 *
 * ادعای دوم گران‌تر است و تا امروز واقعاً نقض می‌شد: `account/domains.blade.php`
 * و `account/domain-show.blade.php` هفت‌بار `route()` می‌زدند به‌جای `lroute()`.
 * روت‌های account داخلِ closureِ `$site`اند، پس `/en/account/…` وجود دارد؛
 * `route()`ِ خام مشتریِ انگلیسی را وسطِ مدیریتِ دامنه‌اش به آدرسِ فارسی پرتاب
 * می‌کرد — و چهار موردش **action فرم** بود (نام‌سرور، قفل، کد انتقال، تمدید
 * خودکار)، یعنی خودِ عملیات هم زبان را عوض می‌کرد.
 *
 * 🔴 هیچ تماسِ واقعی با رجیسترار: `Http::swap` + یک `Http::fake()`.
 */
class DomainLinkSweepTest extends TestCase
{
    use RefreshDatabase;

    /** پیشوندِ URL برای هر زبان */
    private const LOCALES = ['fa' => '', 'en' => '/en', 'tr' => '/tr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingFoundationSeeder::class);

        // هیچ صفحه‌ای در این تست نباید به رجیسترار برسد؛ ولی اگر رسید،
        // باید به یک استابِ بی‌ضرر بخورد نه به شبکه.
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['code' => 196, 'desc' => 'blocked in tests'], 500)]);
    }

    // ═══════════════════════ ابزار ═══════════════════════

    /** همهٔ hrefهای یک صفحه */
    private function hrefs(string $html): array
    {
        preg_match_all('~href="([^"]+)"~', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * لینک‌هایی که به سفرِ دامنه مربوط‌اند.
     *
     * ⚠️ لنگر (`#…`)، `mailto:`/`tel:`، و ارجاعِ SVG (`#i-…`) لینکِ ناوبری
     * نیستند و باید بیرون بمانند، وگرنه تست به‌دلیلِ غلط قرمز می‌شود.
     */
    private function domainLinks(string $html): array
    {
        return array_values(array_filter($this->hrefs($html), function (string $h) {
            if ($h === '' || $h[0] === '#' || str_starts_with($h, 'mailto:') || str_starts_with($h, 'tel:')) {
                return false;
            }

            return str_contains($h, '/domain');   // /domains, /domain/…, /account/domains…
        }));
    }

    /** مسیرِ نسبیِ یک href (میزبانِ کنسول را هم می‌پذیرد) */
    private function pathOf(string $href): string
    {
        return (string) (parse_url($href, PHP_URL_PATH) ?: '/');
    }

    /**
     * سه نسخهٔ زبانیِ **خودِ همین صفحه**.
     *
     * ⚠️ اینها عمداً از ادعای «پیشوند» مستثنا می‌شوند: تگ‌های
     * `<link rel="alternate" hreflang>` و کلیدِ تعویضِ زبانِ هدر باید دقیقاً به
     * زبانِ **دیگر** اشاره کنند. بی این استثنا، تست هر صفحه‌ای را قرمز می‌کرد و
     * برای درست‌کردنش باید hreflang را می‌شکستیم — یعنی تستی که سئو را خراب
     * می‌کند تا خودش سبز شود.
     *
     * @return array<int,string>
     */
    private function alternatesOf(string $page): array
    {
        $bare = preg_replace('~^/(en|tr)(?=/|$)~', '', $page) ?: '/';

        return array_map(fn ($p) => rtrim($p.$bare, '/') ?: '/', ['', '/en', '/tr']);
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'l'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'ا', 'last_name' => 'ا',
        ]);

        return $c;
    }

    /**
     * ادعای پیشوند: مسیر باید دقیقاً با پیشوندِ زبانِ جاری شروع شود، و برای
     * فارسی **نباید** پیشوندِ زبانِ دیگری داشته باشد.
     */
    private function assertLocalePrefix(string $path, string $prefix, string $where): void
    {
        if ($prefix !== '') {
            // خودِ ریشهٔ زبان (`/en`) هم معتبر است — لینکِ «خانه»
            $this->assertTrue($path === $prefix || str_starts_with($path, $prefix.'/'),
                "{$where}: لینکِ «{$path}» پیشوندِ «{$prefix}» ندارد — route() به‌جای lroute()");

            return;
        }

        $this->assertDoesNotMatchRegularExpression('~^/(en|tr)/~', $path,
            "{$where}: لینکِ فارسی «{$path}» پیشوندِ زبانِ دیگری دارد");
    }

    // ═══════════════════════ ۱) منو و صفحهٔ جستجو ═══════════════════════

    /**
     * مگامنو + هدر + فوتر روی صفحهٔ اصلی، و صفحهٔ `/domains` — هر سه زبان.
     */
    public function test_every_domain_link_on_the_public_site_opens_in_every_language(): void
    {
        foreach (self::LOCALES as $loc => $prefix) {
            foreach ([$prefix ?: '/', $prefix.'/domains'] as $page) {
                $html = $this->get($page)->assertOk()->getContent();
                $links = $this->domainLinks($html);
                $alt = $this->alternatesOf($page);

                $this->assertNotEmpty($links, "صفحهٔ {$page} هیچ لینکِ دامنه‌ای ندارد");

                foreach ($links as $href) {
                    $path = $this->pathOf($href);

                    if (in_array(rtrim($path, '/') ?: '/', $alt, true)) {
                        continue;   // hreflang و کلیدِ تعویضِ زبان
                    }

                    $this->assertLocalePrefix($path, $prefix, "{$loc} · {$page}");

                    // مسیرهای پنل احراز هویت می‌خواهند؛ فقط پیشوندشان سنجیده شد
                    if (str_contains($path, '/account/')) {
                        continue;
                    }

                    $status = $this->get($path)->getStatusCode();
                    $this->assertContains($status, [200, 301, 302],
                        "{$loc} · {$page}: لینکِ «{$path}» وضعیتِ {$status} داد");
                }
            }
        }
    }

    /**
     * 🔴 صفحهٔ `/domains` تا امروز **هیچ لینکِ خروجی نداشت**: نه فهرستِ
     * پسوندها، نه ‎.ir، نه پرمیوم، نه نمایندگی. کسی که نامش را پیدا نمی‌کرد به
     * بن‌بست می‌خورد، در حالی که همهٔ آن صفحات وجود داشتند.
     */
    public function test_the_search_page_offers_the_rest_of_the_domain_catalogue(): void
    {
        foreach (self::LOCALES as $loc => $prefix) {
            $html = $this->get($prefix.'/domains')->assertOk()->getContent();

            foreach (['popular-tlds', 'ir', 'persian', 'premium', 'reseller', 'backorder'] as $slug) {
                $this->assertStringContainsString($prefix.'/domain/'.$slug, $html,
                    "{$loc}: لینکِ «{$slug}» زیرِ کادرِ جستجو نیست");
            }
        }
    }

    // ═══════════════════════ ۲) پنلِ مشتری ═══════════════════════

    /**
     * صفحهٔ دامنه‌های پنل و صفحهٔ مدیریتِ یک دامنه، در هر سه زبان.
     *
     * ⚠️ اینجا `action` فرم‌ها هم سنجیده می‌شود، نه فقط `href`: چهار عملیاتِ
     * مدیریتیِ دامنه POST می‌شوند و آن‌ها بودند که زبان را می‌شکستند.
     */
    public function test_every_domain_link_and_form_in_the_console_keeps_the_language(): void
    {
        $c = $this->customer();

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'zhina.com', 'sld' => 'zhina', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'active', 'provision_status' => 'done',
            'period_years' => 1, 'price_toman' => 1000000, 'renew_toman' => 1000000,
            'op_id' => 12345, 'expires_at' => now()->addYear(), 'registered_at' => now(),
        ]);

        foreach (self::LOCALES as $loc => $prefix) {
            foreach ([$prefix.'/account/domains', $prefix.'/account/domains/'.$d->id] as $page) {
                $html = $this->actingAs($c, 'customer')->get($page)->assertOk()->getContent();

                preg_match_all('~(?:href|action)="([^"]+)"~', $html, $m);

                $checked = 0;
                $alt = $this->alternatesOf($page);

                foreach (array_unique($m[1]) as $href) {
                    if ($href === '' || $href[0] === '#'
                        || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                        continue;
                    }

                    /*
                    | ⚠️ میزبانِ بیرونی رد می‌شود، وگرنه لینکدینِ فوتر
                    | (`linkedin.com/company/servernet-co/`) به‌عنوانِ «لینکِ
                    | داخلیِ بی‌پیشوند» قرمز می‌شد — `parse_url` برای آدرسِ
                    | مطلق هم مسیری که با `/` شروع می‌شود برمی‌گرداند.
                    */
                    $host = parse_url($href, PHP_URL_HOST);
                    $ours = [null, parse_url(url('/'), PHP_URL_HOST), 'console.servernet.cloud'];

                    if (! in_array($host, $ours, true)) {
                        continue;
                    }

                    $path = $this->pathOf($href);

                    // فقط مسیرهای داخلیِ خودمان؛ دارایی‌های ایستا زبان ندارند
                    if (! str_starts_with($path, '/') || str_contains($path, '/assets/')
                        || preg_match('~\.(svg|png|ico|css|js|webmanifest|xml|txt)$~i', $path)) {
                        continue;
                    }

                    if (in_array(rtrim($path, '/') ?: '/', $alt, true)) {
                        continue;   // hreflang و کلیدِ تعویضِ زبان
                    }

                    $this->assertLocalePrefix($path, $prefix, "{$loc} · {$page}");
                    $checked++;
                }

                $this->assertGreaterThan(0, $checked, "{$page} هیچ لینکِ داخلی‌ای ندارد");
            }
        }
    }

    /**
     * دکمهٔ «ثبت» صفحهٔ عمومی باید مشتری را به همان زبانِ خودش به کنسول
     * ببرد و نامِ دامنه را همراه ببرد — وگرنه او دوباره باید تایپ کند.
     */
    public function test_the_buy_button_target_keeps_the_language_and_the_domain_name(): void
    {
        foreach (self::LOCALES as $loc => $prefix) {
            $html = $this->get($prefix.'/domains')->assertOk()->getContent();

            /*
            | آدرس داخلِ بلوکِ `@json` است، پس اسلش‌ها escape می‌شوند (`\/`).
            |
            | ⚠️ آدرسِ **کامل** سنجیده می‌شود نه پسوندِ مسیر: رشتهٔ
            | `\/account\/domains"` زیررشتهٔ `\/en\/account\/domains"` هم هست،
            | پس ادعای فارسی برای انگلیسی هم سبز می‌شد و تست هیچ‌چیز نمی‌سنجید.
            */
            $this->assertStringContainsString(
                '"panel":"'.str_replace('/', '\\/', url($prefix.'/account/domains')).'"',
                $html,
                "{$loc}: مقصدِ دکمهٔ ثبت پیشوندِ زبان را ندارد"
            );

            $this->assertStringContainsString("'?register=' + encodeURIComponent(r.domain)", $html);
        }
    }
}
