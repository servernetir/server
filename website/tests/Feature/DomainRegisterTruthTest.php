<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صداقتِ ثبت — «هر جوابی = موفق» ممنوع.
 *
 * ═══ دو باگِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 (الف) `succeed()` وضعیتِ پاسخِ رجیسترار را دور می‌ریخت: هر ردیفی که
 * `findDomain` پیدا می‌کرد — حتی REQ (در انتظار) یا FAI (شکست‌خورده) —
 * «active» اعلام و پیامِ «با موفقیت ثبت شد» به مشتری می‌رفت. اطمینانِ دروغ.
 *
 * 🔴 (ب) اگر خواندنِ جزئیات شکست می‌خورد، تاریخِ انقضا **جعل** می‌شد
 * (`now()+سال‌ها`) و کرونِ چرخهٔ عمر بر پایهٔ همان تاریخِ ساختگی فاکتورِ
 * تمدید صادر می‌کرد.
 */
class DomainRegisterTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);
    }

    private function fake(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(array_merge(['*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']])], $stubs));
    }

    private function ok(array $data = [])
    {
        return Http::response(['code' => 0, 'desc' => '', 'data' => $data]);
    }

    private function err(int $code, string $desc)
    {
        return Http::response(['code' => $code, 'desc' => $desc], 500);
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'tt'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'احسان', 'last_name' => 'ابراهیمی', 'email' => $c->email,
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'mobile' => '09123456789',
        ]);

        return $c;
    }

    private function domain(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id' => $this->customer()->id,
            'domain' => 'truth'.random_int(1000, 99999).'.com',
            'sld' => 'truth'.random_int(1000, 99999), 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'pending', 'period_years' => 1,
            'price_toman' => 2_000_000,
        ], $over));
    }

    private function registrar(): \App\Services\Domain\DomainRegistrar
    {
        return app(\App\Services\Domain\DomainRegistrar::class);
    }

    // ═══════════════ (الف) وضعیتِ رجیستری قانون است ═══════════════

    /** ردیفِ «در انتظارِ رجیستری» (REQ) هرگز «فعال» اعلام نمی‌شود */
    public function test_a_req_row_at_the_registry_is_not_declared_active(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->ok(['results' => [[
                'id' => 500, 'status' => 'REQ',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]),
        ]);

        $res = $this->registrar()->register($d);

        $this->assertFalse($res['ok'], 'REQ موفق اعلام شد — اطمینانِ دروغ به مشتری');
        $d->refresh();
        $this->assertSame('pending', $d->status);
        $this->assertNotSame('done', $d->provision_status);
        $this->assertStringContainsString('رجیستری', (string) $d->provision_error);
    }

    /** ردیفِ FAI نزدِ رجیستری یک شکستِ قطعی است — یکراست صفِ دستی */
    public function test_a_failed_row_at_the_registry_goes_straight_to_the_manual_queue(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->ok(['results' => [[
                'id' => 501, 'status' => 'FAI',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]),
        ]);

        $res = $this->registrar()->register($d);

        $this->assertFalse($res['ok']);
        $d->refresh();
        $this->assertSame('manual', $d->provision_status,
            'FAI با تلاشِ دوباره حل نمی‌شود — باید بی‌درنگ دستِ آدم برسد');
        $this->assertSame(501, (int) $d->op_id, 'شناسهٔ رجیسترار باید بماند تا مدیر پیگیری کند');
        $this->assertSame('pending', $d->status, 'FAI نباید دامنه را فعال کند');
    }

    /** ACT مثلِ قبل فعال می‌شود — رفتارِ سالم نشکند */
    public function test_an_act_row_still_activates(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->ok(['results' => [[
                'id' => 502, 'status' => 'ACT',
                'expiration_date' => '2027-05-01 00:00:00',
                'domain' => ['name' => $d->sld, 'extension' => 'com'],
            ]]]),
        ]);

        $this->assertTrue($this->registrar()->register($d)['ok']);
        $this->assertSame('active', $d->refresh()->status);
    }

    // ═══════════════ (ب) تاریخِ انقضا جعل نمی‌شود ═══════════════

    public function test_a_missing_expiry_stays_null_instead_of_being_invented(): void
    {
        $d = $this->domain();

        $this->fake([
            '*/customers*'    => $this->ok(['handle' => 'AB123-NL']),
            '*/domains/9001*' => $this->err(500, 'detail unavailable'),
            '*/domains*'      => $this->ok(['id' => 9001, 'status' => 'ACT', 'results' => []]),
        ]);

        $this->assertTrue($this->registrar()->register($d)['ok']);

        $d->refresh();
        $this->assertSame('active', $d->status);
        $this->assertNull($d->expires_at,
            'تاریخِ انقضای ساختگی نوشته شد — فاکتورِ تمدید بر پایهٔ دروغ صادر می‌شود');
    }

    /** ردیفِ بی‌تاریخ از چرخهٔ تمدید بیرون است — فاکتورِ الکی نمی‌گیرد */
    public function test_the_lifecycle_never_invoices_a_domain_with_no_expiry(): void
    {
        $this->fake([]);       // اتصال قطع — نباید مهم باشد

        config()->set('services.openprovider.username', null);   // ترمیم هم خاموش

        $d = $this->domain(['status' => 'active', 'provision_status' => 'done', 'expires_at' => null]);

        $this->artisan('domains:lifecycle')->assertExitCode(0);

        $this->assertSame(0, Invoice::where('domain_id', $d->id)->count());
    }

    /** چرخهٔ عمر تاریخِ گمشده را از رجیسترار ترمیم می‌کند — سقف‌دار */
    public function test_the_lifecycle_repairs_a_missing_expiry_from_the_registrar(): void
    {
        $d = $this->domain([
            'status' => 'active', 'provision_status' => 'done',
            'op_id' => 555, 'expires_at' => null,
        ]);

        $this->fake([
            '*/domains/555*' => $this->ok(['id' => 555, 'expiration_date' => '2028-03-01 00:00:00']),
        ]);

        $this->artisan('domains:lifecycle')->assertExitCode(0);

        $this->assertSame('2028-03-01', $d->refresh()->expires_at?->toDateString(),
            'تاریخِ گمشده ترمیم نشد — دامنه بی‌صدا به‌سمتِ انقضا می‌رود');
    }
}
