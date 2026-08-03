<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Cloud\CloudFraudGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سقفِ سفارشِ سرورِ ابری — محافظِ «کارتِ دزدیده‌شده».
 *
 * 🔴 چرا شعاعِ انفجارش بزرگ است: تحویل خودکار است، پس مهاجم با یک ثبت‌نام و یک
 * پرداختِ نامعتبر می‌تواند ده‌ها سرورِ خارج بگیرد. بعد از chargeback، هم
 * صورتحسابِ زیرساخت پای ماست هم گزارشِ abuse — و اگر زیرساخت حسابِ مادر را
 * تعلیق کند، سرورِ **همهٔ** مشتریانِ خارج هم‌زمان از دست می‌رود.
 */
class CloudFraudGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(?\Carbon\Carbon $created = null): Customer
    {
        $c = Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);

        if ($created !== null) {
            $c->forceFill(['created_at' => $created])->save();
        }

        return $c->fresh();
    }

    private function cloudService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی', 'currency_code' => 'IRT',
            'price' => 900000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => 1, 'activated_at' => now(),
        ], $over));
    }

    private function guard(Customer $c): array
    {
        return app(CloudFraudGuard::class)->check($c);
    }

    // ═══════════ مسیرِ عادی ═══════════

    public function test_a_first_order_is_allowed(): void
    {
        $this->assertFalse($this->guard($this->customer())['hold']);
    }

    public function test_an_established_customer_is_not_limited(): void
    {
        $c = $this->customer(now()->subMonths(6));
        Invoice::create(['customer_id' => $c->id, 'number' => 'INV-1', 'currency_code' => 'IRT',
            'subtotal' => 1, 'tax' => 0, 'total' => 1, 'paid' => 1, 'status' => 'paid', 'issued_at' => now()]);

        foreach (range(1, 4) as $i) {
            $this->cloudService($c)->forceFill(['created_at' => now()->subDays(10)])->save();
        }

        $this->assertFalse($this->guard($c)['hold'], 'مشتریِ قدیمیِ پرداخت‌کرده نباید بلوکه شود');
    }

    // ═══════════ حالت‌های مشکوک ═══════════

    /** 🔴 حسابِ چندساعته با چند سرور — الگوی کلاسیکِ کارتِ دزدیده‌شده */
    public function test_a_brand_new_account_is_held_after_two_servers(): void
    {
        $c = $this->customer(now()->subHour());
        $this->cloudService($c);
        $this->cloudService($c);

        $v = $this->guard($c);

        $this->assertTrue($v['hold']);
        $this->assertNotNull($v['reason']);
    }

    /** پرداختِ تسویه‌شده اعتماد می‌سازد و سقفِ حسابِ نوپا را برمی‌دارد */
    public function test_a_settled_payment_lifts_the_new_account_cap(): void
    {
        $c = $this->customer(now()->subHour());
        $this->cloudService($c);
        $this->cloudService($c);

        Invoice::create(['customer_id' => $c->id, 'number' => 'INV-2', 'currency_code' => 'IRT',
            'subtotal' => 1, 'tax' => 0, 'total' => 1, 'paid' => 1, 'status' => 'paid', 'issued_at' => now()]);

        $this->assertFalse($this->guard($c)['hold']);
    }

    /** فاکتورِ پرداخت‌نشده اعتماد نمی‌سازد */
    public function test_an_unpaid_invoice_does_not_lift_the_cap(): void
    {
        $c = $this->customer(now()->subHour());
        $this->cloudService($c);
        $this->cloudService($c);

        Invoice::create(['customer_id' => $c->id, 'number' => 'INV-3', 'currency_code' => 'IRT',
            'subtotal' => 1, 'tax' => 0, 'total' => 1, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);

        $this->assertTrue($this->guard($c)['hold']);
    }

    /** 🔴 سقفِ روزانه حتی برای مشتریِ قدیمی — انفجارِ ناگهانی هم مشکوک است */
    public function test_a_burst_in_one_day_is_held_even_for_an_old_customer(): void
    {
        $c = $this->customer(now()->subYear());
        Invoice::create(['customer_id' => $c->id, 'number' => 'INV-4', 'currency_code' => 'IRT',
            'subtotal' => 1, 'tax' => 0, 'total' => 1, 'paid' => 1, 'status' => 'paid', 'issued_at' => now()]);

        foreach (range(1, 5) as $i) {
            $this->cloudService($c);
        }

        $this->assertTrue($this->guard($c)['hold']);
    }

    /** سرویسِ بسته‌شده نباید تا ابد سقف را اشغال کند */
    public function test_terminated_services_do_not_count_towards_the_live_cap(): void
    {
        $c = $this->customer(now()->subHour());
        $this->cloudService($c, ['status' => 'terminated'])->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->cloudService($c, ['status' => 'cancelled'])->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertFalse($this->guard($c)['hold']);
    }

    // ═══════════ اثرِ واقعی روی تحویل ═══════════

    /**
     * 🔴 مهم‌ترین ادعا: نگه‌داشتن یعنی **هیچ پولی خرج نمی‌شود**.
     *
     * سرویس به صفِ دستی می‌رود و کرونِ `provision:run` — که فقط 'pending' را
     * برمی‌دارد — دیگر سراغش نمی‌آید.
     */
    public function test_a_held_order_never_reaches_the_provider(): void
    {
        $c = $this->customer(now()->subHour());
        $this->cloudService($c);
        $this->cloudService($c);

        $s = $this->cloudService($c, ['status' => 'awaiting_provision', 'provision_status' => 'pending']);

        app(\App\Services\Cloud\CloudProvisioner::class)->provision($s->fresh());

        $fresh = $s->fresh();
        $this->assertSame('manual', $fresh->provision_status, 'باید به صفِ بازبینیِ دستی برود');
        $this->assertStringContainsString('تأییدِ دستی', (string) $fresh->provision_error);

        // هیچ تماسی با زیرساخت نرفته باشد
        Http::assertNothingSent();
    }
}
