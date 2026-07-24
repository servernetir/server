<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * داشبوردِ سرویس در پنل — ورودِ یک‌کلیکِ cPanel، لینکِ عمیق، و آمارِ زنده.
 */
class ServiceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function provisionedService(Customer $c): Service
    {
        $server = Server::create(['name' => 'WHM-1', 'type' => 'whm', 'hostname' => 'w.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);

        return Service::create([
            'customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'username' => 'clientusr', 'domain' => 'x.com',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'x', 'status' => 'active', 'locale' => 'fa']);
    }

    public function test_customer_opens_cpanel_one_click(): void
    {
        Http::fake(['*/json-api/create_user_session*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://w.test:2083/cpsess123/']])]);
        $c = $this->customer();
        $s = $this->provisionedService($c);

        $res = $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/cpanel");
        $res->assertRedirect('https://w.test:2083/cpsess123/');
    }

    public function test_cpanel_deep_link_appends_goto_uri(): void
    {
        Http::fake(['*/json-api/create_user_session*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://w.test:2083/cpsess123/']])]);
        $c = $this->customer();
        $s = $this->provisionedService($c);

        $res = $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/cpanel?app=files");
        $this->assertStringContainsString('goto_uri', (string) $res->headers->get('Location'));
        $this->assertStringContainsString('filemanager', rawurldecode((string) $res->headers->get('Location')));
    }

    public function test_stats_returns_live_disk_usage(): void
    {
        Http::fake(['*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['diskused' => '512', 'disklimit' => '1024', 'suspended' => 0, 'ip' => '1.2.3.4', 'plan' => 'sn_x']]]])]);
        $c = $this->customer();
        $s = $this->provisionedService($c);

        $this->actingAs($c, 'customer')->getJson("/account/services/{$s->id}/stats")
            ->assertOk()
            ->assertJson(['ok' => true, 'disk_used' => 512, 'disk_limit' => 1024, 'suspended' => false, 'ip' => '1.2.3.4']);
    }

    public function test_stranger_cannot_see_another_customers_stats(): void
    {
        Http::fake();
        $owner = $this->customer();
        $stranger = $this->customer();
        $s = $this->provisionedService($owner);

        $this->actingAs($stranger, 'customer')->getJson("/account/services/{$s->id}/stats")->assertNotFound();
        $this->actingAs($stranger, 'customer')->get("/account/services/{$s->id}/cpanel")->assertNotFound();
    }
}
