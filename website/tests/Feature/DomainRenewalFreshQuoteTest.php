<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Setting;
use App\Services\Domain\DomainRenewalInvoicer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * استعلامِ تازهٔ قیمتِ تمدید در لحظهٔ صدورِ فاکتور.
 *
 * ═══ قاعدهٔ کارفرما (۳ شهریور ۱۴۰۵) ═══
 *
 * «تمدید دامنه قیمتش با ایجاد دامنه فرق می‌کند؛ باید حتماً استعلام بشود
 * دوباره.» کفِ ارزی فقط جهشِ **نرخِ ارز** را می‌گرفت؛ اگر خودِ رجیسترار
 * قیمتِ یوروییِ تمدیدِ پسوند را بالا می‌برد، عددِ ذخیره‌شده کهنه می‌مانْد.
 *
 * حالا `effectivePerYear` سه منبع دارد و بلندترین برنده است:
 * ذخیره‌شده، استعلامِ تازهٔ پسوند (TldPriceBook، کش ۶ساعته)، کفِ ارزی.
 *
 * ⚠️ استعلام قیمت را فقط **بالا** می‌برد: پایین‌آوردن برای پرمیوم یعنی
 * فروشِ زیرِ قیمت (استعلامِ پسوندی پرمیوم را نمی‌بیند) و برای بقیه تصمیمِ
 * مالیِ کارفرماست.
 */
class DomainRenewalFreshQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        Setting::put('pricing_rate_override', '100000');    // هر یورو ۱۰۰٬۰۰۰ تومان
    }

    /** استعلامِ پسوند: نامِ کاوشی آزاد، با قیمتِ ثبت و تمدیدِ جدا */
    private function fakeProbe(float $createEur, float $renewEur): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain' => 'sn7price9check4base.shop',
                'status' => 'free',
                'price'  => ['reseller' => [
                    'price' => $createEur, 'currency' => 'EUR',
                    'renewal' => ['price' => $renewEur, 'currency' => 'EUR'],
                ]],
            ]]]]),
            'alanchand.com/*'  => Http::response('', 500),
        ]);
    }

    private function domain(array $over = []): Domain
    {
        $c = Customer::create([
            'email' => 'fq'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Domain::create(array_merge([
            'customer_id'      => $c->id,
            'domain'           => 'fq'.random_int(1000, 99999).'.shop',
            'sld'              => 'x', 'tld' => 'shop',
            'status'           => 'active',
            'provision_status' => 'done',
            'period_years'     => 1,
            'price_toman'      => 190_000,
            'renew_toman'      => 200_000,          // فریزشدهٔ روزِ خرید — کهنه
            'cost_amount'      => 190,
            'cost_renew_amount' => 300,             // کف: €3×۱۰۰k×۱٫۰۸ = ۳۲۴٬۰۰۰
            'cost_currency'    => 'EUR',
            'op_id'            => 777,
            'expires_at'       => now()->addDays(10),
        ], $over));
    }

    /** 🔴 قلب قاعده: رجیسترار قیمتِ تمدید را برده بالا → فاکتور با عددِ روز */
    public function test_a_fresh_registrar_price_raises_a_stale_renewal_invoice(): void
    {
        $this->fakeProbe(createEur: 4.90, renewEur: 20.00);   // تمدیدِ روز: ۲٬۰۰۰٬۰۰۰

        $d = $this->domain();
        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(2_000_000, (int) $inv->subtotal,
            'فاکتورِ تمدید با قیمتِ کهنهٔ روزِ خرید صادر شد — استعلامِ دوباره نشد');
    }

    /** عددِ تازه روی خودِ ردیف هم می‌نشیند تا صفحه و فاکتور یک حرف بزنند */
    public function test_the_fresh_price_is_written_back_to_the_domain_row(): void
    {
        $this->fakeProbe(4.90, 20.00);

        $d = $this->domain();
        app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(2_000_000, (int) $d->fresh()->renew_toman);
    }

    public function test_a_multi_year_invoice_multiplies_the_fresh_price(): void
    {
        $this->fakeProbe(4.90, 20.00);

        $inv = app(DomainRenewalInvoicer::class)->issue($this->domain(), 3);

        $this->assertSame(6_000_000, (int) $inv->subtotal);
    }

    /**
     * ⚠️ استعلامِ ارزان‌تر قیمت را پایین **نمی‌آورد** — محافظِ پرمیوم
     * (استعلامِ پسوندی قیمتِ پرمیومِ خودِ دامنه را نمی‌بیند) + قاعدهٔ
     * «ارزان‌کردن تصمیمِ کارفرماست».
     */
    public function test_a_cheaper_fresh_quote_never_lowers_the_price(): void
    {
        $this->fakeProbe(4.90, 1.00);                        // تمدیدِ استعلامی: ۱۰۰٬۰۰۰

        $d = $this->domain(['renew_toman' => 9_000_000]);    // پرمیومِ ذخیره‌شده
        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(9_000_000, (int) $inv->subtotal,
            'استعلامِ پسوندی قیمتِ دامنهٔ پرمیوم را پایین کشید — فروشِ زیرِ قیمت');
    }

    /** رجیسترار در دسترس نیست → پشتیبان‌ها: ذخیره‌شده + کفِ ارزی */
    public function test_registrar_down_falls_back_to_stored_plus_floor(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 500)]);

        $inv = app(DomainRenewalInvoicer::class)->issue($this->domain(), 1);

        // ذخیره ۲۰۰k < کفِ €3×۱۰۰k×۱٫۰۸ = ۳۲۴٬۰۰۰
        $this->assertSame(324_000, (int) $inv->subtotal,
            'با رجیسترارِ خاموش، تمدید نباید بی‌محافظ یا مسدود شود');
    }
}
