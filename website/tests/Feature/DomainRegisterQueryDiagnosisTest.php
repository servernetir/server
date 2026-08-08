<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مسیرِ پنل: `/account/domains?register=…`
 *
 * ⚠️ **این فایل قبلاً باگ را قفل کرده بود، نه رفتارِ درست را.**
 *
 * نسخهٔ پیشین تشخیصی بود و ادعاهایش عیناً همان خرابی را تثبیت می‌کرد:
 * `test_an_empty_registrar_response_renders_as_already_registered` و
 * `test_a_transport_failure_renders_the_same_as_taken` هر دو با
 * `assertGreaterThan(0, substr_count($html, 'ثبت‌شده'))` **موفق** می‌شدند
 * دقیقاً وقتی سایت به مشتری دروغ می‌گفت. تستی که خرابی را می‌سنجد و سبز
 * می‌ماند، محافظِ باگ است نه محافظِ کاربر.
 *
 * حالا همان سناریوها هستند، ولی ادعاها روی **دادهٔ ویو** است نه پیل‌های HTML:
 * پیل‌ها در `resources/views/account/domains.blade.php` جدا اصلاح می‌شوند، و
 * تستی که به متنِ آن ویو گره بخورد، با همان اصلاح می‌شکند.
 *
 * هیچ تماسِ واقعی با رجیسترار زده نمی‌شود (فقط `Http::swap` + یک `Http::fake`).
 */
class DomainRegisterQueryDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
    }

    /** یک استابِ تنها — استابِ دوم روی اولی سایه می‌اندازد و تست را کور می‌کند. */
    private function fakeCheck(mixed $checkResponse): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't'], 'desc' => '']),
            '*/domains/check*' => $checkResponse,
        ]);
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'ا', 'last_name' => 'ا',
        ]);

        return $c;
    }

    /**
     * مسیرِ پنل هیچ فهرستِ پسوندی نمی‌دهد، پس همهٔ `SUGGEST_TLDS` در **یک**
     * درخواست می‌رود. این‌جا فقط ثبت می‌شود که آن عدد چقدر است — اگر روزی
     * تصمیم گرفتیم دسته‌بندی کنیم، این تست باید آگاهانه عوض شود.
     */
    public function test_the_panel_path_asks_for_every_suggested_tld_in_one_call(): void
    {
        $this->fakeCheck(Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => []]]));

        $out = app(DomainSearch::class)->search('zhina.shop');

        $suggest = (new \ReflectionClass(DomainSearch::class))->getConstant('SUGGEST_TLDS');

        $sent = null;
        Http::assertSent(function ($r) use (&$sent) {
            if (str_contains($r->url(), '/domains/check')) {
                $sent = count($r->data()['domains'] ?? []);
            }

            return true;
        });

        $this->assertSame(count($suggest), count($out));
        $this->assertSame(count($suggest), $sent);
    }

    /**
     * 🔴 ادعایی که وارونه شد.
     *
     * پاسخِ «موفق ولی بی‌ردیف» یعنی رجیسترار هیچ‌چیز نگفت. هر ردیف باید
     * «استعلام نشد» باشد — نه «ثبت‌شده».
     */
    public function test_an_empty_registrar_response_is_not_reported_as_registered(): void
    {
        $this->fakeCheck(Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => []]]));

        $results = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.shop']))
            ->assertOk()
            ->viewData('results');

        $this->assertNotEmpty($results);

        foreach ($results as $r) {
            $this->assertSame('unknown', $r['status'], $r['domain']);
            $this->assertSame('no_response', $r['reason']);
            $this->assertFalse($r['available']);
            $this->assertFalse($r['orderable']);
        }
    }

    /** همان، برای خطای واقعیِ رجیسترار (HTTP 500 با کد در بدنه). */
    public function test_a_registrar_failure_is_not_reported_as_registered(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication failed'], 500));

        $results = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.shop']))
            ->assertOk()
            ->viewData('results');

        $this->assertNotEmpty($results);

        foreach ($results as $r) {
            $this->assertSame('unknown', $r['status'], $r['domain']);
            $this->assertSame('lookup_failed', $r['reason']);
        }
    }

    /**
     * شکلِ شیءِ `{name, extension}` قبلاً «Array to string conversion» پرتاب
     * می‌کرد؛ کنترلر می‌بلعیدش و صفحه «نتیجه‌ای پیدا نشد» می‌شد.
     */
    public function test_an_object_shaped_domain_key_does_not_wipe_the_results(): void
    {
        $this->fakeCheck(Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => [[
            'domain' => ['name' => 'zhina', 'extension' => 'shop'],
            'status' => 'free',
            'price'  => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']],
        ]]]]));

        $out = app(DomainSearch::class)->search('zhina.shop', ['shop']);

        $this->assertCount(1, $out);
        $this->assertSame('zhina.shop', $out[0]['domain']);
        $this->assertTrue($out[0]['available']);
    }
}
