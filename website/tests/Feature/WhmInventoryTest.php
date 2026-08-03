<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\WhmInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تطبیقِ حساب‌های WHM با سرویس‌های سامانه — قدمِ اولِ «افزودنِ مشتریانِ قدیمی».
 *
 * فقط می‌خوانَد. تصمیمِ واردکردن جدا و آگاهانه گرفته می‌شود، چون قیمت و دورهٔ
 * صورت‌حساب را WHM اصلاً نمی‌داند و حدس‌زدنشان یعنی مبلغی که تا ابد فاکتور
 * می‌شود از هوا آمده باشد.
 */
class WhmInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWhm(array $stubs): void
    {
        // ⚠️ کارخانه از نو: یک `Http::fake()`ِ همه‌گیرِ قبلی هر استابِ بعدی را
        // بی‌اثر می‌کند (اولین تطبیق برنده است).
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function server(): Server
    {
        return Server::create(['name' => 'WHM-1', 'type' => 'whm', 'hostname' => 'w.test',
            'username' => 'root', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);
    }

    private function customer(string $email): Customer
    {
        return Customer::create(['email' => $email, 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function service(Server $s, Customer $c, string $user, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'server_id' => $s->id, 'name' => 'هاست',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'username' => $user, 'domain' => $user.'.com',
        ], $over));
    }

    private function accts(array $rows): array
    {
        return ['*/json-api/listaccts*' => Http::response(
            ['metadata' => ['result' => 1], 'data' => ['acct' => $rows]]
        )];
    }

    /** ⚠️ نامش `run` نیست: `PHPUnit\Framework\TestCase::run()` نهایی است */
    private function scan(Server $s): array
    {
        return app(WhmInventory::class)->reconcile($s);
    }

    // ═══════════ سه دسته ═══════════

    public function test_an_account_with_no_service_is_an_orphan(): void
    {
        $s = $this->server();
        $this->fakeWhm($this->accts([
            ['user' => 'oldguy', 'domain' => 'old.com', 'email' => 'old@x.com', 'plan' => 'sn_x', 'suspended' => 0],
        ]));

        $r = $this->scan($s);

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['orphans']);
        $this->assertSame('oldguy', $r['orphans'][0]['user']);
    }

    /** حسابِ یتیم باید به مشتریِ موجود (بر اساسِ ایمیل) وصل شود */
    public function test_an_orphan_is_matched_to_an_existing_customer_by_email(): void
    {
        $s = $this->server();
        $c = $this->customer('old@x.com');
        $this->fakeWhm($this->accts([
            ['user' => 'oldguy', 'domain' => 'old.com', 'email' => 'old@x.com', 'plan' => 'sn_x', 'suspended' => 0],
        ]));

        $this->assertSame($c->id, $this->scan($s)['orphans'][0]['customer_id']);
    }

    /**
     * 🔴 ایمیلِ خراب یعنی «نمی‌شود وارد کرد»، نه «آدرس بساز».
     *
     * `customers.email` یکتا و ناتهی است. ساختنِ `user@servernet.cloud` برای
     * حسابِ بی‌ایمیل، فضای نامِ ورود را برای همیشه آلوده می‌کند و بعداً همان
     * آدرسِ واقعی قابلِ ثبت‌نام نخواهد بود.
     */
    public function test_an_unusable_email_is_flagged_not_invented(): void
    {
        $s = $this->server();
        $this->fakeWhm($this->accts([
            ['user' => 'a', 'domain' => 'a.com', 'email' => '', 'plan' => 'p', 'suspended' => 0],
            ['user' => 'b', 'domain' => 'b.com', 'email' => '*unknown*', 'plan' => 'p', 'suspended' => 0],
        ]));

        foreach ($this->scan($s)['orphans'] as $o) {
            $this->assertFalse($o['email_usable']);
            $this->assertNull($o['customer_id']);
        }
    }

    public function test_a_matched_account_is_not_an_orphan(): void
    {
        $s = $this->server();
        $c = $this->customer('x@x.com');
        $this->service($s, $c, 'clientusr');

        $this->fakeWhm($this->accts([
            ['user' => 'clientusr', 'domain' => 'x.com', 'email' => 'x@x.com', 'plan' => 'p', 'suspended' => 0],
        ]));

        $r = $this->scan($s);

        $this->assertEmpty($r['orphans']);
        $this->assertEmpty($r['ghosts']);
        $this->assertCount(1, $r['matched']);
    }

    public function test_a_service_with_no_account_is_a_ghost(): void
    {
        $s = $this->server();
        $this->service($s, $this->customer('x@x.com'), 'goneusr');
        $this->fakeWhm($this->accts([]));

        $this->assertCount(1, $this->scan($s)['ghosts']);
    }

    /** سرویسِ بسته‌شده شبح نیست — نبودِ حسابش دقیقاً انتظارِ ماست */
    public function test_a_terminated_service_is_not_a_ghost(): void
    {
        $s = $this->server();
        $this->service($s, $this->customer('x@x.com'), 'goneusr', ['status' => 'terminated']);
        $this->fakeWhm($this->accts([]));

        $this->assertEmpty($this->scan($s)['ghosts']);
    }

    /** اختلافِ تعلیق: پنل و سرور دو چیز می‌گویند */
    public function test_status_drift_is_flagged(): void
    {
        $s = $this->server();
        $this->service($s, $this->customer('x@x.com'), 'clientusr', ['status' => 'active']);

        $this->fakeWhm($this->accts([
            ['user' => 'clientusr', 'domain' => 'x.com', 'email' => 'x@x.com', 'plan' => 'p', 'suspended' => 1],
        ]));

        $this->assertTrue($this->scan($s)['matched'][0]['status_drift']);
    }

    // ═══════════ ایمنی ═══════════

    /**
     * 🔴 مهم‌ترین تست: سروری که جواب نداد هیچ شبحی نمی‌سازد.
     *
     * توکنِ منقضی «۰ حساب» می‌دهد. اگر آن را باور کنیم، گزارش می‌گوید همهٔ
     * سایت‌های مشتریان از روی سرور پاک شده‌اند.
     */
    public function test_a_failed_call_produces_no_ghosts(): void
    {
        $s = $this->server();
        $this->service($s, $this->customer('x@x.com'), 'clientusr');

        $this->fakeWhm(['*/json-api/listaccts*' => Http::response(
            ['metadata' => ['result' => 0, 'reason' => 'Access denied']]
        )]);

        $r = $this->scan($s);

        $this->assertFalse($r['ok']);
        $this->assertEmpty($r['ghosts']);
        $this->assertStringContainsString('Access denied', $r['message']);
    }

    public function test_a_non_whm_server_is_refused(): void
    {
        $s = Server::create(['name' => 'DA', 'type' => 'directadmin', 'hostname' => 'd.test',
            'username' => 'admin', 'api_token' => 't', 'verify_tls' => false, 'status' => 'active']);

        $this->assertFalse($this->scan($s)['ok']);
    }

    /** فرمان فقط می‌خوانَد — هیچ سرویس یا مشتری‌ای نباید ساخته شود */
    public function test_the_command_writes_nothing(): void
    {
        $this->server();
        $this->fakeWhm($this->accts([
            ['user' => 'oldguy', 'domain' => 'old.com', 'email' => 'old@x.com', 'plan' => 'p', 'suspended' => 0],
        ]));

        $before = [Service::count(), Customer::count()];

        $this->artisan('whm:scan')->assertSuccessful();

        $this->assertSame($before, [Service::count(), Customer::count()]);
    }
}
