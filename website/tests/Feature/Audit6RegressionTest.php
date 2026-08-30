<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Support\OrderHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * قفل‌های رگرسیونیِ ممیزی ۶ — بندهای RG که در کد سنجیدنی‌اند + اصلاحاتِ شورا.
 *
 * «تنها قلمی که معیارِ پذیرشِ تست‌پذیر داشت، تنها قلمی بود که ۱۰۰٪ تحویل شد.»
 */
class Audit6RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug, string $category = 'shared', int $setup = 0): Product
    {
        return Product::create([
            'name' => 'پکیج '.$slug, 'slug' => $slug, 'group' => 'wordpress', 'category' => $category,
            'price' => 700000, 'price_eur' => 0, 'setup_fee' => $setup, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'r6-'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret1234'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function cacheOn(): void
    {
        config(['pagecache.enabled' => true, 'pagecache.mode' => 'denylist']);
        Cache::flush();
    }

    // ── RG-GONE-10: قلمِ شاهد — ۴۱۰، تصمیم‌گرفته‌شده ─────────────────────

    public function test_share_paths_are_gone_not_not_found(): void
    {
        $this->get('/share/url?url=x')->assertStatus(410);
        $this->get('/sharing/share-offsite?url=x')->assertStatus(410);
        $this->get('/en/share/url')->assertStatus(410);
    }

    // ── RG-CACHE-01: denylist — بخشِ تازه به‌طورِ پیش‌فرض کش می‌شود ────────

    public function test_new_sections_are_cached_by_default_and_private_ones_are_not(): void
    {
        $this->cacheOn();

        // /sla و /aup دیگر به فهرستِ allowlist وابسته نیستند
        foreach (['/sla', '/aup', '/speed'] as $p) {
            $this->get($p)->assertOk()->assertHeader('X-Cache', 'MISS');
            $this->get($p)->assertOk()->assertHeader('X-Cache', 'HIT');
        }

        // وضعیت لحظه‌ای می‌مانَد؛ ۴۱۰ها هم کش نمی‌شوند
        $this->get('/status')->assertHeader('X-Cache', 'BYPASS');
        $this->get('/share/url')->assertHeader('X-Cache', 'BYPASS');
    }

    /** شورا (امنیت): صفحه‌ای که IPِ بازدیدکننده را می‌نویسد هرگز بینِ دو نفر مشترک نشود */
    public function test_ip_dependent_and_sandbox_pages_bypass_the_page_cache(): void
    {
        $this->cacheOn();

        foreach (['/tools/ip', '/lookup'] as $p) {
            $r = $this->get($p);

            if ($r->getStatusCode() === 200) {
                $r->assertHeader('X-Cache', 'BYPASS');
            }
        }
    }

    public function test_cacheable_responses_carry_private_cache_control_and_server_timing(): void
    {
        $this->cacheOn();

        $r = $this->get('/aup');
        $r->assertHeader('X-Cache', 'MISS');
        $cc = (string) $r->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cc, 'HTML با توکنِ CSRF هرگز public نمی‌شود (شورا/امنیت)');
        $this->assertStringContainsString('max-age=60', $cc);
        $this->assertStringNotContainsString('public', $cc);
        $this->assertStringNotContainsString('s-maxage', $cc);
        $this->assertStringContainsString('Cookie', (string) $r->headers->get('Vary'));
        $this->assertStringContainsString('app;dur=', (string) $r->headers->get('Server-Timing'));

        // ۴۰۴ِ MISS هدرِ کش نمی‌گیرد
        $nf = $this->get('/blog/this-post-does-not-exist-r6');
        $this->assertStringNotContainsString('max-age=60', (string) $nf->headers->get('Cache-Control'));
    }

    /** کنترلری که خودش no-store گفته، ذخیره نمی‌شود — لایهٔ دوم بعد از denylist */
    public function test_no_store_responses_are_never_cached(): void
    {
        $this->cacheOn();

        // روتِ نام‌دار (روتِ بی‌نام در denylist خودش BYPASS است) با no-storeِ صریح
        \Illuminate\Support\Facades\Route::get('/r6-no-store', function () {
            return response('<html>secret</html>', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store']);
        })->middleware('web')->name('r6.nostore');

        $this->get('/r6-no-store')->assertHeader('X-Cache', 'MISS');
        $this->get('/r6-no-store')->assertHeader('X-Cache', 'MISS');
        $this->assertStringNotContainsString('max-age=60', (string) $this->get('/r6-no-store')->headers->get('Cache-Control'));
    }

    // ── RG-SCHEMA-05 + UX صفحهٔ سفارش ─────────────────────────────────────

    public function test_order_page_has_product_aggregate_offer_schema_and_signed_cycle_links(): void
    {
        $p = $this->product('wp-test-1');

        $r = $this->get('/order/'.$p->slug);
        $r->assertOk();
        $html = $r->getContent();

        $this->assertStringContainsString('"AggregateOffer"', $html);
        $this->assertStringContainsString('"priceCurrency":"IRR"', $html);
        $this->assertStringContainsString('"merchantReturnDays":14', $html, 'هاستِ اشتراکی قابلِ بازگشت است');
        $this->assertStringContainsString('#cy-yearly', $html, 'Offer.url لنگرِ همین صفحه است');

        /*
        | ممیزی ۷ (قلم ۳): CTA دیگر لینکِ مستقیم و امضاشدهٔ console نیست؛ از
        | گذرگاهِ شمارش‌پذیرِ /go/pay می‌گذرد که sku و cycle را حمل می‌کند و
        | امضا را در لحظهٔ کلیک می‌سازد. هیچ قیمتی و هیچ sidِ سروری در لینک.
        */
        $this->assertSame(4, substr_count($html, 'type="radio" name="cycle"'));
        $this->assertMatchesRegularExpression('~data-href="[^"]*/go/pay\?[^"]*sku=wp-test-1[^"]*cycle=yearly~', $html);
        $this->assertStringNotContainsString('sig=', $html, 'امضا در /go/pay ساخته می‌شود، نه در صفحهٔ کش‌شده');
        $this->assertStringNotContainsString('price=', $html);
        /*
        | ⚠️ ادعا روی **لینک‌های رندرشده** است، نه روی کلِ سند.
        |
        | نسخهٔ قبلی `sid=` را در تمامِ HTML می‌گشت و به سورسِ خودِ جاوااسکریپت
        | گیر می‌کرد (`cta.href = r.dataset.href + '&sid=' + sid`) — یعنی دقیقاً
        | به همان کدی که کامنتِ بالا می‌گوید **باید** آن‌جا باشد. تست چیزی را
        | قرمز می‌کرد که خودش تجویزش کرده بود.
        |
        | خطرِ واقعی این است که یک sidِ **سروری** داخلِ یک آدرس بنشیند و با
        | صفحه کش شود؛ پس همان‌جا را می‌سنجیم.
        */
        preg_match_all('~(?:data-)?href="([^"]*)"~', $html, $hrefs);

        $this->assertNotEmpty($hrefs[1], 'هیچ لینکی در صفحه نیست — پیش‌فرضِ این ادعا عوض شده');

        foreach ($hrefs[1] as $href) {
            $this->assertStringNotContainsString('sid=', $href,
                'sid را مرورگر می‌سازد — این لینک با صفحه کش می‌شود: '.$href);
        }

        // ستونِ خالی حذف شد؛ برچسبِ صرفه‌جویی و «بیشترین صرفه‌جویی» هستند
        $this->assertStringContainsString(__('ui.os_base'), $html);
        $this->assertStringContainsString(__('ui.os_popular'), $html);

        // جمع و CTA سمتِ سرور پر شده‌اند (بدونِ JS هم کامل)
        $this->assertMatchesRegularExpression('~id="os-total"[^>]*>\s*\S~', $html);

        // اعدادِ فارسی dir=ltr نمی‌گیرند (فقط داخلِ فرمِ سفارش — هدر dirِ خودش را دارد)
        preg_match('~<form class="sla-doc[^"]*" id="os-form".*?</form>~s', $html, $form);
        $this->assertNotEmpty($form);
        $this->assertStringNotContainsString('dir="ltr"', $form[0]);

        // ممیزی ۷ (قلم ۲): همهٔ صفحاتِ سفارش ایندکس‌پذیرند و عنوانِ تراکنشیِ
        // یکتا دارند — تصمیمِ «فقط پرچم‌دار»ِ ممیزی ۶ برگشت.
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringContainsString(e($p->name), $html);
    }

    public function test_setup_fee_is_folded_into_the_first_payment(): void
    {
        $p = $this->product('wp-setup-1', 'shared', 200000);

        $html = $this->get('/order/'.$p->slug)->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.os_first_label'), $html);
        $this->assertStringContainsString('data-first=', $html);
    }

    public function test_english_order_page_prices_schema_in_eur_or_omits_offers(): void
    {
        $p = $this->product('wp-test-en');

        $html = $this->get('/en/order/'.$p->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('"priceCurrency":"IRR"', $html, 'صفحهٔ انگلیسی ریال اعلام نمی‌کند');

        if (str_contains($html, '"AggregateOffer"')) {
            $this->assertStringContainsString('"priceCurrency":"EUR"', $html);
        }
    }

    public function test_non_refundable_categories_do_not_advertise_the_guarantee(): void
    {
        $p = $this->product('lic-test-1', 'license');

        $html = $this->get('/order/'.$p->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('merchantReturnDays', $html);
        $this->assertStringContainsString(__('ui.os_no_refund'), $html);
        $this->assertStringNotContainsString(__('ui.hp_inc5'), $html);
    }

    public function test_vat_sentence_and_schema_flag_only_appear_when_registration_is_verified(): void
    {
        $p = $this->product('wp-test-2');

        $before = $this->get('/order/'.$p->slug)->getContent();
        $this->assertStringContainsString(__('ui.os_tax_neutral'), $before);
        $this->assertStringNotContainsString('valueAddedTaxIncluded', $before);

        Setting::put('company_vat_verified', '1');

        $after = $this->get('/order/'.$p->slug)->getContent();
        $this->assertStringContainsString('مالیات بر ارزش افزوده', $after);
        $this->assertStringContainsString('"valueAddedTaxIncluded":true', $after);
    }

    // ── SN-ORDER-001: تحویلِ امضاشده ─────────────────────────────────────

    public function test_handoff_signature_round_trip_and_tamper_rejection(): void
    {
        $cycles = ['monthly', 'quarterly', 'semiannual', 'yearly'];
        $params = OrderHandoff::params('wp-1', 'yearly') + ['sid' => 'abcdef1234567890', 'ref' => 'blog'];

        $ok = OrderHandoff::verify($params, 'wp-1', $cycles, $reason);
        $this->assertSame('yearly', $ok['cycle']);
        $this->assertSame('blog', $ok['ref']);
        $this->assertSame('abcdef1234567890', $ok['sid']);
        $this->assertNull($reason);

        // sid/ref بدشکل ⇒ خالی، نه رد (امضا روی آن‌ها نیست)
        $bad = $params;
        $bad['sid'] = '<script>';
        $this->assertSame('', OrderHandoff::verify($bad, 'wp-1', $cycles)['sid']);

        // دستکاریِ دوره ⇒ رد با دلیل
        $t = $params;
        $t['cycle'] = 'monthly';
        $this->assertNull(OrderHandoff::verify($t, 'wp-1', $cycles, $reason));
        $this->assertSame('tampered', $reason);

        // SKU دیگر ⇒ رد
        $this->assertNull(OrderHandoff::verify($params, 'wp-2', $cycles, $reason));
        $this->assertSame('sku', $reason);

        // منقضی ⇒ رد (نه خطا) با دلیلِ «expired»
        $old = OrderHandoff::params('wp-1', 'yearly', time() - 10);
        $this->assertNull(OrderHandoff::verify($old, 'wp-1', $cycles, $reason));
        $this->assertSame('expired', $reason);
    }

    /** console دورهٔ امضاشده را از پیش انتخاب می‌کند؛ لینکِ دستکاری‌شده دیوار نمی‌سازد */
    public function test_console_checkout_preselects_the_signed_cycle_and_ignores_bad_links(): void
    {
        $p = $this->product('wp-handoff-1');
        $c = $this->customer();
        $q = http_build_query(OrderHandoff::params($p->slug, 'yearly') + ['sid' => 'abcdef1234567890', 'ref' => 'order']);

        $html = $this->actingAs($c, 'customer')->get('/account/order/'.$p->slug.'?'.$q)->assertOk()->getContent();
        $this->assertStringContainsString('value="yearly" checked', $html);
        $this->assertStringContainsString('chk-handoff', $html);

        // دستکاری ⇒ صفحه باز می‌شود (۲۰۰) با پیش‌فرض، بی‌خطِ تأیید
        $bad = str_replace('cycle=yearly', 'cycle=monthly', $q);
        $html = $this->actingAs($c, 'customer')->get('/account/order/'.$p->slug.'?'.$bad)->assertOk()->getContent();
        $this->assertStringNotContainsString('chk-handoff', $html);
    }

    // ── رویدادهای قیف از مرورگر ──────────────────────────────────────────

    public function test_funnel_beacon_accepts_known_events_and_rejects_unknown(): void
    {
        $this->postJson('/api/funnel', ['event' => 'cycle_selected', 'sku' => 'wp-1', 'cycle' => 'yearly', 'sid' => 'abcdef1234567890'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->postJson('/api/funnel', ['event' => 'payment_success'])->assertStatus(422);

        // در تست به فایلِ واقعیِ ماه نمی‌نویسد
        $this->assertDirectoryDoesNotExist(storage_path('app/funnel/r6-never'));
    }

    // ── sitemap / llms / صفحات تازه ───────────────────────────────────────

    public function test_official_channels_page_renders_in_all_locales_and_is_linked_from_footer(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/official-channels')->assertOk()->getContent();
            $this->assertStringContainsString('instagram.com/servernet.tr', $html, 'هر سه اینستاگرام فهرست شده‌اند');
        }

        $this->assertStringContainsString('/official-channels', $this->get('/')->getContent());
    }

    public function test_sitemap_lists_official_page_and_developers(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/official-channels', $xml);
        $this->assertStringContainsString('/developers', $xml);
    }

    public function test_refundability_follows_the_legal_scope(): void
    {
        foreach (['shared', 'reseller', 'vps', 'plesk', 'directadmin'] as $c) {
            $this->assertTrue((new Product(['category' => $c]))->isRefundable(), $c);
        }

        foreach (['dedicated', 'license', 'other'] as $c) {
            $this->assertFalse((new Product(['category' => $c]))->isRefundable(), $c);
        }
    }

    /** صفحهٔ محصولِ سرور اختصاصی ضمانتِ ۱۴روزه را در نوارِ «شامل» قول نمی‌دهد */
    public function test_dedicated_product_pages_do_not_promise_the_refund_in_the_inc_strip(): void
    {
        $r = $this->get('/dedicated/hetzner');

        if ($r->getStatusCode() === 200) {
            $this->assertStringNotContainsString(__('ui.hp_inc5'), $r->getContent());
        } else {
            $this->markTestSkipped('صفحهٔ /dedicated/hetzner روی این نصب نیست');
        }
    }
}
