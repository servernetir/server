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

    /**
     * وب‌میل نشستِ `webmaild` می‌خواهد، نه `cpaneld`.
     *
     * پارامترِ دومِ `createUserSession` از روزِ اول بود و هیچ‌وقت پاس داده
     * نمی‌شد — یک قابلیتِ آمادهٔ استفاده‌نشده.
     */
    public function test_webmail_uses_its_own_session_type(): void
    {
        Http::fake(['*/json-api/create_user_session*' => Http::response(
            ['metadata' => ['result' => 1], 'data' => ['url' => 'https://w.test:2096/cpsess9/']]
        )]);
        $c = $this->customer();
        $s = $this->provisionedService($c);

        $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/cpanel?app=webmail")
            ->assertRedirect('https://w.test:2096/cpsess9/');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'service=webmaild'));
    }

    /** ویرایشگرِ DNS لینکِ عمیق است، نه رابطِ داخلی */
    public function test_dns_deep_link_goes_to_the_zone_editor(): void
    {
        Http::fake(['*/json-api/create_user_session*' => Http::response(
            ['metadata' => ['result' => 1], 'data' => ['url' => 'https://w.test:2083/cpsess1/']]
        )]);
        $c = $this->customer();
        $s = $this->provisionedService($c);

        $res = $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/cpanel?app=dns");

        $this->assertStringContainsString('zoneeditor', rawurldecode((string) $res->headers->get('Location')));
    }

    /**
     * 🔴 پهنای‌باند پرتکرارترین پرسشِ پشتیبانی است و `accountsummary` ندارَدش.
     *
     * ⚠️ هر دو تماس باید استاب شوند: یک `Http::fake` با یک الگو، بقیه را
     * پاسخِ خالیِ ۲۰۰ می‌دهد و تست چیزی را می‌سنجد که فکر می‌کند.
     */
    public function test_stats_include_bandwidth_and_limits(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'diskused' => '512', 'disklimit' => '1024', 'suspended' => 0, 'ip' => '1.2.3.4',
                'plan' => 'sn_x', 'maxpop' => '25', 'maxsql' => '10', 'maxsub' => '5', 'maxaddon' => '2',
            ]]]]),
            '*/json-api/showbw*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'user' => 'clientusr', 'totalbytes' => '1073741824', 'limit' => '10737418240',
            ]]]]),
        ]);

        $c = $this->customer();
        $s = $this->provisionedService($c);

        $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/stats")
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'bw_used' => 1073741824,
                'bw_limit' => 10737418240,
                'max_email' => 25,
                'max_db' => 10,
            ]);
    }

    /**
     * 🔴 پهنای‌باندِ حسابِ دیگری نباید نشان داده شود.
     *
     * پارامترِ `search` در `showbw` یک **عبارتِ باقاعده** است و WHM **فهرست**
     * برمی‌گرداند. با الگوی مهارنشده، `search=shop` حسابِ `bigshop` را هم
     * می‌گرفت و برداشتنِ کورِ `acct[0]` مصرفِ مشتریِ دیگری را روی کارتِ این
     * مشتری می‌نشاند — نشتِ داده بین دو مشتری.
     */
    public function test_bandwidth_of_a_different_account_is_ignored(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'diskused' => '512', 'disklimit' => '1024', 'suspended' => 0,
            ]]]]),
            // WHM حسابِ دیگری را برمی‌گرداند (الگوی مهارنشده در گذشته)
            '*/json-api/showbw*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'user' => 'bigshop', 'totalbytes' => '515396075520', 'limit' => '536870912000',
            ]]]]),
        ]);

        $c = $this->customer();
        $s = $this->provisionedService($c);

        $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/stats")
            ->assertOk()
            ->assertJson(['ok' => true, 'bw_used' => null], );

        // و الگوی فرستاده‌شده باید مهار شده باشد
        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'showbw')
            || str_contains(rawurldecode($r->url()), '^clientusr$'));
    }
    /** اگر توکن دسترسیِ پهنای‌باند نداشت، بقیهٔ آمار باید سالم بماند */
    public function test_a_bandwidth_failure_does_not_break_the_card(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
                'diskused' => '512', 'disklimit' => '1024', 'suspended' => 0,
            ]]]]),
            '*/json-api/showbw*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'no access']]),
        ]);

        $c = $this->customer();
        $s = $this->provisionedService($c);

        $this->actingAs($c, 'customer')->get("/account/services/{$s->id}/stats")
            ->assertOk()
            ->assertJson(['ok' => true, 'disk_used' => 512, 'bw_used' => null]);
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
