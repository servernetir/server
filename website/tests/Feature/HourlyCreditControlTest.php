<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کنترلِ موجودی روی سرورِ ساعتی + زبانِ لاگ‌های صورت‌حساب.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «مشتری با ۱ دلار نتواند ده سرور بگیرد؛ اگر پول نداشت تعلیق و بعد
 * حذف. و لاگِ "کسر ساعتی ۱ ساعت" هم فارسی بود هم نمی‌گفت چقدر کسر شد و چقدر
 * ماند.» گیتِ خرید روی **مجموعِ مصرف** است و لاگ‌ها حالا به زبانِ مشتری با
 * مبلغ و مانده نوشته می‌شوند.
 */
class HourlyCreditControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('pricing_rate_override', '120000');
    }

    private function customer(string $locale = 'fa'): Customer
    {
        return Customer::create([
            'email' => 'hc'.random_int(1, 999999).'@example.com',
            'phone' => $locale === 'fa' ? '0912'.random_int(1000000, 9999999) : '+9053'.random_int(10000000, 99999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    private function topup(Customer $c, int $irt): void
    {
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
            'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);
    }

    private function catalog(): CloudPlan
    {
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function orderHourly(Customer $c, string $prefix = '')
    {
        return $this->actingAs($c, 'customer')->post($prefix.'/account/cloud-store', [
            'location' => 'de-frankfurt', 'plan' => 'cv-2c-4g-40d-de-frankfurt',
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'billing_mode' => 'hourly',
        ]);
    }

    /** ماشینِ تحویل‌شده — تا مترِ ساعتی واقعاً صورت‌حساب کند */
    private function deliver(Service $s): void
    {
        $s->forceFill([
            'status' => 'active', 'provision_status' => 'done',
            'last_metered_at' => now()->subHours(2),
        ])->save();

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-frankfurt', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.1.0.'.($s->id % 250 + 1),
            'status' => 'running', 'ready_notified_at' => now()->subDays(2),
        ]);
    }

    // ═══════════ گیتِ مجموعِ مصرف ═══════════

    /** 🔴 «۱ دلار، ده سرور» ممکن نیست: سرورِ دوم اعتبارِ مجموع می‌خواهد */
    public function test_a_second_hourly_server_must_cover_the_combined_burn(): void
    {
        $plan = $this->catalog();
        $c = $this->customer();

        // دقیقاً کفِ یک سرور — نه بیشتر
        $this->topup($c, $plan->hourlyStartMinIrt());

        $this->orderHourly($c)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Service::where('customer_id', $c->id)->count());

        // سرورِ دوم: کف = کفِ خودش + مصرفِ سرورِ موجود ⇒ رد، و هیچ سرویسی ساخته نشود
        $this->orderHourly($c)->assertSessionHasErrors('billing_mode');
        $this->assertSame(1, Service::where('customer_id', $c->id)->count(),
            'با اعتبارِ یک سرور نباید سرورِ دوم ساخته شود.');
    }

    // ═══════════ زبان و محتوای لاگ‌ها ═══════════

    /** 🔴 نامِ سرویس و لاگِ خرید به زبانِ خودِ مشتری است */
    public function test_hourly_service_name_follows_the_customer_language(): void
    {
        $this->catalog();

        $en = $this->customer('en');
        $this->topup($en, 10_000_000);
        $this->orderHourly($en, '/en')->assertRedirect()->assertSessionHasNoErrors();

        $s = Service::where('customer_id', $en->id)->firstOrFail();
        $this->assertStringStartsWith('Cloud VPS', $s->name, 'نامِ سرویسِ مشتریِ انگلیسی نباید فارسی باشد.');
        $this->assertStringContainsString('(hourly)', $s->name);

        $fa = $this->customer('fa');
        $this->topup($fa, 10_000_000);
        $this->orderHourly($fa)->assertRedirect()->assertSessionHasNoErrors();

        $sf = Service::where('customer_id', $fa->id)->firstOrFail();
        $this->assertStringStartsWith('سرور مجازی', $sf->name);
    }

    /** 🔴 لاگِ کسرِ ساعتی: زبانِ مشتری + مبلغِ کسرشده + مانده */
    public function test_the_hourly_charge_log_has_amount_and_balance_in_the_customers_language(): void
    {
        $this->catalog();

        $en = $this->customer('en');
        $this->topup($en, 10_000_000);
        $this->orderHourly($en, '/en')->assertSessionHasNoErrors();
        $s = Service::where('customer_id', $en->id)->firstOrFail();
        $this->deliver($s);

        $this->artisan('cloud:meter')->assertSuccessful();

        $log = ActivityLog::where('service_id', $s->id)->where('action', 'renew')->latest('id')->first();
        $this->assertNotNull($log, 'کسرِ ساعتی باید لاگ داشته باشد.');
        $this->assertStringContainsString('Hourly charge:', (string) $log->description,
            'لاگِ مشتریِ انگلیسی باید انگلیسی باشد.');
        $this->assertStringContainsString('credit left:', (string) $log->description,
            'مانده باید در لاگ باشد.');
        $this->assertStringContainsString('€', (string) $log->description,
            'مبلغ برای مشتریِ انگلیسی یورویی است.');

        // کنترل: مشتریِ فارسی همان لاگ را فارسی و تومانی می‌گیرد
        $fa = $this->customer('fa');
        $this->topup($fa, 10_000_000);
        $this->orderHourly($fa)->assertSessionHasNoErrors();
        $sf = Service::where('customer_id', $fa->id)->firstOrFail();
        $this->deliver($sf);

        $this->artisan('cloud:meter')->assertSuccessful();

        $logFa = ActivityLog::where('service_id', $sf->id)->where('action', 'renew')->latest('id')->first();
        $this->assertStringContainsString('کسر ساعتی', (string) $logFa->description);
        $this->assertStringContainsString('تومان', (string) $logFa->description);
    }

    /** اتمامِ اعتبار: تعلیق با لاگِ هم‌زبان، و پس از مهلت حذف — ضررِ ما بسته است */
    public function test_credit_out_suspends_then_grace_expiry_deletes(): void
    {
        $this->catalog();

        $en = $this->customer('en');
        $this->topup($en, 10_000_000);
        $this->orderHourly($en, '/en')->assertSessionHasNoErrors();
        $s = Service::where('customer_id', $en->id)->firstOrFail();
        $this->deliver($s);

        // کلِ اعتبار را خالی کن (به‌جز آنچه قبلاً برای ساعتِ اول کسر شده)
        $balance = (int) CreditEntry::where('customer_id', $en->id)->sum('amount');
        CreditEntry::create([
            'customer_id' => $en->id, 'currency_code' => 'IRT', 'amount' => -$balance,
            'balance_after' => 0, 'reason' => 'adjust', 'source_type' => Customer::class,
            'source_id' => $en->id, 'note' => 'drain',
        ]);

        $this->artisan('cloud:meter')->assertSuccessful();

        $s = $s->fresh();
        $this->assertSame('suspended', $s->status, 'بی‌اعتبار ⇒ تعلیق.');
        $this->assertNotNull($s->suspended_at);

        $log = ActivityLog::where('service_id', $s->id)->where('action', 'suspend')->latest('id')->first();
        $this->assertStringContainsString('suspended', (string) $log->description,
            'لاگِ تعلیق باید به زبانِ مشتری باشد.');

        // مهلت گذشت و هنوز خالی ⇒ حذف و آزادسازی
        $s->forceFill(['suspended_at' => now()->subHours(25)])->save();

        $this->artisan('cloud:meter')->assertSuccessful();

        $this->assertSame('terminated', $s->fresh()->status, 'پس از مهلت باید حذف شود — سرورِ خاموش هم برای ما هزینه دارد.');
    }
}
