<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * چرخهٔ تمدید: یادآوریِ ۷/۳/۱ روز، تعلیقِ خودکار، مهلتِ ۳۰ روزه.
 *
 * مهم‌ترین ادعاها: پیامِ تکراری فرستاده نشود، سرویسِ پرداخت‌نشده معلق شود، و
 * terminate **هرگز خودکار** نباشد.
 */
class ServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        config()->set('servernet.contact.notify_phone', '09120000000');
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سرویسِ فعال با سررسیدِ مشخص، و در صورتِ نیاز یک فاکتورِ باز */
    private function service(int $daysToDue, bool $withUnpaidInvoice, array $over = []): Service
    {
        $c = $this->customer();

        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'هاست لینوکس', 'currency_code' => 'IRT',
            'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'activated_at' => now()->subMonths(2),
            'next_due_at' => now()->addDays($daysToDue)->toDateString(),
            'domain' => 'x'.random_int(1, 9999).'.com',
        ], $over));

        if ($withUnpaidInvoice) {
            Invoice::create([
                'customer_id' => $c->id, 'service_id' => $s->id, 'kind' => 'service',
                'currency_code' => 'IRT', 'subtotal' => 250000, 'tax' => 0, 'total' => 250000,
                'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
            ]);
        }

        return $s->refresh();
    }

    /**
     * جاسوس روی CustomerNotifier.
     *
     * ⚠️ چرا Mail::assertSentCount کار نمی‌کند: یادآوری‌ها با Mail::raw()
     * فرستاده می‌شوند و آن شمارنده فقط کلاس‌های Mailable را می‌شمارد، نه پیامِ
     * خام. پس «آیا یادآوری رفت؟» را از خودِ نوتیفایر می‌پرسیم.
     */
    private function spyNotifier(): \Mockery\MockInterface
    {
        $spy = \Mockery::spy(\App\Services\Notify\CustomerNotifier::class);
        $this->app->instance(\App\Services\Notify\CustomerNotifier::class, $spy);

        return $spy;
    }

    public function test_seven_day_reminder_is_sent_and_stage_recorded(): void
    {
        $s = $this->service(7, true);
        $spy = $this->spyNotifier();

        $this->artisan('services:lifecycle')->assertSuccessful();

        $this->assertSame(7, $s->fresh()->reminder_stage);
        // یادآوری حالا از مسیرِ الگو می‌رود (کلیدِ `expiring`)، نه پیامِ آزاد.
        $spy->shouldHaveReceived('templated')
            ->withArgs(fn ($c, $key) => $key === 'expiring')->once();
        $spy->shouldNotHaveReceived('message');
    }

    /** اجرای دوباره نباید پیامِ تکراری بفرستد */
    public function test_reminder_is_not_repeated_on_second_run(): void
    {
        $s = $this->service(7, true);

        $this->artisan('services:lifecycle');          // بارِ اول: یادآوری می‌رود

        $spy = $this->spyNotifier();                   // از این‌جا به بعد را بشمار
        $this->artisan('services:lifecycle');          // بارِ دوم: نباید چیزی برود

        $spy->shouldNotHaveReceived('message');
        $spy->shouldNotHaveReceived('templated');
        $this->assertSame(7, $s->fresh()->reminder_stage);
    }

    public function test_three_and_one_day_stages_advance(): void
    {
        $s = $this->service(3, true);
        $this->artisan('services:lifecycle');
        $this->assertSame(3, $s->fresh()->reminder_stage);

        // یک روز مانده
        $s->update(['next_due_at' => now()->addDay()->toDateString()]);
        $this->artisan('services:lifecycle');
        $this->assertSame(1, $s->fresh()->reminder_stage);
    }

    /** سررسید گذشته و پرداخت نشده → تعلیقِ خودکار */
    public function test_overdue_unpaid_service_is_suspended(): void
    {
        $s = $this->service(-1, true);

        $this->artisan('services:lifecycle')->assertSuccessful();

        $s->refresh();
        $this->assertSame('suspended', $s->status);
        $this->assertNotNull($s->suspended_at);
    }

    /** سررسید گذشته ولی فاکتور پرداخت شده → دست نزن */
    public function test_overdue_but_paid_service_is_left_alone(): void
    {
        $s = $this->service(-2, false);

        $this->artisan('services:lifecycle');

        $this->assertSame('active', $s->fresh()->status);
        $this->assertNull($s->fresh()->suspended_at);
    }

    /** پرداخت پس از تعلیق → برگشت به فعال */
    public function test_paid_after_suspension_is_restored(): void
    {
        $s = $this->service(5, false, [
            'status' => 'suspended',
            'suspended_at' => now()->subDays(3),
        ]);

        $this->artisan('services:lifecycle')->assertSuccessful();

        $s->refresh();
        $this->assertSame('active', $s->status);
        $this->assertNull($s->suspended_at);
    }

    /** پس از ۳۰ روز، یک‌بار به مدیر بگو — و terminate خودکار نکن */
    public function test_grace_alert_fires_once_and_never_terminates(): void
    {
        $s = $this->service(-31, true, [
            'status' => 'suspended',
            'suspended_at' => now()->subDays(31),
        ]);

        $this->artisan('services:lifecycle');
        $s->refresh();
        $this->assertNotNull($s->grace_alert_at, 'اعلانِ پایانِ مهلت نرفت');

        // اجرای دوباره نباید دوباره اعلان بدهد
        $first = $s->grace_alert_at;
        $this->artisan('services:lifecycle');
        $this->assertEquals($first, $s->fresh()->grace_alert_at);

        // و سرویس هرگز خودکار حذف/لغو نمی‌شود
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertNotSame('cancelled', $s->fresh()->status);
    }

    /** حالتِ آزمایشی هیچ‌چیز را عوض نمی‌کند و هیچ پیامی نمی‌فرستد */
    public function test_dry_run_changes_nothing(): void
    {
        $s = $this->service(7, true);
        $spy = $this->spyNotifier();

        $this->artisan('services:lifecycle --dry')->assertSuccessful();

        $this->assertNull($s->fresh()->reminder_stage);
        $spy->shouldNotHaveReceived('message');
        $spy->shouldNotHaveReceived('templated');
    }

    /** سرویسِ یک‌بارمصرف (بدونِ دوره) وارد این چرخه نمی‌شود */
    public function test_one_time_service_is_ignored(): void
    {
        $s = $this->service(-5, true, ['cycle' => 'once']);

        $this->artisan('services:lifecycle');

        $this->assertSame('active', $s->fresh()->status);
    }
}
