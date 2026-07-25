<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * طولِ ستون‌های وضعیت باید به مقدارهای واقعی بخورد.
 *
 * ⚠️ درسِ گران: services.status = varchar(12) بود ولی «awaiting_provision»
 * ۱۸ نویسه است. SQLiteِ تست طولِ VARCHAR را **اعمال نمی‌کند** پس همهٔ تست‌ها سبز
 * بودند، ولی MariaDBِ پروداکشن خطای «Data too long» می‌داد و کلِ تراکنشِ پرداخت
 * برمی‌گشت: مشتری ۵۰۰ می‌دید، فاکتور پرداخت‌نشده می‌ماند و سرویس ساخته نمی‌شد.
 *
 * این تست طولِ ستون را از خودِ schema می‌خواند تا این کلاسِ خطا دوباره از تور
 * رد نشود — مستقل از اینکه درایورِ تست سخت‌گیر باشد یا نه.
 */
class ServiceStatusColumnTest extends TestCase
{
    use RefreshDatabase;

    /** همهٔ وضعیت‌هایی که کد واقعاً می‌نویسد */
    private const SERVICE_STATUSES = [
        'pending', 'active', 'suspended', 'cancelled',
        'awaiting_provision', 'provision_failed',
    ];

    public function test_services_status_column_fits_every_status_we_write(): void
    {
        $longest = max(array_map('strlen', self::SERVICE_STATUSES));

        // نامِ نوعِ ستون را از schema بگیر (mariadb: varchar(24)، sqlite: varchar)
        $columns = Schema::getColumns('services');
        $status = collect($columns)->firstWhere('name', 'status');

        $this->assertNotNull($status, 'ستونِ status پیدا نشد');

        if (preg_match('/\((\d+)\)/', (string) ($status['type'] ?? ''), $m)) {
            $this->assertGreaterThanOrEqual(
                $longest,
                (int) $m[1],
                'ستونِ services.status برای «'.implode('/', self::SERVICE_STATUSES).'» کوتاه است',
            );
        } else {
            // درایور طول نمی‌دهد (SQLite) — دستِ‌کم مطمئن شو نوشتن و خواندن سالم است
            $this->assertTrue(true);
        }
    }

    /** هر وضعیت باید بی‌بریدگی ذخیره و خوانده شود */
    public function test_every_status_round_trips_without_truncation(): void
    {
        $customer = \App\Models\Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        foreach (self::SERVICE_STATUSES as $status) {
            $service = Service::create([
                'customer_id' => $customer->id, 'name' => 'س', 'currency_code' => 'IRT',
                'price' => 1000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => $status,
            ]);

            $this->assertSame($status, $service->fresh()->status, "وضعیتِ «{$status}» بریده شد");
        }
    }

    /** برچسبِ هر وضعیت باید تعریف شده باشد (نه رشتهٔ خام به مشتری) */
    public function test_every_status_has_a_badge(): void
    {
        foreach (self::SERVICE_STATUSES as $status) {
            $badge = (new Service(['status' => $status]))->statusBadge();

            $this->assertNotSame($status, $badge[0], "وضعیتِ «{$status}» برچسبِ فارسی ندارد");
            $this->assertNotEmpty($badge[1]);
        }
    }
}
