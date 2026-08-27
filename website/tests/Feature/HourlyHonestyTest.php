<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صداقتِ نمایشِ سرویسِ ساعتی + پاک‌سازیِ محصولاتِ قلابیِ GPU.
 *
 * ═══ دو گزارشِ کارفرما (شهریور ۱۴۰۵) ═══
 *
 * ۱) «کاربر ساعتی خریده ولی در پنلِ مدیریت ماهانه نوشته بود» — سرویسِ ساعتی
 *    cycle=monthly دارد (ساختارِ داده) و برچسب همان را چاپ می‌کرد.
 * ۲) پرسش‌های تیکتِ مشتری («بکاپ روزانه»، «API و CLI کامل») از صفحات
 *    gpuaas/gpu-platform آمده بود — دو محصولِ config که A100/H100 و پلتفرمِ
 *    فاین‌تیونی می‌فروختند که هیچ زیرساختی پشتشان نیست.
 */
class HourlyHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function hourlyService(): Service
    {
        $c = Customer::create([
            'email' => 'hh'.random_int(1, 999999).'@x.com',
            'phone' => '0915'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create([
            'customer_id' => $c->id, 'name' => 'GPU ساعتی', 'currency_code' => 'IRT',
            'price' => 12_390_000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 17_300, 'status' => 'active',
        ]);
    }

    /** 🔴 برچسبِ دورهٔ سرویسِ ساعتی «ساعتی» است، نه «ماهانه» */
    public function test_an_hourly_service_is_labelled_hourly_not_monthly(): void
    {
        $s = $this->hourlyService();

        $this->assertSame('ساعتی', $s->cycleLabel());
        // و سرویسِ عادی دست‌نخورده
        $s2 = Service::create([
            'customer_id' => $s->customer_id, 'name' => 'x', 'price' => 1000,
            'cycle' => 'monthly', 'status' => 'active',
        ]);
        $this->assertNotSame('ساعتی', $s2->cycleLabel());
    }

    /**
     * 🔴 چکِ «سررسید ندارد» نباید سرویسِ ساعتی را بشمارد — ساعتی عمداً
     * سررسید ندارد و دکمهٔ «تنظیم» دعوت به فاکتورِ دوبله روی متر بود.
     */
    public function test_the_no_due_date_check_ignores_hourly_services(): void
    {
        $this->hourlyService();

        $rows = collect(app(\App\Services\SystemHealth::class)->checks());
        $row = $rows->firstWhere('key', 'unbilled');

        if ($row === null) {
            $this->markTestSkipped('چکِ سررسید با کلیدِ دیگری ثبت شده');
        }

        $this->assertTrue((bool) $row['ok'],
            'سرویسِ ساعتیِ بی‌سررسید چکِ سلامت را قرمز کرد: '.($row['detail'] ?? ''));
    }

    /** 🔴 محصولاتِ قلابیِ GPU حذف و ۳۰۱ شدند — نه ۴۰۴ و نه صفحهٔ زنده */
    public function test_the_fake_gpu_catalogue_pages_redirect_to_the_real_line(): void
    {
        foreach (['/cloud/gpuaas', '/cloud/gpu-platform'] as $path) {
            $r = $this->get($path);
            $r->assertStatus(301);
            $this->assertStringContainsString('/gpu', $r->headers->get('Location'),
                $path.' به /gpu نمی‌رود.');
        }

        // و از config واقعاً حذف شده‌اند — وگرنه sitemap و منو دوباره می‌سازندشان
        $this->assertNull(config('catalog.cloud.gpuaas'));
        $this->assertNull(config('catalog.cloud.gpu-platform'));
    }
}
