<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\RegistryHandle;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ثبتِ دامنه — مسیرِ پول.
 *
 * این تست‌ها سه ادعا را نگه می‌دارند که اگر بشکنند، پولِ واقعی می‌سوزد:
 * دامنه دو بار خریده نشود، شکست بی‌صدا نماند، و شناسهٔ مالک بازاستفاده شود.
 */
class DomainRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // بدونِ اعتبارنامه، کلاینت `enabled()` نیست و هر تست به «پیکربندی نشده»
        // می‌خورد — یعنی هیچ‌کدام از ادعاهای واقعی سنجیده نمی‌شد.
        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);
    }

    /** ⚠️ کارخانه از نو: یک `Http::fake()`ِ همه‌گیرِ قبلی هر استابِ بعدی را بی‌اثر می‌کند */
    private function fake(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(array_merge(['*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']])], $stubs));
    }

    private function ok(array $data = [])
    {
        return Http::response(['code' => 0, 'desc' => '', 'data' => $data]);
    }

    /** اوپن‌پروایدر روی خطا هم HTTP 500 می‌دهد — خطای واقعی در فیلد code است */
    private function err(int $code, string $desc)
    {
        return Http::response(['code' => $code, 'desc' => $desc], 500);
    }

    private function customer(bool $withProfile = true, array $over = []): Customer
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($withProfile) {
            CustomerProfile::create(array_merge([
                'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
                'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
                'country' => 'IR', 'province' => 'تهران', 'city' => 'تهران',
                'address' => 'خیابان نمونه، کوچهٔ آزمایش', 'postal_code' => '1234567890',
                'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
            ], $over));
        }

        return $c;
    }

    private function domain(Customer $c, array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id' => $c->id, 'domain' => 'example.com', 'sld' => 'example', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending', 'provision_status' => 'pending',
            'period_years' => 1, 'price_toman' => 2500000,
        ], $over));
    }

    private function registrar(): DomainRegistrar
    {
        return app(DomainRegistrar::class);
    }

    // ═══════════════ شناسهٔ مالک ═══════════════

    public function test_a_handle_is_created_once_and_then_reused(): void
    {
        $c = $this->customer();
        $this->fake(['*/customers*' => $this->ok(['handle' => 'AB123-NL'])]);

        $first  = $this->registrar()->handleFor($c->defaultProfile());
        $second = $this->registrar()->handleFor($c->defaultProfile());

        $this->assertTrue($first['ok']);
        $this->assertSame('AB123-NL', $second['handle']);
        $this->assertSame(1, RegistryHandle::where('registry', 'openprovider')->count(),
            'شناسهٔ دوم یعنی WHOISِ دامنه‌های قدیمی برای همیشه کهنه می‌مانَد');

        // ⚠️ آنچه فرستادیم باید ذخیره شده باشد، وگرنه هیچ‌وقت نمی‌فهمیم handle
        // کهنه شده و باید به‌روز شود. `$fillable` نداشتنش یعنی حذفِ بی‌صدا.
        $saved = RegistryHandle::first();
        $this->assertIsArray($saved->sent_data);
        $this->assertSame('احسان', data_get($saved->sent_data, 'name.first_name'));
    }

    /** دادهٔ شخصیِ مالک نباید در JSON بیرون برود */
    public function test_the_owner_payload_never_leaks_through_serialization(): void
    {
        $c = $this->customer();
        $this->fake(['*/customers*' => $this->ok(['handle' => 'AB123-NL'])]);
        $this->registrar()->handleFor($c->defaultProfile());

        $json = RegistryHandle::first()->toJson();

        $this->assertStringNotContainsString('sent_data', $json);
        $this->assertStringNotContainsString('خیابان نمونه', $json);
    }

    /**
     * 🔴 دادهٔ ناقص باید **این‌جا** رد شود، نه نزدِ رجیسترار.
     *
     * خطای «field required» انگلیسیِ رجیسترار به مشتری نمی‌گوید چه چیزی را
     * باید پر کند — و بدتر، تلاشِ ناموفق را روی حسابِ ما ثبت می‌کند.
     */
    public function test_an_incomplete_profile_is_refused_before_any_api_call(): void
    {
        $c = $this->customer(true, ['address' => '', 'city' => '']);
        $this->fake(['*' => $this->err(400, 'should never be called')]);

        $res = $this->registrar()->handleFor($c->defaultProfile());

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('ناقص', $res['message']);
        $this->assertSame(0, RegistryHandle::count());
    }

    /** شمارهٔ فارسی باید به سه‌تکهٔ موردِ انتظارِ رجیسترار تبدیل شود */
    public function test_a_persian_digit_phone_is_split_correctly(): void
    {
        $c = $this->customer(true, ['mobile' => '۰۹۱۲۳۴۵۶۷۸۹']);

        $payload = $this->registrar()->profileToCustomer($c->defaultProfile());

        $this->assertSame('+98', $payload['phone']['country_code']);
        $this->assertSame('912', $payload['phone']['area_code']);
        $this->assertSame('3456789', $payload['phone']['subscriber_number']);
    }

    // ═══════════════ ثبت ═══════════════

    public function test_a_successful_registration_activates_the_domain(): void
    {
        $c = $this->customer();
        $d = $this->domain($c);

        $this->fake([
            '*/customers*'      => $this->ok(['handle' => 'AB123-NL']),
            '*/domains/check*'  => $this->ok([]),
            '*/domains/9001*'   => $this->ok(['id' => 9001, 'expiration_date' => '2027-08-04 00:00:00']),
            '*/domains*'        => $this->ok(['id' => 9001, 'status' => 'ACT', 'results' => []]),
        ]);

        $res = $this->registrar()->register($d);

        $this->assertTrue($res['ok'], $res['message']);
        $d->refresh();
        $this->assertSame('active', $d->status);
        $this->assertSame('done', $d->provision_status);
        $this->assertNotNull($d->expires_at);
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل: دامنه‌ای که رجیسترار از قبل دارد، **دوباره
     * خریده نمی‌شود**.
     *
     * سناریوی واقعی: تلاشِ اول timeout می‌خورد ولی ثبت انجام شده. بدونِ استعلامِ
     * «قبلاً هست؟» تلاشِ دوم پولِ دوم را خرج می‌کند.
     */
    public function test_an_already_registered_domain_is_adopted_not_bought_again(): void
    {
        $c = $this->customer();
        $d = $this->domain($c);

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->ok(['results' => [[
                'id' => 777, 'domain' => ['name' => 'example', 'extension' => 'com'],
                'expiration_date' => '2027-01-01 00:00:00',
            ]]]),
        ]);

        $this->assertTrue($this->registrar()->register($d)['ok']);

        $d->refresh();
        $this->assertSame(777, (int) $d->op_id);
        $this->assertSame('active', $d->status);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/domains'));
    }

    /**
     * 🔴 قفلِ اتمی: دو اجرای هم‌زمان، یک ثبت.
     *
     * کرونِ هر-دقیقه و کلیکِ دستیِ مدیر می‌توانند هم‌زمان شروع کنند.
     */
    public function test_a_second_concurrent_run_cannot_claim_the_same_domain(): void
    {
        $c = $this->customer();
        $d = $this->domain($c);

        // شبیه‌سازیِ اجرای اول که قفل را برداشته و هنوز تمام نشده
        DB::table('domains')->where('id', $d->id)->update(['provision_status' => 'running']);

        $this->fake(['*' => $this->err(400, 'should never be called')]);

        $res = $this->registrar()->register($d->fresh());

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('اجرای دیگری', $res['message']);
    }

    /** شکست نباید بی‌صدا بماند — پیام ذخیره می‌شود و دامنه فعال نمی‌شود */
    public function test_a_failure_is_recorded_and_never_marks_the_domain_active(): void
    {
        $c = $this->customer();
        $d = $this->domain($c);

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->err(307, 'Domain is already registered by someone else'),
        ]);

        $res = $this->registrar()->register($d);

        $this->assertFalse($res['ok']);
        $d->refresh();
        $this->assertSame('pending', $d->status);
        $this->assertNotSame('done', $d->provision_status);
        $this->assertStringContainsString('already registered', (string) $d->provision_error);
        $this->assertSame(1, $d->provision_tries);
    }

    /** بعد از چند تلاشِ ناموفق، تصمیم با آدم است نه کرون */
    public function test_repeated_failures_escalate_to_a_human(): void
    {
        $c = $this->customer();
        $d = $this->domain($c, ['provision_tries' => 2]);

        $this->fake([
            '*/customers*' => $this->ok(['handle' => 'AB123-NL']),
            '*/domains*'   => $this->err(307, 'nope'),
        ]);

        $this->registrar()->register($d);

        $this->assertSame('manual', $d->fresh()->provision_status);
    }

    /** مشتریِ بی‌پروفایل: بدونِ مالک نمی‌شود دامنه ثبت کرد */
    public function test_a_customer_without_a_profile_goes_to_the_manual_queue(): void
    {
        $d = $this->domain($this->customer(withProfile: false));
        $this->fake(['*' => $this->ok([])]);

        $res = $this->registrar()->register($d);

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['manual']);
        $this->assertSame('manual', $d->fresh()->provision_status);
    }

    // ═══════════════ کرون ═══════════════

    /** کرون فقط `pending` را برمی‌دارد — `manual` دستِ آدم می‌مانَد */
    public function test_the_cron_never_picks_up_a_domain_parked_for_manual_review(): void
    {
        $c = $this->customer();
        $this->domain($c, ['provision_status' => 'manual', 'domain' => 'manual.com', 'sld' => 'manual']);

        $this->fake(['*' => $this->err(400, 'should never be called')]);

        $this->artisan('domains:provision')->assertSuccessful();

        $this->assertSame('manual', Domain::where('sld', 'manual')->value('provision_status'));
    }

    // ═══════════════ نشتِ داده ═══════════════

    /** بهایِ تمام‌شده و شناسهٔ رجیسترار نباید در JSONِ مشتری ظاهر شوند */
    public function test_cost_and_registrar_ids_never_leak_through_serialization(): void
    {
        $c = $this->customer();
        $d = $this->domain($c, ['cost_amount' => 950, 'cost_currency' => 'EUR', 'op_id' => 42, 'owner_handle' => 'AB1-NL']);

        $json = $d->fresh()->toJson();

        foreach (['cost_amount', 'cost_currency', 'op_id', 'owner_handle'] as $key) {
            $this->assertStringNotContainsString($key, $json, "«{$key}» دادهٔ داخلی است");
        }
        $this->assertStringNotContainsString('AB1-NL', $json);
    }

    // ═══════════════ تمدید ═══════════════

    public function test_renewal_takes_the_new_expiry_from_the_registrar(): void
    {
        $c = $this->customer();
        $d = $this->domain($c, ['status' => 'active', 'provision_status' => 'done', 'op_id' => 555,
            'expires_at' => now()->addDays(10)]);

        $this->fake([
            '*/domains/555/renew*' => $this->ok([]),
            '*/domains/555*'       => $this->ok(['id' => 555, 'expiration_date' => '2028-03-01 00:00:00']),
        ]);

        $this->assertTrue($this->registrar()->renew($d)['ok']);
        $this->assertSame('2028-03-01', $d->fresh()->expires_at->toDateString());
    }

    public function test_renewal_without_a_registrar_id_fails_cleanly(): void
    {
        $d = $this->domain($this->customer(), ['status' => 'active', 'op_id' => null]);
        $this->fake(['*' => $this->ok([])]);

        $this->assertFalse($this->registrar()->renew($d)['ok']);
    }
}
