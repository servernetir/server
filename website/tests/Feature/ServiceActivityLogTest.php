<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لاگِ چرخهٔ عمرِ سرویس — خواستهٔ کارفرما: «باید بدانم این سرور در فلان زمان
 * دستِ کی بود؛ کی خرید، کی تمدید، کی غیرفعال شد».
 *
 * تمرکز: هر رویداد باید به **سرویسِ مشخص** (service_id) و «کنندهٔ کار» (actor)
 * بچسبد تا تاریخچه‌اش قابلِ پرس‌وجو باشد.
 */
class ServiceActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        $customer = Customer::create([
            'email' => 'log'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create([
            'customer_id' => $customer->id, 'name' => 'سرور تست',
            'currency_code' => 'IRT', 'price' => 570000, 'tax_percent' => 10,
            'cycle' => 'monthly', 'status' => 'active',
        ]);
    }

    public function test_for_service_binds_both_customer_and_service(): void
    {
        $service = $this->service();

        ActivityLog::forService($service, 'suspend', 'تعلیقِ آزمایشی', 'staff');

        $log = ActivityLog::ofService($service->id)->first();

        $this->assertNotNull($log, 'رویداد باید ثبت شود');
        $this->assertSame($service->id, (int) $log->service_id);
        $this->assertSame($service->customer_id, (int) $log->customer_id);
        $this->assertSame('staff', $log->actor);
        $this->assertSame('suspend', $log->action);
    }

    public function test_record_accepts_a_service_id(): void
    {
        $service = $this->service();

        ActivityLog::record($service->customer_id, 'purchase', 'خرید', null, 'customer', $service->id);

        $log = ActivityLog::ofService($service->id)->first();
        $this->assertSame('customer', $log->actor);
        $this->assertSame('purchase', $log->action);
        $this->assertSame($service->id, (int) $log->service_id);
    }

    /** تایم‌لاین باید فقط رویدادهای همین سرویس را، تازه‌ترین اول، بدهد */
    public function test_timeline_is_scoped_and_ordered(): void
    {
        $a = $this->service();
        $b = $this->service();

        ActivityLog::forService($a, 'purchase', 'خرید A', 'customer');
        ActivityLog::forService($b, 'purchase', 'خرید B', 'customer');
        ActivityLog::forService($a, 'renew', 'تمدید A', 'customer');
        ActivityLog::forService($a, 'suspend', 'تعلیق A', 'system');

        $timeline = ActivityLog::ofService($a->id)->get();

        $this->assertCount(3, $timeline, 'فقط رویدادهای سرویسِ A');
        $this->assertSame('suspend', $timeline->first()->action, 'تازه‌ترین اول');
        $this->assertSame('purchase', $timeline->last()->action);

        // هر سه actor در تاریخچه دیده شوند
        $this->assertEqualsCanonicalizing(
            ['customer', 'system'],
            $timeline->pluck('actor')->unique()->sort()->values()->all()
        );
    }

    /** رویدادِ سرویس هم به تاریخچهٔ مشتری می‌رسد (customer_id هم پر است) */
    public function test_service_event_also_appears_in_customer_history(): void
    {
        $service = $this->service();

        ActivityLog::forService($service, 'terminate', 'حذف', 'staff');

        $this->assertSame(1, ActivityLog::where('customer_id', $service->customer_id)->count());
    }
}
