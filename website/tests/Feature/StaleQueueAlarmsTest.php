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

}
