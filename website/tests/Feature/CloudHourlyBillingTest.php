<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فروشِ ساعتیِ سرورِ ابری — کسر از کیفِ پول، idempotency، اتمامِ اعتبار.
 *
 * قاعده‌ها: حداقلِ ۲۴ ساعت اعتبار برای شروع · بدونِ حداقلِ مصرف · فقط ساعتِ
 * گذشته کسر شود · هرگز بدونِ اعتبارِ کافی کسر نشود · دو اجرا در یک ساعت دوبار
 * کسر نکند.
 */
class CloudHourlyBillingTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithCredit(int $irt): Customer
    {
        $c = Customer::create([
            'email' => 'h'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($irt !== 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
                'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
                'source_id' => $c->id, 'note' => 'test',
            ]);
        }

        return $c;
    }

    private function hourlyService(Customer $c, int $rate, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT',
            'price' => $rate * 720, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => $rate, 'status' => 'active', 'activated_at' => now(),
            'last_metered_at' => now()->subHours(1), 'on_credit_out' => 'suspend',
        ], $over));
    }

    // ═══════════ نرخِ ساعتی ═══════════

    public function test_hourly_rate_is_monthly_over_720_rounded_up(): void
    {
        $plan = new CloudPlan(['price_irt' => 3_600_000, 'price_eur_cents' => 720]);

        // ۳٬۶۰۰٬۰۰۰ ÷ ۷۲۰ = ۵۰۰۰ تومان/ساعت
        $this->assertSame(5000, $plan->hourlyIrt());
        // ۷۲۰ سنت ÷ ۷۲۰ = ۱ سنت/ساعت
        $this->assertSame(1, $plan->hourlyEurCents());
        // حداقلِ شروع = ۲۴ ساعت
        $this->assertSame(5000 * 24, $plan->hourlyStartMinIrt());
    }

    // ═══════════ کسرِ ساعتی ═══════════

    public function test_meter_deducts_one_hour_from_credit(): void
    {
        $c = $this->customerWithCredit(100_000);
        $s = $this->hourlyService($c, 5000);

        $this->artisan('cloud:meter')->assertOk();

        $this->assertSame(95_000, $c->creditBalance('IRT'));
        $this->assertDatabaseHas('credit_ledger', ['source_id' => $s->id, 'amount' => -5000, 'reason' => 'cloud_hourly']);
        $this->assertSame('active', $s->fresh()->status);
    }

    /** دو اجرا در یک ساعت نباید دوبار کسر کند */
    public function test_meter_is_idempotent_within_the_hour(): void
    {
        $c = $this->customerWithCredit(100_000);
        $this->hourlyService($c, 5000);

        $this->artisan('cloud:meter');
        $this->artisan('cloud:meter');   // بلافاصله دوباره

        $this->assertSame(95_000, $c->creditBalance('IRT'), 'فقط یک ساعت باید کسر شده باشد');
    }

    /** اگر اعتبار برای یک ساعت هم نباشد، کسر نمی‌کند و تعلیق می‌کند */
    public function test_meter_suspends_when_credit_insufficient(): void
    {
        $c = $this->customerWithCredit(3000);          // < نرخِ ۵۰۰۰
        $s = $this->hourlyService($c, 5000);

        $this->artisan('cloud:meter');

        $this->assertSame(3000, $c->creditBalance('IRT'), 'نباید کسر شود');
        $this->assertSame('suspended', $s->fresh()->status);
    }

    /** on_credit_out=terminate → حذفِ سرویس هنگام اتمامِ اعتبار */
    public function test_meter_terminates_when_policy_is_terminate(): void
    {
        $c = $this->customerWithCredit(1000);
        $s = $this->hourlyService($c, 5000, ['on_credit_out' => 'terminate']);

        $this->artisan('cloud:meter');

        $this->assertSame('terminated', $s->fresh()->status);
    }

    /** on_credit_out=convert + اعتبارِ یک ماه → تبدیل به ماهانه */
    public function test_meter_converts_to_monthly_when_policy_is_convert(): void
    {
        // نرخ ۵۰۰۰ → ماهانه price=۳٬۶۰۰٬۰۰۰. اعتبار ۴٬۰۰۰٬۰۰۰ (کم‌تر از یک ساعتِ بعدی نیست
        // ولی برای تبدیل کافی است). برای اینکه به creditOut برسیم، نرخ را بالا می‌بریم.
        $c = $this->customerWithCredit(4_000_000);
        $s = $this->hourlyService($c, 5_000_000, ['price' => 3_600_000, 'on_credit_out' => 'convert']);

        $this->artisan('cloud:meter');

        $fresh = $s->fresh();
        $this->assertSame('cycle', $fresh->billing_mode);
        $this->assertSame('monthly', $fresh->cycle);
        $this->assertSame(400_000, $c->creditBalance('IRT'), 'یک ماه (۳٬۶۰۰٬۰۰۰) کسر شده');
    }

    /** سرویسِ تعلیق‌شده که دوباره شارژ شد → روشن می‌شود */
    public function test_suspended_service_resumes_on_topup(): void
    {
        $c = $this->customerWithCredit(50_000);
        $s = $this->hourlyService($c, 5000, ['status' => 'suspended', 'suspended_at' => now()->subHour()]);

        $this->artisan('cloud:meter');

        $this->assertSame('active', $s->fresh()->status);
    }

    /** جبرانِ ساعت‌های ازدست‌رفته سقف دارد (۴۸ ساعت) */
    public function test_catchup_is_capped(): void
    {
        $c = $this->customerWithCredit(10_000_000);
        $this->hourlyService($c, 5000, ['last_metered_at' => now()->subHours(100)]);

        $this->artisan('cloud:meter');

        // حداکثر ۴۸ ساعت × ۵۰۰۰ = ۲۴۰٬۰۰۰ کسر
        $this->assertSame(10_000_000 - 240_000, $c->creditBalance('IRT'));
    }

    /** سرویسِ ماهانه (غیرساعتی) نباید متر شود */
    public function test_monthly_service_is_not_metered(): void
    {
        $c = $this->customerWithCredit(100_000);
        Service::create([
            'customer_id' => $c->id, 'name' => 'ماهانه', 'currency_code' => 'IRT', 'price' => 500_000,
            'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active', 'activated_at' => now(),
        ]);

        $this->artisan('cloud:meter');

        $this->assertSame(100_000, $c->creditBalance('IRT'), 'سرویسِ ماهانه نباید کسر شود');
    }
}
