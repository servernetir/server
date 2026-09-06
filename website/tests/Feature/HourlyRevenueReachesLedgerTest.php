<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Finance\BusinessLedger;
use App\Services\Reports\BusinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درآمدِ سرورِ ابریِ ساعتی باید به دفترِ مالی برسد.
 *
 * ═══ حفره‌ای که ممیزیِ شهریور ۱۴۰۵ پیدا کرد ═══
 *
 * 🔴 مترِ ساعتی نه فاکتور می‌سازد نه `Payment`؛ مستقیم از `credit_ledger` کم
 * می‌کند. `recordPayment()` به پرداختِ متصل‌به‌فاکتور نیاز دارد و
 * `recordCreditSale()` به فاکتور — پس هیچ‌کدام این مسیر را نمی‌گرفت و کلِ
 * درآمدِ خطِ ساعتی از `/admin/finance` غایب بود. هزینهٔ همان سرورها ثبت
 * می‌شد، پس دفتر این خط را **زیان‌ده** نشان می‌داد.
 *
 * ⚠️ هیچ تماسِ واقعیِ API — همان قاعدهٔ CloudFairHourlyMeteringTest.
 */
class HourlyRevenueReachesLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function customer(int $irt): Customer
    {
        $c = Customer::create([
            'email' => 'hr'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
            'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);

        return $c;
    }

    private function service(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT',
            'price' => self::RATE * 720, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => self::RATE, 'status' => 'active', 'activated_at' => now()->subDay(),
            'provision_status' => 'done', 'on_credit_out' => 'suspend',
            'last_metered_at' => now()->subHour(),
        ], $over));
    }

    private function machine(Service $s): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'aeza', 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.0.0.7',
            'status' => 'running', 'ready_notified_at' => now()->subDays(10),
        ]);
    }

    private function ledger(): BusinessLedger
    {
        return app(BusinessLedger::class);
    }

    // ───────────────────────── تست‌ها ─────────────────────────

    public function test_an_hourly_charge_becomes_revenue_in_the_ledger(): void
    {
        $c = $this->customer(1_000_000);
        $this->machine($this->service($c));

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(self::RATE, $this->ledger()->summary()['revenue'],
            'یک ساعت کسر شد، پس همان مبلغ باید درآمد باشد');
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * کلیدِ یکتای دفتر (منبع، نوع) است. اگر منبع را `Service` می‌گذاشتیم،
     * کسرِ ساعتِ دوم با ردیفِ اول برخورد می‌کرد و `firstOrCreate` **بی‌صدا**
     * چیزی نمی‌نوشت — یعنی فقط ساعتِ اول درآمد می‌شد و بقیه گم.
     */
    public function test_each_hour_is_its_own_revenue_row(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->service($c);
        $this->machine($s);

        $this->artisan('cloud:meter')->assertOk();

        // ساعتِ بعد
        $s->fresh()->update(['last_metered_at' => now()->subHour()]);
        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(2, BusinessEntry::where('kind', 'revenue')->count());
        $this->assertSame(self::RATE * 2, $this->ledger()->summary()['revenue']);
    }

    /** اجرای دوبارهٔ کرون روی همان کسر نباید درآمد را دو برابر کند. */
    public function test_running_the_meter_twice_does_not_double_the_revenue(): void
    {
        $c = $this->customer(1_000_000);
        $this->machine($this->service($c));

        $this->artisan('cloud:meter')->assertOk();
        $this->artisan('cloud:meter')->assertOk();   // هنوز یک ساعت نشده

        $this->assertSame(self::RATE, $this->ledger()->summary()['revenue']);
    }

    /**
     * ⚠️ شارژِ کیفِ پول درآمد نیست — بدهی است تا وقتی خرج شود. اگر این‌جا
     * ثبت می‌شد، هم شارژ و هم مصرفش شمرده می‌شدند: درآمدِ دو برابر.
     */
    public function test_a_topup_never_becomes_revenue(): void
    {
        $c = $this->customer(1_000_000);
        $topup = CreditEntry::where('customer_id', $c->id)->where('reason', 'topup')->first();

        $this->ledger()->recordCreditSpend($topup);

        $this->assertSame(0, $this->ledger()->summary()['revenue']);
    }

    /**
     * ⚠️ `invoice_payment` از مسیرِ رسمیِ `Payment` رد می‌شود و `recordPayment`
     * درآمدش را می‌نویسد. ثبتِ دوبارهٔ آن از این‌جا یعنی درآمدِ دو برابر.
     */
    public function test_an_invoice_paid_from_credit_is_not_counted_here(): void
    {
        $c = $this->customer(1_000_000);

        $spend = CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -250_000,
            'balance_after' => 750_000, 'reason' => 'invoice_payment',
            'source_type' => Customer::class, 'source_id' => $c->id, 'note' => 'test',
        ]);

        $this->ledger()->recordCreditSpend($spend);

        $this->assertSame(0, $this->ledger()->summary()['revenue']);
    }

    /**
     * گزارشِ «درآمدی که به دفتر نرسیده» باید خودبه‌خود خالی شود.
     *
     * تا دیروز کلِ درآمدِ ساعتی را می‌شمرد. حالا که همان مبلغ در دفتر هم
     * هست، اگر باز هم بشمارد خواننده یک عدد را دو بار می‌بیند.
     */
    public function test_the_report_stops_counting_what_the_ledger_now_has(): void
    {
        $c = $this->customer(1_000_000);
        $this->machine($this->service($c));

        $this->artisan('cloud:meter')->assertOk();

        $hourly = app(BusinessReport::class)->forecast(30)['incoming']['hourly'];

        $this->assertSame(0, $hourly['total'],
            'ردیفی که دفتر دیده، دیگر «نرسیده به دفتر» نیست');
        $this->assertFalse($hourly['has_any']);
    }

    /** ولی کسرِ قدیمی که ردیفِ دفتری ندارد، باید همچنان دیده شود. */
    public function test_the_report_still_shows_a_charge_the_ledger_never_saw(): void
    {
        $c = $this->customer(1_000_000);

        // کسرِ پیش از این تغییر — بدونِ ردیفِ دفتری
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -40_000,
            'balance_after' => 960_000, 'reason' => 'cloud_hourly',
            'source_type' => Customer::class, 'source_id' => $c->id, 'note' => 'کسرِ قدیمی',
        ]);

        $hourly = app(BusinessReport::class)->forecast(30)['incoming']['hourly'];

        $this->assertSame(40_000, $hourly['total']);
        $this->assertTrue($hourly['has_any']);
    }

    /** تبدیلِ ساعتی به ماهانه هم پولِ همین کسب‌وکار است. */
    public function test_the_hourly_to_monthly_conversion_is_revenue_too(): void
    {
        $c = $this->customer(1_000_000);

        $entry = CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -600_000,
            'balance_after' => 400_000, 'reason' => 'cloud_hourly_convert',
            'source_type' => Customer::class, 'source_id' => $c->id, 'note' => 'تبدیل',
        ]);

        $this->ledger()->recordCreditSpend($entry);

        $this->assertSame(600_000, $this->ledger()->summary()['revenue']);
    }
}
