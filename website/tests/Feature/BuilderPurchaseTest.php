<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\BuilderSitePublisher;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * فاز C سایت‌ساز — خریدِ خودمدیریت‌شده و استقرارِ خودکار.
 *
 * زنجیرهٔ کامل: تسویهٔ builder (سرویس + دامنه + **یک** فاکتور) → پرداخت →
 * تحویلِ WHM → نوشتنِ index.html در public_html اکانت.
 *
 * ادعاها روی **بدنهٔ درخواستی که به سرور می‌رود** و روی ردیف‌های واقعیِ
 * دیتابیس‌اند، نه روی «۲۰۰ برگشت» — همان قاعدهٔ ResellerProvisioningTest.
 */
class BuilderPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        // دیسکِ واقعیِ storage/app آلوده نشود؛ Publisher هم از همین می‌خوانَد
        Storage::fake('local');
    }

    private function whmServer(): Server
    {
        return Server::create([
            'name' => 'WHM-Test', 'type' => 'whm', 'hostname' => 'whm.test',
            'port' => 2087, 'username' => 'root', 'api_token' => 'TESTTOKEN',
            'verify_tls' => false, 'status' => 'active',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function seedProducts(): Product
    {
        \Illuminate\Support\Facades\Artisan::call('products:seed-builder');

        $p = Product::where('slug', 'site-builder-2')->first();
        $this->assertNotNull($p, 'seeder باید پکیج‌های site-builder-* را بسازد');
        $this->assertSame('site-builder', $p->group);

        return $p;
    }

    private function freshQuote(string $fqdn = 'cafenemuneh.com'): DomainQuote
    {
        [, $tld] = Domain::splitFqdn($fqdn);

        return DomainQuote::create([
            'domain' => $fqdn, 'tld' => $tld, 'registrar' => 'openprovider',
            'is_premium' => false, 'cost_amount' => 900, 'cost_currency' => 'EUR',
            'sell_toman' => 2000000, 'renew_toman' => 2500000,
            'honour_until' => now()->addMinutes(10), 'raw' => [],
        ]);
    }

    private function storeSite(string $ref, string $html = '<!doctype html><html><body>SITE</body></html>'): void
    {
        Storage::disk('local')->put(BuilderSitePublisher::path($ref), $html);
    }

    // ───────────────────────── تسویه ─────────────────────────

    public function test_checkout_creates_service_domain_and_one_invoice_with_both_ids(): void
    {
        $this->whmServer();
        $product = $this->seedProducts();
        $quote = $this->freshQuote();
        $this->storeSite('SB-TEST01');

        $res = $this->actingAs($this->customer(), 'customer')->post('/account/builder-checkout', [
            'ref' => 'SB-TEST01', 'plan' => $product->slug, 'quote_id' => $quote->id,
        ]);

        $res->assertRedirect();

        $service = Service::firstOrFail();
        $domain = Domain::firstOrFail();
        $invoice = Invoice::firstOrFail();

        // یک فاکتور با هر دو شناسه — لولای کلِ طراحی: applyPaid هر دو زنجیره را می‌اندازد
        $this->assertSame(1, Invoice::count());
        $this->assertSame($service->id, $invoice->service_id);
        $this->assertSame($domain->id, $invoice->domain_id);

        // مرجعِ سایت روی سرویس قفل شده
        $this->assertSame('SB-TEST01', $service->provision_meta['builder_ref'] ?? null);
        $this->assertSame('pending', $service->status);
        $this->assertSame($product->plan, $service->plan);

        // دامنه تا پرداخت نشده نباید واردِ صفِ ثبت شود
        $this->assertSame('none', $domain->provision_status);
        $this->assertSame((int) $quote->sell_toman, (int) $domain->price_toman);

        // قیمت هرگز از مرورگر نیامده: هاست از پکیج، دامنه از استعلامِ سرور
        $hostPrice = $product->priceForCycle('monthly');
        $this->assertSame($hostPrice, (int) $service->price);
        $expectedSubtotal = $hostPrice + (int) $quote->sell_toman;
        $this->assertSame($expectedSubtotal, (int) $invoice->subtotal);
        $this->assertGreaterThan($expectedSubtotal, (int) $invoice->total); // مالیات رویش

        // نیم‌سرورها همان پیش‌فرضِ شرکت‌اند
        $this->assertSame(Domain::defaultNameServers(), $domain->name_servers);
    }

    public function test_expired_quote_and_unsold_tld_are_rejected_before_any_row_is_written(): void
    {
        $this->whmServer();
        $product = $this->seedProducts();
        $this->storeSite('SB-TEST02');
        $customer = $this->customer();

        // استعلامِ منقضی
        $stale = $this->freshQuote();
        $stale->forceFill(['honour_until' => now()->subMinute()])->save();

        $this->actingAs($customer, 'customer')->post('/account/builder-checkout', [
            'ref' => 'SB-TEST02', 'plan' => $product->slug, 'quote_id' => $stale->id,
        ])->assertSessionHasErrors();

        // پسوندِ فروخته‌نشدنی (ir) — حتی اگر کسی دستی quote بسازد
        $ir = $this->freshQuote('mysite.ir');

        $this->actingAs($customer, 'customer')->post('/account/builder-checkout', [
            'ref' => 'SB-TEST02', 'plan' => $product->slug, 'quote_id' => $ir->id,
        ])->assertSessionHasErrors();

        $this->assertSame(0, Service::count());
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_guest_cannot_reach_builder_checkout(): void
    {
        $this->get('/account/builder-checkout?ref=SB-X1&plan=site-builder-2&domain=a.com')
            ->assertRedirect();

        $this->assertSame(0, Service::count());
    }

    public function test_missing_site_html_blocks_the_order_before_money(): void
    {
        $this->whmServer();
        $product = $this->seedProducts();
        $quote = $this->freshQuote();
        // عمداً هیچ فایلی ذخیره نمی‌شود — کشِ ۷روزه هم خالی است

        $this->actingAs($this->customer(), 'customer')->post('/account/builder-checkout', [
            'ref' => 'SB-GONE99', 'plan' => $product->slug, 'quote_id' => $quote->id,
        ])->assertSessionHasErrors();

        $this->assertSame(0, Invoice::count());
    }

    // ───────────────────────── انتشارِ خودکار ─────────────────────────

    public function test_provisioned_builder_service_publishes_the_site_into_public_html(): void
    {
        $html = '<!doctype html><html lang="fa"><body>کافه نمونه</body></html>';
        $this->storeSite('SB-PUB001', $html);

        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $url = $request->url();

            if (str_contains($url, '/json-api/cpanel')) {
                $sent[] = $request->data();

                return Http::response(['result' => ['status' => 1, 'errors' => [], 'data' => []]]);
            }
            if (str_contains($url, 'accountsummary')) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'account does not exist']]);
            }

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'], 'data' => ['ip' => '1.2.3.4']]);
        });

        $server = $this->whmServer();
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'سایت‌ساز — Business',
            'currency_code' => 'IRT', 'price' => 590000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'domain' => 'cafenemuneh.com', 'plan' => 'sn_site_builder_2',
            'provision_meta' => ['builder_ref' => 'SB-PUB001'],
        ]);

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        $service->refresh();
        $this->assertSame('active', $service->status);

        // 🔴 مرجع از بازنویسیِ metaی درایور جان به در برده
        $this->assertSame('SB-PUB001', $service->provision_meta['builder_ref'] ?? null);
        $this->assertNotEmpty($service->provision_meta['builder_published_at'] ?? null);

        // خودِ درخواست: UAPI به‌نامِ همان اکانت، همان فایل، همان محتوا
        $this->assertCount(1, $sent);
        $this->assertSame($service->username, $sent[0]['cpanel_jsonapi_user'] ?? null);
        $this->assertSame('Fileman', $sent[0]['cpanel_jsonapi_module'] ?? null);
        $this->assertSame('save_file_content', $sent[0]['cpanel_jsonapi_func'] ?? null);
        $this->assertSame('public_html', $sent[0]['dir'] ?? null);
        $this->assertSame('index.html', $sent[0]['file'] ?? null);
        $this->assertSame($html, $sent[0]['content'] ?? null);
    }

    public function test_publish_failure_never_fails_the_delivery_itself(): void
    {
        $this->storeSite('SB-PUB002');

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/json-api/cpanel')) {
                return Http::response(['result' => ['status' => 0, 'errors' => ['disk full'], 'data' => []]]);
            }
            if (str_contains($url, 'accountsummary')) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'account does not exist']]);
            }

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'], 'data' => ['ip' => '1.2.3.4']]);
        });

        $server = $this->whmServer();
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'سایت‌ساز',
            'currency_code' => 'IRT', 'price' => 290000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'domain' => 'x1.com', 'plan' => 'sn_site_builder_1',
            'provision_meta' => ['builder_ref' => 'SB-PUB002'],
        ]);

        // حساب ساخته شده و رمز رفته — شکستِ نوشتنِ فایل نباید تحویل را بکشد
        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertSame('done', $service->provision_status);
        $this->assertNotEmpty($service->provision_meta['builder_publish_error'] ?? null);
        $this->assertEmpty($service->provision_meta['builder_published_at'] ?? null);
    }

    public function test_ordinary_services_never_touch_the_cpanel_passthrough(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/json-api/cpanel')) {
                $this->fail('سرویسِ عادی نباید سراغِ انتشارِ سایت‌ساز برود');
            }
            if (str_contains($url, 'accountsummary')) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'account does not exist']]);
            }

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'], 'data' => ['ip' => '1.2.3.4']]);
        });

        $server = $this->whmServer();
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'هاست لینوکس',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'domain' => 'plain.com', 'plan' => 'WP-5',
        ]);

        $this->assertTrue(app(ProvisioningService::class)->provision($service));
        $this->assertSame('active', $service->refresh()->status);
    }
}
