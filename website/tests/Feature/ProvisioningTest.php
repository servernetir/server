<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * موتورِ فراهم‌سازی — ساختِ خودکارِ حساب روی WHM، idempotency و چرخهٔ حیات.
 */
class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function whmServer(): Server
    {
        return Server::create([
            'name' => 'WHM-Test', 'type' => 'whm', 'hostname' => 'whm.test',
            'port' => 2087, 'username' => 'root', 'api_token' => 'TESTTOKEN',
            'verify_tls' => false, 'status' => 'active',
        ]);
    }

    private function service(Server $server, array $over = []): Service
    {
        $c = Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'هاست لینوکس',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'domain' => 'client-site.com', 'plan' => 'WP-5',
        ], $over));
    }

    public function test_whm_account_is_created_and_service_activated(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'account does not exist']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'], 'data' => ['ip' => '1.2.3.4']]),
        ]);

        $server = $this->whmServer();
        $service = $this->service($server);

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok);
        $service->refresh();
        $this->assertSame('done', $service->provision_status);
        $this->assertSame('active', $service->status);
        $this->assertNotEmpty($service->username);
        $this->assertNotEmpty($service->password);          // رمز ساخته و رمزنگاری شد
        $this->assertStringContainsString('whm.test', $service->panel_url);
        $this->assertSame(1, $server->fresh()->active_accounts);   // شمارندهٔ ظرفیت
    }

    public function test_provision_is_idempotent_when_account_already_exists(): void
    {
        // accountsummary موفق = حساب هست → نباید دوباره بسازد
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'ok'], 'data' => []]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 0, 'reason' => 'username already exists']]),
        ]);

        $server = $this->whmServer();
        $service = $this->service($server, ['username' => 'existinguser']);

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok);
        $this->assertSame('done', $service->fresh()->provision_status);
        // چون حساب از قبل بود (reused)، شمارندهٔ ظرفیت زیاد نشد
        $this->assertSame(0, $server->fresh()->active_accounts);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createacct'));
    }

    public function test_whm_failure_marks_service_failed(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'no account']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 0, 'reason' => 'Sorry, a DNS entry for this domain already exists']]),
        ]);

        $server = $this->whmServer();
        $service = $this->service($server);

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertFalse($ok);
        $service->refresh();
        $this->assertSame('failed', $service->provision_status);
        $this->assertSame('provision_failed', $service->status);
        $this->assertStringContainsString('DNS entry', $service->provision_error);
    }

    public function test_manual_server_flags_for_manual_delivery(): void
    {
        Http::fake();   // نباید تماسی برود
        $server = Server::create(['name' => 'VPS-1', 'type' => 'vps', 'status' => 'active']);
        $service = $this->service($server);

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertFalse($ok);
        $this->assertSame('manual', $service->fresh()->provision_status);
        $this->assertSame('awaiting_provision', $service->fresh()->status);
        Http::assertNothingSent();
    }
}
