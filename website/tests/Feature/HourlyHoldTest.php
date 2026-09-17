<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\HourlyHold;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نگهداریِ ۲۴ساعتهٔ سرورِ ساعتیِ خاموش — **از پیش ذخیره‌شده، بدونِ ضرر**.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 *
 * مهلتِ ۲۴ساعته پس از اتمامِ اعتبار رایگان بود؛ روی هتزنر/aeza/OVH/آروان
 * ماشینِ خاموش کامل صورت‌حساب می‌شود، پس هر مشتری‌ای که برنمی‌گشت ۲۴ ساعت
 * اجارهٔ خالص از جیبِ ما بود. کارفرما: «جایی که برای ما هزینه دارد هزینه
 * بگیریم، جایی که ندارد نه… متضرر نشویم اصلاً.»
 *
 * عددها (بهای ۳۷۹ سنت/ماه، یورو ۱۲۰٬۰۰۰ تومان):
 *   نرخِ فروش   = ⌈۵۷۰٬۰۰۰ ÷ ۷۲۰⌉ ⇒ ۸۰۰ تومان/ساعت
 *   نرخِ نگهداری = ⌈۰٫۰۰۵۲۶۴ × ۱۲۰٬۰۰۰⌉₁₀₀ ⇒ ۷۰۰ تومان/ساعت
 *   ذخیره       = ۲۴ × ۷۰۰ = ۱۶٬۸۰۰
 */
class HourlyHoldTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 800;

    private const HOLD = 700;

    private const RESERVE = 24 * 700;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('pricing_rate_override', '120000');
        HourlyHold::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(int $credit = 0): Customer
    {
        $c = Customer::create([
            'email' => 'hold'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($credit > 0) {
            $this->topup($c, $credit);
        }

        return $c;
    }

    private function topup(Customer $c, int $irt): void
    {
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
            'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);
    }

    private function plan(string $provider = 'hetzner', array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-frankfurt'], ['country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        CloudImage::firstOrCreate(['provider' => $provider, 'key' => 'ubuntu-24.04'], [
            'provider_ref' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);

        return CloudPlan::create(array_merge([
            'provider' => $provider, 'provider_ref' => 'cx22-'.$provider.'-'.($over['slug'] ?? 'a'), 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    /** سرورِ ساعتیِ تحویل‌شده روی همین پلن، با ذخیرهٔ هم‌سو */
    private function running(Customer $c, CloudPlan $plan, array $over = []): Service
    {
        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT',
            'price' => 570000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => self::RATE, 'status' => 'active', 'activated_at' => now()->subDay(),
            'last_metered_at' => now()->subHour(), 'on_credit_out' => 'suspend',
            'provision_status' => 'done', 'cloud_plan_id' => $plan->id,
        ], $over));

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => $plan->provider, 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-frankfurt', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.2.0.'.($s->id % 250 + 1),
            'status' => 'running', 'ready_notified_at' => now()->subDays(2),
        ]);

        app(HourlyHold::class)->syncRunning($s->fresh());

        return $s->fresh();
    }

    private function wallet(): Wallet
    {
        return app(Wallet::class);
    }

    // ═══════════════ نرخ ═══════════════

    public function test_the_grace_still_equals_the_minimum_start(): void
    {
        $this->assertSame(CloudPlan::HOURLY_START_MIN_HOURS, HourlyHold::GRACE_HOURS);
    }

    public function test_hold_rate_is_our_cost_rounded_up_and_never_above_the_sell_rate(): void
    {
        $plan = $this->plan();
        $hold = app(HourlyHold::class);

        $this->assertSame(self::RATE, $plan->hourlyIrt());
        $this->assertSame(self::HOLD, $hold->rateForPlan($plan, self::RATE));

        // بهای تقریباً برابر با نرخِ فروش: نگهداری هرگز گران‌تر از کار کردن نیست
        $this->assertSame(500, $hold->rateForPlan($plan, 500));
    }

    /** «جایی که برای ما هزینه ندارد نمی‌گیریم» */
    public function test_providers_that_do_not_bill_a_stopped_machine_hold_for_free(): void
    {
        $hold = app(HourlyHold::class);

        $this->assertSame(0, $hold->rateForPlan($this->plan('salad', ['slug' => 'gpu-a']), self::RATE));
        $this->assertSame(0, $hold->rateForPlan($this->plan('proxmox', ['slug' => 'ir-a']), self::RATE));
        $this->assertSame(0, $hold->rateForPlan($this->plan('aeza', ['slug' => 'gpu-b', 'is_interruptible' => true]), self::RATE));
        $this->assertSame(self::HOLD, $hold->rateForPlan($this->plan('aeza', ['slug' => 'de-b']), self::RATE));
    }

    /** بها نداریم ⇒ ادعا هم نداریم — عددِ حدسی از جیبِ مشتری ممنوع */
    public function test_unknown_cost_holds_for_free(): void
    {
        $plan = $this->plan('hetzner', ['cost_eur_cents' => 0, 'cost_hour_eur_micro' => null]);

        $this->assertSame(0, app(HourlyHold::class)->rateForPlan($plan, self::RATE));
    }

    public function test_delete_on_credit_out_needs_no_reserve(): void
    {
        $this->assertSame(0, HourlyHold::fullReserve(self::HOLD, 'terminate'));
        $this->assertSame(self::RESERVE, HourlyHold::fullReserve(self::HOLD, 'suspend'));
        $this->assertSame(self::RESERVE, HourlyHold::fullReserve(self::HOLD, 'convert'));
    }

    // ═══════════════ ذخیره در کیفِ پول ═══════════════

    /** 🔴 پولِ نگهداری نه با فاکتور خرج می‌شود، نه با دامنه، نه با AI */
    public function test_no_other_purchase_can_spend_the_hold_reserve(): void
    {
        $c = $this->customer(50_000);
        $s = $this->running($c, $this->plan());

        $this->assertSame(self::RESERVE, (int) $s->hold_reserve_irt);
        $this->assertSame(50_000 - self::RESERVE, $this->wallet()->availableOf($c->id));

        $this->expectException(WalletException::class);
        $this->wallet()->debit($c->id, 'IRT', 50_000 - self::RESERVE + 1, 'domain_renew', $c, 'test');
    }

    // ═══════════════ متر ═══════════════

    /** سرور وقتی می‌ایستد که فقط ذخیرهٔ نگهداری مانده — نه وقتی کیف صفر شد */
    public function test_the_server_stops_while_the_hold_is_still_fully_funded(): void
    {
        $c = $this->customer(self::RESERVE + self::RATE * 2);
        $s = $this->running($c, $this->plan(), ['last_metered_at' => now()->subHours(3)]);

        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame('active', $s->status, 'دو ساعت پرداخت‌شدنی بود');
        $this->assertSame(self::RESERVE, $this->wallet()->balanceOf($c->id));

        Carbon::setTestNow(now()->addHour());
        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame('suspended', $s->status);
        $this->assertSame(self::RESERVE, $this->wallet()->balanceOf($c->id), 'ذخیره دست‌نخورده');
        $this->assertSame(self::RESERVE, (int) $s->hold_reserve_irt);
    }

    /** ساعت‌های نگهداری به بهای تمام‌شده از همان ذخیره کسر می‌شوند */
    public function test_hold_hours_are_charged_from_the_reserve_as_they_pass(): void
    {
        $c = $this->customer(self::RESERVE);
        $s = $this->running($c, $this->plan());
        $s->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'last_metered_at' => now()])->save();

        Carbon::setTestNow(now()->addHours(5)->addMinutes(10));
        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame(self::RESERVE - 5 * self::HOLD, $this->wallet()->balanceOf($c->id));
        $this->assertSame(19 * self::HOLD, (int) $s->hold_reserve_irt);
        $this->assertSame(0, $this->wallet()->availableOf($c->id), 'در دسترس همچنان صفر: بقیه مالِ نگهداری است');

        // دوباره در همان ساعت: هیچ کسرِ دوباره‌ای
        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame(self::RESERVE - 5 * self::HOLD, $this->wallet()->balanceOf($c->id));
    }

    /** 🔴 مشتری‌ای که برنگردد: کلِ ۲۴ ساعتِ نگهداری پرداخت شده، بعد حذف */
    public function test_a_customer_who_never_returns_costs_us_nothing(): void
    {
        $c = $this->customer(self::RESERVE);
        $s = $this->running($c, $this->plan());
        $s->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'last_metered_at' => now()])->save();

        Carbon::setTestNow(now()->addHours(24)->addMinutes(5));
        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame('terminated', $s->status);
        $this->assertSame(0, $this->wallet()->balanceOf($c->id), '۲۴ × ۷۰۰ کامل کسر شد');
        $this->assertDatabaseHas('credit_ledger', [
            'source_id' => $s->id, 'reason' => 'cloud_hourly_hold', 'amount' => -self::RESERVE,
        ]);
        $this->assertSame(0, HourlyHold::heldOf($c->id), 'سرویسِ حذف‌شده ذخیره‌ای نگه نمی‌دارد');
    }

    /** شارژ ⇒ روشن، ساعتِ اول پیش‌پرداخت، ذخیرهٔ کامل دوباره سر جایش */
    public function test_topping_up_resumes_with_a_prepaid_hour_and_a_full_reserve(): void
    {
        $c = $this->customer(self::RESERVE);
        $s = $this->running($c, $this->plan());
        $s->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'last_metered_at' => now()])->save();

        Carbon::setTestNow(now()->addHours(2)->addMinutes(3));

        // فقط «نگهداریِ مصرف‌شده + یک ساعت» — هنوز کافی نیست
        $this->topup($c, 2 * self::HOLD + self::RATE - 1);
        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame('suspended', $s->fresh()->status, 'بی ذخیرهٔ کامل روشن نمی‌شود');

        $this->topup($c, 1);
        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame('active', $s->status);
        $this->assertNull($s->suspended_at);
        $this->assertSame(self::RESERVE, (int) $s->hold_reserve_irt);
        $this->assertSame(self::RESERVE, $this->wallet()->balanceOf($c->id));
        $this->assertSame(0, $this->wallet()->availableOf($c->id));
        $this->assertDatabaseHas('credit_ledger', ['source_id' => $s->id, 'reason' => 'cloud_hourly', 'amount' => -self::RATE]);
    }

    /** سرورِ تعلیق‌شده پیش از این قاعده (نرخِ صفر) همان مهلتِ رایگانِ وعده‌داده را می‌گیرد */
    public function test_a_server_suspended_before_the_rule_is_never_charged_for_hold(): void
    {
        $c = $this->customer(30_000);
        $s = $this->running($c, $this->plan());
        $s->forceFill([
            'status' => 'suspended', 'suspended_at' => now(), 'last_metered_at' => now(),
            'hold_rate_irt' => 0, 'hold_reserve_irt' => 0,
        ])->save();

        Carbon::setTestNow(now()->addHours(6));
        $this->artisan('cloud:meter')->assertOk();

        $this->assertDatabaseMissing('credit_ledger', ['source_id' => $s->id, 'reason' => 'cloud_hourly_hold']);
    }

    /** تبدیل به ماهانه ذخیرهٔ همین سرور را آزاد می‌کند و جزوِ پولِ تبدیل است */
    public function test_convert_to_monthly_can_use_its_own_reserve(): void
    {
        $plan = $this->plan();
        $c = $this->customer(570_000);
        // نرخِ ساعتیِ بالا تا یک ساعت هم پرداخت‌شدنی نباشد و متر به «پایانِ اعتبار» برسد
        $s = $this->running($c, $plan, ['on_credit_out' => 'convert', 'hourly_rate_irt' => 600_000]);

        $this->artisan('cloud:meter')->assertOk();

        $s->refresh();
        $this->assertSame('cycle', $s->billing_mode);
        $this->assertSame(0, (int) $s->hold_reserve_irt);
        $this->assertSame(0, $this->wallet()->balanceOf($c->id));
    }

    // ═══════════════ خرید ═══════════════

    public function test_an_hourly_order_sets_the_reserve_aside_from_the_first_moment(): void
    {
        $this->plan();
        $c = $this->customer(24 * self::RATE);

        $this->actingAs($c, 'customer')->post('/account/cloud-store', [
            'location' => 'de-frankfurt', 'plan' => 'cv-2c-4g-40d-de-frankfurt',
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'billing_mode' => 'hourly',
        ])->assertSessionHasNoErrors();

        $s = Service::where('customer_id', $c->id)->firstOrFail();
        $this->assertSame(self::HOLD, (int) $s->hold_rate_irt);
        $this->assertSame(self::RESERVE, (int) $s->hold_reserve_irt);
        $this->assertSame(24 * self::RATE - self::RATE - self::RESERVE, $this->wallet()->availableOf($c->id));
    }

    public function test_the_panel_hours_left_ignore_the_reserve(): void
    {
        $c = $this->customer(self::RESERVE + 10 * self::RATE);
        $s = $this->running($c, $this->plan());

        $this->assertSame(10, $s->hoursLeft());
    }
}
