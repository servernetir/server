<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دو هشدارِ **دائمیِ دروغین** که روی پروداکشن دیده شدند.
 *
 * ═══ چرا هشدارِ دروغینِ دائمی از نبودِ هشدار بدتر است ═══
 *
 * امضای وضعیتِ `SystemHealth` شاملِ **کلیدِ چکِ خراب** است و اعلان فقط روی
 * **تغییرِ** امضا می‌رود. پس چکی که برای همیشه قرمز/زرد بماند یعنی خرابیِ
 * واقعیِ بعدیِ همان چک، هیچ اعلانی تولید نمی‌کند — سفارشِ پرداخت‌شده‌ای که
 * تحویل نمی‌شود، در سکوت می‌مانَد. همان قاعدهٔ ثبت‌شدهٔ خودِ این فایل.
 */
class StaleQueueAlarmsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'sq'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function service(array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $this->customer()->id,
            'name'        => 'سرور آزمایشی',
            'cycle'       => 'monthly',
            'price'       => 100000,
            'status'      => 'active',
        ], $over));
    }

    private function check(string $key): array
    {
        foreach (app(SystemHealth::class)->checks() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("چکِ «{$key}» پیدا نشد.");
    }

    /**
     * 🔴 سرویسِ **لغوشده** در `manual` نباید «منتظرِ تحویلِ دستیِ شما» شمرده شود.
     *
     * روی پروداکشن دو سرویسِ لغوشده این کارت را زرد نگه داشته بودند. `$old` و
     * `$failed` دو خط پایین‌تر همین فیلتر را داشتند و فقط `$manual` نداشت.
     */
    public function test_a_cancelled_service_is_not_counted_as_awaiting_manual_delivery(): void
    {
        $this->service(['status' => 'cancelled', 'provision_status' => 'manual']);
        $this->service(['status' => 'terminated', 'provision_status' => 'manual']);

        $row = $this->check('services');

        $this->assertTrue($row['ok'], 'سرویسِ مردهٔ `manual` صفِ تحویل را زرد نگه داشت: '.$row['detail']);
    }

    /**
     * ⚠️ و نیمهٔ دیگر — وگرنه رفعِ بالا فقط هشدار را خفه کرده، نه درست کرده.
     *
     * سرویسِ **زنده**ای که منتظرِ تحویلِ دستی است باید همچنان دیده شود.
     */
    public function test_a_live_service_awaiting_manual_delivery_is_still_reported(): void
    {
        $s = $this->service(['status' => 'active', 'provision_status' => 'manual']);

        $row = $this->check('services');

        $this->assertFalse($row['ok'], 'سرویسِ زندهٔ منتظرِ تحویلِ دستی گزارش نشد.');
        $this->assertNotEmpty($row['links'] ?? [], 'هشدار نامِ سرویس را نداد.');
        $this->assertStringContainsString((string) $s->id, json_encode($row['links'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 🔴 شمارنده و فهرستِ نام‌ها باید **یک** تعریف داشته باشند.
     *
     * نشانهٔ باگِ بالا دقیقاً همین ناهماهنگی بود و روی صفحهٔ زنده دیده می‌شد:
     * کارت عدد داشت و هیچ **نامی** نداشت، چون `stuckServiceRows()` فیلترِ مرده
     * را داشت و شمارنده نداشت. عددِ بی‌نام یعنی مدیر نمی‌داند سراغِ که برود.
     */
    public function test_the_counter_and_the_named_list_never_disagree(): void
    {
        $this->service(['status' => 'cancelled', 'provision_status' => 'manual']);
        $this->service(['status' => 'active', 'provision_status' => 'manual']);

        $row = $this->check('services');

        // یک سرویسِ زنده ⇒ هم هشدار، هم دقیقاً یک نام
        $this->assertFalse($row['ok']);
        $this->assertCount(1, $row['links'] ?? [],
            'تعدادِ نام‌ها با آنچه شمرده شد نمی‌خوانَد — همان ناهماهنگیِ اصلی.');
    }

    /**
     * 🔴 خطِ محصولی که قیمتش صفر شده، **کاملاً** از سایت غیب می‌شود و هیچ
     * خطایی نمی‌سازد — کاتالوگ پر است و کرون هم موفق گزارش می‌دهد.
     *
     * روی زیرساختِ دلاری حادتر است: نبودِ نرخِ دلار یعنی همهٔ کارت‌های GPU
     * صفر می‌شوند و صفحهٔ /gpu خالی می‌مانَد، بی‌آنکه کسی بفهمد.
     */
    public function test_a_catalogue_priced_at_zero_is_reported_not_silent(): void
    {
        $this->gpuPlan(['price_irt' => 0]);

        $row = $this->check('catalogue_price');

        $this->assertFalse($row['ok'], 'کاتالوگِ بی‌قیمت بی‌صدا ماند.');
        $this->assertStringContainsString('غیب', $row['detail']);
    }

    /** ⚠️ و نیمهٔ دیگر: یک پلنِ قیمت‌دار کافی است تا هشدار ساخته نشود. */
    public function test_a_priced_catalogue_raises_nothing(): void
    {
        $this->gpuPlan(['price_irt' => 7_200_000]);

        $this->assertTrue($this->check('catalogue_price')['ok']);
    }

    /**
     * ⚠️ زیرساختی که مدیر **عمداً** بسته نباید هشدار بسازد — وگرنه هر
     * خاموش‌کردنِ آگاهانه یک قرمزِ دائمی می‌گذارد و آژیر بی‌اعتبار می‌شود.
     */
    public function test_a_deliberately_disabled_plan_is_not_an_alarm(): void
    {
        $this->gpuPlan(['price_irt' => 0, 'admin_disabled' => true]);

        $this->assertTrue($this->check('catalogue_price')['ok']);
    }

    private function gpuPlan(array $over = []): \App\Models\CloudPlan
    {
        return \App\Models\CloudPlan::create(array_merge([
            'provider' => 'salad', 'provider_ref' => 'gc-1',
            'location_code' => 'global-gpu', 'public_name' => 'RTX 4090',
            'slug' => 'cv-8c-30g-100d-global-gpu-rtx-4090',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 100,
            'disk_type' => 'ssd', 'traffic_gb' => 0, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 4000, 'price_eur_cents' => 6000, 'price_irt' => 0,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
            'gpu_model' => 'RTX 4090', 'gpu_count' => 1, 'is_interruptible' => true,
        ], $over));
    }
}
