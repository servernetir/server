<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * زیردامنهٔ رایگان: بدونِ رکوردِ DNS، سایتِ مشتری بالا نمی‌آید — چون
 * nameserverهای دامنه روی Cloudflare است و zoneِ محلیِ WHM را دنیا نمی‌بیند.
 */
class SubdomainDnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        /*
        | موضوعِ این تست DNSِ زیردامنه است، نه دروازهٔ فروشِ ایران — مشتریِ
        | احرازشده تا سفارشِ IR از IranSalesGate (پیش‌فرض بسته) رد شود.
        */
        \App\Models\CustomerProfile::create([
            'customer_id' => $c->id, 'is_default' => true, 'type' => 'individual',
            'status' => 'verified', 'email' => $c->email, 'mobile' => $c->phone,
        ]);

        return $c;
    }

    private function server(): Server
    {
        return Server::create([
            'name' => 'WHM-IR-01', 'type' => 'whm', 'status' => 'active', 'country' => 'IR',
            'hostname' => 'ir.test', 'username' => 'root', 'api_token' => 't',
            'server_ip' => '185.10.20.30', 'verify_tls' => false,
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'هاست', 'category' => 'shared', 'price' => 250000,
            'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ], $over));
    }

    // ───────────────────── انتخابِ زیردامنه در خرید ─────────────────────

    /** برچسب‌های حساسِ خودمان نباید گرفته شوند (راهِ فیشینگ) */
    public function test_reserved_labels_are_rejected(): void
    {
        $this->server();
        $product = $this->product();
        $customer = $this->customer();

        foreach (['console', 'mail', 'www', 'cpanel', 'pay', 'admin'] as $label) {
            $this->actingAs($customer, 'customer')
                ->post("/account/order/{$product->slug}", [
                    'country' => 'IR', 'cycle' => 'monthly',
                    'domain_mode' => 'subdomain', 'subdomain' => $label,
                ])
                ->assertSessionHasErrors('subdomain');
        }

        $this->assertSame(0, Service::count());
    }

    /** دو مشتری یک زیردامنه نمی‌گیرند */
    public function test_taken_subdomain_is_rejected(): void
    {
        $this->server();
        $product = $this->product();
        $first = $this->customer();

        $this->actingAs($first, 'customer')->post("/account/order/{$product->slug}", [
            'country' => 'IR', 'cycle' => 'monthly',
            'domain_mode' => 'subdomain', 'subdomain' => 'mysite',
        ])->assertRedirect();

        $this->assertSame(1, Service::count());

        $second = $this->customer();
        $this->actingAs($second, 'customer')->post("/account/order/{$product->slug}", [
            'country' => 'IR', 'cycle' => 'monthly',
            'domain_mode' => 'subdomain', 'subdomain' => 'mysite',
        ])->assertSessionHasErrors('subdomain');

        $this->assertSame(1, Service::count());
    }

    /** زیردامنهٔ سالم پذیرفته و به‌شکلِ کاملِ FQDN ذخیره می‌شود */
    public function test_valid_subdomain_is_accepted(): void
    {
        $this->server();
        $product = $this->product();

        $this->actingAs($this->customer(), 'customer')->post("/account/order/{$product->slug}", [
            'country' => 'IR', 'cycle' => 'monthly',
            'domain_mode' => 'subdomain', 'subdomain' => 'ali-shop',
        ])->assertRedirect();

        $this->assertSame('ali-shop.servernet.cloud', Service::first()->domain);
    }

    // ───────────────────── ساختِ رکورد در Cloudflare ─────────────────────

    private function provisionedSubdomainService(): Service
    {
        $server = $this->server();

        return Service::create([
            'customer_id' => $this->customer()->id, 'server_id' => $server->id,
            'name' => 'هاست', 'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 10,
            'cycle' => 'monthly', 'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'plan' => 'sn_x', 'domain' => 'mysite.servernet.cloud',
        ]);
    }

    /** پس از تحویلِ موفق، رکوردِ A زیردامنه روی IPِ سرور ست می‌شود */
    public function test_a_record_is_created_after_successful_provision(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-secret-token');
        Setting::put('cloudflare_zone_id', 'abc123');

        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'not found']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 1], 'data' => []]),
            '*/dns_records?*'            => Http::response(['success' => true, 'result' => []]),   // رکوردی نیست
            '*/dns_records'              => Http::response(['success' => true, 'result' => ['id' => 'rec1']]),
        ]);

        $service = $this->provisionedSubdomainService();
        app(ProvisioningService::class)->provision($service);

        $created = collect(Http::recorded())
            ->filter(fn ($p) => str_contains((string) $p[0]->url(), 'dns_records') && $p[0]->method() === 'POST');

        $this->assertTrue($created->isNotEmpty(), 'رکوردِ DNS ساخته نشد');

        $body = $created->first()[0]->data();
        $this->assertSame('A', $body['type']);
        $this->assertSame('mysite.servernet.cloud', $body['name']);
        $this->assertSame('185.10.20.30', $body['content']);
        $this->assertFalse($body['proxied'], 'برای هاست باید DNS-only باشد وگرنه AutoSSL/FTP می‌شکند');

        $this->assertStringContainsString('185.10.20.30', (string) ($service->fresh()->provision_meta['dns'] ?? ''));
    }

    /** دامنهٔ اختصاصیِ مشتری نباید در Cloudflare ما دست‌کاری شود */
    public function test_customer_own_domain_is_never_touched(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-secret-token');

        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'not found']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 1], 'data' => []]),
            '*' => Http::response(['success' => true, 'result' => []]),
        ]);

        $service = $this->provisionedSubdomainService();
        $service->update(['domain' => 'client-own-domain.com']);

        app(ProvisioningService::class)->provision($service);

        $cf = collect(Http::recorded())->filter(fn ($p) => str_contains((string) $p[0]->url(), 'cloudflare.com'));
        $this->assertTrue($cf->isEmpty(), 'دامنهٔ مشتری نباید به Cloudflare ما برود');
    }

    /** اگر Cloudflare خطا داد، تحویلِ سرویس نباید شکست‌خورده اعلام شود */
    public function test_dns_failure_does_not_fail_the_service(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-secret-token');
        Setting::put('cloudflare_zone_id', 'abc123');

        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'not found']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 1], 'data' => []]),
            // Cloudflare روی خطا هم HTTP 200 می‌دهد؛ نتیجه در بدنه است
            '*cloudflare.com*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid token']]]),
        ]);

        $service = $this->provisionedSubdomainService();
        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok, 'خطای DNS نباید تحویل را شکست بدهد');
        $this->assertSame('done', $service->fresh()->provision_status);
        $this->assertStringContainsString('ناموفق', (string) ($service->fresh()->provision_meta['dns'] ?? ''));
    }

    /** توکن رمزنگاری‌شده ذخیره می‌شود، نه خام */
    public function test_token_is_stored_encrypted(): void
    {
        Setting::putSecret('cloudflare_token', 'super-secret');

        $raw = Setting::get('cloudflare_token');
        $this->assertNotSame('super-secret', $raw, 'توکن نباید خام ذخیره شود');
        $this->assertSame('super-secret', Setting::getSecret('cloudflare_token'));
    }
}
