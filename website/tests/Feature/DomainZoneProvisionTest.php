<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Dns\DomainZoneProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ساختِ خودکارِ DNS zone — دامنهٔ روی نیم‌سرورهای ما باید resolve شود.
 *
 * ═══ بزرگ‌ترین یافتهٔ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 ثبت با ns1/ns2.servernet.cloud انجام می‌شد ولی هیچ zone‌ای ساخته
 * نمی‌شد: دامنه «فعال» بود و به هیچ‌جا resolve نمی‌شد. مشتری پول داده بود
 * و سایتش بالا نمی‌آمد — بی‌هیچ خطایی، تا کسی دستی در WHM بسازد.
 *
 * قاعده‌ها: فقط برای NSِ پیش‌فرض؛ «از قبل هست» موفقیت است؛ و شکستِ zone
 * هرگز ثبتِ موفق را خراب نمی‌کند — فقط مدیر را با نامِ دامنه صدا می‌زند.
 */
class DomainZoneProvisionTest extends TestCase
{
    use RefreshDatabase;

    private Server $whm;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);

        $this->whm = Server::create([
            'name' => 'core', 'type' => 'whm', 'country' => 'FI', 'city' => 'HEL',
            'hostname' => 'core.servernet.cloud', 'username' => 'root',
            'api_token' => 'tok', 'verify_tls' => false,
        ]);

        Setting::put('domain_zone_server_id', (string) $this->whm->id);
        Setting::put('domain_zone_ip', '65.109.176.14');
    }

    private function fake(array $extra = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);

        /*
        | ⚠️ ترتیبِ الگوها معنادار است (اولین تطبیق برنده): خاص‌ها اول،
        | کچ‌آل آخر — وگرنه یا «*» همه را می‌بلعد یا درخواستِ بی‌الگو به
        | اینترنتِ واقعی می‌رود. array_merge مقدارِ $extra را روی کلیدِ
        | هم‌نامِ پیش‌فرض می‌نشانَد (جای کلید همان اولی می‌مانَد).
        */
        Http::fake(array_merge([
            '*/auth/login*'      => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/json-api/adddns*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'created']]),
        ], $extra, [
            '*' => Http::response([], 500),
        ]));
    }

    private function domain(array $over = []): Domain
    {
        $c = Customer::create([
            'email' => 'zn'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'احسان', 'last_name' => 'ا', 'email' => $c->email,
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'mobile' => '09123456789',
        ]);

        return Domain::create(array_merge([
            'customer_id' => $c->id,
            'domain' => 'zn'.random_int(1000, 99999).'.com',
            'sld' => 'zn'.random_int(1000, 99999), 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'pending', 'period_years' => 1,
        ], $over));
    }

    private function register(Domain $d): array
    {
        return app(\App\Services\Domain\DomainRegistrar::class)->register($d);
    }

    // ═══════════════ ثبتِ موفق → zone ═══════════════

    public function test_a_registration_on_our_default_ns_creates_the_zone(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/customers*' => Http::response(['code' => 0, 'data' => ['handle' => 'AB1-NL']]),
            '*/domains*'   => Http::response(['code' => 0, 'data' => ['results' => [[
                'id' => 900, 'status' => 'ACT', 'expiration_date' => '2027-01-01 00:00:00',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]]),
        ]);

        $this->assertTrue($this->register($d)['ok']);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/json-api/adddns')
            && str_contains($req->url(), $d->domain));
    }

    public function test_custom_nameservers_do_not_get_our_zone(): void
    {
        $d = $this->domain(['name_servers' => ['ns1.customer.io', 'ns2.customer.io']]);

        $this->fake([
            '*/customers*' => Http::response(['code' => 0, 'data' => ['handle' => 'AB1-NL']]),
            '*/domains*'   => Http::response(['code' => 0, 'data' => ['results' => [[
                'id' => 901, 'status' => 'ACT',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]]),
        ]);

        $this->assertTrue($this->register($d)['ok']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/json-api/adddns'));
    }

    // ═══════════════ idempotency و ایمنی ═══════════════

    public function test_an_existing_zone_reads_as_success(): void
    {
        $this->fake([
            '*/json-api/adddns*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'domain already exists in DNS']]),
        ]);

        $d = $this->domain(['status' => 'active', 'provision_status' => 'done']);

        $this->assertTrue(app(DomainZoneProvisioner::class)->ensure($d)['ok'],
            'zone موجود باید موفقیت خوانده شود — idempotent');
    }

    /** 🔴 مهم‌ترین ادعا: شکستِ zone هرگز ثبتِ موفق را «ناموفق» جا نمی‌زند */
    public function test_a_zone_failure_never_breaks_a_successful_registration(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/json-api/adddns*' => Http::response('boom', 500),
            '*/customers*' => Http::response(['code' => 0, 'data' => ['handle' => 'AB1-NL']]),
            '*/domains*'   => Http::response(['code' => 0, 'data' => ['results' => [[
                'id' => 902, 'status' => 'ACT',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]]),
        ]);

        $res = $this->register($d);

        $this->assertTrue($res['ok'], 'دامنه ثبت شده و مالِ مشتری است؛ zone نساخته کارِ دستیِ اعلام‌شده است');
        $this->assertSame('active', $d->fresh()->status);
    }

    public function test_no_configured_server_is_calm_but_reported(): void
    {
        Setting::put('domain_zone_server_id', null);
        Setting::put('domain_zone_ip', null);
        $this->fake();

        $d = $this->domain(['status' => 'active', 'provision_status' => 'done']);

        $res = app(DomainZoneProvisioner::class)->ensure($d);

        $this->assertFalse($res['ok']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/json-api/adddns'));
    }

    /** برگشتِ NS به پیش‌فرض از پنل هم zone را تضمین می‌کند */
    public function test_switching_back_to_default_ns_ensures_the_zone(): void
    {
        $d = $this->domain([
            'status' => 'active', 'provision_status' => 'done', 'op_id' => 903,
            'name_servers' => ['ns1.other.io', 'ns2.other.io'],
        ]);

        $this->fake([
            '*/domains/903*' => Http::response(['code' => 0, 'data' => ['id' => 903]]),
        ]);

        $this->actingAs($d->customer, 'customer')
            ->post('/account/domains/'.$d->id.'/nameservers', [
                'ns' => ['ns1.servernet.cloud', 'ns2.servernet.cloud'],
            ])->assertRedirect();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/json-api/adddns'));
    }
}
